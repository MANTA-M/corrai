"""JSON schema for mathematics student-correction LLM replies."""

from __future__ import annotations

from typing import Any


def math_correction_schema() -> dict[str, Any]:
    """JSON schema for math student correction (retained percentage per question)."""
    question = {
        "type": "object",
        "additionalProperties": False,
        "required": [
            "titre",
            "pourcentage_retenu",
            "coefficient_de_confiance",
            "remarques",
        ],
        "properties": {
            "titre": {
                "type": "string",
                "description": (
                    "Titre de la question, identique à la grille de lecture."
                ),
            },
            "pourcentage_retenu": {
                "type": "number",
                "minimum": 0,
                "maximum": 1,
                "description": (
                    "Fraction du barème retenue pour la question "
                    "(0 = rien, 1 = totalité)."
                ),
            },
            "coefficient_de_confiance": {
                "type": "number",
                "description": (
                    "Coefficient de confiance quant à l'évaluation "
                    "de cette question."
                ),
            },
            "remarques": {
                "type": "array",
                "description": (
                    "Remarques de correction pour la question "
                    "(erreurs, manques, points positifs)."
                ),
                "items": {"type": "string"},
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
                            "items": question,
                        },
                    },
                },
            },
        },
    }
