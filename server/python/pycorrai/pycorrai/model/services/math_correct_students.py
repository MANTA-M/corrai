"""LLM-based parallel math student correction using the math reading grid."""

from __future__ import annotations

import asyncio
import json
import logging
import os
import time
from collections.abc import Callable, Sequence
from typing import TYPE_CHECKING, Any

from pycorrai.model.prompts.math_correction import math_correction_system_prompt
from pycorrai.model.publishing import publish_student_loading
from pycorrai.model.schemas.math_correction import math_correction_schema
from pycorrai.model.services.correct_students import _llm_client_factory
from pycorrai.model.services.grid_helpers import (
    apply_math_retained_percentages,
    strip_question_points_from_math_grid,
)
from pycorrai.store.object_store import ObjectStore

if TYPE_CHECKING:
    from pycorrai.model.assessment import Assessment
    from pycorrai.model.student import Student

ClientFactory = Callable[[], Any]
PublishStudent = Callable[..., None]

logger = logging.getLogger(__name__)


def math_correct_students(
    assessment: Assessment,
    students: Sequence[Student],
    client_llm: Any,
    model_name: str,
    *,
    publish: PublishStudent | None = None,
) -> dict[str, float | None]:
    """Correct several students asynchronously with an LLM (math grid + images).

    Requires an existing ``math_correction_grid.json``. Each student is processed
    in parallel: Nchan ``En correction``, send the reading grid (without question
    ``points``) as system content and submission images as user content, apply
    ``pourcentage_retenu × points`` rounded to one decimal, save
    ``correction_<model>.json``, then Nchan ``Corrigé``.

    Returns a mapping ``student_id -> note`` (``None`` when correction failed).
    """
    if (
        assessment.id is None
        or assessment.id == ""
        or assessment.school_id == ""
        or assessment.user_id == ""
    ):
        raise RuntimeError(
            "Cannot correct math students without id, school_id and user_id"
        )
    if not isinstance(model_name, str) or model_name.strip() == "":
        raise RuntimeError("model_name is required")

    make_client = _llm_client_factory(client_llm)
    model = model_name.strip()

    store = ObjectStore.get_instance()
    grid_key = ObjectStore.assessment_subject_math_correction_grid_key(
        assessment.school_id, assessment.user_id, assessment.id
    )
    if not store.exists(grid_key):
        raise RuntimeError("Math correction grid is missing")
    try:
        correction_grid = json.loads(store.get_contents(grid_key))
    except json.JSONDecodeError as exc:
        raise RuntimeError("Math correction grid is not valid JSON") from exc
    if not isinstance(correction_grid, dict):
        raise RuntimeError("Math correction grid is not a JSON object")

    publish_fn = publish or publish_student_loading

    return asyncio.run(
        _math_correct_students_async(
            assessment,
            list(students),
            make_client,
            publish_fn,
            correction_grid,
            model,
        )
    )


async def _math_correct_students_async(
    assessment: Assessment,
    students: list[Student],
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
    model_name: str,
) -> dict[str, float | None]:
    tasks = [
        _math_correct_one_student(
            assessment,
            student,
            make_client,
            publish,
            correction_grid,
            model_name,
        )
        for student in students
    ]
    results = await asyncio.gather(*tasks)
    return {student_id: mark for student_id, mark in results}


async def _math_correct_one_student(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
    model_name: str,
) -> tuple[str, float | None]:
    student_id = str(student.id or "")
    if student_id == "":
        raise RuntimeError("Student has no id")

    return await asyncio.to_thread(
        _math_correct_one_student_sync,
        assessment,
        student,
        make_client,
        publish,
        correction_grid,
        model_name,
    )


def _math_correct_one_student_sync(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
    model_name: str,
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
    temp_paths: list[str] = []

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
        submissions = sorted(
            student.get_submissions(),
            key=lambda file: file.name,
        )
        if not submissions:
            raise RuntimeError("Student has no submission images")

        store = ObjectStore.get_instance()
        for file in submissions:
            path = store.download_to_temp(file.content_key())
            temp_paths.append(path)

        grid_for_llm = strip_question_points_from_math_grid(correction_grid)
        grid_payload = json.dumps(
            grid_for_llm,
            ensure_ascii=False,
            indent=2,
            separators=(",", ": "),
        )

        client = make_client()
        client.set_system_content(math_correction_system_prompt(grid_payload))
        client.set_json_response("math_correction", math_correction_schema())
        for file, path in zip(submissions, temp_paths, strict=True):
            client.add_file(path, file.name)
        reply, cost = client.call_text_with_cost()
        decoded = json.loads(reply)
        if not isinstance(decoded, dict):
            raise RuntimeError("Correction reply is not a JSON object")

        mark = apply_math_retained_percentages(decoded, correction_grid)
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
        correction_key = ObjectStore.assessment_student_correction_model_json_key(
            student.school_id,
            student.user_id,
            student.assessment_id,
            student_id,
            model_name,
        )
        store.put_contents(correction_key, body, "application/json")

        duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
        student.status = STATUS_GRADED
        student.save()
        student.append_event(
            f"Correction ({duration_ms} ms, {cost:.4f} USD)"
        )
        for file in submissions:
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
            "Failed to math-correct student %s for assessment %s",
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
                "Failed to publish math-correction failure for student %s",
                student_id,
            )
        return student_id, None
    finally:
        for path in temp_paths:
            try:
                os.unlink(path)
            except OSError:
                pass
