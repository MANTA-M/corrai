"""Assessment task dispatch for the Python Redis worker."""

from __future__ import annotations

from typing import Sequence

DEFAULT_GPT_MODEL = "openai/gpt-6.1-sol"
DEFAULT_CLAUDE_MODEL = "anthropic/claude-sonnet-5.5"


def treat_assessment_task(
    task: str,
    assessment_id: str,
    *,
    student_ids: Sequence[str] | None = None,
    model: str | None = None,
) -> None:
    """Run a Python assessment task ticket."""
    from pycorrai.model.assessment import Assessment

    if task == "create_correction_grid":
        assessment = Assessment.from_hash(assessment_id)
        assessment.create_correction_grid()
        return
    if task == "create_strict_correction_grid":
        assessment = Assessment.from_hash(assessment_id)
        assessment.create_strict_correction_grid()
        return
    if task == "create_math_correction_grid":
        assessment = Assessment.from_hash(assessment_id)
        assessment.create_math_correction_grid()
        return
    if task == "google_vision_ocr_unclassified":
        assessment = Assessment.from_hash(assessment_id)
        assessment.google_vision_ocr_unclassified()
        return
    if task == "correct_students":
        _correct_students(
            assessment_id,
            student_ids=student_ids,
            model=model or DEFAULT_GPT_MODEL,
        )
        return
    if task == "math_correct_students":
        _math_correct_students(
            assessment_id,
            student_ids=student_ids,
            model=model or DEFAULT_CLAUDE_MODEL,
        )
        return
    if task == "jev_correct_students":
        _jev_correct_students(assessment_id, student_ids=student_ids)
        return
    raise ValueError(f"Unknown Python assessment task: {task}")


def _load_students(student_ids: Sequence[str] | None) -> list:
    from pycorrai.model.student import Student

    if not student_ids:
        raise ValueError("student_ids are required for bulk correction tasks")
    students = []
    for student_id in student_ids:
        sid = student_id.strip() if isinstance(student_id, str) else ""
        if sid == "":
            continue
        students.append(Student.from_hash(sid))
    if not students:
        raise ValueError("student_ids are required for bulk correction tasks")
    return students


def _correct_students(
    assessment_id: str,
    *,
    student_ids: Sequence[str] | None,
    model: str,
) -> None:
    from pycorrai.clients.openrouter import LlmClientFactory
    from pycorrai.model.assessment import Assessment

    assessment = Assessment.from_hash(assessment_id)
    students = _load_students(student_ids)
    client_llm = LlmClientFactory.create(model)
    assessment.correct_students(students, client_llm)


def _math_correct_students(
    assessment_id: str,
    *,
    student_ids: Sequence[str] | None,
    model: str,
) -> None:
    from pycorrai.clients.openrouter import LlmClientFactory
    from pycorrai.model.assessment import Assessment

    assessment = Assessment.from_hash(assessment_id)
    students = _load_students(student_ids)
    client_llm = LlmClientFactory.create(model)
    assessment.math_correct_students(students, client_llm, model)


def _jev_correct_students(
    assessment_id: str,
    *,
    student_ids: Sequence[str] | None,
) -> None:
    from pycorrai.model.assessment import Assessment

    assessment = Assessment.from_hash(assessment_id)
    students = _load_students(student_ids)
    assessment.jev_correct_students(students)
