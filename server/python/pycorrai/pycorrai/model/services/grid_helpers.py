"""Helpers for reading grids, retained points, and Jev noul mapping."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any


def strip_points_from_correction_grid(grid: Any) -> Any:
    """Return a deep copy of the reading grid without criterion ``point`` fields."""
    if isinstance(grid, list):
        return [strip_points_from_correction_grid(item) for item in grid]
    if not isinstance(grid, dict):
        return grid
    cleaned: dict[str, Any] = {}
    for key, value in grid.items():
        if key == "point":
            continue
        cleaned[key] = strip_points_from_correction_grid(value)
    return cleaned


def strip_question_points_from_math_grid(grid: Any) -> Any:
    """Deep-copy a math grid without question-level ``points`` (keep barème)."""
    if isinstance(grid, list):
        return [strip_question_points_from_math_grid(item) for item in grid]
    if not isinstance(grid, dict):
        return grid
    is_question = "barème" in grid
    cleaned: dict[str, Any] = {}
    for key, value in grid.items():
        if key == "points" and is_question:
            continue
        cleaned[key] = strip_question_points_from_math_grid(value)
    return cleaned


def apply_math_retained_percentages(
    correction: dict[str, Any],
    grid: dict[str, Any],
) -> float:
    """Set per-question points from pourcentage_retenu × barème; return total.

    Points are rounded to one decimal place (tenth).
    """
    grid_parties = grid.get("parties") or []
    if not isinstance(grid_parties, list):
        grid_parties = []

    total_mark = 0.0
    for party_idx, party in enumerate(correction.get("parties") or []):
        if not isinstance(party, dict):
            continue
        grid_party = (
            grid_parties[party_idx]
            if party_idx < len(grid_parties)
            and isinstance(grid_parties[party_idx], dict)
            else {}
        )
        grid_questions = grid_party.get("questions") or []
        if not isinstance(grid_questions, list):
            grid_questions = []

        for question_idx, question in enumerate(party.get("questions") or []):
            if not isinstance(question, dict):
                continue
            grid_question = (
                grid_questions[question_idx]
                if question_idx < len(grid_questions)
                and isinstance(grid_questions[question_idx], dict)
                else {}
            )
            raw_points = grid_question.get("points")
            max_points = (
                float(raw_points)
                if isinstance(raw_points, (int, float))
                and not isinstance(raw_points, bool)
                else 0.0
            )
            raw_pct = question.get("pourcentage_retenu")
            if isinstance(raw_pct, (int, float)) and not isinstance(raw_pct, bool):
                percentage = max(0.0, min(1.0, float(raw_pct)))
            else:
                percentage = 0.0
            obtained = round(max_points * percentage, 1)
            question["points"] = obtained
            total_mark += obtained

    total_mark = round(total_mark, 1)
    correction["mark"] = total_mark
    correction["note"] = total_mark
    return total_mark


def apply_retained_criterion_points(
    correction: dict[str, Any],
    grid: dict[str, Any],
) -> float:
    """Add per-question and global marks from retained criteria; return total."""
    points_by_id: dict[str, float] = {}
    for party in grid.get("parties") or []:
        if not isinstance(party, dict):
            continue
        for question in party.get("questions") or []:
            if not isinstance(question, dict):
                continue
            for criterion in question.get("critères") or []:
                if not isinstance(criterion, dict):
                    continue
                criterion_id = criterion.get("id")
                point = criterion.get("point")
                if (
                    isinstance(criterion_id, str)
                    and criterion_id != ""
                    and isinstance(point, (int, float))
                    and not isinstance(point, bool)
                ):
                    points_by_id[criterion_id] = float(point)

    total_mark = 0.0
    for party in correction.get("parties") or []:
        if not isinstance(party, dict):
            continue
        for question in party.get("questions") or []:
            if not isinstance(question, dict):
                continue
            question_mark = 0.0
            for criterion in question.get("critères") or []:
                if not isinstance(criterion, dict):
                    continue
                if criterion.get("retenu") is not True:
                    continue
                criterion_id = criterion.get("id")
                if isinstance(criterion_id, str):
                    question_mark += points_by_id.get(criterion_id, 0.0)
            question["points_obtenus"] = question_mark
            total_mark += question_mark

    correction["mark"] = total_mark
    return total_mark


def iter_grid_criteria(
    grid: dict[str, Any],
) -> list[tuple[int, int, int, dict[str, Any], str, str, float]]:
    """Walk correction-grid criteria; return indexed tuples with id/label/points.

    Supports both classic ``critère`` entries (``critère`` / ``point``) and
    strict ``critères`` entries (``id`` / ``description`` / ``point``).
    """
    rows: list[tuple[int, int, int, dict[str, Any], str, str, float]] = []
    for party_idx, party in enumerate(grid.get("parties") or []):
        if not isinstance(party, dict):
            continue
        for question_idx, question in enumerate(party.get("questions") or []):
            if not isinstance(question, dict):
                continue
            criteria = question.get("critères")
            if not isinstance(criteria, list):
                criteria = question.get("critère")
            if not isinstance(criteria, list):
                continue
            for criterion_idx, criterion in enumerate(criteria):
                if not isinstance(criterion, dict):
                    continue
                criterion_id = criterion.get("id")
                if not isinstance(criterion_id, str) or criterion_id.strip() == "":
                    criterion_id = f"P{party_idx}-Q{question_idx}-C{criterion_idx}"
                label = ""
                for key in ("description", "critère"):
                    value = criterion.get(key)
                    if isinstance(value, str) and value.strip() != "":
                        label = value.strip()
                        break
                if label == "":
                    label = f"Critère {criterion_id}"
                point_raw = criterion.get("point")
                if criterion.get("modificateur") is not None and point_raw is None:
                    point_raw = criterion.get("modificateur")
                point = (
                    float(point_raw)
                    if isinstance(point_raw, (int, float))
                    and not isinstance(point_raw, bool)
                    else 0.0
                )
                rows.append(
                    (
                        party_idx,
                        question_idx,
                        criterion_idx,
                        criterion,
                        criterion_id,
                        label,
                        point,
                    )
                )
    return rows


def build_jev_noul_questions(
    grid: dict[str, Any],
) -> dict[str, dict[str, Any]]:
    """Map each grid criterion to a Jev yes/no (noul) question payload."""
    questions: dict[str, dict[str, Any]] = {}
    for *_rest, criterion_id, label, _point in iter_grid_criteria(grid):
        questions[criterion_id] = {
            "type": "noul",
            "instructions": (
                "Le critère suivant est-il rempli dans la copie de l'étudiant ? "
                f"{label}"
            ),
            "criteria": {
                "true": "Le critère est clairement rempli dans la copie.",
                "false": "Le critère n'est pas rempli ou est insuffisamment présent.",
            },
        }
    return questions


def build_correction_from_jev_answers(
    grid: dict[str, Any],
    answers: Mapping[str, Any],
    *,
    retain_threshold: float = 0.5,
) -> dict[str, Any]:
    """Build an openrouter-like correction object from Jev noul answers.

    Each criterion becomes ``{id, retenu, confiance}``. Question ``points`` and
    the global ``mark`` / ``note`` are summed from retained criteria.
    """
    points_by_id = {
        criterion_id: point
        for *_rest, criterion_id, _label, point in iter_grid_criteria(grid)
    }

    parties_out: list[dict[str, Any]] = []
    total_mark = 0.0
    for party_idx, party in enumerate(grid.get("parties") or []):
        if not isinstance(party, dict):
            continue
        questions_out: list[dict[str, Any]] = []
        for question_idx, question in enumerate(party.get("questions") or []):
            if not isinstance(question, dict):
                continue
            criteria = question.get("critères")
            if not isinstance(criteria, list):
                criteria = question.get("critère")
            if not isinstance(criteria, list):
                criteria = []
            criteria_out: list[dict[str, Any]] = []
            question_mark = 0.0
            for criterion_idx, criterion in enumerate(criteria):
                if not isinstance(criterion, dict):
                    continue
                criterion_id = criterion.get("id")
                if not isinstance(criterion_id, str) or criterion_id.strip() == "":
                    criterion_id = f"P{party_idx}-Q{question_idx}-C{criterion_idx}"
                probability_yes = _jev_probability_yes(answers, criterion_id)
                retained = probability_yes >= retain_threshold
                if retained:
                    question_mark += points_by_id.get(criterion_id, 0.0)
                criteria_out.append(
                    {
                        "id": criterion_id,
                        "retenu": retained,
                        "confiance": probability_yes,
                    }
                )
            questions_out.append(
                {
                    "titre": str(question.get("titre") or ""),
                    "points": question_mark,
                    "points_obtenus": question_mark,
                    "critères": criteria_out,
                }
            )
            total_mark += question_mark
        parties_out.append(
            {
                "titre": str(party.get("titre") or ""),
                "questions": questions_out,
            }
        )

    return {
        "parties": parties_out,
        "mark": total_mark,
        "note": total_mark,
    }


def _jev_probability_yes(answers: Mapping[str, Any], criterion_id: str) -> float:
    """Extract P(yes) from a Jev answer or raw answer mapping."""
    answer = answers.get(criterion_id)
    if answer is None:
        return 0.0
    noul = getattr(answer, "noul", None)
    if isinstance(noul, (int, float)) and not isinstance(noul, bool):
        return max(0.0, min(1.0, float(noul)))
    if isinstance(answer, Mapping):
        raw = answer.get("noul")
        if isinstance(raw, (int, float)) and not isinstance(raw, bool):
            return max(0.0, min(1.0, float(raw)))
    return 0.0
