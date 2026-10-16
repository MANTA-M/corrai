"""Jev Decisions API parallel student correction."""

from __future__ import annotations

import asyncio
import json
import logging
import time
from collections.abc import Callable, Mapping, Sequence
from typing import TYPE_CHECKING, Any

from pycorrai.model.publishing import publish_student_loading
from pycorrai.model.services.grid_helpers import (
    build_correction_from_jev_answers,
    iter_grid_criteria,
)
from pycorrai.store.object_store import ObjectStore

if TYPE_CHECKING:
    from pycorrai.model.assessment import Assessment
    from pycorrai.model.student import Student

ClientFactory = Callable[[], Any]
PublishStudent = Callable[..., None]

logger = logging.getLogger(__name__)


def jev_correct_students(
    assessment: Assessment,
    students: Sequence[Student],
    *,
    client_factory: ClientFactory | None = None,
    publish: PublishStudent | None = None,
) -> dict[str, float | None]:
    """Correct several students asynchronously with the Jev Decisions API.

    Requires an existing ``correction_grid.json``. Each student is processed
    in parallel: Nchan ``En correction``, compile submission if needed, ask
    Jev one yes/no (noul) question per criterion, persist a correction JSON
    similar to openrouter/mistral, then Nchan ``Corrigé``.

    Returns a mapping ``student_id -> note`` (``None`` when correction failed).
    """
    if (
        assessment.id is None
        or assessment.id == ""
        or assessment.school_id == ""
        or assessment.user_id == ""
    ):
        raise RuntimeError(
            "Cannot Jev-correct students without id, school_id and user_id"
        )

    store = ObjectStore.get_instance()
    grid_key = ObjectStore.assessment_subject_correction_grid_key(
        assessment.school_id, assessment.user_id, assessment.id
    )
    if not store.exists(grid_key):
        raise RuntimeError("Correction grid is missing")
    try:
        correction_grid = json.loads(store.get_contents(grid_key))
    except json.JSONDecodeError as exc:
        raise RuntimeError("Correction grid is not valid JSON") from exc
    if not isinstance(correction_grid, dict):
        raise RuntimeError("Correction grid is not a JSON object")

    if client_factory is not None:
        make_client = client_factory
    else:

        def make_client() -> Any:
            from pycorrai.clients.openrouter.jev_client import JevClient

            return JevClient()

    publish_fn = publish or publish_student_loading
    return asyncio.run(
        _jev_correct_students_async(
            assessment,
            list(students),
            make_client,
            publish_fn,
            correction_grid,
        )
    )


async def _jev_correct_students_async(
    assessment: Assessment,
    students: list[Student],
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
) -> dict[str, float | None]:
    tasks = [
        _jev_correct_one_student(
            assessment,
            student,
            make_client,
            publish,
            correction_grid,
        )
        for student in students
    ]
    results = await asyncio.gather(*tasks)
    return {student_id: mark for student_id, mark in results}


async def _jev_correct_one_student(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
) -> tuple[str, float | None]:
    student_id = str(student.id or "")
    if student_id == "":
        raise RuntimeError("Student has no id")

    return await asyncio.to_thread(
        _jev_correct_one_student_sync,
        assessment,
        student,
        make_client,
        publish,
        correction_grid,
    )


def _jev_correct_one_student_sync(
    assessment: Assessment,
    student: Student,
    make_client: ClientFactory,
    publish: PublishStudent,
    correction_grid: dict[str, Any],
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
        if not isinstance(compiled, dict):
            raise RuntimeError("Compiled submission is missing")

        compiled_body = (
            json.dumps(
                compiled,
                ensure_ascii=False,
                indent=2,
                separators=(",", ": "),
            )
            + "\n"
        )
        state = "Correction examen de droit.\n" + compiled_body

        client = make_client()
        if not hasattr(client, "decide_with_cost") or not hasattr(
            client, "add_noul"
        ):
            raise RuntimeError(
                "client_factory must return a Jev client with "
                "add_noul and decide_with_cost"
            )
        client.set_state(state)
        if hasattr(client, "clear_questions"):
            client.clear_questions()
        for *_rest, criterion_id, label, _point in iter_grid_criteria(
            correction_grid
        ):
            client.add_noul(
                criterion_id,
                (
                    "Le critère suivant est-il rempli dans la copie "
                    f"de l'étudiant ? {label}"
                ),
                criteria_true=(
                    "Le critère est clairement rempli dans la copie."
                ),
                criteria_false=(
                    "Le critère n'est pas rempli ou est "
                    "insuffisamment présent."
                ),
            )
        if not getattr(client, "questions", None):
            raise RuntimeError("Correction grid has no criteria")
        response, cost = client.decide_with_cost()
        answers = getattr(response, "answers", None)
        if not isinstance(answers, Mapping):
            raise RuntimeError("Jev response has no answers")

        correction = build_correction_from_jev_answers(
            correction_grid, answers
        )
        mark = float(correction.get("mark") or 0.0)
        student.mark = mark

        body = (
            json.dumps(
                correction,
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
            "Failed to Jev-correct student %s for assessment %s",
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
                "Failed to publish Jev correction failure for student %s",
                student_id,
            )
        return student_id, None
