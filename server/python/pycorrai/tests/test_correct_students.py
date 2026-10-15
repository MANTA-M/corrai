"""Unit tests for Assessment.correct_students."""

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
    apply_retained_criterion_points,
    strip_points_from_correction_grid,
)
from pycorrai.model.student import Student
from pycorrai.store.object_store import ObjectStore


SAMPLE_GRID = {
    "parties": [
        {
            "titre": "Partie A",
            "questions": [
                {
                    "titre": "Question 1",
                    "points": 3,
                    "critères": [
                        {"id": "A1-01", "description": "Critère 1", "point": 1},
                        {"id": "A1-02", "description": "Critère 2", "point": 2},
                    ],
                }
            ],
        }
    ]
}


class CorrectStudentsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assessment = Assessment()
        self.assessment.id = "a"
        self.assessment.school_id = "s"
        self.assessment.user_id = "t"
        self.assessment.correction_language = "fr"
        self.grid_key = ObjectStore.assessment_subject_strict_correction_grid_key(
            "s", "t", "a"
        )
        self.subject_key = ObjectStore.assessment_subject_compile_key("s", "t", "a")
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
                                    "critères": [
                                        {
                                            "id": "A1-01",
                                            "retenu": True,
                                            "confiance": 0.9,
                                            "page_debut": 1,
                                            "index_debut": 0,
                                            "page_fin": 1,
                                            "index_fin": 12,
                                        },
                                        {
                                            "id": "A1-02",
                                            "retenu": False,
                                            "confiance": 0.8,
                                        },
                                    ],
                                }
                            ],
                        }
                    ]
                }
            ),
            0.0042,
        )

        self.student = Student()
        self.student.id = "stu"
        self.student.school_id = "s"
        self.student.user_id = "t"
        self.student.assessment_id = "a"
        self.student.name = "Alice"

    def test_rejects_invalid_client(self) -> None:
        with self.assertRaises(RuntimeError) as ctx:
            self.assessment.correct_students(
                [self.student],
                object(),
                publish=self.publish,
            )
        self.assertIn("client_llm must be an LLM client", str(ctx.exception))

    def test_rejects_missing_correction_grid(self) -> None:
        store = MagicMock()
        store.exists.return_value = False
        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ):
            with self.assertRaises(RuntimeError) as ctx:
                self.assessment.correct_students(
                    [self.student],
                    lambda: self.client,
                    publish=self.publish,
                )
        self.assertIn("Strict correction grid is missing", str(ctx.exception))

    def test_strip_points_from_correction_grid(self) -> None:
        cleaned = strip_points_from_correction_grid(SAMPLE_GRID)
        criterion = cleaned["parties"][0]["questions"][0]["critères"][0]
        self.assertEqual(criterion["id"], "A1-01")
        self.assertNotIn("point", criterion)
        self.assertEqual(
            SAMPLE_GRID["parties"][0]["questions"][0]["critères"][0]["point"], 1
        )

    def test_apply_retained_criterion_points(self) -> None:
        correction = {
            "parties": [
                {
                    "titre": "Partie A",
                    "questions": [
                        {
                            "titre": "Question 1",
                            "critères": [
                                {"id": "A1-01", "retenu": True, "confiance": 1.0},
                                {"id": "A1-02", "retenu": True, "confiance": 0.5},
                            ],
                        }
                    ],
                }
            ]
        }
        mark = apply_retained_criterion_points(correction, SAMPLE_GRID)
        self.assertEqual(mark, 3.0)
        self.assertEqual(correction["mark"], 3.0)
        self.assertEqual(
            correction["parties"][0]["questions"][0]["points_obtenus"], 3.0
        )

    def test_corrects_students_and_returns_marks(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key in {
            self.grid_key,
            self.subject_key,
        }
        store.get_contents.side_effect = lambda key: {
            self.grid_key: json.dumps(SAMPLE_GRID),
            self.subject_key: json.dumps({"pages": ["sujet page"]}),
        }[key]
        submission = MagicMock()
        submission.status = "transcribed"
        submission.save_attributes = MagicMock()
        submission.append_event = MagicMock()

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ), patch.object(
            self.assessment, "_instructions_md_text", return_value="Lire le sujet"
        ), patch.object(
            self.student,
            "compile_submission",
            return_value={"pages": ["copie page"]},
        ), patch.object(
            self.student, "save"
        ) as save, patch.object(
            self.student, "append_event"
        ) as append_event, patch.object(
            self.student, "get_submissions", return_value=[submission]
        ):
            result = self.assessment.correct_students(
                [self.student],
                lambda: self.client,
                publish=self.publish,
            )

        self.assertEqual(result, {"stu": 1.0})
        self.assertEqual(self.student.status, STATUS_GRADED)
        self.assertEqual(self.student.mark, 1.0)
        self.assertEqual(save.call_count, 2)
        append_event.assert_called_once()
        self.assertIn("Correction (", append_event.call_args.args[0])
        self.assertIn("0.0042 USD", append_event.call_args.args[0])
        self.assertEqual(submission.status, "corrected")
        submission.append_event.assert_called_once_with("Correction written")
        store.put_contents.assert_called_once()
        self.assertTrue(
            store.put_contents.call_args.args[0].endswith("correction.json")
        )
        saved = json.loads(store.put_contents.call_args.args[1])
        self.assertEqual(saved["mark"], 1.0)
        self.assertEqual(
            saved["parties"][0]["questions"][0]["points_obtenus"], 1.0
        )

        system_prompt = self.client.set_system_content.call_args.args[0]
        self.assertIn("A1-01", system_prompt)
        self.assertNotIn('"point"', system_prompt)

        first = self.publish.call_args_list[0]
        self.assertEqual(first.args[0], "a")
        self.assertEqual(first.args[1], "stu")
        self.assertEqual(first.kwargs["status"], STATUS_UNDER_CORRECTION)
        self.assertEqual(first.kwargs["status_label"], LABEL_UNDER_CORRECTION)
        self.assertTrue(first.kwargs["loading"])

        last = self.publish.call_args_list[-1]
        self.assertEqual(last.kwargs["status"], STATUS_GRADED)
        self.assertEqual(last.kwargs["status_label"], LABEL_CORRECTED)
        self.assertFalse(last.kwargs["loading"])
        self.assertEqual(last.kwargs["mark"], 1.0)

        texts = [call.args[0] for call in self.client.add_text.call_args_list]
        self.assertEqual(len(texts), 2)
        self.assertIn("sujet page", texts[0])
        self.assertIn("copie page", texts[1])

    def test_error_sets_status_and_publishes_loading_false(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key in {
            self.grid_key,
            self.subject_key,
        }
        store.get_contents.side_effect = lambda key: {
            self.grid_key: json.dumps(SAMPLE_GRID),
            self.subject_key: json.dumps({"pages": ["sujet"]}),
        }[key]
        failing = MagicMock()
        failing.call_text_with_cost.side_effect = RuntimeError("boom")

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ), patch.object(
            self.assessment, "_instructions_md_text", return_value=""
        ), patch.object(
            self.student,
            "compile_submission",
            return_value={"pages": ["copie"]},
        ), patch.object(
            self.student, "save"
        ), patch.object(
            self.student, "append_event"
        ) as append_event, patch.object(
            self.student, "get_submissions", return_value=[]
        ):
            result = self.assessment.correct_students(
                [self.student],
                lambda: failing,
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
