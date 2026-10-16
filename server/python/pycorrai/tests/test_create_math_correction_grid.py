"""Unit tests for Assessment.create_math_correction_grid."""

from __future__ import annotations

import json
import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import (
    LABEL_MATH_GRID_CREATED,
    LABEL_MATH_GRID_CREATION,
    STATUS_MATH_GRID_CREATED,
    STATUS_MATH_GRID_CREATION,
    Assessment,
)
from pycorrai.model.instruction_file import InstructionFile
from pycorrai.store.object_store import ObjectStore


class CreateMathCorrectionGridTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assessment = Assessment()
        self.assessment.id = "a"
        self.assessment.school_id = "s"
        self.assessment.user_id = "t"
        self.grid_key = ObjectStore.assessment_subject_math_correction_grid_key(
            "s", "t", "a"
        )
        self.publish = MagicMock()
        self.client = MagicMock()
        self.client.call_text_with_cost.return_value = (
            json.dumps(
                {
                    "parties": [
                        {
                            "titre": "Exercice 1",
                            "questions": [
                                {
                                    "titre": "1.a",
                                    "points": 2,
                                    "barème": [
                                        {
                                            "description": "Dérivée correcte",
                                            "points": 1,
                                        },
                                        {
                                            "description": "Résultat final",
                                            "points": 1,
                                        },
                                    ],
                                    "détails_à_vérifier": [
                                        "Domaine de définition",
                                    ],
                                    "résultat_attendu": "f'(x) = 2x",
                                }
                            ],
                        }
                    ]
                }
            ),
            0.0123,
        )

    def test_builds_grid_from_instructions_then_pages(self) -> None:
        store = MagicMock()
        instruction = InstructionFile()
        instruction.id = "i1"
        instruction.school_id = "s"
        instruction.user_id = "t"
        instruction.assessment_id = "a"
        instruction.name = "instructions.md"

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ), patch.object(
            self.assessment,
            "compile_subject",
            return_value={"pages": ["page one", "page two"]},
        ) as compile_subject, patch.object(
            self.assessment, "save"
        ) as save, patch.object(
            self.assessment, "append_event"
        ) as append_event, patch.object(
            self.assessment,
            "list_file_models",
            return_value=[instruction],
        ):
            store.get_contents.return_value = "Valoriser la méthode."
            result = self.assessment.create_math_correction_grid(
                client_factory=lambda: self.client,
                publish=self.publish,
            )

        compile_subject.assert_called_once_with(re_compile_subject=False)
        self.client.set_system_content.assert_called_once()
        self.client.set_json_response.assert_called_once()
        name, schema = self.client.set_json_response.call_args.args
        self.assertEqual(name, "math_correction_grid")
        question = schema["properties"]["parties"]["items"]["properties"][
            "questions"
        ]["items"]
        self.assertEqual(
            question["required"],
            [
                "titre",
                "points",
                "barème",
                "détails_à_vérifier",
                "résultat_attendu",
            ],
        )
        texts = [call.args[0] for call in self.client.add_text.call_args_list]
        self.assertEqual(len(texts), 2)
        self.assertIn("Valoriser la méthode.", texts[0])
        self.assertIn("Page 1:\npage one", texts[1])
        self.assertIn("Page 2:\npage two", texts[1])
        store.put_contents.assert_called_once()
        self.assertEqual(store.put_contents.call_args.args[0], self.grid_key)
        self.assertEqual(result["parties"][0]["titre"], "Exercice 1")
        self.assertEqual(
            result["parties"][0]["questions"][0]["résultat_attendu"],
            "f'(x) = 2x",
        )
        self.assertEqual(self.assessment.status, STATUS_MATH_GRID_CREATED)
        self.assertEqual(save.call_count, 2)
        append_event.assert_called_once()
        self.assertIn("Math grid creation (", append_event.call_args.args[0])
        self.assertIn("0.0123 USD", append_event.call_args.args[0])
        self.assertEqual(
            self.publish.call_args_list[0].kwargs["status_label"],
            LABEL_MATH_GRID_CREATION,
        )
        self.assertTrue(self.publish.call_args_list[0].kwargs["loading"])
        self.assertEqual(
            self.publish.call_args_list[-1].kwargs["status_label"],
            LABEL_MATH_GRID_CREATED,
        )
        self.assertFalse(self.publish.call_args_list[-1].kwargs["loading"])

    def test_publishes_loading_false_on_error(self) -> None:
        with patch.object(self.assessment, "save"), patch.object(
            self.assessment,
            "compile_subject",
            side_effect=RuntimeError("boom"),
        ):
            with self.assertRaises(RuntimeError):
                self.assessment.create_math_correction_grid(
                    client_factory=lambda: self.client,
                    publish=self.publish,
                )

        self.assertEqual(self.assessment.status, STATUS_MATH_GRID_CREATION)
        self.assertFalse(self.publish.call_args_list[-1].kwargs["loading"])

    def test_enqueue_pushes_python_ticket(self) -> None:
        redis = MagicMock()
        with patch(
            "pycorrai.consumer.enqueue_assessment_task"
        ) as enqueue:
            self.assessment.enqueue_create_math_correction_grid(redis=redis)
        enqueue.assert_called_once_with(
            "a", "create_math_correction_grid", redis=redis
        )


if __name__ == "__main__":
    unittest.main()
