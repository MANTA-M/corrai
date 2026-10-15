"""Thin S3 wrapper pointed at SeaweedFS (or any S3-compatible endpoint)."""

from __future__ import annotations

import json
import logging
import time
from datetime import datetime
from typing import Any, Callable

import boto3
from botocore.client import BaseClient, Config
from botocore.exceptions import ClientError, ParamValidationError
from redis import Redis

from pycorrai.config import env
from pycorrai.store.exceptions import StoreConflictException

logger = logging.getLogger(__name__)

ATTR_FILE = "attributes.json"
CONTENT_FILE = "content"
OCR_RESULT_FILE = "ocr_result.json"
FOUND_ERRORS_FILE = "found_errors.json"
MARKUP_ANNOTATIONS_FILE = "markup_annotations.php"
THUMBNAIL_FILE = "thumbnail"
SCHEMA = 1

DEFAULT_CONNECT_TIMEOUT = 2.0
DEFAULT_TIMEOUT = 10.0
DEFAULT_RETRIES = 3
DEFAULT_SLOW_THRESHOLD_MS = 500.0

# redis-py defaults can race BRPOP; keep read deadline longer than the block.
BRPOP_TIMEOUT = 5
SOCKET_CONNECT_TIMEOUT = 5
SOCKET_TIMEOUT = BRPOP_TIMEOUT + 10


def make_s3_client() -> BaseClient:
    endpoint = env("S3_ENDPOINT", "http://seaweedfs:8333")
    region = env("S3_REGION", "us-east-1")
    access_key = env("S3_ACCESS_KEY")
    secret_key = env("S3_SECRET_KEY")
    if access_key == "" or secret_key == "":
        raise RuntimeError("S3_ACCESS_KEY and S3_SECRET_KEY must be configured")

    connect_timeout = float(env("S3_CONNECT_TIMEOUT", str(DEFAULT_CONNECT_TIMEOUT)))
    timeout = float(env("S3_TIMEOUT", str(DEFAULT_TIMEOUT)))
    retries = int(env("S3_RETRIES", str(DEFAULT_RETRIES)))

    config_kwargs: dict[str, Any] = {
        "s3": {"addressing_style": "path"},
        "connect_timeout": connect_timeout,
        "read_timeout": timeout,
        "retries": {"max_attempts": retries, "mode": "standard"},
    }

    # Newer botocore defaults to always sending checksums; SeaweedFS rejects them.
    try:
        config = Config(
            request_checksum_calculation="when_required",
            response_checksum_validation="when_required",
            **config_kwargs,
        )
    except TypeError:
        config = Config(**config_kwargs)

    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        region_name=region,
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
        config=config,
    )


def make_redis_client(
    *,
    socket_connect_timeout: float = SOCKET_CONNECT_TIMEOUT,
    socket_timeout: float = SOCKET_TIMEOUT,
) -> Redis:
    host = env("REDIS_HOST", "127.0.0.1")
    port = int(env("REDIS_PORT", "6379"))
    return Redis(
        host=host,
        port=port,
        decode_responses=True,
        socket_connect_timeout=socket_connect_timeout,
        socket_timeout=socket_timeout,
    )


def _normalize_etag(etag: Any) -> str | None:
    if not isinstance(etag, str) or etag == "":
        return None
    return etag.strip('"')


def _last_modified_timestamp(last_modified: Any) -> int | None:
    if isinstance(last_modified, datetime):
        timestamp = int(last_modified.timestamp())
        return timestamp if timestamp > 0 else None
    if isinstance(last_modified, str) and last_modified != "":
        try:
            # boto3 usually returns datetime; keep string fallback.
            parsed = datetime.fromisoformat(last_modified.replace("Z", "+00:00"))
            timestamp = int(parsed.timestamp())
            return timestamp if timestamp > 0 else None
        except ValueError:
            return None
    return None


def _is_precondition_failed(exc: ClientError) -> bool:
    resp = exc.response or {}
    status = resp.get("ResponseMetadata", {}).get("HTTPStatusCode")
    code = str(resp.get("Error", {}).get("Code", ""))
    return status == 412 or code in ("PreconditionFailed", "412")


def _apply_if_match(params: dict[str, Any], if_match: str | None) -> None:
    if if_match is None or if_match == "":
        return
    params["IfMatch"] = '"' + if_match.strip('"') + '"'


def _body_to_bytes(body: Any) -> bytes:
    if isinstance(body, bytes):
        return body
    if isinstance(body, str):
        return body.encode("utf-8")
    read = getattr(body, "read", None)
    if callable(read):
        data = read()
        return data if isinstance(data, bytes) else bytes(data)
    return bytes(body)


class ObjectStore:
    """S3 wrapper matching PHP ``Corrai\\Utils\\Store\\ObjectStore``."""

    ATTR_FILE = ATTR_FILE
    CONTENT_FILE = CONTENT_FILE
    OCR_RESULT_FILE = OCR_RESULT_FILE
    FOUND_ERRORS_FILE = FOUND_ERRORS_FILE
    MARKUP_ANNOTATIONS_FILE = MARKUP_ANNOTATIONS_FILE
    THUMBNAIL_FILE = THUMBNAIL_FILE
    SCHEMA = SCHEMA

    _instance: ObjectStore | None = None
    _log_callback: Callable[[str, str, float], None] | None = None

    def __init__(self, client: Any | None = None, bucket: str | None = None) -> None:
        if client is not None:
            self.client = client
            self.bucket = bucket if bucket is not None else env("S3_BUCKET", "corrai")
            # Injected clients (tests / DI) are assumed ready.
            self._bucket_ready = True
            return

        endpoint = env("S3_ENDPOINT", "http://seaweedfs:8333")
        access_key = env("S3_ACCESS_KEY")
        secret_key = env("S3_SECRET_KEY")
        if endpoint == "" or access_key == "" or secret_key == "":
            raise RuntimeError(
                "S3_ENDPOINT, S3_ACCESS_KEY and S3_SECRET_KEY must be configured"
            )
        self.client = make_s3_client()
        self.bucket = bucket if bucket is not None else env("S3_BUCKET", "corrai")
        self._bucket_ready = False

    @classmethod
    def get_instance(cls) -> ObjectStore:
        if cls._instance is None:
            cls._instance = cls()
        return cls._instance

    @classmethod
    def set_instance(cls, instance: ObjectStore | None) -> None:
        cls._instance = instance

    @classmethod
    def reset_instance(cls) -> None:
        cls._instance = None

    def get_client(self) -> Any:
        return self.client

    @classmethod
    def set_log_callback(
        cls, callback: Callable[[str, str, float], None] | None
    ) -> None:
        cls._log_callback = callback

    @classmethod
    def get_slow_threshold_ms(cls) -> float:
        raw = env("S3_SLOW_THRESHOLD_MS")
        if raw != "":
            try:
                return float(raw)
            except ValueError:
                pass
        return DEFAULT_SLOW_THRESHOLD_MS

    def _log_slow(self, operation: str, key: str, start: float) -> None:
        elapsed_ms = (time.monotonic() - start) * 1000
        threshold_ms = self.get_slow_threshold_ms()
        if elapsed_ms > threshold_ms:
            message = (
                f"S3 slow request ({operation}): key={key or '(none)'} "
                f"duration={elapsed_ms:.1f} ms"
            )
            if self._log_callback is not None:
                self._log_callback(message, key, elapsed_ms)
            logger.warning(message)

    # -------------------------------------------------------------------------
    # Path builders
    # -------------------------------------------------------------------------

    @staticmethod
    def id_index_key(hash_id: str) -> str:
        return "_id/" + hash_id

    @staticmethod
    def schools_root_prefix() -> str:
        return "schools/"

    @staticmethod
    def school_prefix(school_id: str) -> str:
        return f"schools/{school_id}/"

    @staticmethod
    def school_attr_key(school_id: str) -> str:
        return ObjectStore.school_prefix(school_id) + ATTR_FILE

    @staticmethod
    def teachers_prefix(school_id: str) -> str:
        return ObjectStore.school_prefix(school_id) + "teachers/"

    @staticmethod
    def teacher_prefix(school_id: str, teacher_id: str) -> str:
        return ObjectStore.teachers_prefix(school_id) + teacher_id + "/"

    @staticmethod
    def teacher_attr_key(school_id: str, teacher_id: str) -> str:
        return ObjectStore.teacher_prefix(school_id, teacher_id) + ATTR_FILE

    @staticmethod
    def assessments_prefix(school_id: str, teacher_id: str) -> str:
        return ObjectStore.teacher_prefix(school_id, teacher_id) + "assessments/"

    @staticmethod
    def assessment_prefix(school_id: str, teacher_id: str, assessment_id: str) -> str:
        return (
            ObjectStore.assessments_prefix(school_id, teacher_id) + assessment_id + "/"
        )

    @staticmethod
    def assessment_attr_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + ATTR_FILE
        )

    @staticmethod
    def assessment_events_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "events/"
        )

    @staticmethod
    def assessment_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        event_id: str,
    ) -> str:
        return (
            ObjectStore.assessment_events_prefix(school_id, teacher_id, assessment_id)
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "files/"
        )

    @staticmethod
    def assessment_subject_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "subject/"
        )

    @staticmethod
    def assessment_subject_compile_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compiled_subject.json"
        )

    @staticmethod
    def assessment_subject_compile_legacy_json_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        """Previous ``compile_subject.json`` name; migrated by ``compile_subject``."""
        return (
            ObjectStore.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compile_subject.json"
        )

    @staticmethod
    def assessment_subject_compile_legacy_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        """Pre-.json key; migrated by ``Assessment.compile_subject``."""
        return (
            ObjectStore.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compile_subject"
        )

    @staticmethod
    def assessment_subject_correction_grid_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "correction_grid.json"
        )

    @staticmethod
    def assessment_subject_strict_correction_grid_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "strict_correction_grid.json"
        )

    @staticmethod
    def assessment_unclassified_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "unclassified/"
        )

    @staticmethod
    def assessment_file_area(type_: str, student_id: str | None) -> str:
        if student_id is not None and student_id.strip() != "":
            return "student"
        if type_ in ("subject", "solution", "instructions"):
            return "subject"
        return "unclassified"

    @staticmethod
    def assessment_file_prefix(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        area = ObjectStore.assessment_file_area(type_, student_id)
        if area == "student":
            return (
                ObjectStore.assessment_student_prefix(
                    school_id, teacher_id, assessment_id, (student_id or "").strip()
                )
                + file_id
                + "/"
            )
        if area == "subject":
            return (
                ObjectStore.assessment_subject_files_prefix(
                    school_id, teacher_id, assessment_id
                )
                + file_id
                + "/"
            )
        return (
            ObjectStore.assessment_unclassified_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + file_id
            + "/"
        )

    @staticmethod
    def assessment_blobs_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "blobs/"
        )

    @staticmethod
    def assessment_blob_key(
        school_id: str, teacher_id: str, assessment_id: str, file_id: str
    ) -> str:
        return (
            ObjectStore.assessment_blobs_prefix(school_id, teacher_id, assessment_id)
            + file_id
        )

    @staticmethod
    def assessment_file_attr_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + ATTR_FILE
        )

    @staticmethod
    def assessment_file_content_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + CONTENT_FILE
        )

    @staticmethod
    def assessment_file_ocr_result_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + OCR_RESULT_FILE
        )

    @staticmethod
    def assessment_file_found_errors_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + FOUND_ERRORS_FILE
        )

    @staticmethod
    def assessment_file_markup_annotations_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + MARKUP_ANNOTATIONS_FILE
        )

    @staticmethod
    def assessment_file_thumbnail_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + THUMBNAIL_FILE
        )

    @staticmethod
    def assessment_file_events_prefix(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + "events/"
        )

    @staticmethod
    def assessment_file_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        event_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStore.assessment_file_events_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_students_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
            + "students/"
        )

    @staticmethod
    def assessment_students_list_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.assessment_students_prefix(school_id, teacher_id, assessment_id)
            + "list.json"
        )

    @staticmethod
    def assessment_student_prefix(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_students_prefix(school_id, teacher_id, assessment_id)
            + student_id
            + "/"
        )

    @staticmethod
    def assessment_student_attr_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + ATTR_FILE
        )

    @staticmethod
    def assessment_student_correction_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "correction.png"
        )

    @staticmethod
    def assessment_student_compiled_submission_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "compiled_submission.json"
        )

    @staticmethod
    def assessment_student_events_prefix(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "events/"
        )

    @staticmethod
    def assessment_student_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        student_id: str,
        event_id: str,
    ) -> str:
        return (
            ObjectStore.assessment_student_events_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_student_correction_json_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStore.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "correction.json"
        )

    @staticmethod
    def parse_node_prefix(prefix: str) -> dict[str, Any]:
        """Parse a schools/... node prefix into path segments."""
        parts = [p for p in prefix.strip("/").split("/") if p != ""]
        if len(parts) < 2 or parts[0] != "schools":
            raise ValueError(f"Invalid node prefix: {prefix}")

        school_id = parts[1]
        if len(parts) == 2:
            return {"kind": "school", "school_id": school_id}

        if (parts[2] if len(parts) > 2 else "") != "teachers" or len(parts) < 4:
            raise ValueError(f"Invalid teacher path in prefix: {prefix}")
        teacher_id = parts[3]
        if len(parts) == 4:
            return {
                "kind": "teacher",
                "school_id": school_id,
                "teacher_id": teacher_id,
            }

        if (parts[4] if len(parts) > 4 else "") != "assessments" or len(parts) < 6:
            raise ValueError(f"Invalid assessment path in prefix: {prefix}")
        assessment_id = parts[5]
        if len(parts) == 6:
            return {
                "kind": "assessment",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
            }

        area = parts[6] if len(parts) > 6 else ""
        if area == "blobs" and len(parts) == 8:
            return {
                "kind": "blob",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
                "file_id": parts[7],
                "area": "blobs",
            }

        if area in ("subject", "unclassified", "files") and len(parts) == 8:
            return {
                "kind": "file",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
                "file_id": parts[7],
                "area": "unclassified" if area == "files" else area,
            }

        if area == "students" and len(parts) >= 8:
            if len(parts) == 8:
                return {
                    "kind": "student",
                    "school_id": school_id,
                    "teacher_id": teacher_id,
                    "assessment_id": assessment_id,
                    "student_id": parts[7],
                }
            if len(parts) == 9:
                if parts[8] == "correction.png" or "." in parts[8]:
                    return {
                        "kind": "blob",
                        "school_id": school_id,
                        "teacher_id": teacher_id,
                        "assessment_id": assessment_id,
                        "student_id": parts[7],
                        "file_id": parts[8],
                        "area": "student",
                    }
                return {
                    "kind": "file",
                    "school_id": school_id,
                    "teacher_id": teacher_id,
                    "assessment_id": assessment_id,
                    "student_id": parts[7],
                    "file_id": parts[8],
                    "area": "student",
                }

        raise ValueError(f"Unrecognized node prefix: {prefix}")

    # -------------------------------------------------------------------------
    # Legacy CSV path builders
    # -------------------------------------------------------------------------

    @staticmethod
    def legacy_school_prefix(school_id: str) -> str:
        return school_id + "/"

    @staticmethod
    def legacy_school_csv_key(school_id: str) -> str:
        return school_id + "/school.csv"

    @staticmethod
    def legacy_user_prefix(school_id: str, user_id: str) -> str:
        return f"{school_id}/{user_id}/"

    @staticmethod
    def legacy_user_csv_key(school_id: str, user_id: str) -> str:
        return f"{school_id}/{user_id}/user.csv"

    @staticmethod
    def legacy_assessment_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return f"{school_id}/{user_id}/{assessment_id}/"

    @staticmethod
    def legacy_assessment_csv_key(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return f"{school_id}/{user_id}/{assessment_id}/exam.csv"

    @staticmethod
    def legacy_assessment_files_csv_key(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.legacy_assessment_prefix(school_id, user_id, assessment_id)
            + "files.csv"
        )

    @staticmethod
    def legacy_assessment_files_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.legacy_assessment_prefix(school_id, user_id, assessment_id)
            + "files/"
        )

    @staticmethod
    def legacy_assessment_file_key(
        school_id: str, user_id: str, assessment_id: str, filename: str
    ) -> str:
        return (
            ObjectStore.legacy_assessment_files_prefix(
                school_id, user_id, assessment_id
            )
            + filename
        )

    @staticmethod
    def legacy_assessment_unassigned_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.legacy_assessment_prefix(school_id, user_id, assessment_id)
            + "unassigned/"
        )

    @staticmethod
    def legacy_assessment_subject_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStore.legacy_assessment_prefix(school_id, user_id, assessment_id)
            + "subject/"
        )

    # -------------------------------------------------------------------------
    # Bucket / object operations
    # -------------------------------------------------------------------------

    def ensure_bucket(self) -> None:
        if self._bucket_ready:
            return
        try:
            self.client.head_bucket(Bucket=self.bucket)
        except ClientError as exc:
            status = (exc.response or {}).get("ResponseMetadata", {}).get(
                "HTTPStatusCode"
            )
            if status in (404, 403):
                self.client.create_bucket(Bucket=self.bucket)
            else:
                raise
        self._bucket_ready = True

    def put(
        self,
        key: str,
        local_path: str,
        content_type: str | None = None,
        if_match: str | None = None,
        metadata: dict[str, str] | None = None,
    ) -> str | None:
        with open(local_path, "rb") as fh:
            body = fh.read()
        return self.put_contents(key, body, content_type, if_match, metadata)

    def put_contents(
        self,
        key: str,
        body: bytes | str,
        content_type: str | None = None,
        if_match: str | None = None,
        metadata: dict[str, str] | None = None,
    ) -> str | None:
        self.ensure_bucket()
        if isinstance(body, str):
            body_bytes: bytes | str = body.encode("utf-8")
        else:
            body_bytes = body

        params: dict[str, Any] = {
            "Bucket": self.bucket,
            "Key": key,
            "Body": body_bytes,
        }
        if content_type is not None and content_type != "":
            params["ContentType"] = content_type
        if metadata:
            params["Metadata"] = metadata
        _apply_if_match(params, if_match)

        start = time.monotonic()
        try:
            result = self._put_object(params)
        finally:
            self._log_slow("PutObject", key, start)

        return _normalize_etag(result.get("ETag"))

    def put_bytes(
        self, key: str, body: bytes | str, content_type: str
    ) -> str | None:
        """Consumer-compatible upload helper."""
        return self.put_contents(key, body, content_type)

    def _put_object(self, params: dict[str, Any]) -> dict[str, Any]:
        try:
            return self.client.put_object(**params)
        except ParamValidationError:
            if_match = params.pop("IfMatch", None)
            if if_match is None:
                raise
            # Older botocore: inject If-Match as a custom header.
            def _inject_header(request: Any, **_kwargs: Any) -> None:
                request.headers["If-Match"] = if_match

            event = "before-sign.s3.PutObject"
            self.client.meta.events.register(event, _inject_header)
            try:
                return self.client.put_object(**params)
            finally:
                self.client.meta.events.unregister(event, _inject_header)
        except ClientError as exc:
            if _is_precondition_failed(exc):
                key = str(params.get("Key", ""))
                raise StoreConflictException(f"Conflict writing {key}", 412, exc) from exc
            raise

    def put_json(
        self, key: str, data: dict[str, Any], if_match: str | None = None
    ) -> str | None:
        payload = dict(data)
        if "schema" not in payload:
            payload["schema"] = SCHEMA
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        return self.put_contents(key, body, "application/json", if_match)

    def get(self, key: str) -> dict[str, Any]:
        self.ensure_bucket()
        start = time.monotonic()
        try:
            result = self.client.get_object(Bucket=self.bucket, Key=key)
        finally:
            self._log_slow("GetObject", key, start)
        return {
            "Body": result["Body"],
            "ContentType": result.get("ContentType", "application/octet-stream"),
            "ContentLength": int(result.get("ContentLength") or 0),
            "ETag": _normalize_etag(result.get("ETag")),
            "LastModified": _last_modified_timestamp(result.get("LastModified")),
        }

    def get_bytes(self, key: str) -> bytes:
        return _body_to_bytes(self.get(key)["Body"])

    def get_contents(self, key: str) -> str:
        return self.get_bytes(key).decode("utf-8")

    def get_json(self, key: str) -> dict[str, Any]:
        """Return JSON data only (consumer-compatible)."""
        data, _etag = self.get_json_with_etag(key)
        return data

    def get_json_with_etag(self, key: str) -> tuple[dict[str, Any], str | None]:
        obj = self.get(key)
        raw = _body_to_bytes(obj["Body"]).decode("utf-8")
        data = json.loads(raw)
        if not isinstance(data, dict):
            raise ValueError(f"Invalid JSON object at {key}")
        return data, obj.get("ETag")

    def get_object_json(self, key: str) -> dict[str, Any]:
        """PHP-shaped ``{data, etag}`` result."""
        data, etag = self.get_json_with_etag(key)
        return {"data": data, "etag": etag}

    def head(self, key: str) -> dict[str, Any]:
        self.ensure_bucket()
        start = time.monotonic()
        try:
            result = self.client.head_object(Bucket=self.bucket, Key=key)
        finally:
            self._log_slow("HeadObject", key, start)
        metadata = result.get("Metadata") or {}
        if not isinstance(metadata, dict):
            metadata = {}
        return {
            "ETag": _normalize_etag(result.get("ETag")),
            "ContentLength": int(result.get("ContentLength") or 0),
            "ContentType": str(result.get("ContentType") or "application/octet-stream"),
            "Metadata": metadata,
            "LastModified": _last_modified_timestamp(result.get("LastModified")),
        }

    def replace_metadata(
        self,
        key: str,
        metadata: dict[str, str],
        content_type: str | None = None,
    ) -> None:
        self.ensure_bucket()
        params: dict[str, Any] = {
            "Bucket": self.bucket,
            "CopySource": {"Bucket": self.bucket, "Key": key},
            "Key": key,
            "MetadataDirective": "REPLACE",
            "Metadata": metadata,
        }
        if content_type is not None and content_type != "":
            params["ContentType"] = content_type
        self.client.copy_object(**params)

    def copy(self, source_key: str, dest_key: str) -> None:
        self.ensure_bucket()
        self.client.copy_object(
            Bucket=self.bucket,
            CopySource={"Bucket": self.bucket, "Key": source_key},
            Key=dest_key,
            MetadataDirective="COPY",
        )

    def copy_prefix(self, source_prefix: str, dest_prefix: str) -> None:
        source_prefix = source_prefix.rstrip("/") + "/"
        dest_prefix = dest_prefix.rstrip("/") + "/"
        if source_prefix == dest_prefix:
            return
        for key in self.list_keys(source_prefix):
            relative = key[len(source_prefix) :]
            if relative == "":
                continue
            self.copy(key, dest_prefix + relative)

    def delete(self, key: str) -> None:
        self.ensure_bucket()
        self.client.delete_object(Bucket=self.bucket, Key=key)

    def delete_prefix(self, prefix: str) -> None:
        self.ensure_bucket()
        continuation: str | None = None
        while True:
            params: dict[str, Any] = {"Bucket": self.bucket, "Prefix": prefix}
            if continuation is not None:
                params["ContinuationToken"] = continuation
            page = self.client.list_objects_v2(**params)
            contents = page.get("Contents") or []
            if contents:
                objects = [{"Key": item["Key"]} for item in contents if item.get("Key")]
                if objects:
                    self.client.delete_objects(
                        Bucket=self.bucket,
                        Delete={"Objects": objects},
                    )
            if page.get("IsTruncated"):
                continuation = page.get("NextContinuationToken")
            else:
                break
        if prefix != "":
            try:
                self.client.delete_object(Bucket=self.bucket, Key=prefix)
            except Exception:
                pass

    def exists(self, key: str) -> bool:
        self.ensure_bucket()
        try:
            self.client.head_object(Bucket=self.bucket, Key=key)
            return True
        except ClientError as exc:
            status = (exc.response or {}).get("ResponseMetadata", {}).get(
                "HTTPStatusCode"
            )
            code = str((exc.response or {}).get("Error", {}).get("Code", ""))
            if status == 404 or code in ("404", "NoSuchKey", "NotFound"):
                return False
            raise

    def list_keys(self, prefix: str = "") -> list[str]:
        self.ensure_bucket()
        keys: list[str] = []
        continuation: str | None = None
        while True:
            params: dict[str, Any] = {"Bucket": self.bucket, "Prefix": prefix}
            if continuation is not None:
                params["ContinuationToken"] = continuation
            page = self.client.list_objects_v2(**params)
            for item in page.get("Contents") or []:
                key = item.get("Key") or ""
                if key == "" or key.endswith("/"):
                    continue
                keys.append(key)
            if page.get("IsTruncated"):
                continuation = page.get("NextContinuationToken")
            else:
                break
        return keys

    def list(self, prefix: str) -> list[dict[str, Any]]:
        self.ensure_bucket()
        files: list[dict[str, Any]] = []
        continuation: str | None = None
        while True:
            params: dict[str, Any] = {"Bucket": self.bucket, "Prefix": prefix}
            if continuation is not None:
                params["ContinuationToken"] = continuation
            page = self.client.list_objects_v2(**params)
            for item in page.get("Contents") or []:
                key = item.get("Key") or ""
                if key.endswith("/"):
                    continue
                created = _last_modified_timestamp(item.get("LastModified")) or 0
                files.append(
                    {
                        "name": key.rsplit("/", 1)[-1],
                        "size": int(item.get("Size") or 0),
                        "created": created,
                        "key": key,
                    }
                )
            if page.get("IsTruncated"):
                continuation = page.get("NextContinuationToken")
            else:
                break
        return files

    def list_child_prefixes(self, prefix: str = "") -> list[str]:
        self.ensure_bucket()
        if prefix != "" and not prefix.endswith("/"):
            prefix += "/"
        children: list[str] = []
        continuation: str | None = None
        while True:
            params: dict[str, Any] = {
                "Bucket": self.bucket,
                "Delimiter": "/",
            }
            if prefix != "":
                params["Prefix"] = prefix
            if continuation is not None:
                params["ContinuationToken"] = continuation
            result = self.client.list_objects_v2(**params)
            for common in result.get("CommonPrefixes") or []:
                full = common.get("Prefix") or ""
                relative = full[len(prefix) :] if prefix else full
                relative = relative.rstrip("/")
                if relative != "":
                    children.append(relative)
            if result.get("IsTruncated"):
                continuation = result.get("NextContinuationToken")
            else:
                break
        return children

    def list_immediate_files(self, prefix: str) -> list[str]:
        self.ensure_bucket()
        if prefix != "" and not prefix.endswith("/"):
            prefix += "/"
        names: list[str] = []
        continuation: str | None = None
        while True:
            params: dict[str, Any] = {
                "Bucket": self.bucket,
                "Delimiter": "/",
            }
            if prefix != "":
                params["Prefix"] = prefix
            if continuation is not None:
                params["ContinuationToken"] = continuation
            result = self.client.list_objects_v2(**params)
            for item in result.get("Contents") or []:
                key = str(item.get("Key") or "")
                if key == "" or key.endswith("/"):
                    continue
                relative = key[len(prefix) :] if prefix else key
                if relative == "" or "/" in relative:
                    continue
                names.append(relative)
            if result.get("IsTruncated"):
                continuation = result.get("NextContinuationToken")
            else:
                break
        return names

    def set_id_pointer(
        self, hash_id: str, node_prefix: str, directory: bool = True
    ) -> None:
        value = node_prefix.rstrip("/")
        if directory:
            value += "/"
        self.put_contents(self.id_index_key(hash_id), value, "text/plain")

    def resolve_id_pointer(self, hash_id: str) -> str:
        key = self.id_index_key(hash_id)
        if not self.exists(key):
            raise RuntimeError(f"ID pointer for hash {hash_id} does not exist")
        prefix = self.get_contents(key).strip()
        if prefix == "":
            raise RuntimeError(f"ID pointer for hash {hash_id} is empty")
        return prefix

    def delete_id_pointer(self, hash_id: str) -> None:
        key = self.id_index_key(hash_id)
        if self.exists(key):
            self.delete(key)

    def download_to_temp(self, key: str) -> str:
        import tempfile

        data = self.get_bytes(key)
        fd, path = tempfile.mkstemp(prefix="corrai_s3_")
        try:
            with open(fd, "wb") as fh:
                fh.write(data)
        except Exception:
            import os

            try:
                os.unlink(path)
            except OSError:
                pass
            raise
        return path
