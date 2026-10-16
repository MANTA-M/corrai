"""Create strict (binary) correction grid from subject + instructions."""

from __future__ import annotations

import json
import logging
import time
from collections.abc import Callable
from typing import TYPE_CHECKING, Any

from pycorrai.model.prompts.correction_grid import (
    STRICT_CORRECTION_GRID_SYSTEM_PROMPT,
)
from pycorrai.model.publishing import publish_assessment_loading
from pycorrai.model.schemas.correction_grid import strict_correction_grid_schema
from pycorrai.store.object_store import ObjectStore

if TYPE_CHECKING:
    from pycorrai.model.assessment import Assessment

ClientFactory = Callable[[], Any]
PublishAssessment = Callable[..., None]

logger = logging.getLogger(__name__)


def create_strict_correction_grid(
    assessment: Assessment,
    *,
    client_factory: ClientFactory | None = None,
    publish: PublishAssessment | None = None,
) -> dict[str, Any]:
    """Build ``strict_correction_grid.json`` as a binary reading grid.

    Same pipeline as ``create_correction_grid``, but the LLM returns only
    self-contained yes/no criteria with short unique ids.
    """
    from pycorrai.model.assessment import (
        LABEL_STRICT_GRID_CREATED,
        LABEL_STRICT_GRID_CREATION,
        STATUS_STRICT_GRID_CREATED,
        STATUS_STRICT_GRID_CREATION,
    )

    if (
        assessment.id is None
        or assessment.id == ""
        or assessment.school_id == ""
        or assessment.user_id == ""
    ):
        raise RuntimeError(
            "Cannot create strict correction grid without id, "
            "school_id and user_id"
        )

    publish_fn = publish or publish_assessment_loading
    started_at = time.perf_counter()
    assessment.status = STATUS_STRICT_GRID_CREATION
    assessment.save()
    publish_fn(
        assessment.id,
        status=STATUS_STRICT_GRID_CREATION,
        status_label=LABEL_STRICT_GRID_CREATION,
        loading=True,
    )

    try:
        compiled = assessment.compile_subject(re_compile_subject=False)
        pages = compiled.get("pages") if isinstance(compiled, dict) else None
        if not isinstance(pages, list):
            raise RuntimeError("Compiled subject pages are missing")
        page_texts = [str(page) if page is not None else "" for page in pages]

        instructions = assessment._instructions_md_text()
        if client_factory is not None:
            client = client_factory()
        else:
            from pycorrai.clients.openrouter.gemini3_client import Gemini3Client

            client = Gemini3Client()
        client.set_system_content(STRICT_CORRECTION_GRID_SYSTEM_PROMPT)
        client.set_json_response(
            "strict_correction_grid", strict_correction_grid_schema()
        )
        if instructions.strip() != "":
            client.add_text(
                "Instructions de l'évaluation :\n" + instructions.strip()
            )
        sujet_parts = [
            f"Page {index + 1}:\n{text}"
            for index, text in enumerate(page_texts)
        ]
        client.add_text("Sujet :\n" + "\n\n".join(sujet_parts))
        reply, cost = client.call_text_with_cost()
        decoded = json.loads(reply)
        if not isinstance(decoded, dict):
            raise RuntimeError(
                "Strict correction grid reply is not a JSON object"
            )

        body = (
            json.dumps(
                decoded,
                ensure_ascii=False,
                indent=2,
                separators=(",", ": "),
            )
            + "\n"
        )
        grid_key = ObjectStore.assessment_subject_strict_correction_grid_key(
            assessment.school_id, assessment.user_id, assessment.id
        )
        ObjectStore.get_instance().put_contents(
            grid_key, body, "application/json"
        )

        duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
        assessment.status = STATUS_STRICT_GRID_CREATED
        assessment.save()
        assessment.append_event(
            f"Strict grid creation ({duration_ms} ms, {cost:.4f} USD)"
        )
        publish_fn(
            assessment.id,
            status=STATUS_STRICT_GRID_CREATED,
            status_label=LABEL_STRICT_GRID_CREATED,
            loading=False,
        )
        return decoded
    except Exception:
        try:
            publish_fn(
                assessment.id,
                status=assessment.status or STATUS_STRICT_GRID_CREATION,
                status_label=LABEL_STRICT_GRID_CREATION,
                loading=False,
            )
        except Exception:
            logger.exception(
                "Failed to publish strict-grid-creation failure for %s",
                assessment.id,
            )
        raise


def enqueue_create_strict_correction_grid(
    assessment: Assessment, redis: Any | None = None
) -> None:
    """Push a ``create_strict_correction_grid`` ticket on the Python Redis queue."""
    if assessment.id is None or assessment.id == "":
        raise RuntimeError(
            "Cannot enqueue strict correction grid without assessment id"
        )
    from pycorrai.consumer import enqueue_assessment_task

    enqueue_assessment_task(
        assessment.id, "create_strict_correction_grid", redis=redis
    )
