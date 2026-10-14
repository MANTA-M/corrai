"""Redis cache-aside helpers for assessment metadata (worker invalidation)."""

from __future__ import annotations

import logging
from typing import Any

from redis import Redis

logger = logging.getLogger(__name__)

KEY_PREFIX = "corrai:meta:v1"
TTL_SECONDS = 3600


def assessment_key(school_id: str, teacher_id: str, assessment_id: str) -> str:
    return f"{KEY_PREFIX}:{school_id}:{teacher_id}:a:{assessment_id}"


def assessment_gen_key(school_id: str, teacher_id: str, assessment_id: str) -> str:
    return assessment_key(school_id, teacher_id, assessment_id) + ":gen"


def invalidate_assessment(
    redis: Redis,
    school_id: str,
    teacher_id: str,
    assessment_id: str,
) -> None:
    """Bump gen and drop the assessment metadata tree key."""
    if school_id == "" or teacher_id == "" or assessment_id == "":
        return
    key = assessment_key(school_id, teacher_id, assessment_id)
    gen_key = assessment_gen_key(school_id, teacher_id, assessment_id)
    try:
        redis.incr(gen_key)
        redis.delete(key)
    except Exception:
        logger.exception("MetadataCache invalidateAssessment failed for %s", key)


# Alias matching the informal name used by workers.
invalidate = invalidate_assessment


def invalidate_for_path(redis: Redis, content_path: str) -> None:
    """Invalidate from a schools/.../assessments/... object key."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    try:
        school_idx = parts.index("schools")
        teacher_idx = parts.index("teachers")
        assessment_idx = parts.index("assessments")
    except ValueError:
        logger.warning("Cannot invalidate metadata cache for path %s", content_path)
        return
    if (
        school_idx + 1 >= len(parts)
        or teacher_idx + 1 >= len(parts)
        or assessment_idx + 1 >= len(parts)
        or parts[school_idx + 1] == ""
        or parts[teacher_idx + 1] == ""
        or parts[assessment_idx + 1] == ""
    ):
        logger.warning("Cannot invalidate metadata cache for path %s", content_path)
        return
    invalidate_assessment(
        redis,
        parts[school_idx + 1],
        parts[teacher_idx + 1],
        parts[assessment_idx + 1],
    )


def get_instance_redis() -> Redis | None:
    """Optional helper; workers usually pass an explicit Redis client."""
    return None


def current_gen(
    redis: Redis, school_id: str, teacher_id: str, assessment_id: str
) -> int:
    try:
        raw: Any = redis.get(assessment_gen_key(school_id, teacher_id, assessment_id))
    except Exception:
        return 0
    if raw is None or raw == "":
        return 0
    try:
        return int(raw)
    except (TypeError, ValueError):
        return 0
