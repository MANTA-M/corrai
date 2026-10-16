"""Unit tests for Assessment.math_correct_students."""

from __future__ import annotations

import json
import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import (
    LABEL_CORRECTED,
    LABEL_STUDENT_ERROR,
    LABEL_UNDER_CORRECTION,
    STATUS_GRADED,
    STATUS_STUDENT_ERROR,
    STATUS_UNDER_CORRECTION,
    Assessment,
    apply_math_retained_percentages,
    strip_question_points_from_math_grid,
)
from pycorrai.model.student import Student
from pycorrai.store.object_store import ObjectStore


SAMPLE_MATH_GRID = {
    "parties": [
        {
            "titre": "Partie A",
            "questions": [
                {
                    "titre": "Question 1",
                    "points": 4,
                    "barème": [
                        {"description": "Méthode", "points": 2},
                        {"description": "Résultat", "points": 2},
                    ],
                    "détails_à_vérifier": ["Unités"],
                    "résultat_attendu": "42",
                },
                {
                    "titre": "Question 2",
                    "points": 3,
                    "barème": [
                        {"description": "Calcul", "points": 3},
                    ],
                    "détails_à_vérifier": [],
                    "résultat_attendu": "7",
                },
            ],
        }
    ]
}


class MathCorrectStudentsTest(unittest.TestCase):
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
                            "titre": "Partie A",
                            "questions": [
                                {
                                    "titre": "Question 1",
                                    "pourcentage_retenu": 0.5,
                                    "coefficient_de_confiance": 0.9,
                                    "remarques": ["Résultat partiel"],
                                },
                                {
                                    "titre": "Question 2",
                                    "pourcentage_retenu": 1.0,
                                    "coefficient_de_confiance": 0.8,
                                    "remarques": [],
                                },
                            ],
                        }
                    ]
                }
            ),
            0.0123,
        )

        self.student = Student()
        self.student.id = "stu"
        self.student.school_id = "s"
        self.student.user_id = "t"
        self.student.assessment_id = "a"
        self.student.name = "Alice"

        self.submission = MagicMock()
        self.submission.name = "copie.png"
        self.submission.content_key.return_value = "s3/copie.png"
        self.submission.status = "transcribed"
        self.submission.save_attributes = MagicMock()
        self.submission.append_event = MagicMock()

    def test_rejects_missing_math_correction_grid(self) -> None:
        store = MagicMock()
        store.exists.return_value = False
        with patch(
            "pycorrai.model.services.math_correct_students.ObjectStore.get_instance",
            return_value=store,
        ):
            with self.assertRaises(RuntimeError) as ctx:
                self.assessment.math_correct_students(
                    [self.student],
                    lambda: self.client,
                    "google/gemini-3.8-flash",
                    publish=self.publish,
                )
        self.assertIn("Math correction grid is missing", str(ctx.exception))

    def test_rejects_empty_model_name(self) -> None:
        with self.assertRaises(RuntimeError) as ctx:
            self.assessment.math_correct_students(
                [self.student],
                lambda: self.client,
                "  ",
                publish=self.publish,
            )
        self.assertIn("model_name is required", str(ctx.exception))

    def test_strip_question_points_keeps_bareme_points(self) -> None:
        cleaned = strip_question_points_from_math_grid(SAMPLE_MATH_GRID)
        question = cleaned["parties"][0]["questions"][0]
        self.assertNotIn("points", question)
        self.assertEqual(question["barème"][0]["points"], 2)
        self.assertEqual(
            SAMPLE_MATH_GRID["parties"][0]["questions"][0]["points"], 4
        )

    def test_apply_math_retained_percentages_rounds_to_tenth(self) -> None:
        correction = {
            "parties": [
                {
                    "titre": "Partie A",
                    "questions": [
                        {
                            "titre": "Question 1",
                            "pourcentage_retenu": 0.33,
                            "coefficient_de_confiance": 0.5,
                            "remarques": [],
                        },
                        {
                            "titre": "Question 2",
                            "pourcentage_retenu": 1.0,
                            "coefficient_de_confiance": 1.0,
                            "remarques": [],
                        },
                    ],
                }
            ]
        }
        # 4 * 0.33 = 1.32 -> 1.3 ; + 3.0 = 4.3
        mark = apply_math_retained_percentages(correction, SAMPLE_MATH_GRID)
        self.assertEqual(mark, 4.3)
        self.assertEqual(correction["mark"], 4.3)
        self.assertEqual(correction["note"], 4.3)
        self.assertEqual(correction["parties"][0]["questions"][0]["points"], 1.3)
        self.assertEqual(correction["parties"][0]["questions"][1]["points"], 3.0)

    def test_corrects_students_with_images_and_model_filename(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.grid_key
        store.get_contents.return_value = json.dumps(SAMPLE_MATH_GRID)
        store.download_to_temp.return_value = "/tmp/copie.png"

        with patch(
            "pycorrai.model.services.math_correct_students.ObjectStore.get_instance",
            return_value=store,
        ), patch(
            "pycorrai.model.services.math_correct_students.os.unlink"
        ), patch.object(
            self.student, "get_submissions", return_value=[self.submission]
        ), patch.object(
            self.student, "save"
        ) as save, patch.object(
            self.student, "append_event"
        ) as append_event:
            result = self.assessment.math_correct_students(
                [self.student],
                lambda: self.client,
                "google/gemini-3.8-flash",
                publish=self.publish,
            )

        # 4 * 0.5 + 3 * 1.0 = 5.0
        self.assertEqual(result, {"stu": 5.0})
        self.assertEqual(self.student.status, STATUS_GRADED)
        self.assertEqual(self.student.mark, 5.0)
        self.assertEqual(save.call_count, 2)
        append_event.assert_called_once()
        self.assertIn("Correction (", append_event.call_args.args[0])
        self.assertIn("0.0123 USD", append_event.call_args.args[0])

        store.download_to_temp.assert_called_once_with("s3/copie.png")
        self.client.add_file.assert_called_once_with("/tmp/copie.png", "copie.png")
        store.put_contents.assert_called_once()
        self.assertTrue(
            store.put_contents.call_args.args[0].endswith(
                "correction_google_gemini-3.8-flash.json"
            )
        )
        saved = json.loads(store.put_contents.call_args.args[1])
        self.assertEqual(saved["mark"], 5.0)
        self.assertEqual(saved["parties"][0]["questions"][0]["points"], 2.0)

        system_prompt = self.client.set_system_content.call_args.args[0]
        self.assertIn("Question 1", system_prompt)
        self.assertIn('"barème"', system_prompt)
        # Question-level points stripped from system grid
        self.assertNotIn('"points": 4', system_prompt)
        self.assertIn('"points": 2', system_prompt)  # barème step kept

        first = self.publish.call_args_list[0]
        self.assertEqual(first.kwargs["status"], STATUS_UNDER_CORRECTION)
        self.assertEqual(first.kwargs["status_label"], LABEL_UNDER_CORRECTION)
        self.assertTrue(first.kwargs["loading"])

        last = self.publish.call_args_list[-1]
        self.assertEqual(last.kwargs["status"], STATUS_GRADED)
        self.assertEqual(last.kwargs["status_label"], LABEL_CORRECTED)
        self.assertFalse(last.kwargs["loading"])
        self.assertEqual(last.kwargs["mark"], 5.0)

        self.assertEqual(self.submission.status, "corrected")
        self.submission.append_event.assert_called_once_with("Correction written")

    def test_error_sets_status_and_publishes_loading_false(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.grid_key
        store.get_contents.return_value = json.dumps(SAMPLE_MATH_GRID)
        store.download_to_temp.return_value = "/tmp/copie.png"
        failing = MagicMock()
        failing.call_text_with_cost.side_effect = RuntimeError("boom")

        with patch(
            "pycorrai.model.services.math_correct_students.ObjectStore.get_instance",
            return_value=store,
        ), patch(
            "pycorrai.model.services.math_correct_students.os.unlink"
        ), patch.object(
            self.student, "get_submissions", return_value=[self.submission]
        ), patch.object(
            self.student, "save"
        ), patch.object(
            self.student, "append_event"
        ) as append_event:
            result = self.assessment.math_correct_students(
                [self.student],
                lambda: failing,
                "google/gemini-3.8-flash",
                publish=self.publish,
            )

        self.assertEqual(result, {"stu": None})
        self.assertEqual(self.student.status, STATUS_STUDENT_ERROR)
        self.assertIn("Correction failed", append_event.call_args.args[0])
        last = self.publish.call_args_list[-1]
        self.assertEqual(last.kwargs["status"], STATUS_STUDENT_ERROR)
        self.assertEqual(last.kwargs["status_label"], LABEL_STUDENT_ERROR)
        self.assertFalse(last.kwargs["loading"])


if __name__ == "__main__":
    unittest.main()
