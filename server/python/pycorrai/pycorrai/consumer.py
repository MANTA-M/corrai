"""Redis consumer that OCRs assessment files from S3 and hands them to PHP."""

from __future__ import annotations

import json
import logging
import secrets
import ssl
import sys
import time
import urllib.error
import urllib.request
from typing import Any
from urllib.parse import quote

from redis import Redis
from redis.exceptions import TimeoutError as RedisTimeoutError

from pycorrai.config import ensure_env_loaded, env
from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES, recognize_bytes
from pycorrai.store.metadata_cache import (
    assessment_key as metadata_cache_assessment_key,
    invalidate_for_path,
)
from pycorrai.store.object_store import (
    BRPOP_TIMEOUT,
    SCHEMA,
    SOCKET_TIMEOUT,
    ObjectStore,
    make_redis_client,
    make_s3_client,
)

logger = logging.getLogger(__name__)

OCR_LIST_KEY = "corrai:ocr"
PHP_LIST_KEY = "corrai:files"
DEFAULT_LANG = "fr"
DEFAULT_OCR_ENGINE = DEFAULT_ENGINE


def parse_ticket(raw: str | bytes) -> dict[str, str]:
    """Decode a queue ticket into path, language, engine, and optional follow-up.

    Accepts JSON ``{"path": "..."}`` (optional ``lang``, ``engine``,
    ``after_task``) or a bare path string. ``operation`` is ignored.
    ``engine`` defaults to ``paddle`` and must be ``paddle`` or ``doctr``.
    """
    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")
    text = raw.strip()
    if not text:
        raise ValueError("Empty OCR ticket")

    path: str
    lang = DEFAULT_LANG
    engine = DEFAULT_OCR_ENGINE
    after_task = ""

    if text.startswith("{"):
        decoded = json.loads(text)
        if not isinstance(decoded, dict):
            raise ValueError(f"Invalid OCR ticket JSON: {text}")
        ticket_path = decoded.get("path")
        if not isinstance(ticket_path, str) or ticket_path.strip() == "":
            raise ValueError(f"OCR ticket missing path: {text}")
        path = ticket_path.strip()
        ticket_lang = decoded.get("lang")
        if isinstance(ticket_lang, str) and ticket_lang.strip() != "":
            lang = ticket_lang.strip()
        ticket_engine = decoded.get("engine")
        if isinstance(ticket_engine, str) and ticket_engine.strip() != "":
            engine = ticket_engine.strip().lower()
        ticket_after = decoded.get("after_task")
        if isinstance(ticket_after, str):
            after_task = ticket_after.strip()
    else:
        path = text

    path = path.lstrip("/")
    if path == "":
        raise ValueError("OCR ticket path is empty")
    if engine not in ENGINES:
        raise ValueError(f"OCR engine must be paddle or doctr, got: {engine}")

    return {"path": path, "lang": lang, "engine": engine, "after_task": after_task}


def parse_queue_message(raw: str | bytes) -> dict[str, str]:
    """Decode a Python-queue ticket: assessment task or OCR path ticket."""
    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")
    text = raw.strip()
    if not text:
        raise ValueError("Empty queue ticket")

    if text.startswith("{"):
        decoded = json.loads(text)
        if not isinstance(decoded, dict):
            raise ValueError(f"Invalid queue ticket JSON: {text}")
        task = decoded.get("task")
        assessment_id = decoded.get("assessment_id")
        if (
            isinstance(task, str)
            and task.strip() != ""
            and isinstance(assessment_id, str)
            and assessment_id.strip() != ""
        ):
            return {
                "kind": "task",
                "task": task.strip(),
                "assessment_id": assessment_id.strip(),
            }

    ticket = parse_ticket(raw)
    return {"kind": "ocr", **ticket}


def enqueue_assessment_task(
    assessment_id: str,
    task: str,
    redis: Redis | None = None,
) -> None:
    """Push an assessment task ticket on the Python Redis queue."""
    assessment_id = assessment_id.strip()
    task = task.strip()
    if assessment_id == "" or task == "":
        raise ValueError("assessment_id and task are required")
    client = redis if redis is not None else make_redis_client()
    payload = json.dumps(
        {"assessment_id": assessment_id, "task": task},
        ensure_ascii=False,
        separators=(",", ":"),
    )
    client.lpush(OCR_LIST_KEY, payload)
    logger.info(
        "Enqueued Python task=%s assessment=%s on %s",
        task,
        assessment_id,
        OCR_LIST_KEY,
    )


def treat_assessment_task(task: str, assessment_id: str) -> None:
    """Run a Python assessment task ticket."""
    from pycorrai.model.assessment import Assessment

    if task == "create_correction_grid":
        assessment = Assessment.from_hash(assessment_id)
        assessment.create_correction_grid()
        return
    raise ValueError(f"Unknown Python assessment task: {task}")


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


# French labels matching the PHP file model. Only a changed status is published.
STATUS_LABELS = {
    "ocr_done": "OCR terminé",
    "error": "Erreur",
}


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


def invalidate_metadata_cache(redis: Redis, content_path: str) -> None:
    """Drop the PHP metadata cache entry for the assessment owning ``content_path``."""
    invalidate_for_path(redis, content_path)


def file_id_from_path(content_path: str) -> str:
    """Extract the file id, the directory that contains the ``content`` object."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    if len(parts) < 2 or parts[-1] != "content":
        raise ValueError(f"Path is not a content object: {content_path}")
    file_id = parts[-2]
    if file_id == "":
        raise ValueError(f"Empty file id in path: {content_path}")
    return file_id


def format_ocr_json(words: list[dict[str, Any]]) -> str:
    """Pretty-print OCR words the same way PHP pipelines do."""
    return json.dumps(words, indent=2, ensure_ascii=False) + "\n"


def event_body(name: str, timestamp: int | None = None) -> dict[str, Any]:
    ts = int(time.time()) if timestamp is None else timestamp
    return {
        "timestamp": ts,
        "name": name,
        "schema": SCHEMA,
    }


def publish_assessment_status(content_path: str, status: str) -> None:
    """POST the new file status to the assessment Nchan channel.

    A failed publish is logged and does not fail the OCR ticket.
    """
    try:
        file_id = file_id_from_path(content_path)
        assessment_id = assessment_id_from_path(content_path)
    except ValueError:
        logger.exception("Cannot publish status for %s", content_path)
        return

    file_payload: dict[str, str] = {"id": file_id, "status": status}
    label = STATUS_LABELS.get(status)
    if label is not None:
        file_payload["status_label"] = label
    body = json.dumps(
        {"scope": "assessment", "file": file_payload},
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")
    base = env("NCHAN_PUBLISH_URL", "https://127.0.0.1/corrai_test/internal/pub").rstrip(
        "/"
    )
    channel = quote(f"assessment:{assessment_id}", safe="")
    request = urllib.request.Request(
        f"{base}/{channel}",
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Host": env("NCHAN_PUBLISH_HOST", "mantam.eu"),
        },
    )
    try:
        with urllib.request.urlopen(
            request,
            timeout=2,
            context=ssl._create_unverified_context(),
        ) as response:
            if response.status < 200 or response.status >= 300:
                logger.error(
                    "Publish status %s for %s returned HTTP %s",
                    status,
                    content_path,
                    response.status,
                )
    except urllib.error.HTTPError as exc:
        logger.error(
            "Publish status %s for %s returned HTTP %s",
            status,
            content_path,
            exc.code,
        )
    except Exception:
        logger.exception("Failed to publish status %s for %s", status, content_path)


def php_task_ticket(content_path: str, after_task: str) -> str:
    """PHP queue ticket that runs ``after_task`` on the same content path."""
    return json.dumps(
        {"path": content_path, "task": after_task},
        ensure_ascii=False,
        separators=(",", ":"),
    )


def treat(
    content_path: str,
    lang: str,
    store: ObjectStore,
    redis: Redis,
    recognize=recognize_bytes,
    after_task: str = "",
    engine: str = DEFAULT_OCR_ENGINE,
    publish=publish_assessment_status,
) -> None:
    """OCR one file path and, when set, enqueue ``after_task`` for PHP."""
    start_time = time.perf_counter()
    logger.info("OCR starting for %s lang=%s engine=%s", content_path, lang, engine)
    attr_key = attributes_key(content_path)
    store.get_json(attr_key)
    try:
        store.put_json(event_key(content_path), event_body("OCR started"))

        content = store.get_bytes(content_path)
        if not content:
            raise ValueError(f"Empty content at {content_path}")

        words = recognize(content, lang, engine)
        store.put_bytes(
            ocr_json_key(content_path),
            format_ocr_json(words),
            "application/json",
        )

        store.get_json(attr_key)
        store.put_json(event_key(content_path), event_body("OCR ended"))

        attrs = store.get_json(attr_key)
        attrs["status"] = "ocr_done"
        store.put_json(attr_key, attrs)
        invalidate_metadata_cache(redis, content_path)
        publish(content_path, "ocr_done")

        duration_ms = max(0, round((time.perf_counter() - start_time) * 1000))

        follow_up = after_task.strip()
        if follow_up != "":
            redis.lpush(PHP_LIST_KEY, php_task_ticket(content_path, follow_up))
            logger.info(
                "OCR finished for %s in %d ms, enqueued task=%s",
                content_path,
                duration_ms,
                follow_up,
            )
        else:
            logger.info(
                "OCR finished for %s in %d ms, no follow-up task",
                content_path,
                duration_ms,
            )
    except Exception:
        try:
            attrs = store.get_json(attr_key)
            attrs["status"] = "error"
            store.put_json(attr_key, attrs)
            store.put_json(event_key(content_path), event_body("OCR failed"))
            invalidate_metadata_cache(redis, content_path)
            publish(content_path, "error")
        except Exception:
            logger.exception("Failed to record OCR failure for %s", content_path)
        raise


def run_forever(
    store: ObjectStore | None = None,
    redis: Redis | None = None,
    recognize=recognize_bytes,
) -> None:
    store = store if store is not None else ObjectStore()
    redis = redis if redis is not None else make_redis_client()

    # OCR models are loaded lazily on the first ticket for each engine so this
    # process does not pull both Paddle and docTR stacks at startup.
    logger.info("Python consumer started, waiting on %s", OCR_LIST_KEY)
    while True:
        try:
            result = redis.brpop(OCR_LIST_KEY, timeout=BRPOP_TIMEOUT)
        except RedisTimeoutError:
            logger.warning(
                "Redis read timed out while waiting on %s; retrying",
                OCR_LIST_KEY,
            )
            continue
        except Exception:
            logger.exception("Python consumer error")
            continue

        if result is None:
            continue
        try:
            _key, raw = result
            ticket = parse_queue_message(raw)
            if ticket.get("kind") == "task":
                logger.info(
                    "Treating task ticket task=%s assessment=%s",
                    ticket["task"],
                    ticket["assessment_id"],
                )
                treat_assessment_task(ticket["task"], ticket["assessment_id"])
                continue
            logger.info(
                "Treating OCR ticket %s engine=%s",
                ticket["path"],
                ticket["engine"],
            )
            treat(
                ticket["path"],
                ticket["lang"],
                store,
                redis,
                recognize=recognize,
                after_task=ticket["after_task"],
                engine=ticket["engine"],
            )
        except Exception:
            logger.exception("Python consumer error")


def main(argv: list[str] | None = None) -> int:
    del argv  # CLI takes no arguments; reserved for future flags.
    ensure_env_loaded()
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stderr,
    )
    run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
