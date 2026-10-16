"""System prompt for binary reading-grid student correction."""

from __future__ import annotations


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
