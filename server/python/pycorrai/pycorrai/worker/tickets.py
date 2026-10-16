"""Redis queue ticket parsing and enqueue helpers."""

from __future__ import annotations

import json
import logging
from typing import TypedDict

from redis import Redis

from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES
from pycorrai.store.object_store import make_redis_client

logger = logging.getLogger(__name__)

OCR_LIST_KEY = "corrai:ocr"
DEFAULT_LANG = "fr"
DEFAULT_OCR_ENGINE = DEFAULT_ENGINE


class OcrTicket(TypedDict):
    path: str
    lang: str
    engine: str
    after_task: str


class TaskTicket(TypedDict, total=False):
    kind: str
    task: str
    assessment_id: str
    student_ids: list[str]
    model: str


class OcrQueueTicket(OcrTicket):
    kind: str


def parse_ticket(raw: str | bytes) -> OcrTicket:
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


def parse_queue_message(raw: str | bytes) -> TaskTicket | OcrQueueTicket:
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
            ticket: TaskTicket = {
                "kind": "task",
                "task": task.strip(),
                "assessment_id": assessment_id.strip(),
            }
            student_ids = _parse_student_ids(decoded.get("student_ids"))
            if student_ids:
                ticket["student_ids"] = student_ids
            model = decoded.get("model")
            if isinstance(model, str) and model.strip() != "":
                ticket["model"] = model.strip()
            return ticket

    ticket = parse_ticket(raw)
    return {"kind": "ocr", **ticket}


def _parse_student_ids(raw: object) -> list[str]:
    if not isinstance(raw, list):
        return []
    ids: list[str] = []
    for item in raw:
        if isinstance(item, str) and item.strip() != "":
            ids.append(item.strip())
    return ids


def enqueue_assessment_task(
    assessment_id: str,
    task: str,
    redis: Redis | None = None,
    *,
    student_ids: list[str] | None = None,
    model: str | None = None,
) -> None:
    """Push an assessment task ticket on the Python Redis queue."""
    assessment_id = assessment_id.strip()
    task = task.strip()
    if assessment_id == "" or task == "":
        raise ValueError("assessment_id and task are required")
    client = redis if redis is not None else make_redis_client()
    payload_obj: dict[str, object] = {
        "assessment_id": assessment_id,
        "task": task,
    }
    if student_ids:
        payload_obj["student_ids"] = [
            sid.strip() for sid in student_ids if isinstance(sid, str) and sid.strip() != ""
        ]
    if model is not None and model.strip() != "":
        payload_obj["model"] = model.strip()
    payload = json.dumps(
        payload_obj,
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
