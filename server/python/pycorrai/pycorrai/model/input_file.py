"""Assessment file stored as a directory (attributes.json + content + events)."""

from __future__ import annotations

import logging
import re
import secrets
import time
from abc import ABC, abstractmethod
from typing import Any, Type

from pycorrai.store.exceptions import StoreConflictException
from pycorrai.store.metadata_cache import invalidate_assessment
from pycorrai.store.object_store import ObjectStore, make_redis_client
from pycorrai.model.s3_file import S3File

logger = logging.getLogger(__name__)


class InputFile(ABC):
    def __init__(self) -> None:
        self.id: str | None = None
        self.school_id: str = ""
        self.user_id: str = ""
        self.assessment_id: str = ""
        self.name: str = ""
        self.size: int = 0
        self.created: int = 0
        self.student: str | None = None
        self.status: str = ""
        self.content_type: str = "application/octet-stream"
        self.thumbnail: bool = False
        self.loading: bool | None = None
        self.etag: str | None = None
        self.has_stored_class: bool = False

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> InputFile:
        from pycorrai.model.submission_file import SubmissionFile

        file = cls()
        file.id = data.get("id")
        file.school_id = str(data.get("school_id") or "")
        file.user_id = str(data.get("user_id") or "")
        file.assessment_id = str(data.get("assessment_id") or "")
        file.name = str(data.get("name") or "")
        file.size = int(data.get("size") or 0)
        file.created = int(data.get("created") or 0)
        student = data.get("student")
        if isinstance(student, str) and student.strip() != "":
            file.student = student.strip()
        else:
            file.student = None
        if isinstance(file, SubmissionFile):
            identifier = data.get("student_identifier")
            file.student_identifier = (
                identifier if isinstance(identifier, str) else None
            )
            qualigraphy = data.get("qualigraphy_score")
            if isinstance(qualigraphy, (int, float)):
                file.qualigraphy_score = qualigraphy
            elif isinstance(qualigraphy, str) and qualigraphy.strip() != "":
                try:
                    file.qualigraphy_score = float(qualigraphy)
                except ValueError:
                    file.qualigraphy_score = None
            else:
                file.qualigraphy_score = None
        status = str(data.get("status") or "")
        file.status = "stored" if status == "loaded" else status
        file.content_type = str(data.get("content_type") or "application/octet-stream")
        thumb = data.get("thumbnail", False)
        if isinstance(thumb, bool):
            file.thumbnail = thumb
        elif isinstance(thumb, str):
            file.thumbnail = thumb.lower() in ("1", "true", "yes", "on")
        else:
            file.thumbnail = bool(thumb)
        file.has_stored_class = False
        return file

    def attribute_payload(self) -> dict[str, Any]:
        from pycorrai.model.submission_file import SubmissionFile

        payload: dict[str, Any] = {
            "name": self.name,
            "student": self.student,
            "status": self.status,
            "content_type": self.content_type,
            "size": self.size,
            "created": self.created,
            "thumbnail": self.thumbnail,
            "type": self.role(),
        }
        if isinstance(self, SubmissionFile):
            payload["student_identifier"] = self.student_identifier
            payload["qualigraphy_score"] = self.qualigraphy_score
        return payload

    def validate(self) -> None:
        if (
            self.school_id.strip() == ""
            or self.user_id.strip() == ""
            or self.assessment_id.strip() == ""
        ):
            raise ValueError("File school_id, user_id and assessment_id are required")
        if self.name.strip() == "" or re.search(r"[/\\]", self.name):
            raise ValueError("Invalid file name")

    def role(self) -> str:
        from pycorrai.model.instruction_file import InstructionFile
        from pycorrai.model.subject_file import SubjectFile

        if isinstance(self, SubjectFile):
            return "subject"
        if isinstance(self, InstructionFile):
            return "instructions"
        return "submission"

    @classmethod
    def from_hash(cls, hash_id: str) -> InputFile:
        store = ObjectStore.get_instance()
        prefix = store.resolve_id_pointer(hash_id)
        parsed = ObjectStore.parse_node_prefix(prefix)
        if parsed.get("kind") != "file":
            raise RuntimeError(f"Hash {hash_id} does not point to a file")

        attr_key = prefix.rstrip("/") + "/" + ObjectStore.ATTR_FILE
        if not store.exists(attr_key):
            raise RuntimeError(f"File with hash {hash_id} does not exist")

        data, etag = store.get_json_with_etag(attr_key)
        return cls._hydrate(data, parsed, etag)

    @classmethod
    def from_path(cls, path: str) -> InputFile:
        prefix = cls._prefix_from_path(path)
        parsed = ObjectStore.parse_node_prefix(prefix)
        if (parsed.get("kind") or "") != "file":
            raise RuntimeError(f"Path {path} does not point to a file")

        attr_key = prefix.rstrip("/") + "/" + ObjectStore.ATTR_FILE
        store = ObjectStore.get_instance()
        if not store.exists(attr_key):
            raise RuntimeError(f"File attributes do not exist for path {path}")

        data, etag = store.get_json_with_etag(attr_key)
        return cls._hydrate(data, parsed, etag)

    @staticmethod
    def _prefix_from_path(path: str) -> str:
        trimmed = path.strip().rstrip("/")
        for suffix in ("/" + ObjectStore.ATTR_FILE, "/" + ObjectStore.CONTENT_FILE):
            if trimmed.endswith(suffix):
                trimmed = trimmed[: -len(suffix)]
                break
        return trimmed.rstrip("/") + "/"

    @classmethod
    def from_cached_attributes(
        cls, data: dict[str, Any], parsed: dict[str, Any], etag: str | None
    ) -> InputFile:
        return cls._hydrate(data, parsed, etag)

    @classmethod
    def _hydrate(
        cls, data: dict[str, Any], parsed: dict[str, Any], etag: str | None
    ) -> InputFile:
        file_cls = cls._resolve_file_class(data, parsed)
        file = file_cls.from_array(data)
        file.id = str(parsed.get("file_id") or "")
        file.school_id = str(parsed.get("school_id") or "")
        file.user_id = str(parsed.get("teacher_id") or "")
        file.assessment_id = str(parsed.get("assessment_id") or "")
        file.etag = etag
        return file

    @staticmethod
    def _resolve_file_class(
        data: dict[str, Any], parsed: dict[str, Any]
    ) -> Type[InputFile]:
        """Resolve concrete type by role/type/path — ignore PHP class FQCNs."""
        from pycorrai.model.instruction_file import InstructionFile
        from pycorrai.model.subject_file import SubjectFile
        from pycorrai.model.submission_file import SubmissionFile

        type_ = str(data.get("type") or data.get("role") or "").strip().lower()
        area = str(parsed.get("area") or "").strip().lower()

        if type_ in ("subject",):
            return SubjectFile
        if type_ in ("instructions", "instruction"):
            return InstructionFile
        if type_ in ("submission", "copy"):
            return SubmissionFile

        # Path area under subject/ without an explicit type → subject material.
        if area == "subject":
            return SubjectFile
        if area in ("student", "unclassified"):
            return SubmissionFile

        return SubmissionFile

    def student_id(self) -> str | None:
        from pycorrai.model.submission_file import SubmissionFile

        return self.student if isinstance(self, SubmissionFile) else None

    def can_reassign(self) -> bool:
        return False

    def attr_key(self) -> str:
        return ObjectStore.assessment_file_attr_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def content_key(self) -> str:
        return ObjectStore.assessment_file_content_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def ocr_result_key(self) -> str:
        return ObjectStore.assessment_file_ocr_result_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def found_errors_key(self) -> str:
        return ObjectStore.assessment_file_found_errors_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def markup_annotations_key(self) -> str:
        return ObjectStore.assessment_file_markup_annotations_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def thumbnail_key(self) -> str:
        return ObjectStore.assessment_file_thumbnail_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def prefix(self) -> str:
        return ObjectStore.assessment_file_prefix(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
        )

    def content_file(self) -> S3File:
        return S3File.at(self.content_key())

    def attributes_file(self) -> S3File:
        return S3File.at(self.attr_key())

    def save_attributes(self, retry: bool = True) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot save file attributes without id")
        self.validate()

        payload = self.attribute_payload()
        attempts = 5 if retry else 1
        store = ObjectStore.get_instance()
        desired = self.prefix()
        previous: str | None = None
        if store.exists(ObjectStore.id_index_key(self.id)):
            current = store.resolve_id_pointer(self.id)
            # Normalize for comparison (trailing slash).
            current_norm = current.rstrip("/") + "/"
            if current_norm != desired:
                store.copy_prefix(current, desired)
                self.etag = None
                previous = current

        for i in range(attempts):
            try:
                self.etag = store.put_json(self.attr_key(), payload, self.etag)
                store.set_id_pointer(self.id, desired)
                if previous is not None:
                    store.delete_prefix(previous)
                    previous = None
                self._invalidate_metadata_cache()
                return
            except StoreConflictException:
                if i == attempts - 1:
                    raise
                _data, self.etag = store.get_json_with_etag(self.attr_key())

    def list_annexes(self) -> list[str]:
        names = ObjectStore.get_instance().list_immediate_files(self.prefix())
        excluded = {ObjectStore.ATTR_FILE, ObjectStore.CONTENT_FILE}
        annexes = [name for name in names if name not in excluded]
        annexes.sort()
        return annexes

    def list_directory_objects(self) -> list[str]:
        names: list[str] = []
        for relative in ObjectStore.get_instance().list_immediate_files(self.prefix()):
            if relative == "" or relative == ObjectStore.CONTENT_FILE:
                continue
            names.append(relative)
        names.sort()
        return names

    def list_events(self) -> list[dict[str, Any]]:
        store = ObjectStore.get_instance()
        prefix = ObjectStore.assessment_file_events_prefix(
            self.school_id,
            self.user_id,
            self.assessment_id,
            str(self.id or ""),
            self.role(),
            self.student_id(),
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
            raise RuntimeError("Cannot append event without file id")
        ts = int(time.time()) if timestamp is None else timestamp
        event_id = f"{ts}-{secrets.token_hex(4)}"
        key = ObjectStore.assessment_file_event_key(
            self.school_id,
            self.user_id,
            self.assessment_id,
            self.id,
            event_id,
            self.role(),
            self.student_id(),
        )
        S3File.at(key).put_json({"timestamp": ts, "name": name})

    def delete(self) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot delete file without id")
        store = ObjectStore.get_instance()
        store.delete_prefix(self.prefix())
        store.delete_id_pointer(self.id)
        self._invalidate_metadata_cache()

    def get_status(self) -> str:
        return self.status

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
            logger.exception("MetadataCache invalidate (InputFile) failed")

    @abstractmethod
    def on_stored(self) -> None:
        raise NotImplementedError
