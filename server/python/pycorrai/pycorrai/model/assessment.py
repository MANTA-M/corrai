"""Assessment model for Corrai workers (no subject pipelines / Stripe / menus)."""

from __future__ import annotations

import json
import logging
import secrets
import time
from collections.abc import Callable, Sequence
from typing import Any

from pycorrai.store.hash_id import HashId
from pycorrai.store.metadata_cache import invalidate_assessment
from pycorrai.store.object_store import ObjectStore, make_redis_client
from pycorrai.model.input_file import InputFile
from pycorrai.model.instruction_file import InstructionFile
from pycorrai.model.s3_file import S3File
from pycorrai.model.student import Student

# Re-exports (public API compatibility)
from pycorrai.model.prompts.correction_grid import (  # noqa: F401
    CORRECTION_GRID_SYSTEM_PROMPT,
    MATH_CORRECTION_GRID_SYSTEM_PROMPT,
    STRICT_CORRECTION_GRID_SYSTEM_PROMPT,
)
from pycorrai.model.prompts.law_correction import (  # noqa: F401
    law_correction_system_prompt,
)
from pycorrai.model.prompts.math_correction import (  # noqa: F401
    math_correction_system_prompt,
)
from pycorrai.model.publishing import (  # noqa: F401
    publish_assessment_loading,
    publish_student_loading,
)
from pycorrai.model.schemas.correction_grid import (  # noqa: F401
    correction_grid_schema,
    math_correction_grid_schema,
    strict_correction_grid_schema,
)
from pycorrai.model.schemas.law_correction import law_correction_schema  # noqa: F401
from pycorrai.model.schemas.math_correction import math_correction_schema  # noqa: F401
from pycorrai.model.services.grid_helpers import (  # noqa: F401
    apply_math_retained_percentages,
    apply_retained_criterion_points,
    build_correction_from_jev_answers,
    build_jev_noul_questions,
    iter_grid_criteria,
    strip_points_from_correction_grid,
    strip_question_points_from_math_grid,
)

OcrRunner = Callable[..., None]
ClientFactory = Callable[[], Any]
PublishAssessment = Callable[..., None]
PublishStudent = Callable[..., None]

logger = logging.getLogger(__name__)

LOCALE_NAMES = {
    "en": "English",
    "fr": "French",
    "ru": "Russian",
    "uk": "Ukrainian",
    "es": "Spanish",
    "pt": "Portuguese",
    "ro": "Romanian",
    "de": "German",
}

STATUS_GRID_CREATION = "create_correction_grid"
STATUS_GRID_CREATED = "correction_grid_generated"
LABEL_GRID_CREATION = "Grid creation"
LABEL_GRID_CREATED = "Grid created"

STATUS_STRICT_GRID_CREATION = "create_strict_correction_grid"
STATUS_STRICT_GRID_CREATED = "strict_correction_grid_generated"
LABEL_STRICT_GRID_CREATION = "Strict grid creation"
LABEL_STRICT_GRID_CREATED = "Strict grid created"

STATUS_MATH_GRID_CREATION = "create_math_correction_grid"
STATUS_MATH_GRID_CREATED = "math_correction_grid_generated"
LABEL_MATH_GRID_CREATION = "Math grid creation"
LABEL_MATH_GRID_CREATED = "Math grid created"

STATUS_UNDER_CORRECTION = "under_correction"
STATUS_GRADED = "graded"
STATUS_STUDENT_ERROR = "error"
LABEL_UNDER_CORRECTION = "En correction"
LABEL_CORRECTED = "Corrigé"
LABEL_STUDENT_ERROR = "Erreur"


class Assessment:
    FILE_TYPES = [
        "subject",
        "solution",
        "submission",
        "instructions",
        "correction",
        "debug",
    ]

    def __init__(self) -> None:
        self.id: str | None = None
        self.school_id: str = ""
        self.user_id: str = ""
        self.name: str = ""
        self.subject: str = ""
        self.country: str | None = None
        self.level: str | None = None
        self.correction_language: str = "fr"
        self.date: str = ""
        self.created_at: str = ""
        self.status: str = "draft"
        self.assessed_students_number: int | None = None
        self.mark_average: float | None = None
        self.mark_min: float | None = None
        self.mark_max: float | None = None

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> Assessment:
        assessment = cls()
        assessment.id = data.get("id")
        assessment.school_id = str(data.get("school_id") or "")
        assessment.user_id = str(data.get("user_id") or data.get("author") or "")
        assessment.name = str(data.get("name") or "")
        assessment.subject = str(data.get("subject") or "")
        assessment.country = cls.optional_attribute(data.get("country"))
        assessment.level = cls.optional_attribute(data.get("level"))
        assessment.correction_language = cls.normalize_locale(
            data.get("correction_language")
        )
        assessment.date = str(data.get("date") or "")
        assessment.created_at = str(data.get("created_at") or "")
        assessment.status = str(data.get("status") or "draft")
        assessment.assessed_students_number = cls.non_negative_int_attribute(
            data.get("assessed_students_number")
        )
        assessment.mark_average = cls.nullable_number_attribute(
            data.get("mark_average")
        )
        assessment.mark_min = cls.nullable_number_attribute(data.get("mark_min"))
        assessment.mark_max = cls.nullable_number_attribute(data.get("mark_max"))
        return assessment

    @staticmethod
    def optional_attribute(value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        trimmed = value.strip()
        return None if trimmed == "" else trimmed

    @staticmethod
    def non_negative_int_attribute(value: Any) -> int | None:
        if isinstance(value, int) and value >= 0:
            return value
        if isinstance(value, float) and value >= 0:
            return int(value)
        if isinstance(value, str) and value.strip().isdigit():
            return int(value)
        return None

    @staticmethod
    def nullable_number_attribute(value: Any) -> float | None:
        if isinstance(value, (int, float)):
            return float(value)
        if isinstance(value, str) and value.strip() != "":
            try:
                return float(value)
            except ValueError:
                return None
        return None

    @staticmethod
    def normalize_locale(value: Any) -> str:
        if not isinstance(value, str) or value.strip() == "":
            return "fr"
        locale = value.strip().lower()
        dash = locale.find("-")
        if dash >= 0:
            locale = locale[:dash]
        if locale not in LOCALE_NAMES:
            return "fr"
        return locale

    def attribute_payload(self) -> dict[str, Any]:
        return {
            "name": self.name,
            "subject": self.subject,
            "country": self.optional_attribute(self.country),
            "level": self.optional_attribute(self.level),
            "correction_language": self.correction_language,
            "date": self.date,
            "created_at": self.created_at,
            "status": self.status,
            "assessed_students_number": self.assessed_students_number,
            "mark_average": self.mark_average,
            "mark_min": self.mark_min,
            "mark_max": self.mark_max,
        }

    def validate(self) -> None:
        if self.school_id.strip() == "":
            raise ValueError("Assessment school_id is required")
        if self.user_id.strip() == "":
            raise ValueError("Assessment user_id is required")
        if self.subject.strip() == "":
            raise ValueError("Assessment subject is required")

    @classmethod
    def from_hash(cls, hash_id: str) -> Assessment:
        store = ObjectStore.get_instance()
        prefix = store.resolve_id_pointer(hash_id)
        parsed = ObjectStore.parse_node_prefix(prefix)
        if parsed.get("kind") != "assessment":
            raise RuntimeError(f"Invalid assessment path for hash {hash_id}")
        school_id = str(parsed["school_id"])
        user_id = str(parsed["teacher_id"])
        assessment_id = str(parsed["assessment_id"])
        attr_key = ObjectStore.assessment_attr_key(school_id, user_id, assessment_id)
        if not store.exists(attr_key):
            raise RuntimeError(f"Assessment with hash {hash_id} does not exist")

        data, _etag = store.get_json_with_etag(attr_key)
        assessment = cls.from_array(data)
        assessment.id = assessment_id
        assessment.school_id = school_id
        assessment.user_id = user_id
        return assessment

    def save(self) -> None:
        if self.id is None or self.id == "":
            self.id = HashId.create()
        if self.created_at == "":
            self.created_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        self.validate()

        store = ObjectStore.get_instance()
        attr_key = ObjectStore.assessment_attr_key(
            self.school_id, self.user_id, self.id
        )
        store.put_json(attr_key, self.attribute_payload())
        store.set_id_pointer(
            self.id,
            ObjectStore.assessment_prefix(self.school_id, self.user_id, self.id),
        )
        self._invalidate_metadata_cache()

    def delete(self) -> None:
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot delete assessment without id, school_id and user_id"
            )
        store = ObjectStore.get_instance()
        for file in self.list_file_models():
            if file.id is not None:
                store.delete_id_pointer(file.id)
        for student in self.list_student_models():
            if student.id is not None:
                store.delete_id_pointer(student.id)
        store.delete_prefix(
            ObjectStore.assessment_prefix(self.school_id, self.user_id, self.id)
        )
        store.delete_id_pointer(self.id)
        self._invalidate_metadata_cache()

    def get_file(self, file_id: str) -> InputFile | S3File:
        store = ObjectStore.get_instance()
        pointer = store.resolve_id_pointer(file_id)
        parsed = ObjectStore.parse_node_prefix(pointer)
        file: InputFile | S3File
        if (parsed.get("kind") or "") == "blob":
            file = S3File.from_hash(file_id)
        else:
            file = InputFile.from_hash(file_id)
        if (
            file.school_id != self.school_id
            or file.user_id != self.user_id
            or file.assessment_id != self.id
        ):
            raise LookupError(
                f"File '{file_id}' does not belong to assessment {self.id}"
            )
        return file

    def get_student(self, student_id: str) -> Student:
        student = Student.from_hash(student_id)
        if (
            student.school_id != self.school_id
            or student.user_id != self.user_id
            or student.assessment_id != self.id
        ):
            raise LookupError(
                f"Student '{student_id}' does not belong to assessment {self.id}"
            )
        return student

    def list_file_models(self) -> list[InputFile | S3File]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []

        files: list[InputFile | S3File] = []
        seen: set[str] = set()
        for file_id in self._stored_file_ids():
            if file_id in seen:
                continue
            try:
                files.append(InputFile.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue
        for file_id in self._blob_file_ids():
            if file_id in seen:
                continue
            try:
                files.append(S3File.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue

        store = ObjectStore.get_instance()
        students = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id
        )
        for student_id in store.list_child_prefixes(students):
            student_prefix = students + student_id + "/"
            for name in store.list_immediate_files(student_prefix):
                if name == ObjectStore.ATTR_FILE:
                    continue
                try:
                    direct = S3File.from_key(student_prefix + name)
                    if direct.id is not None and direct.id not in seen:
                        files.append(direct)
                        seen.add(direct.id)
                except Exception:
                    continue
        return files

    def list_student_models(self) -> list[Student]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        students: list[Student] = []
        prefix = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id
        )
        for student_id in store.list_child_prefixes(prefix):
            attr_key = ObjectStore.assessment_student_attr_key(
                self.school_id, self.user_id, self.id, student_id
            )
            if not store.exists(attr_key):
                continue
            try:
                students.append(Student.from_hash(student_id))
            except Exception:
                continue
        return students

    def list_student_file_models(self, student_id: str) -> list[InputFile | S3File]:
        student_id = student_id.strip()
        if (
            student_id == ""
            or not self.id
            or self.school_id == ""
            or self.user_id == ""
        ):
            return []

        store = ObjectStore.get_instance()
        student_prefix = ObjectStore.assessment_student_prefix(
            self.school_id, self.user_id, self.id, student_id
        )
        files: list[InputFile | S3File] = []
        seen: set[str] = set()

        for file_id in store.list_child_prefixes(student_prefix):
            if file_id in seen:
                continue
            try:
                files.append(InputFile.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue

        for name in store.list_immediate_files(student_prefix):
            if name == ObjectStore.ATTR_FILE:
                continue
            try:
                direct = S3File.from_key(student_prefix + name)
                if direct.id is not None and direct.id not in seen:
                    files.append(direct)
                    seen.add(direct.id)
            except Exception:
                continue
        return files

    def list_events(self) -> list[dict[str, Any]]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        prefix = ObjectStore.assessment_events_prefix(
            self.school_id, self.user_id, self.id
        )
        events: list[dict[str, Any]] = []
        for name in store.list_immediate_files(prefix):
            if not name.endswith(".json"):
                continue
            event_id = name[:-5]
            timestamp = 0
            label = event_id
            try:
                data = store.get_json(prefix + name)
                timestamp = int(data.get("timestamp") or 0)
                event_name = data.get("name") or ""
                if isinstance(event_name, str) and event_name != "":
                    label = event_name
            except Exception:
                pass
            events.append({"id": event_id, "timestamp": timestamp, "name": label})
        events.sort(key=lambda e: (e["timestamp"], e["name"]))
        return events

    def append_event(self, name: str, timestamp: int | None = None) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot append event without assessment id")
        ts = int(time.time()) if timestamp is None else timestamp
        event_id = f"{ts}-{secrets.token_hex(4)}"
        key = ObjectStore.assessment_event_key(
            self.school_id, self.user_id, self.id, event_id
        )
        S3File.at(key).put_json({"timestamp": ts, "name": name})

    def get_status(self) -> str:
        return self.status

    def compile_inputs(
        self,
        files: Sequence[InputFile],
        output_key: str,
        *,
        re_compile: bool = False,
        legacy_keys: Sequence[str] | None = None,
        ocr_runner: OcrRunner | None = None,
    ) -> dict[str, Any]:
        """Compile OCR text from ``files`` into ``output_key`` as ``{"pages": [...]}``."""
        from pycorrai.model.compile_inputs import compile_inputs as run_compile_inputs

        return run_compile_inputs(
            files,
            output_key,
            re_compile=re_compile,
            legacy_keys=legacy_keys,
            ocr_runner=ocr_runner,
        )

    def compile_subject(
        self,
        param: str = "",
        *,
        re_compile_subject: bool = False,
        ocr_runner: OcrRunner | None = None,
    ) -> dict[str, Any]:
        """Compile subject InputFiles into ``subject/compiled_subject.json``."""
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot compile subject without id, school_id and user_id"
            )

        json_key = ObjectStore.assessment_subject_compile_key(
            self.school_id, self.user_id, self.id
        )
        legacy_keys = [
            ObjectStore.assessment_subject_compile_legacy_json_key(
                self.school_id, self.user_id, self.id
            ),
            ObjectStore.assessment_subject_compile_legacy_key(
                self.school_id, self.user_id, self.id
            ),
        ]
        return self.compile_inputs(
            self._subject_input_files(),
            json_key,
            re_compile=re_compile_subject or param == "re_compile_subject",
            legacy_keys=legacy_keys,
            ocr_runner=ocr_runner,
        )

    def _subject_input_files(self) -> list[InputFile]:
        """InputFile entries stored under the assessment ``subject/`` prefix."""
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        subject_prefix = ObjectStore.assessment_subject_files_prefix(
            self.school_id, self.user_id, self.id
        )
        files: list[InputFile] = []
        for file in self.list_file_models():
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if file.id is None or file.id == "":
                continue
            file_prefix = ObjectStore.assessment_file_prefix(
                self.school_id,
                self.user_id,
                self.id,
                file.id,
                file.role(),
                file.student_id(),
            )
            if file_prefix.startswith(subject_prefix):
                files.append(file)
        files.sort(key=lambda file: file.name)
        return files

    def unclassified_file_ids(self) -> list[str]:
        """File ids under ``unclassified/`` that have ``attributes.json``."""
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        prefix = ObjectStore.assessment_unclassified_files_prefix(
            self.school_id, self.user_id, self.id
        )
        ids: list[str] = []
        for file_id in store.list_child_prefixes(prefix):
            attr_key = prefix + file_id + "/" + ObjectStore.ATTR_FILE
            if store.exists(attr_key):
                ids.append(file_id)
        return ids

    def _unclassified_input_files(self) -> list[InputFile]:
        """InputFile entries stored under the assessment ``unclassified/`` prefix."""
        files: list[InputFile] = []
        for file_id in self.unclassified_file_ids():
            try:
                file = self.get_file(file_id)
            except Exception:
                logger.exception(
                    "Failed to load unclassified file %s for assessment %s",
                    file_id,
                    self.id,
                )
                continue
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if file.id is None or file.id == "":
                continue
            files.append(file)
        files.sort(key=lambda file: file.name)
        return files

    def google_vision_ocr_unclassified(
        self,
        force_re_ocr: bool = False,
        *,
        ocr_runner: OcrRunner | None = None,
    ) -> None:
        """Run Google Vision OCR on every unclassified copy of this assessment.

        When ``force_re_ocr`` is false, files that already have ``ocr_result.json``
        are skipped (handled by ``google_vision_ocr``). Intended to be launched
        via a Redis Python-queue ticket.
        """
        from pycorrai.model.services.unclassified_ocr import (
            google_vision_ocr_unclassified as run,
        )

        return run(self, force_re_ocr, ocr_runner=ocr_runner)

    def enqueue_google_vision_ocr_unclassified(
        self, redis: Any | None = None
    ) -> None:
        """Push a ``google_vision_ocr_unclassified`` ticket on the Python Redis queue."""
        from pycorrai.model.services.unclassified_ocr import (
            enqueue_google_vision_ocr_unclassified as enqueue,
        )

        return enqueue(self, redis=redis)

    def create_correction_grid(
        self,
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishAssessment | None = None,
    ) -> dict[str, Any]:
        """Build ``correction_grid.json`` from instructions.md and compiled pages.

        Publishes Nchan ``Grid creation`` / ``Grid created`` with a loading flag,
        calls Claude Sonnet (same schema as PHP AssTaskGridCreation), stores the
        JSON under the assessment subject prefix, and appends a timed cost event.
        """
        from pycorrai.model.services.correction_grid import create_correction_grid

        return create_correction_grid(
            self, client_factory=client_factory, publish=publish
        )

    def enqueue_create_correction_grid(self, redis: Any | None = None) -> None:
        """Push a ``create_correction_grid`` ticket on the Python Redis queue."""
        from pycorrai.model.services.correction_grid import (
            enqueue_create_correction_grid,
        )

        return enqueue_create_correction_grid(self, redis=redis)

    def create_strict_correction_grid(
        self,
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishAssessment | None = None,
    ) -> dict[str, Any]:
        """Build ``strict_correction_grid.json`` as a binary reading grid.

        Same pipeline as ``create_correction_grid``, but the LLM returns only
        self-contained yes/no criteria with short unique ids.
        """
        from pycorrai.model.services.strict_correction_grid import (
            create_strict_correction_grid,
        )

        return create_strict_correction_grid(
            self, client_factory=client_factory, publish=publish
        )

    def enqueue_create_strict_correction_grid(
        self, redis: Any | None = None
    ) -> None:
        """Push a ``create_strict_correction_grid`` ticket on the Python Redis queue."""
        from pycorrai.model.services.strict_correction_grid import (
            enqueue_create_strict_correction_grid,
        )

        return enqueue_create_strict_correction_grid(self, redis=redis)

    def create_math_correction_grid(
        self,
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishAssessment | None = None,
    ) -> dict[str, Any]:
        """Build ``math_correction_grid.json`` for mathematics subjects.

        Same pipeline as ``create_correction_grid``, but the LLM returns a
        grid structured as parties → questions with points, barème,
        détails à vérifier and résultat attendu.
        """
        from pycorrai.model.services.math_correction_grid import (
            create_math_correction_grid,
        )

        return create_math_correction_grid(
            self, client_factory=client_factory, publish=publish
        )

    def enqueue_create_math_correction_grid(
        self, redis: Any | None = None
    ) -> None:
        """Push a ``create_math_correction_grid`` ticket on the Python Redis queue."""
        from pycorrai.model.services.math_correction_grid import (
            enqueue_create_math_correction_grid,
        )

        return enqueue_create_math_correction_grid(self, redis=redis)

    def correct_students(
        self,
        students: Sequence[Student],
        client_llm: Any,
        *,
        publish: PublishStudent | None = None,
    ) -> dict[str, float | None]:
        """Correct several students asynchronously with an LLM client.

        ``client_llm`` must be an LLM client instance (or a zero-arg factory
        returning one). A fresh client is created for each student. Requires an
        existing strict reading grid (and compiled subject), then corrects each
        student in parallel. Waits until every student finishes or fails.

        The LLM receives the reading grid without criterion ``point`` values and
        returns binary retained/not-retained decisions. Points are summed in
        Python from the full grid before saving.

        Returns a mapping ``student_id -> mark`` (``None`` when correction failed).
        """
        from pycorrai.model.services.correct_students import correct_students

        return correct_students(self, students, client_llm, publish=publish)

    def math_correct_students(
        self,
        students: Sequence[Student],
        client_llm: Any,
        model_name: str,
        *,
        publish: PublishStudent | None = None,
    ) -> dict[str, float | None]:
        """Correct several students asynchronously with an LLM (math grid + images).

        ``client_llm`` must be an LLM client instance (or a zero-arg factory
        returning one). Requires an existing ``math_correction_grid.json``.
        Each student is processed in parallel: Nchan ``En correction``, grid
        without question ``points`` as system content and submission images as
        user content, then ``pourcentage_retenu × points`` (rounded to 1/10)
        saved as ``correction_<model>.json``, then Nchan ``Corrigé``.

        Returns a mapping ``student_id -> note`` (``None`` when correction failed).
        """
        from pycorrai.model.services.math_correct_students import (
            math_correct_students,
        )

        return math_correct_students(
            self, students, client_llm, model_name, publish=publish
        )

    @staticmethod
    def _llm_client_factory(client_llm: Any) -> ClientFactory:
        """Return a zero-arg factory that yields a fresh LLM client each call."""
        from pycorrai.model.services.correct_students import _llm_client_factory

        return _llm_client_factory(client_llm)

    async def _correct_students_async(
        self,
        students: list[Student],
        make_client: ClientFactory,
        publish: PublishStudent,
        instructions: str,
        correction_grid: dict[str, Any],
        compiled_subject: str,
        language_name: str,
    ) -> dict[str, float | None]:
        from pycorrai.model.services.correct_students import _correct_students_async

        return await _correct_students_async(
            self,
            students,
            make_client,
            publish,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )

    async def _correct_one_student(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        instructions: str,
        correction_grid: dict[str, Any],
        compiled_subject: str,
        language_name: str,
    ) -> tuple[str, float | None]:
        from pycorrai.model.services.correct_students import _correct_one_student

        return await _correct_one_student(
            self,
            student,
            make_client,
            publish,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )

    def _correct_one_student_sync(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        instructions: str,
        correction_grid: dict[str, Any],
        compiled_subject: str,
        language_name: str,
    ) -> tuple[str, float | None]:
        from pycorrai.model.services.correct_students import _correct_one_student_sync

        return _correct_one_student_sync(
            self,
            student,
            make_client,
            publish,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )

    def jev_correct_students(
        self,
        students: Sequence[Student],
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishStudent | None = None,
    ) -> dict[str, float | None]:
        """Correct several students asynchronously with the Jev Decisions API.

        Requires an existing ``correction_grid.json``. Each student is processed
        in parallel: Nchan ``En correction``, compile submission if needed, ask
        Jev one yes/no (noul) question per criterion, persist a correction JSON
        similar to openrouter/mistral, then Nchan ``Corrigé``.

        Returns a mapping ``student_id -> note`` (``None`` when correction failed).
        """
        from pycorrai.model.services.jev_correction import jev_correct_students

        return jev_correct_students(
            self, students, client_factory=client_factory, publish=publish
        )

    async def _jev_correct_students_async(
        self,
        students: list[Student],
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> dict[str, float | None]:
        from pycorrai.model.services.jev_correction import _jev_correct_students_async

        return await _jev_correct_students_async(
            self, students, make_client, publish, correction_grid
        )

    async def _jev_correct_one_student(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> tuple[str, float | None]:
        from pycorrai.model.services.jev_correction import _jev_correct_one_student

        return await _jev_correct_one_student(
            self, student, make_client, publish, correction_grid
        )

    def _jev_correct_one_student_sync(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> tuple[str, float | None]:
        from pycorrai.model.services.jev_correction import _jev_correct_one_student_sync

        return _jev_correct_one_student_sync(
            self, student, make_client, publish, correction_grid
        )

    @staticmethod
    def _compiled_subject_pages(compiled_subject: str) -> list[str]:
        """Extract page texts from compiled_subject JSON or fall back to raw text."""
        try:
            decoded = json.loads(compiled_subject)
        except json.JSONDecodeError:
            text = compiled_subject.strip()
            return [text] if text != "" else [""]
        if isinstance(decoded, dict):
            pages = decoded.get("pages")
            if isinstance(pages, list):
                return [str(page) if page is not None else "" for page in pages]
        if isinstance(decoded, list):
            return [str(page) if page is not None else "" for page in decoded]
        text = compiled_subject.strip()
        return [text] if text != "" else [""]

    def _instructions_md_text(self) -> str:
        """Return the S3 contents of ``instructions.md`` when present."""
        store = ObjectStore.get_instance()
        preferred: InputFile | None = None
        fallback: InputFile | None = None
        for file in self.list_file_models():
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if not isinstance(file, InstructionFile) and file.role() != "instructions":
                continue
            if file.name.lower() == "instructions.md":
                preferred = file
                break
            if fallback is None:
                fallback = file
        target = preferred or fallback
        if target is None:
            return ""
        try:
            return store.get_contents(target.content_key())
        except Exception:
            logger.exception(
                "Unreadable instructions file %s for assessment %s",
                target.id,
                self.id,
            )
            return ""

    def _blob_file_ids(self) -> list[str]:

        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        prefix = ObjectStore.assessment_blobs_prefix(
            self.school_id, self.user_id, self.id
        )
        return ObjectStore.get_instance().list_immediate_files(prefix)

    def _stored_file_ids(self) -> list[str]:
        store = ObjectStore.get_instance()
        ids: dict[str, bool] = {}
        areas = [
            ObjectStore.assessment_subject_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
            ObjectStore.assessment_unclassified_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
            ObjectStore.assessment_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
        ]
        for prefix in areas:
            for file_id in store.list_child_prefixes(prefix):
                ids[file_id] = True

        students = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id or ""
        )
        for student_id in store.list_child_prefixes(students):
            student_prefix = students + student_id + "/"
            for file_id in store.list_child_prefixes(student_prefix):
                ids[file_id] = True
        return list(ids.keys())

    def _invalidate_metadata_cache(self) -> None:
        if (
            self.id is None
            or self.id == ""
            or self.school_id == ""
            or self.user_id == ""
        ):
            return
        try:
            invalidate_assessment(
                make_redis_client(), self.school_id, self.user_id, self.id
            )
        except Exception:
            logger.exception("MetadataCache invalidate (Assessment) failed")
