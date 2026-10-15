"""Unit tests for LawFrance correction-grid and correction JSON schemas."""

from __future__ import annotations

import unittest

from pycorrai.model.assessment import (
    CORRECTION_GRID_SYSTEM_PROMPT,
    STRICT_CORRECTION_GRID_SYSTEM_PROMPT,
    correction_grid_schema,
    law_correction_schema,
    law_correction_system_prompt,
    strict_correction_grid_schema,
)


class LawSchemasTest(unittest.TestCase):
    def test_correction_grid_schema_includes_question_criteria(self) -> None:
        schema = correction_grid_schema()
        question = schema["properties"]["parties"]["items"]["properties"][
            "questions"
        ]["items"]
        self.assertIn("critère", question["required"])
        criterion_items = question["properties"]["critère"]["items"]
        self.assertEqual(criterion_items["required"], ["critère", "point"])
        self.assertEqual(criterion_items["properties"]["critère"]["type"], "string")
        self.assertEqual(criterion_items["properties"]["point"]["type"], "number")
        self.assertIn('tableau "critère"', CORRECTION_GRID_SYSTEM_PROMPT)

    def test_strict_correction_grid_schema_is_binary(self) -> None:
        schema = strict_correction_grid_schema()
        self.assertEqual(schema["required"], ["parties"])
        question = schema["properties"]["parties"]["items"]["properties"][
            "questions"
        ]["items"]
        self.assertEqual(question["required"], ["titre", "points", "critères"])
        criterion = question["properties"]["critères"]["items"]
        self.assertEqual(criterion["required"], ["id", "description", "point"])
        self.assertEqual(criterion["properties"]["point"]["minimum"], 0.1)
        self.assertIn(
            "Question fermée",
            criterion["properties"]["description"]["description"],
        )
        self.assertIn("critères binaires", STRICT_CORRECTION_GRID_SYSTEM_PROMPT)
        self.assertIn("identifiant court", STRICT_CORRECTION_GRID_SYSTEM_PROMPT)
        self.assertIn("au minimum 1/10", STRICT_CORRECTION_GRID_SYSTEM_PROMPT)
        self.assertIn(
            "doit égaler exactement ce barème",
            STRICT_CORRECTION_GRID_SYSTEM_PROMPT,
        )

    def test_law_correction_schema_uses_binary_criteria(self) -> None:
        schema = law_correction_schema("français")
        self.assertEqual(schema["required"], ["parties"])
        question = schema["properties"]["parties"]["items"]["properties"][
            "questions"
        ]["items"]
        self.assertEqual(question["required"], ["titre", "critères"])
        criterion = question["properties"]["critères"]["items"]
        self.assertEqual(
            criterion["required"],
            [
                "id",
                "retenu",
                "confiance",
                "page_debut",
                "index_debut",
                "page_fin",
                "index_fin",
            ],
        )
        self.assertEqual(criterion["properties"]["retenu"]["type"], "boolean")
        self.assertEqual(criterion["properties"]["confiance"]["minimum"], 0)
        self.assertEqual(criterion["properties"]["confiance"]["maximum"], 1)
        self.assertEqual(
            criterion["properties"]["page_debut"]["type"], ["integer", "null"]
        )
        self.assertEqual(
            criterion["properties"]["index_debut"]["type"], ["integer", "null"]
        )
        self.assertEqual(
            criterion["properties"]["page_fin"]["type"], ["integer", "null"]
        )
        self.assertEqual(
            criterion["properties"]["index_fin"]["type"], ["integer", "null"]
        )
        self.assertNotIn("point", criterion["properties"])
        self.assertNotIn("mark", schema["properties"])

        prompt = law_correction_system_prompt("instr", "grid", "français")
        self.assertIn("grille de lecture", prompt)
        self.assertIn("retenu", prompt)
        self.assertIn("confiance", prompt)
        self.assertIn("N'attribuez aucun point", prompt)
        self.assertIn("page_debut", prompt)


if __name__ == "__main__":
    unittest.main()
