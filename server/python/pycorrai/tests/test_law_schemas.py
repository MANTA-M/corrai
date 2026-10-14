"""Unit tests for LawFrance correction-grid and correction JSON schemas."""

from __future__ import annotations

import unittest

from pycorrai.model.assessment import (
    CORRECTION_GRID_SYSTEM_PROMPT,
    correction_grid_schema,
    law_correction_schema,
    law_correction_system_prompt,
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

    def test_law_correction_schema_uses_max_and_justification(self) -> None:
        schema = law_correction_schema("français")
        question = schema["properties"]["parties"]["items"]["properties"][
            "questions"
        ]["items"]
        self.assertIn("critères", question["required"])
        criterion = question["properties"]["critères"]["items"]
        self.assertEqual(
            criterion["required"],
            ["description", "max", "note", "score_de_confiance", "justification"],
        )
        self.assertEqual(criterion["properties"]["max"]["type"], "number")
        self.assertEqual(criterion["properties"]["justification"]["type"], "string")
        self.assertNotIn("ponderation", criterion["properties"])
        self.assertNotIn("commentaire", criterion["properties"])

        prompt = law_correction_system_prompt("instr", "grid", "français")
        self.assertIn("son max", prompt)
        self.assertIn("justification", prompt)
        self.assertNotIn("pondération", prompt)


if __name__ == "__main__":
    unittest.main()
