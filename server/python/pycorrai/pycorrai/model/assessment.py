"""Assessment model for Corrai workers (no subject pipelines / Stripe / menus)."""

from __future__ import annotations

import asyncio
import json
import logging
import secrets
import ssl
import time
import urllib.error
import urllib.request
from collections.abc import Callable, Mapping, Sequence
from typing import Any
from urllib.parse import quote

from pycorrai.config import env
from pycorrai.store.hash_id import HashId
from pycorrai.store.metadata_cache import invalidate_assessment
from pycorrai.store.object_store import ObjectStore, make_redis_client
from pycorrai.model.input_file import InputFile
from pycorrai.model.instruction_file import InstructionFile
from pycorrai.model.s3_file import S3File
from pycorrai.model.student import Student

OcrRunner = Callable[..., None]
ClientFactory = Callable[[], Any]
PublishAssessment = Callable[..., None]
PublishStudent = Callable[..., None]

logger = logging.getLogger(__name__)

LOCALE_NAMES = {
    "en": "English",
    "fr": "French",
    "ru": "Russian",
    "uk": "Ukrainian",
    "es": "Spanish",
    "pt": "Portuguese",
    "ro": "Romanian",
    "de": "German",
}

STATUS_GRID_CREATION = "create_correction_grid"
STATUS_GRID_CREATED = "correction_grid_generated"
LABEL_GRID_CREATION = "Grid creation"
LABEL_GRID_CREATED = "Grid created"

STATUS_STRICT_GRID_CREATION = "create_strict_correction_grid"
STATUS_STRICT_GRID_CREATED = "strict_correction_grid_generated"
LABEL_STRICT_GRID_CREATION = "Strict grid creation"
LABEL_STRICT_GRID_CREATED = "Strict grid created"

STATUS_UNDER_CORRECTION = "under_correction"
STATUS_GRADED = "graded"
STATUS_STUDENT_ERROR = "error"
LABEL_UNDER_CORRECTION = "En correction"
LABEL_CORRECTED = "Corrigé"
LABEL_STUDENT_ERROR = "Erreur"

CORRECTION_GRID_SYSTEM_PROMPT = (
    "Tu es un correcteur dans une école d'avocat en France. "
    "Extrait du sujet et des instructions une grille de correction.\n"
    "N'extrait du sujet que les informations utiles à la correction. "
    "Ignore les informations purement organisationnelles.\n"
    "Toutes les instructions utiles présentes dans le fichier d'instructions "
    'se retrouveront de manière synthétique dans le tableau '
    '"instructions_générales" du JSON.\n'
    "Le contenu du sujet sera réparti sans redites entre les instructions "
    "générales et les parties.\n"
    "Pour chaque question, renseigne le tableau \"critère\" : chaque entrée "
    "contient un libellé \"critère\" et un \"point\" (points maximum "
    "attribuables pour ce critère). La somme des points des critères d'une "
    "question doit correspondre au barème \"points\" de la question.\n"
    'Dans la section "references", mets le texte verbatim de chaque article '
    "cité dans le sujet et recherché sur un site législatif officiel français "
    "et en vigueur, en incluant l'ensemble des alinéas "
    "(en identifiant/numérotant distinctement chaque alinéa).\n"
    "Tout doit être en Français.\n"
)


STRICT_CORRECTION_GRID_SYSTEM_PROMPT = (
    "Tu es un correcteur dans une école d'avocat en France. "
    "Extrait du sujet et des instructions une grille de lecture simplifiée "
    "composée uniquement de critères binaires.\n"
    "N'extrait du sujet que les informations utiles à la correction. "
    "Ignore les informations purement organisationnelles.\n"
    "Chaque critère est une question fermée (oui/non) : si la réponse est "
    "oui, le correcteur attribue exactement le nombre de points indiqué ; "
    "sinon 0. Aucun critère partiel, gradué ou subjectif.\n"
    "La description de chaque critère doit être autosuffisante : elle "
    "contient tout ce qui permet d'y répondre sans autre documentation "
    "(énoncé, article, seuil, formule attendue, etc.).\n"
    "Génère pour chaque critère un identifiant court et unique dans toute "
    "la grille (ex. A1-01, B2-03).\n"
    "Pour chaque question, renseigne le barème \"points\". La somme des "
    "\"point\" des critères d'une question doit égaler exactement ce "
    "barème (tous les points de la question sont ainsi couverts).\n"
    "Chaque critère vaut au minimum 1/10 de point (0,1).\n"
    "Organise la grille en parties, chacune contenant des questions, "
    "chacune contenant des critères.\n"
    "Tout doit être en Français.\n"
)


def strict_correction_grid_schema() -> dict[str, Any]:
    """JSON schema for a binary, self-contained reading grid."""
    criterion = {
        "type": "object",
        "additionalProperties": False,
        "required": ["id", "description", "point"],
        "properties": {
            "id": {
                "type": "string",
                "description": (
                    "Identifiant court et unique du critère dans la grille."
                ),
            },
            "description": {
                "type": "string",
                "description": (
                    "Question fermée (oui/non), autosuffisante pour "
                    "répondre sans autre documentation."
                ),
            },
            "point": {
                "type": "number",
                "minimum": 0.1,
                "description": (
                    "Points attribués si la réponse au critère est oui "
                    "(sinon 0). Minimum 1/10 (0,1)."
                ),
            },
        },
    }
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["parties"],
        "properties": {
            "parties": {
                "type": "array",
                "description": "Parties du sujet.",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["titre", "questions"],
                    "properties": {
                        "titre": {"type": "string"},
                        "questions": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "additionalProperties": False,
                                "required": ["titre", "points", "critères"],
                                "properties": {
                                    "titre": {"type": "string"},
                                    "points": {
                                        "type": "number",
                                        "description": (
                                            "Barème de la question ; égal "
                                            "à la somme des points des "
                                            "critères."
                                        ),
                                    },
                                    "critères": {
                                        "type": "array",
                                        "description": (
                                            "Critères binaires de la "
                                            "question ; leur somme de "
                                            "points couvre tout le barème."
                                        ),
                                        "items": criterion,
                                    },
                                },
                            },
                        },
                    },
                },
            },
        },
    }


def correction_grid_schema() -> dict[str, Any]:
    """JSON schema for Claude Sonnet correction-grid replies (LawFrance)."""
    criterion = {
        "type": "object",
        "additionalProperties": False,
        "required": ["critère", "modificateur"],
        "properties": {
            "critère": {
                "type": "string",
                "description": "Critère de correction, en français.",
            },
            "modificateur": {
                "type": "integer",
                "description": (
                    "Points ajoutés si le nombre est positif, "
                    "retirés s'il est négatif."
                ),
            },
        },
    }
    question_criterion = {
        "type": "object",
        "additionalProperties": False,
        "required": ["critère", "point"],
        "properties": {
            "critère": {
                "type": "string",
                "description": (
                    "Libellé du critère d'évaluation de la question."
                ),
            },
            "point": {
                "type": "number",
                "description": (
                    "Points maximum attribuables pour ce critère."
                ),
            },
        },
    }
    reference = {
        "type": "object",
        "additionalProperties": False,
        "required": ["reference", "texte"],
        "properties": {
            "reference": {
                "type": "string",
                "description": "Référence de l'article cité dans le sujet.",
            },
            "texte": {
                "type": "string",
                "description": (
                    "Texte verbatim de l'article recherché sur un site "
                    "législatif officiel français et en vigueur, "
                    "incluant tous ses alinéas."
                ),
            },
        },
    }
    return {
        "type": "object",
        "additionalProperties": False,
        "required": [
            "instructions_générales",
            "modificateurs_généraux",
            "parties",
            "references",
        ],
        "properties": {
            "instructions_générales": {
                "type": "array",
                "items": {"type": "string"},
                "description": "Consignes utiles à la correction.",
            },
            "modificateurs_généraux": {
                "type": "array",
                "items": criterion,
                "description": "Bonus ou malus qui s'appliquent à toute la copie.",
            },
            "parties": {
                "type": "array",
                "description": "Parties du sujet.",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["titre", "questions", "nota_bene"],
                    "properties": {
                        "titre": {"type": "string"},
                        "questions": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "additionalProperties": False,
                                "required": [
                                    "titre",
                                    "points",
                                    "contenu",
                                    "questions_posées",
                                    "critère",
                                ],
                                "properties": {
                                    "titre": {"type": "string"},
                                    "points": {
                                        "type": "integer",
                                        "description": "Barème de la question.",
                                    },
                                    "contenu": {"type": "string"},
                                    "questions_posées": {
                                        "type": "array",
                                        "items": {"type": "string"},
                                    },
                                    "critère": {
                                        "type": "array",
                                        "description": (
                                            "Critères d'évaluation de la "
                                            "question, avec leur barème."
                                        ),
                                        "items": question_criterion,
                                    },
                                },
                            },
                        },
                        "nota_bene": {
                            "type": "array",
                            "items": {"type": "string"},
                        },
                    },
                },
            },
            "references": {
                "type": "array",
                "description": (
                    "Texte verbatim de chaque article cité dans le sujet "
                    "et recherché sur un site législatif officiel français "
                    "et en vigueur, incluant tous ses alinéas."
                ),
                "items": reference,
            },
        },
    }


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


def law_correction_schema(language_name: str) -> dict[str, Any]:
    """JSON schema for binary reading-grid student correction replies."""
    del language_name  # kept for call-site compatibility
    criterion = {
        "type": "object",
        "additionalProperties": False,
        # OpenAI/Azure strict JSON schema requires every property key in
        # ``required``; optional location fields are therefore nullable.
        "required": [
            "id",
            "retenu",
            "confiance",
            "page_debut",
            "index_debut",
            "page_fin",
            "index_fin",
        ],
        "properties": {
            "id": {
                "type": "string",
                "description": (
                    "Identifiant du critère, identique à celui de la "
                    "grille de lecture."
                ),
            },
            "retenu": {
                "type": "boolean",
                "description": "Vrai si le critère est rempli sur la copie.",
            },
            "confiance": {
                "type": "number",
                "minimum": 0,
                "maximum": 1,
                "description": (
                    "Coefficient de confiance entre 0 et 1 quant à "
                    "l'évaluation de ce critère."
                ),
            },
            "page_debut": {
                "type": ["integer", "null"],
                "minimum": 1,
                "description": (
                    "Page de début du passage justifiant un critère retenu ; "
                    "null si le critère n'est pas retenu."
                ),
            },
            "index_debut": {
                "type": ["integer", "null"],
                "minimum": 0,
                "description": (
                    "Index de début (dans la page) du passage justifiant "
                    "un critère retenu ; null si le critère n'est pas retenu."
                ),
            },
            "page_fin": {
                "type": ["integer", "null"],
                "minimum": 1,
                "description": (
                    "Page de fin du passage justifiant un critère retenu ; "
                    "null si le critère n'est pas retenu."
                ),
            },
            "index_fin": {
                "type": ["integer", "null"],
                "minimum": 0,
                "description": (
                    "Index de fin (dans la page) du passage justifiant "
                    "un critère retenu ; null si le critère n'est pas retenu."
                ),
            },
        },
    }
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["parties"],
        "properties": {
            "parties": {
                "type": "array",
                "description": (
                    "Mêmes parties et mêmes questions que la grille, "
                    "dans le même ordre."
                ),
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["titre", "questions"],
                    "properties": {
                        "titre": {
                            "type": "string",
                            "description": (
                                "Titre de la partie, identique à la grille."
                            ),
                        },
                        "questions": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "additionalProperties": False,
                                "required": ["titre", "critères"],
                                "properties": {
                                    "titre": {
                                        "type": "string",
                                        "description": (
                                            "Titre de la question, "
                                            "identique à la grille."
                                        ),
                                    },
                                    "critères": {
                                        "type": "array",
                                        "description": (
                                            "Évaluation binaire de chaque "
                                            "critère de la question."
                                        ),
                                        "items": criterion,
                                    },
                                },
                            },
                        },
                    },
                },
            },
        },
    }


def law_correction_system_prompt(
    instruction_text: str,
    correction_grid: str,
    language_name: str,
) -> str:
    """System prompt for binary reading-grid correction."""
    return (
        "Vous êtes un professeur chargé de corriger les copies "
        "d'une école d'avocat.\n"
        "Appliquez strictement la grille de lecture fournie. Reproduisez "
        "les mêmes parties et les mêmes questions, dans le même ordre, "
        "avec les mêmes titres.\n"
        "Pour chaque critère de chaque question, indiquez :\n"
        "- id : identifiant identique à celui de la grille ;\n"
        "- retenu : vrai si le critère est rempli, faux sinon ;\n"
        "- confiance : coefficient de confiance entre 0 et 1 ;\n"
        "- si et seulement si le critère est retenu, localisez le passage "
        "de la copie qui justifie la décision via page_debut, index_debut, "
        "page_fin et index_fin.\n"
        "N'attribuez aucun point : le barème sera calculé ensuite.\n"
        "Ne prenez pas en compte les fautes d'orthographe vraisemblablement "
        "dues à des erreurs d'OCR.\n"
        f"Répondez en vous basant sur une grille rédigée en {language_name}.\n"
        "\n# Instructions\n"
        f"{instruction_text}"
        "\n# Grille de lecture\n"
        f"{correction_grid}"
    )


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


class Assessment:
    FILE_TYPES = [
        "subject",
        "solution",
        "submission",
        "instructions",
        "correction",
        "debug",
    ]

    def __init__(self) -> None:
        self.id: str | None = None
        self.school_id: str = ""
        self.user_id: str = ""
        self.name: str = ""
        self.subject: str = ""
        self.country: str | None = None
        self.level: str | None = None
        self.correction_language: str = "fr"
        self.date: str = ""
        self.created_at: str = ""
        self.status: str = "draft"
        self.assessed_students_number: int | None = None
        self.mark_average: float | None = None
        self.mark_min: float | None = None
        self.mark_max: float | None = None

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> Assessment:
        assessment = cls()
        assessment.id = data.get("id")
        assessment.school_id = str(data.get("school_id") or "")
        assessment.user_id = str(data.get("user_id") or data.get("author") or "")
        assessment.name = str(data.get("name") or "")
        assessment.subject = str(data.get("subject") or "")
        assessment.country = cls.optional_attribute(data.get("country"))
        assessment.level = cls.optional_attribute(data.get("level"))
        assessment.correction_language = cls.normalize_locale(
            data.get("correction_language")
        )
        assessment.date = str(data.get("date") or "")
        assessment.created_at = str(data.get("created_at") or "")
        assessment.status = str(data.get("status") or "draft")
        assessment.assessed_students_number = cls.non_negative_int_attribute(
            data.get("assessed_students_number")
        )
        assessment.mark_average = cls.nullable_number_attribute(
            data.get("mark_average")
        )
        assessment.mark_min = cls.nullable_number_attribute(data.get("mark_min"))
        assessment.mark_max = cls.nullable_number_attribute(data.get("mark_max"))
        return assessment

    @staticmethod
    def optional_attribute(value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        trimmed = value.strip()
        return None if trimmed == "" else trimmed

    @staticmethod
    def non_negative_int_attribute(value: Any) -> int | None:
        if isinstance(value, int) and value >= 0:
            return value
        if isinstance(value, float) and value >= 0:
            return int(value)
        if isinstance(value, str) and value.strip().isdigit():
            return int(value)
        return None

    @staticmethod
    def nullable_number_attribute(value: Any) -> float | None:
        if isinstance(value, (int, float)):
            return float(value)
        if isinstance(value, str) and value.strip() != "":
            try:
                return float(value)
            except ValueError:
                return None
        return None

    @staticmethod
    def normalize_locale(value: Any) -> str:
        if not isinstance(value, str) or value.strip() == "":
            return "fr"
        locale = value.strip().lower()
        dash = locale.find("-")
        if dash >= 0:
            locale = locale[:dash]
        if locale not in LOCALE_NAMES:
            return "fr"
        return locale

    def attribute_payload(self) -> dict[str, Any]:
        return {
            "name": self.name,
            "subject": self.subject,
            "country": self.optional_attribute(self.country),
            "level": self.optional_attribute(self.level),
            "correction_language": self.correction_language,
            "date": self.date,
            "created_at": self.created_at,
            "status": self.status,
            "assessed_students_number": self.assessed_students_number,
            "mark_average": self.mark_average,
            "mark_min": self.mark_min,
            "mark_max": self.mark_max,
        }

    def validate(self) -> None:
        if self.school_id.strip() == "":
            raise ValueError("Assessment school_id is required")
        if self.user_id.strip() == "":
            raise ValueError("Assessment user_id is required")
        if self.subject.strip() == "":
            raise ValueError("Assessment subject is required")

    @classmethod
    def from_hash(cls, hash_id: str) -> Assessment:
        store = ObjectStore.get_instance()
        prefix = store.resolve_id_pointer(hash_id)
        parsed = ObjectStore.parse_node_prefix(prefix)
        if parsed.get("kind") != "assessment":
            raise RuntimeError(f"Invalid assessment path for hash {hash_id}")
        school_id = str(parsed["school_id"])
        user_id = str(parsed["teacher_id"])
        assessment_id = str(parsed["assessment_id"])
        attr_key = ObjectStore.assessment_attr_key(school_id, user_id, assessment_id)
        if not store.exists(attr_key):
            raise RuntimeError(f"Assessment with hash {hash_id} does not exist")

        data, _etag = store.get_json_with_etag(attr_key)
        assessment = cls.from_array(data)
        assessment.id = assessment_id
        assessment.school_id = school_id
        assessment.user_id = user_id
        return assessment

    def save(self) -> None:
        if self.id is None or self.id == "":
            self.id = HashId.create()
        if self.created_at == "":
            self.created_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        self.validate()

        store = ObjectStore.get_instance()
        attr_key = ObjectStore.assessment_attr_key(
            self.school_id, self.user_id, self.id
        )
        store.put_json(attr_key, self.attribute_payload())
        store.set_id_pointer(
            self.id,
            ObjectStore.assessment_prefix(self.school_id, self.user_id, self.id),
        )
        self._invalidate_metadata_cache()

    def delete(self) -> None:
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot delete assessment without id, school_id and user_id"
            )
        store = ObjectStore.get_instance()
        for file in self.list_file_models():
            if file.id is not None:
                store.delete_id_pointer(file.id)
        for student in self.list_student_models():
            if student.id is not None:
                store.delete_id_pointer(student.id)
        store.delete_prefix(
            ObjectStore.assessment_prefix(self.school_id, self.user_id, self.id)
        )
        store.delete_id_pointer(self.id)
        self._invalidate_metadata_cache()

    def get_file(self, file_id: str) -> InputFile | S3File:
        store = ObjectStore.get_instance()
        pointer = store.resolve_id_pointer(file_id)
        parsed = ObjectStore.parse_node_prefix(pointer)
        file: InputFile | S3File
        if (parsed.get("kind") or "") == "blob":
            file = S3File.from_hash(file_id)
        else:
            file = InputFile.from_hash(file_id)
        if (
            file.school_id != self.school_id
            or file.user_id != self.user_id
            or file.assessment_id != self.id
        ):
            raise LookupError(
                f"File '{file_id}' does not belong to assessment {self.id}"
            )
        return file

    def get_student(self, student_id: str) -> Student:
        student = Student.from_hash(student_id)
        if (
            student.school_id != self.school_id
            or student.user_id != self.user_id
            or student.assessment_id != self.id
        ):
            raise LookupError(
                f"Student '{student_id}' does not belong to assessment {self.id}"
            )
        return student

    def list_file_models(self) -> list[InputFile | S3File]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []

        files: list[InputFile | S3File] = []
        seen: set[str] = set()
        for file_id in self._stored_file_ids():
            if file_id in seen:
                continue
            try:
                files.append(InputFile.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue
        for file_id in self._blob_file_ids():
            if file_id in seen:
                continue
            try:
                files.append(S3File.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue

        store = ObjectStore.get_instance()
        students = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id
        )
        for student_id in store.list_child_prefixes(students):
            student_prefix = students + student_id + "/"
            for name in store.list_immediate_files(student_prefix):
                if name == ObjectStore.ATTR_FILE:
                    continue
                try:
                    direct = S3File.from_key(student_prefix + name)
                    if direct.id is not None and direct.id not in seen:
                        files.append(direct)
                        seen.add(direct.id)
                except Exception:
                    continue
        return files

    def list_student_models(self) -> list[Student]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        students: list[Student] = []
        prefix = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id
        )
        for student_id in store.list_child_prefixes(prefix):
            attr_key = ObjectStore.assessment_student_attr_key(
                self.school_id, self.user_id, self.id, student_id
            )
            if not store.exists(attr_key):
                continue
            try:
                students.append(Student.from_hash(student_id))
            except Exception:
                continue
        return students

    def list_student_file_models(self, student_id: str) -> list[InputFile | S3File]:
        student_id = student_id.strip()
        if (
            student_id == ""
            or not self.id
            or self.school_id == ""
            or self.user_id == ""
        ):
            return []

        store = ObjectStore.get_instance()
        student_prefix = ObjectStore.assessment_student_prefix(
            self.school_id, self.user_id, self.id, student_id
        )
        files: list[InputFile | S3File] = []
        seen: set[str] = set()

        for file_id in store.list_child_prefixes(student_prefix):
            if file_id in seen:
                continue
            try:
                files.append(InputFile.from_hash(file_id))
                seen.add(file_id)
            except Exception:
                continue

        for name in store.list_immediate_files(student_prefix):
            if name == ObjectStore.ATTR_FILE:
                continue
            try:
                direct = S3File.from_key(student_prefix + name)
                if direct.id is not None and direct.id not in seen:
                    files.append(direct)
                    seen.add(direct.id)
            except Exception:
                continue
        return files

    def list_events(self) -> list[dict[str, Any]]:
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        prefix = ObjectStore.assessment_events_prefix(
            self.school_id, self.user_id, self.id
        )
        events: list[dict[str, Any]] = []
        for name in store.list_immediate_files(prefix):
            if not name.endswith(".json"):
                continue
            event_id = name[:-5]
            timestamp = 0
            label = event_id
            try:
                data = store.get_json(prefix + name)
                timestamp = int(data.get("timestamp") or 0)
                event_name = data.get("name") or ""
                if isinstance(event_name, str) and event_name != "":
                    label = event_name
            except Exception:
                pass
            events.append({"id": event_id, "timestamp": timestamp, "name": label})
        events.sort(key=lambda e: (e["timestamp"], e["name"]))
        return events

    def append_event(self, name: str, timestamp: int | None = None) -> None:
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot append event without assessment id")
        ts = int(time.time()) if timestamp is None else timestamp
        event_id = f"{ts}-{secrets.token_hex(4)}"
        key = ObjectStore.assessment_event_key(
            self.school_id, self.user_id, self.id, event_id
        )
        S3File.at(key).put_json({"timestamp": ts, "name": name})

    def get_status(self) -> str:
        return self.status

    def compile_inputs(
        self,
        files: Sequence[InputFile],
        output_key: str,
        *,
        re_compile: bool = False,
        legacy_keys: Sequence[str] | None = None,
        ocr_runner: OcrRunner | None = None,
    ) -> dict[str, Any]:
        """Compile OCR text from ``files`` into ``output_key`` as ``{"pages": [...]}``."""
        from pycorrai.model.compile_inputs import compile_inputs as run_compile_inputs

        return run_compile_inputs(
            files,
            output_key,
            re_compile=re_compile,
            legacy_keys=legacy_keys,
            ocr_runner=ocr_runner,
        )

    def compile_subject(
        self,
        param: str = "",
        *,
        re_compile_subject: bool = False,
        ocr_runner: OcrRunner | None = None,
    ) -> dict[str, Any]:
        """Compile subject InputFiles into ``subject/compiled_subject.json``."""
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot compile subject without id, school_id and user_id"
            )

        json_key = ObjectStore.assessment_subject_compile_key(
            self.school_id, self.user_id, self.id
        )
        legacy_keys = [
            ObjectStore.assessment_subject_compile_legacy_json_key(
                self.school_id, self.user_id, self.id
            ),
            ObjectStore.assessment_subject_compile_legacy_key(
                self.school_id, self.user_id, self.id
            ),
        ]
        return self.compile_inputs(
            self._subject_input_files(),
            json_key,
            re_compile=re_compile_subject or param == "re_compile_subject",
            legacy_keys=legacy_keys,
            ocr_runner=ocr_runner,
        )

    def _subject_input_files(self) -> list[InputFile]:
        """InputFile entries stored under the assessment ``subject/`` prefix."""
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        subject_prefix = ObjectStore.assessment_subject_files_prefix(
            self.school_id, self.user_id, self.id
        )
        files: list[InputFile] = []
        for file in self.list_file_models():
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if file.id is None or file.id == "":
                continue
            file_prefix = ObjectStore.assessment_file_prefix(
                self.school_id,
                self.user_id,
                self.id,
                file.id,
                file.role(),
                file.student_id(),
            )
            if file_prefix.startswith(subject_prefix):
                files.append(file)
        files.sort(key=lambda file: file.name)
        return files

    def unclassified_file_ids(self) -> list[str]:
        """File ids under ``unclassified/`` that have ``attributes.json``."""
        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        store = ObjectStore.get_instance()
        prefix = ObjectStore.assessment_unclassified_files_prefix(
            self.school_id, self.user_id, self.id
        )
        ids: list[str] = []
        for file_id in store.list_child_prefixes(prefix):
            attr_key = prefix + file_id + "/" + ObjectStore.ATTR_FILE
            if store.exists(attr_key):
                ids.append(file_id)
        return ids

    def _unclassified_input_files(self) -> list[InputFile]:
        """InputFile entries stored under the assessment ``unclassified/`` prefix."""
        files: list[InputFile] = []
        for file_id in self.unclassified_file_ids():
            try:
                file = self.get_file(file_id)
            except Exception:
                logger.exception(
                    "Failed to load unclassified file %s for assessment %s",
                    file_id,
                    self.id,
                )
                continue
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if file.id is None or file.id == "":
                continue
            files.append(file)
        files.sort(key=lambda file: file.name)
        return files

    def google_vision_ocr_unclassified(
        self,
        force_re_ocr: bool = False,
        *,
        ocr_runner: OcrRunner | None = None,
    ) -> None:
        """Run Google Vision OCR on every unclassified copy of this assessment.

        When ``force_re_ocr`` is false, files that already have ``ocr_result.json``
        are skipped (handled by ``google_vision_ocr``). Intended to be launched
        via a Redis Python-queue ticket.
        """
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot OCR unclassified files without id, school_id and user_id"
            )

        files = self._unclassified_input_files()
        if not files:
            logger.info(
                "No unclassified files to OCR for assessment %s", self.id
            )
            return

        if ocr_runner is not None:
            ocr_runner(files, force_re_ocr=force_re_ocr)
            return

        from pycorrai.ocr.google_vision_ocr import google_vision_ocr

        google_vision_ocr(files, force_re_ocr=force_re_ocr)

    def enqueue_google_vision_ocr_unclassified(
        self, redis: Any | None = None
    ) -> None:
        """Push a ``google_vision_ocr_unclassified`` ticket on the Python Redis queue."""
        if self.id is None or self.id == "":
            raise RuntimeError(
                "Cannot enqueue unclassified OCR without assessment id"
            )
        from pycorrai.consumer import enqueue_assessment_task

        enqueue_assessment_task(
            self.id, "google_vision_ocr_unclassified", redis=redis
        )

    def create_correction_grid(
        self,
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishAssessment | None = None,
    ) -> dict[str, Any]:
        """Build ``correction_grid.json`` from instructions.md and compiled pages.

        Publishes Nchan ``Grid creation`` / ``Grid created`` with a loading flag,
        calls Claude Sonnet (same schema as PHP AssTaskGridCreation), stores the
        JSON under the assessment subject prefix, and appends a timed cost event.
        """
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot create correction grid without id, school_id and user_id"
            )

        publish_fn = publish or publish_assessment_loading
        started_at = time.perf_counter()
        self.status = STATUS_GRID_CREATION
        self.save()
        publish_fn(
            self.id,
            status=STATUS_GRID_CREATION,
            status_label=LABEL_GRID_CREATION,
            loading=True,
        )

        try:
            compiled = self.compile_subject(re_compile_subject=False)
            pages = compiled.get("pages") if isinstance(compiled, dict) else None
            if not isinstance(pages, list):
                raise RuntimeError("Compiled subject pages are missing")
            page_texts = [str(page) if page is not None else "" for page in pages]

            instructions = self._instructions_md_text()
            if client_factory is not None:
                client = client_factory()
            else:
                from pycorrai.clients.openrouter.claude_sonnet_client import (
                    ClaudeSonnetClient,
                )

                client = ClaudeSonnetClient()
            client.set_system_content(CORRECTION_GRID_SYSTEM_PROMPT)
            client.set_json_response("correction_grid", correction_grid_schema())
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
                raise RuntimeError("Correction grid reply is not a JSON object")

            body = (
                json.dumps(
                    decoded,
                    ensure_ascii=False,
                    indent=2,
                    separators=(",", ": "),
                )
                + "\n"
            )
            grid_key = ObjectStore.assessment_subject_correction_grid_key(
                self.school_id, self.user_id, self.id
            )
            ObjectStore.get_instance().put_contents(
                grid_key, body, "application/json"
            )

            duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
            self.status = STATUS_GRID_CREATED
            self.save()
            self.append_event(
                f"Grid creation ({duration_ms} ms, {cost:.4f} USD)"
            )
            publish_fn(
                self.id,
                status=STATUS_GRID_CREATED,
                status_label=LABEL_GRID_CREATED,
                loading=False,
            )
            return decoded
        except Exception:
            try:
                publish_fn(
                    self.id,
                    status=self.status or STATUS_GRID_CREATION,
                    status_label=LABEL_GRID_CREATION,
                    loading=False,
                )
            except Exception:
                logger.exception(
                    "Failed to publish grid-creation failure for %s", self.id
                )
            raise

    def enqueue_create_correction_grid(self, redis: Any | None = None) -> None:
        """Push a ``create_correction_grid`` ticket on the Python Redis queue."""
        if self.id is None or self.id == "":
            raise RuntimeError("Cannot enqueue correction grid without assessment id")
        from pycorrai.consumer import enqueue_assessment_task

        enqueue_assessment_task(
            self.id, "create_correction_grid", redis=redis
        )

    def create_strict_correction_grid(
        self,
        *,
        client_factory: ClientFactory | None = None,
        publish: PublishAssessment | None = None,
    ) -> dict[str, Any]:
        """Build ``strict_correction_grid.json`` as a binary reading grid.

        Same pipeline as ``create_correction_grid``, but the LLM returns only
        self-contained yes/no criteria with short unique ids.
        """
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot create strict correction grid without id, "
                "school_id and user_id"
            )

        publish_fn = publish or publish_assessment_loading
        started_at = time.perf_counter()
        self.status = STATUS_STRICT_GRID_CREATION
        self.save()
        publish_fn(
            self.id,
            status=STATUS_STRICT_GRID_CREATION,
            status_label=LABEL_STRICT_GRID_CREATION,
            loading=True,
        )

        try:
            compiled = self.compile_subject(re_compile_subject=False)
            pages = compiled.get("pages") if isinstance(compiled, dict) else None
            if not isinstance(pages, list):
                raise RuntimeError("Compiled subject pages are missing")
            page_texts = [str(page) if page is not None else "" for page in pages]

            instructions = self._instructions_md_text()
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
                self.school_id, self.user_id, self.id
            )
            ObjectStore.get_instance().put_contents(
                grid_key, body, "application/json"
            )

            duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
            self.status = STATUS_STRICT_GRID_CREATED
            self.save()
            self.append_event(
                f"Strict grid creation ({duration_ms} ms, {cost:.4f} USD)"
            )
            publish_fn(
                self.id,
                status=STATUS_STRICT_GRID_CREATED,
                status_label=LABEL_STRICT_GRID_CREATED,
                loading=False,
            )
            return decoded
        except Exception:
            try:
                publish_fn(
                    self.id,
                    status=self.status or STATUS_STRICT_GRID_CREATION,
                    status_label=LABEL_STRICT_GRID_CREATION,
                    loading=False,
                )
            except Exception:
                logger.exception(
                    "Failed to publish strict-grid-creation failure for %s",
                    self.id,
                )
            raise

    def enqueue_create_strict_correction_grid(
        self, redis: Any | None = None
    ) -> None:
        """Push a ``create_strict_correction_grid`` ticket on the Python Redis queue."""
        if self.id is None or self.id == "":
            raise RuntimeError(
                "Cannot enqueue strict correction grid without assessment id"
            )
        from pycorrai.consumer import enqueue_assessment_task

        enqueue_assessment_task(
            self.id, "create_strict_correction_grid", redis=redis
        )

    def correct_students(
        self,
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
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot correct students without id, school_id and user_id"
            )
        make_client = self._llm_client_factory(client_llm)

        store = ObjectStore.get_instance()
        grid_key = ObjectStore.assessment_subject_strict_correction_grid_key(
            self.school_id, self.user_id, self.id
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
            self.school_id, self.user_id, self.id
        )
        if not store.exists(subject_key):
            raise RuntimeError("Compiled subject is missing")
        compiled_subject = store.get_contents(subject_key)

        instructions = self._instructions_md_text()
        language_name = LOCALE_NAMES.get(
            self.normalize_locale(self.correction_language), "French"
        )
        publish_fn = publish or publish_student_loading

        return asyncio.run(
            self._correct_students_async(
                list(students),
                make_client,
                publish_fn,
                instructions,
                correction_grid,
                compiled_subject,
                language_name,
            )
        )

    @staticmethod
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
        self,
        students: list[Student],
        make_client: ClientFactory,
        publish: PublishStudent,
        instructions: str,
        correction_grid: dict[str, Any],
        compiled_subject: str,
        language_name: str,
    ) -> dict[str, float | None]:
        tasks = [
            self._correct_one_student(
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
        self,
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
            self._correct_one_student_sync,
            student,
            make_client,
            publish,
            instructions,
            correction_grid,
            compiled_subject,
            language_name,
        )

    def _correct_one_student_sync(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        instructions: str,
        correction_grid: dict[str, Any],
        compiled_subject: str,
        language_name: str,
    ) -> tuple[str, float | None]:
        student_id = str(student.id or "")
        assessment_id = str(self.id or "")
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
                    self._compiled_subject_pages(compiled_subject)
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

    def jev_correct_students(
        self,
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
        if self.id is None or self.id == "" or self.school_id == "" or self.user_id == "":
            raise RuntimeError(
                "Cannot Jev-correct students without id, school_id and user_id"
            )

        store = ObjectStore.get_instance()
        grid_key = ObjectStore.assessment_subject_correction_grid_key(
            self.school_id, self.user_id, self.id
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
            self._jev_correct_students_async(
                list(students),
                make_client,
                publish_fn,
                correction_grid,
            )
        )

    async def _jev_correct_students_async(
        self,
        students: list[Student],
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> dict[str, float | None]:
        tasks = [
            self._jev_correct_one_student(
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
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> tuple[str, float | None]:
        student_id = str(student.id or "")
        if student_id == "":
            raise RuntimeError("Student has no id")

        return await asyncio.to_thread(
            self._jev_correct_one_student_sync,
            student,
            make_client,
            publish,
            correction_grid,
        )

    def _jev_correct_one_student_sync(
        self,
        student: Student,
        make_client: ClientFactory,
        publish: PublishStudent,
        correction_grid: dict[str, Any],
    ) -> tuple[str, float | None]:
        student_id = str(student.id or "")
        assessment_id = str(self.id or "")
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

    @staticmethod
    def _compiled_subject_pages(compiled_subject: str) -> list[str]:
        """Extract page texts from compiled_subject JSON or fall back to raw text."""
        try:
            decoded = json.loads(compiled_subject)
        except json.JSONDecodeError:
            text = compiled_subject.strip()
            return [text] if text != "" else [""]
        if isinstance(decoded, dict):
            pages = decoded.get("pages")
            if isinstance(pages, list):
                return [str(page) if page is not None else "" for page in pages]
        if isinstance(decoded, list):
            return [str(page) if page is not None else "" for page in decoded]
        text = compiled_subject.strip()
        return [text] if text != "" else [""]

    def _instructions_md_text(self) -> str:
        """Return the S3 contents of ``instructions.md`` when present."""
        store = ObjectStore.get_instance()
        preferred: InputFile | None = None
        fallback: InputFile | None = None
        for file in self.list_file_models():
            if isinstance(file, S3File) or not isinstance(file, InputFile):
                continue
            if not isinstance(file, InstructionFile) and file.role() != "instructions":
                continue
            if file.name.lower() == "instructions.md":
                preferred = file
                break
            if fallback is None:
                fallback = file
        target = preferred or fallback
        if target is None:
            return ""
        try:
            return store.get_contents(target.content_key())
        except Exception:
            logger.exception(
                "Unreadable instructions file %s for assessment %s",
                target.id,
                self.id,
            )
            return ""

    def _blob_file_ids(self) -> list[str]:

        if not self.id or self.school_id == "" or self.user_id == "":
            return []
        prefix = ObjectStore.assessment_blobs_prefix(
            self.school_id, self.user_id, self.id
        )
        return ObjectStore.get_instance().list_immediate_files(prefix)

    def _stored_file_ids(self) -> list[str]:
        store = ObjectStore.get_instance()
        ids: dict[str, bool] = {}
        areas = [
            ObjectStore.assessment_subject_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
            ObjectStore.assessment_unclassified_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
            ObjectStore.assessment_files_prefix(
                self.school_id, self.user_id, self.id or ""
            ),
        ]
        for prefix in areas:
            for file_id in store.list_child_prefixes(prefix):
                ids[file_id] = True

        students = ObjectStore.assessment_students_prefix(
            self.school_id, self.user_id, self.id or ""
        )
        for student_id in store.list_child_prefixes(students):
            student_prefix = students + student_id + "/"
            for file_id in store.list_child_prefixes(student_prefix):
                ids[file_id] = True
        return list(ids.keys())

    def _invalidate_metadata_cache(self) -> None:
        if (
            self.id is None
            or self.id == ""
            or self.school_id == ""
            or self.user_id == ""
        ):
            return
        try:
            invalidate_assessment(
                make_redis_client(), self.school_id, self.user_id, self.id
            )
        except Exception:
            logger.exception("MetadataCache invalidate (Assessment) failed")
