"""JSON schema for binary reading-grid student correction replies."""

from __future__ import annotations

from typing import Any


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
