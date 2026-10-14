"""Student model for Corrai workers."""

from __future__ import annotations

import logging
import secrets
import time
from collections.abc import Callable
from typing import Any

from pycorrai.store.exceptions import StoreConflictException
from pycorrai.store.hash_id import HashId
from pycorrai.store.metadata_cache import invalidate_assessment
from pycorrai.store.object_store import ObjectStore, make_redis_client
from pycorrai.model.input_file import InputFile
from pycorrai.model.s3_file import S3File
from pycorrai.model.submission_file import SubmissionFile

OcrRunner = Callable[..., None]

logger = logging.getLogger(__name__)


class Student:
    def __init__(self) -> None:
        self.id: str | None = None
        self.school_id: str = ""
        self.user_id: str = ""
        self.assessment_id: str = ""
        self.name: str = ""
        self.status: str = ""
        self.mark: float | None = None
        self.appreciation: str = ""
        self.etag: str | None = None

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> Student:
        student = cls()
        student.id = data.get("id")
        student.school_id = str(data.get("school_id") or "")
        student.user_id = str(data.get("user_id") or "")
        student.assessment_id = str(data.get("assessment_id") or "")
        student.name = str(data.get("name") or data.get("student") or "")
        student.status = str(data.get("status") or "")
        mark = data.get("mark")
        if isinstance(mark, (int, float)):
            student.mark = float(mark)
        elif isinstance(mark, str) and mark.strip() != "" and _is_numeric(mark):
            student.mark = float(mark)
        else:
            student.mark = None
        student.appreciation = str(data.get("appreciation") or "")
        return student

    def validate(self) -> None:
        if (
            self.school_id.strip() == ""
            or self.user_id.strip() == ""
            or self.assessment_id.strip() == ""
        ):
            raise ValueError("Student school_id, user_id and assessment_id are required")
        if self.name.strip() == "":
            raise ValueError("Student name is required")

    @classmethod
    def from_hash(cls, hash_id: str) -> Student:
        store = ObjectStore.get_instance()
        prefix = store.resolve_id_pointer(hash_id)
        parsed = ObjectStore.parse_node_prefix(prefix)
        if parsed.get("kind") != "student":
            raise RuntimeError(f"Hash {hash_id} does not point to a student")

        attr_key = ObjectStore.assessment_student_attr_key(
            str(parsed["school_id"]),
            str(parsed["teacher_id"]),
            str(parsed["assessment_id"]),
            str(parsed["student_id"]),
        )
        if not store.exists(attr_key):
            raise RuntimeError(f"Student with hash {hash_id} does not exist")

        data, etag = store.get_json_with_etag(attr_key)
        student = cls.from_array(data)
        student.id = str(parsed["student_id"])
        student.school_id = str(parsed["school_id"])
        student.user_id = str(parsed["teacher_id"])
        student.assessment_id = str(parsed["assessment_id"])
        student.etag = etag
        return student

    def attr_key(self) -> str:
        return ObjectStore.assessment_student_attr_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
        )

    def prefix(self) -> str:
        return ObjectStore.assessment_student_prefix(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
        )

    def compiled_submission_key(self) -> str:
        return ObjectStore.assessment_student_compiled_submission_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
        )

    def compile_submission(
        self,
        param: str = "",
        *,
        re_compile_submission: bool = False,
        ocr_runner: OcrRunner | None = None,
    ) -> dict[str, Any]:
        """Compile student submission InputFiles into ``compiled_submission.json``."""
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot compile submission without student id")
        if (
            self.school_id == ""
            or self.user_id == ""
            or self.assessment_id == ""
        ):
            raise RuntimeError(
                "Cannot compile submission without school_id, user_id and assessment_id"
            )

        from pycorrai.model.compile_inputs import compile_inputs

        files = self._submission_input_files()
        return compile_inputs(
            files,
            self.compiled_submission_key(),
            re_compile=re_compile_submission
            or param == "re_compile_submission",
            ocr_runner=ocr_runner,
        )

    def _submission_input_files(self) -> list[InputFile]:
        """Submission InputFiles for this student, ordered by name."""
        files: list[InputFile] = list(self.get_submissions())
        files.sort(key=lambda file: file.name)
        return files

    def save(self, retry: bool = True) -> None:
        if self.id is None or self.id == "":
            self.id = HashId.create()
            self.etag = None
        self.validate()

        payload: dict[str, Any] = {
            "name": self.name,
            "status": self.status,
            "mark": self.mark,
            "appreciation": self.appreciation,
        }

        store = ObjectStore.get_instance()
        attempts = 5 if retry else 1
        for i in range(attempts):
            try:
                self.etag = store.put_json(self.attr_key(), payload, self.etag)
                store.set_id_pointer(self.id, self.prefix())
                self._invalidate_metadata_cache()
                return
            except StoreConflictException:
                if i == attempts - 1:
                    raise
                _data, self.etag = store.get_json_with_etag(self.attr_key())

    def get_submissions(self) -> list[SubmissionFile]:
        if self.assessment_id == "" or self.id is None or self.id == "":
            return []
        from pycorrai.model.assessment import Assessment

        assessment = Assessment.from_hash(self.assessment_id)
        files: list[SubmissionFile] = []
        for file in assessment.list_student_file_models(self.id):
            if isinstance(file, SubmissionFile):
                files.append(file)
        return files

    def get_status(self) -> str:
        return self.status

    def append_event(self, name: str, timestamp: int | None = None) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot append event without student id")
        ts = int(time.time()) if timestamp is None else timestamp
        event_id = f"{ts}-{secrets.token_hex(4)}"
        key = ObjectStore.assessment_student_event_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id),
            event_id,
        )
        S3File.at(key).put_json({"timestamp": ts, "name": name})

    def delete(self) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot delete student without id")
        store = ObjectStore.get_instance()
        store.delete_prefix(self.prefix())
        store.delete_id_pointer(self.id)
        self._invalidate_metadata_cache()

    def _invalidate_metadata_cache(self) -> None:
        if self.school_id == "" or self.user_id == "" or self.assessment_id == "":
            return
        try:
            invalidate_assessment(
                make_redis_client(),
                self.school_id,
                self.user_id,
                self.assessment_id,
            )
        except Exception:
            logger.exception("MetadataCache invalidate (Student) failed")


def _is_numeric(value: str) -> bool:
    try:
        float(value)
        return True
    except ValueError:
        return False
