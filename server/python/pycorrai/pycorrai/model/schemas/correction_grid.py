"""JSON schemas for correction-grid LLM replies."""

from __future__ import annotations

from typing import Any


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


def math_correction_grid_schema() -> dict[str, Any]:
    """JSON schema for mathematics correction-grid replies."""
    bareme_step = {
        "type": "object",
        "additionalProperties": False,
        "required": ["description", "points"],
        "properties": {
            "description": {
                "type": "string",
                "description": (
                    "Étape de résolution valorisée par le barème "
                    "(méthode, calcul intermédiaire, conclusion, etc.)."
                ),
            },
            "points": {
                "type": "number",
                "minimum": 0.1,
                "description": (
                    "Points attribués pour cette étape. Minimum 1/10 (0,1)."
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
                "description": "Parties du sujet de mathématiques.",
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
                                "required": [
                                    "titre",
                                    "points",
                                    "barème",
                                    "détails_à_vérifier",
                                    "résultat_attendu",
                                ],
                                "properties": {
                                    "titre": {"type": "string"},
                                    "points": {
                                        "type": "number",
                                        "description": (
                                            "Barème total de la question ; "
                                            "égal à la somme des points "
                                            "du barème détaillé."
                                        ),
                                    },
                                    "barème": {
                                        "type": "array",
                                        "description": (
                                            "Répartition des points par "
                                            "étape de résolution."
                                        ),
                                        "items": bareme_step,
                                    },
                                    "détails_à_vérifier": {
                                        "type": "array",
                                        "description": (
                                            "Éléments de méthode et "
                                            "justifications à contrôler "
                                            "dans la copie."
                                        ),
                                        "items": {"type": "string"},
                                    },
                                    "résultat_attendu": {
                                        "type": "string",
                                        "description": (
                                            "Résultat ou expression finale "
                                            "attendue pour la question."
                                        ),
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
