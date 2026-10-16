"""Google Vision OCR for unclassified assessment files."""

from __future__ import annotations

import logging
from collections.abc import Callable
from typing import TYPE_CHECKING, Any

if TYPE_CHECKING:
    from pycorrai.model.assessment import Assessment

OcrRunner = Callable[..., None]

logger = logging.getLogger(__name__)


def google_vision_ocr_unclassified(
    assessment: Assessment,
    force_re_ocr: bool = False,
    *,
    ocr_runner: OcrRunner | None = None,
) -> None:
    """Run Google Vision OCR on every unclassified copy of this assessment.

    When ``force_re_ocr`` is false, files that already have ``ocr_result.json``
    are skipped (handled by ``google_vision_ocr``). Intended to be launched
    via a Redis Python-queue ticket.
    """
    if (
        assessment.id is None
        or assessment.id == ""
        or assessment.school_id == ""
        or assessment.user_id == ""
    ):
        raise RuntimeError(
            "Cannot OCR unclassified files without id, school_id and user_id"
        )

    files = assessment._unclassified_input_files()
    if not files:
        logger.info(
            "No unclassified files to OCR for assessment %s", assessment.id
        )
        return

    if ocr_runner is not None:
        ocr_runner(files, force_re_ocr=force_re_ocr)
        return

    from pycorrai.ocr.google_vision_ocr import google_vision_ocr

    google_vision_ocr(files, force_re_ocr=force_re_ocr)


def enqueue_google_vision_ocr_unclassified(
    assessment: Assessment, redis: Any | None = None
) -> None:
    """Push a ``google_vision_ocr_unclassified`` ticket on the Python Redis queue."""
    if assessment.id is None or assessment.id == "":
        raise RuntimeError(
            "Cannot enqueue unclassified OCR without assessment id"
        )
    from pycorrai.consumer import enqueue_assessment_task

    enqueue_assessment_task(
        assessment.id, "google_vision_ocr_unclassified", redis=redis
    )
