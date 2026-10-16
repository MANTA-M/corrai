"""LLM-based parallel student correction using the strict reading grid."""

from __future__ import annotations

import asyncio
import json
import logging
import time
from collections.abc import Callable, Sequence
from typing import TYPE_CHECKING, Any

from pycorrai.model.prompts.law_correction import law_correction_system_prompt
from pycorrai.model.publishing import publish_student_loading
from pycorrai.model.schemas.law_correction import law_correction_schema
from pycorrai.model.services.grid_helpers import (
    apply_retained_criterion_points,
    strip_points_from_correction_grid,
)
from pycorrai.store.object_store import ObjectStore

if TYPE_CHECKING:
    from pycorrai.model.assessment import Assessment
    from pycorrai.model.student import Student

ClientFactory = Callable[[], Any]
PublishStudent = Callable[..., None]

logger = logging.getLogger(__name__)


def correct_students(
    assessment: Assessment,
    students: Sequence[Student],
    client_llm: Any,
    *,
    publish: PublishStudent | None = None,
) -> dict[str, float | None]:
    """Correct several students asynchronously with an LLM client.

    ``client_llm`` must be an LLM client instance (or a zero-arg factory
    returning one). A fresh client is created for each student. Requires an
    existing strict reading grid (and compiled subject), then corrects each
    student in parallel. Waits until every student finishes or fails.

    The LLM receives the reading grid without criterion ``point`` values and
    returns binary retained/not-retained decisions. Points are summed in
    Python from the full grid before saving.

    Returns a mapping ``student_id -> mark`` (``None`` when correction failed).
    """
    from pycorrai.model.assessment import LOCALE_NAMES

    if (
        assessment.id is None
        or assessment.id == ""
        or assessment.school_id == ""
        or assessment.user_id == ""
    ):
        raise RuntimeError(
            "Cannot correct students without id, school_id and user_id"
        )
    make_client = _llm_client_factory(client_llm)

    store = ObjectStore.get_instance()
    grid_key = ObjectStore.assessment_subject_strict_correction_grid_key(
        assessment.school_id, assessment.user_id, assessment.id
    )
    if not store.exists(grid_key):
        raise RuntimeError("Strict correction grid is missing")
    try:
        correction_grid = json.loads(store.get_contents(grid_key))
    except json.JSONDecodeError as exc:
        raise RuntimeError("Strict correction grid is not valid JSON") from exc
    if not isinstance(correction_grid, dict):
        raise RuntimeError("Strict correction grid is not a JSON object")

    subject_key = ObjectStore.assessment_subject_compile_key(
        assessment.school_id, assessment.user_id, assessment.id
    )
    if not store.exists(subject_key):
        raise RuntimeError("Compiled subject is missing")
    compiled_subject = store.get_contents(subject_key)

    instructions = assessment._instructions_md_text()
    language_name = LOCALE_NAMES.get(
        assessment.normalize_locale(assessment.correction_language), "French"
    )
    publish_fn = publish or publish_student_loading

    return asyncio.run(
        _correct_students_async(
            assessment,
            list(students),
            make_client,
            publish_fn,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )
    )


def _llm_client_factory(client_llm: Any) -> ClientFactory:
    """Return a zero-arg factory that yields a fresh LLM client each call."""
    if callable(client_llm) and not hasattr(client_llm, "call_text_with_cost"):
        def make_from_factory() -> Any:
            client = client_llm()
            if not hasattr(client, "call_text_with_cost"):
                raise RuntimeError(
                    "client_llm factory must return an LLM client "
                    "with call_text_with_cost"
                )
            return client

        try:
            make_from_factory()
        except RuntimeError:
            raise
        except Exception as exc:
            raise RuntimeError("Invalid client_llm factory") from exc
        return make_from_factory

    if not hasattr(client_llm, "call_text_with_cost"):
        raise RuntimeError(
            "client_llm must be an LLM client with call_text_with_cost"
        )

    client_cls = type(client_llm)
    model: str | None = None
    payload = getattr(client_llm, "payload", None)
    if isinstance(payload, dict):
        raw_model = payload.get("model")
        if isinstance(raw_model, str) and raw_model.strip() != "":
            model = raw_model

    def make_from_instance() -> Any:
        if model is not None:
            try:
                return client_cls(model)
            except TypeError:
                pass
        return client_cls()

    return make_from_instance


async def _correct_students_async(
    assessment: Assessment,
    students: list[Student],
    make_client: ClientFactory,
    publish: PublishStudent,
    instructions: str,
    correction_grid: dict[str, Any],
    compiled_subject: str,
    language_name: str,
) -> dict[str, float | None]:
    tasks = [
        _correct_one_student(
            assessment,
            student,
            make_client,
            publish,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )
        for student in students
    ]
    results = await asyncio.gather(*tasks)
    return {student_id: mark for student_id, mark in results}


async def _correct_one_student(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    instructions: str,
    correction_grid: dict[str, Any],
    compiled_subject: str,
    language_name: str,
) -> tuple[str, float | None]:
    student_id = str(student.id or "")
    if student_id == "":
        raise RuntimeError("Student has no id")

    return await asyncio.to_thread(
        _correct_one_student_sync,
        assessment,
        student,
        make_client,
        publish,
        instructions,
        correction_grid,
        compiled_subject,
        language_name,
    )


def _correct_one_student_sync(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    instructions: str,
    correction_grid: dict[str, Any],
    compiled_subject: str,
    language_name: str,
) -> tuple[str, float | None]:
    from pycorrai.model.assessment import (
        LABEL_CORRECTED,
        LABEL_STUDENT_ERROR,
        LABEL_UNDER_CORRECTION,
        STATUS_GRADED,
        STATUS_STUDENT_ERROR,
        STATUS_UNDER_CORRECTION,
    )

    student_id = str(student.id or "")
    assessment_id = str(assessment.id or "")
    started_at = time.perf_counter()

    student.status = STATUS_UNDER_CORRECTION
    student.save()
    publish(
        assessment_id,
        student_id,
        status=STATUS_UNDER_CORRECTION,
        status_label=LABEL_UNDER_CORRECTION,
        loading=True,
    )

    try:
        compiled = student.compile_submission(re_compile_submission=False)
        pages = compiled.get("pages") if isinstance(compiled, dict) else None
        if not isinstance(pages, list):
            raise RuntimeError("Compiled submission pages are missing")
        page_texts = [str(page) if page is not None else "" for page in pages]

        grid_for_llm = strip_points_from_correction_grid(correction_grid)
        grid_payload = json.dumps(
            grid_for_llm,
            ensure_ascii=False,
            indent=2,
            separators=(",", ": "),
        )

        client = make_client()
        client.set_system_content(
            law_correction_system_prompt(
                instructions, grid_payload, language_name
            )
        )
        client.set_json_response(
            "law_correction", law_correction_schema(language_name)
        )
        subject_parts = [
            f"Page {index + 1}:\n{text}"
            for index, text in enumerate(
                assessment._compiled_subject_pages(compiled_subject)
            )
        ]
        client.add_text("Sujet :\n" + "\n\n".join(subject_parts))
        submission_parts = [
            f"Page {index + 1}:\n{text}"
            for index, text in enumerate(page_texts)
        ]
        client.add_text("Copie de l'étudiant :\n" + "\n\n".join(submission_parts))
        reply, cost = client.call_text_with_cost()
        decoded = json.loads(reply)
        if not isinstance(decoded, dict):
            raise RuntimeError("Correction reply is not a JSON object")

        mark = apply_retained_criterion_points(decoded, correction_grid)
        student.mark = mark

        body = (
            json.dumps(
                decoded,
                ensure_ascii=False,
                indent=2,
                separators=(",", ": "),
            )
            + "\n"
        )
        correction_key = ObjectStore.assessment_student_correction_json_key(
            student.school_id,
            student.user_id,
            student.assessment_id,
            student_id,
        )
        ObjectStore.get_instance().put_contents(
            correction_key, body, "application/json"
        )

        duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
        student.status = STATUS_GRADED
        student.save()
        student.append_event(
            f"Correction ({duration_ms} ms, {cost:.4f} USD)"
        )
        for file in student.get_submissions():
            file.status = "corrected"
            file.save_attributes()
            file.append_event("Correction written")

        publish(
            assessment_id,
            student_id,
            status=STATUS_GRADED,
            status_label=LABEL_CORRECTED,
            loading=False,
            mark=mark,
        )
        return student_id, mark
    except Exception as exc:
        logger.exception(
            "Failed to correct student %s for assessment %s",
            student_id,
            assessment_id,
        )
        try:
            student.status = STATUS_STUDENT_ERROR
            student.save()
            student.append_event(f"Correction failed: {exc}")
        except Exception:
            logger.exception(
                "Failed to persist error status for student %s", student_id
            )
        try:
            publish(
                assessment_id,
                student_id,
                status=STATUS_STUDENT_ERROR,
                status_label=LABEL_STUDENT_ERROR,
                loading=False,
            )
        except Exception:
            logger.exception(
                "Failed to publish correction failure for student %s",
                student_id,
            )
        return student_id, None
