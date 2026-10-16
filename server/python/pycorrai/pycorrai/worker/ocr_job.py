"""OCR one S3 content object and optionally enqueue a PHP follow-up task."""

from __future__ import annotations

import json
import logging
import ssl
import time
import urllib.error
import urllib.request
from typing import Any
from urllib.parse import quote

from redis import Redis

from pycorrai.config import env
from pycorrai.ocr.dispatch import recognize_bytes
from pycorrai.store.object_store import SCHEMA, ObjectStore
from pycorrai.worker.paths import (
    assessment_id_from_path,
    attributes_key,
    event_key,
    file_id_from_path,
    invalidate_metadata_cache,
    ocr_json_key,
)
from pycorrai.worker.tickets import DEFAULT_OCR_ENGINE

logger = logging.getLogger(__name__)

PHP_LIST_KEY = "corrai:files"

# French labels matching the PHP file model. Only a changed status is published.
STATUS_LABELS = {
    "ocr_done": "OCR terminé",
    "error": "Erreur",
}


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
