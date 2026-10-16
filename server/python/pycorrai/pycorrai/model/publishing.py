"""Nchan publish helpers for assessment / student loading status."""

from __future__ import annotations

import json
import logging
import ssl
import urllib.error
import urllib.request
from typing import Any
from urllib.parse import quote

from pycorrai.config import env

logger = logging.getLogger(__name__)


def _nchan_publish(channel: str, payload: dict[str, Any]) -> None:
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode(
        "utf-8"
    )
    base = env(
        "NCHAN_PUBLISH_URL", "https://127.0.0.1/corrai_test/internal/pub"
    ).rstrip("/")
    request = urllib.request.Request(
        f"{base}/{quote(channel, safe='')}",
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
                    "Nchan publish %s returned HTTP %s", channel, response.status
                )
    except urllib.error.HTTPError as exc:
        logger.error("Nchan publish %s returned HTTP %s", channel, exc.code)
    except Exception:
        logger.exception("Failed to publish Nchan event on %s", channel)


def publish_assessment_loading(
    assessment_id: str,
    *,
    status: str,
    status_label: str,
    loading: bool,
) -> None:
    """POST assessment status + loading flag to the assessment Nchan channel."""
    if assessment_id.strip() == "":
        return
    _nchan_publish(
        f"assessment:{assessment_id}",
        {
            "scope": "assessment",
            "assessment": {
                "id": assessment_id,
                "status": status,
                "status_label": status_label,
                "loading": loading,
            },
        },
    )


def publish_student_loading(
    assessment_id: str,
    student_id: str,
    *,
    status: str,
    status_label: str,
    loading: bool,
    mark: float | None = None,
) -> None:
    """POST student status + loading flag to assessment and student Nchan channels."""
    if assessment_id.strip() == "" or student_id.strip() == "":
        return
    student_payload: dict[str, Any] = {
        "id": student_id,
        "status": status,
        "status_label": status_label,
        "loading": loading,
    }
    if mark is not None:
        student_payload["mark"] = mark
    _nchan_publish(
        f"assessment:{assessment_id}",
        {"scope": "assessment", "student": student_payload},
    )
    _nchan_publish(
        f"student:{student_id}",
        {"scope": "student", "student": student_payload},
    )
