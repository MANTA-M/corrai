"""One S3 object (bare assessment blob or sibling of an InputFile)."""

from __future__ import annotations

import json
import logging
from typing import Any
from urllib.parse import quote, unquote

from pycorrai.store.hash_id import HashId
from pycorrai.store.metadata_cache import invalidate_assessment
from pycorrai.store.object_store import ObjectStore, SCHEMA, make_redis_client

logger = logging.getLogger(__name__)


class S3File:
    def __init__(self) -> None:
        self.key: str = ""
        self.id: str | None = None
        self.school_id: str = ""
        self.user_id: str = ""
        self.assessment_id: str = ""
        self.name: str = ""
        self.type: str = ""
        self.student: str | None = None
        self.status: str = ""
        self.content_type: str = "application/octet-stream"
        self.size: int = 0
        self.created: int = 0
        self.etag: str | None = None

    @staticmethod
    def stores_as_blob(type_: str) -> bool:
        return type_ not in ("submission", "subject")

    @classmethod
    def at(cls, key: str) -> S3File:
        file = cls()
        file.key = key
        return file

    def get_contents(self) -> str:
        return ObjectStore.get_instance().get_contents(self.key)

    def get_bytes(self) -> bytes:
        return ObjectStore.get_instance().get_bytes(self.key)

    def put_contents(
        self, body: bytes | str, content_type: str | None = None
    ) -> str | None:
        if content_type is not None and content_type != "":
            self.content_type = content_type
        if isinstance(body, str):
            self.size = len(body.encode("utf-8"))
        else:
            self.size = len(body)
        metadata = self.user_metadata() if self.id else None
        self.etag = ObjectStore.get_instance().put_contents(
            self.key,
            body,
            self.content_type,
            None,
            metadata,
        )
        self._invalidate_metadata_cache()
        return self.etag

    def put_json(self, data: dict[str, Any]) -> str | None:
        payload = dict(data)
        if "schema" not in payload:
            payload["schema"] = SCHEMA
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        return self.put_contents(body, "application/json")

    def content_key(self) -> str:
        return self.key

    def prefix(self) -> str:
        return self.key + "/"

    def user_metadata(self) -> dict[str, str]:
        meta = {
            "name": quote(self.name, safe=""),
            "type": self.type,
            "created": str(self.created),
        }
        if self.student is not None and self.student != "":
            meta["student"] = self.student
        if self.id is not None and self.id != "":
            meta["id"] = self.id
        return meta

    @classmethod
    def from_hash(cls, hash_id: str) -> S3File:
        store = ObjectStore.get_instance()
        pointer = store.resolve_id_pointer(hash_id)
        return cls.from_key(pointer, hash_id)

    @classmethod
    def from_key(cls, key: str, hash_id: str | None = None) -> S3File:
        store = ObjectStore.get_instance()
        clean_key = key.rstrip("/")
        parsed = ObjectStore.parse_node_prefix(clean_key)
        if (parsed.get("kind") or "") != "blob":
            raise RuntimeError(f"Key or pointer {clean_key} does not point to a blob")

        head = store.head(clean_key)
        meta = cls.normalize_metadata(head.get("Metadata") or {})

        file = cls()
        file.key = clean_key
        file_id = hash_id or meta.get("id")
        parsed_file_id = parsed.get("file_id")
        if file_id is None or file_id == "":
            if (
                isinstance(parsed_file_id, str)
                and parsed_file_id != "correction.png"
                and "." not in parsed_file_id
            ):
                file_id = parsed_file_id
            else:
                file_id = HashId.create()
            store.set_id_pointer(file_id, clean_key, False)
        file.id = file_id
        file.school_id = str(parsed.get("school_id") or "")
        file.user_id = str(parsed.get("teacher_id") or "")
        file.assessment_id = str(parsed.get("assessment_id") or "")
        file.etag = head.get("ETag")
        file.size = int(head.get("ContentLength") or 0)
        file.content_type = str(head.get("ContentType") or "application/octet-stream")
        file.name = unquote(str(meta.get("name") or clean_key.rsplit("/", 1)[-1]))
        if "type" in meta:
            file.type = str(meta["type"])
        elif clean_key.endswith("/correction.png") or clean_key.endswith(
            "correction.png"
        ):
            file.type = "correction"
        else:
            file.type = ""
        student = meta.get("student") or parsed.get("student_id")
        file.student = student if isinstance(student, str) and student != "" else None
        file.created = int(meta.get("created") or 0)
        file.status = ""
        return file

    def save_attributes(self, retry: bool = True) -> None:
        del retry  # Blobs rewrite metadata unconditionally.
        if self.id is None or self.id == "" or self.key == "":
            raise RuntimeError("Cannot save blob attributes without id")
        store = ObjectStore.get_instance()
        store.replace_metadata(self.key, self.user_metadata(), self.content_type)
        store.set_id_pointer(self.id, self.key, False)
        self._invalidate_metadata_cache()

    def list_annexes(self) -> list[str]:
        return []

    def list_directory_objects(self) -> list[str]:
        return []

    def list_events(self) -> list[dict[str, Any]]:
        return []

    def delete(self) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot delete blob without id")
        store = ObjectStore.get_instance()
        if self.key != "" and store.exists(self.key):
            store.delete(self.key)
        store.delete_id_pointer(self.id)
        self._invalidate_metadata_cache()

    def is_direct_student_file(self) -> bool:
        if self.key == "":
            return False
        try:
            parsed = ObjectStore.parse_node_prefix(self.key)
        except ValueError:
            return False
        return (parsed.get("kind") or "") == "blob" and (
            parsed.get("area") or ""
        ) == "student"

    def can_reassign(self) -> bool:
        if self.type in ("subject", "solution", "instructions"):
            return False
        if self.type in ("submission", ""):
            return True
        return (self.student or "").strip() == ""

    def to_cache_array(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "name": self.name,
            "key": self.key,
            "type": self.type,
            "student": self.student,
            "status": self.status,
            "content_type": self.content_type,
            "size": self.size,
            "created": self.created,
            "school_id": self.school_id,
            "user_id": self.user_id,
            "assessment_id": self.assessment_id,
        }

    @classmethod
    def from_cached_array(cls, data: dict[str, Any]) -> S3File:
        file = cls()
        raw_id = data.get("id")
        file.id = raw_id if isinstance(raw_id, str) and raw_id != "" else None
        file.name = str(data.get("name") or "")
        file.key = str(data.get("key") or "")
        file.type = str(data.get("type") or "")
        student = data.get("student")
        file.student = student if isinstance(student, str) and student != "" else None
        file.status = str(data.get("status") or "")
        file.content_type = str(data.get("content_type") or "application/octet-stream")
        file.size = int(data.get("size") or 0)
        file.created = int(data.get("created") or 0)
        file.school_id = str(data.get("school_id") or "")
        file.user_id = str(data.get("user_id") or "")
        file.assessment_id = str(data.get("assessment_id") or "")
        return file

    @staticmethod
    def normalize_metadata(metadata: dict[str, Any]) -> dict[str, str]:
        normalized: dict[str, str] = {}
        for key, value in metadata.items():
            if not isinstance(key, str):
                continue
            normalized[key.lower()] = value if isinstance(value, str) else str(value)
        return normalized

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
            logger.exception("MetadataCache invalidate (S3File) failed")
