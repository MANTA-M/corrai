"""Path helpers for assessment content keys on the OCR queue."""

from __future__ import annotations

import secrets
import time

from redis import Redis

from pycorrai.store.metadata_cache import invalidate_for_path


def file_prefix(content_path: str) -> str:
    """Parent prefix of a content key (trailing slash)."""
    path = content_path.rstrip("/")
    parent, sep, _name = path.rpartition("/")
    if sep == "":
        raise ValueError(f"Cannot derive file prefix from path: {content_path}")
    return parent + "/"


def attributes_key(content_path: str) -> str:
    return file_prefix(content_path) + "attributes.json"


def event_key(content_path: str, timestamp: int | None = None) -> str:
    """Build an events/<unix>-<8 hex>.json key under the file prefix."""
    ts = int(time.time()) if timestamp is None else timestamp
    event_id = f"{ts}-{secrets.token_hex(4)}"
    return file_prefix(content_path) + f"events/{event_id}.json"


def ocr_json_key(content_path: str) -> str:
    """Sibling ``ocr_result.json`` next to the content object."""
    return file_prefix(content_path) + "ocr_result.json"


def assessment_id_from_path(content_path: str) -> str:
    """Assessment id from a content key under ``assessments/<id>/``."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    try:
        index = parts.index("assessments")
    except ValueError as exc:
        raise ValueError(f"Path has no assessment: {content_path}") from exc
    if index + 1 >= len(parts) or parts[index + 1] == "":
        raise ValueError(f"Empty assessment id in path: {content_path}")
    return parts[index + 1]


def school_teacher_assessment_from_path(content_path: str) -> tuple[str, str, str]:
    """Return ``(school_id, teacher_id, assessment_id)`` from a schools/... key."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    try:
        school_idx = parts.index("schools")
        teacher_idx = parts.index("teachers")
        assessment_idx = parts.index("assessments")
    except ValueError as exc:
        raise ValueError(
            f"Path is not under schools/teachers/assessments: {content_path}"
        ) from exc
    if (
        school_idx + 1 >= len(parts)
        or teacher_idx + 1 >= len(parts)
        or assessment_idx + 1 >= len(parts)
        or parts[school_idx + 1] == ""
        or parts[teacher_idx + 1] == ""
        or parts[assessment_idx + 1] == ""
    ):
        raise ValueError(f"Incomplete school/teacher/assessment path: {content_path}")
    return parts[school_idx + 1], parts[teacher_idx + 1], parts[assessment_idx + 1]


def file_id_from_path(content_path: str) -> str:
    """Extract the file id, the directory that contains the ``content`` object."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    if len(parts) < 2 or parts[-1] != "content":
        raise ValueError(f"Path is not a content object: {content_path}")
    file_id = parts[-2]
    if file_id == "":
        raise ValueError(f"Empty file id in path: {content_path}")
    return file_id


def invalidate_metadata_cache(redis: Redis, content_path: str) -> None:
    """Drop the PHP metadata cache entry for the assessment owning ``content_path``."""
    invalidate_for_path(redis, content_path)
