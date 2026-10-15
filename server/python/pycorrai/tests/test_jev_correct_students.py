"""Unit tests for Assessment.jev_correct_students."""

from __future__ import annotations

import json
import unittest
from types import SimpleNamespace
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import (
    LABEL_CORRECTED,
    LABEL_STUDENT_ERROR,
    LABEL_UNDER_CORRECTION,
    STATUS_GRADED,
    STATUS_STUDENT_ERROR,
    STATUS_UNDER_CORRECTION,
    Assessment,
    build_correction_from_jev_answers,
    build_jev_noul_questions,
    iter_grid_criteria,
)
from pycorrai.model.student import Student
from pycorrai.store.object_store import ObjectStore


SAMPLE_STRICT_GRID = {
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

SAMPLE_CLASSIC_GRID = {
    "parties": [
        {
            "titre": "Partie A",
            "questions": [
                {
                    "titre": "Question 1",
                    "points": 3,
                    "critère": [
                        {"critère": "Critère 1", "point": 1},
                        {"critère": "Critère 2", "point": 2},
                    ],
                }
            ],
        }
    ]
}


class FakeJevClient:
    def __init__(self, answers: dict[str, float], cost: float = 0.0012) -> None:
        self.state = None
        self.questions: dict[str, dict] = {}
        self._answers = answers
        self._cost = cost

    def set_state(self, state: object) -> None:
        self.state = state

    def clear_questions(self) -> None:
        self.questions.clear()

    def add_noul(
        self,
        key: str,
        instructions: object,
        *,
        criteria_true: object = None,
        criteria_false: object = None,
        criteria: object = None,
    ) -> None:
        del criteria_true, criteria_false, criteria
        self.questions[key] = {"type": "noul", "instructions": instructions}

    def decide_with_cost(self) -> tuple[SimpleNamespace, float]:
        answers = {
            key: SimpleNamespace(noul=self._answers.get(key, 0.0), type="noul")
            for key in self.questions
        }
        return SimpleNamespace(answers=answers), self._cost


class JevCorrectStudentsHelpersTest(unittest.TestCase):
    def test_iter_grid_criteria_strict_and_classic(self) -> None:
        strict_ids = [row[4] for row in iter_grid_criteria(SAMPLE_STRICT_GRID)]
        self.assertEqual(strict_ids, ["A1-01", "A1-02"])

        classic = list(iter_grid_criteria(SAMPLE_CLASSIC_GRID))
        self.assertEqual([row[4] for row in classic], ["P0-Q0-C0", "P0-Q0-C1"])
        self.assertEqual(classic[0][5], "Critère 1")
        self.assertEqual(classic[1][6], 2.0)

    def test_build_jev_noul_questions(self) -> None:
        questions = build_jev_noul_questions(SAMPLE_STRICT_GRID)
        self.assertEqual(set(questions), {"A1-01", "A1-02"})
        self.assertEqual(questions["A1-01"]["type"], "noul")
        self.assertIn("Critère 1", questions["A1-01"]["instructions"])

    def test_build_correction_from_jev_answers(self) -> None:
        answers = {
            "A1-01": SimpleNamespace(noul=0.91),
            "A1-02": SimpleNamespace(noul=0.2),
        }
        correction = build_correction_from_jev_answers(SAMPLE_STRICT_GRID, answers)
        self.assertEqual(correction["mark"], 1.0)
        self.assertEqual(correction["note"], 1.0)
        criterion = correction["parties"][0]["questions"][0]["critères"]
        self.assertTrue(criterion[0]["retenu"])
        self.assertAlmostEqual(criterion[0]["confiance"], 0.91)
        self.assertFalse(criterion[1]["retenu"])
        self.assertEqual(correction["parties"][0]["questions"][0]["points"], 1.0)


class JevCorrectStudentsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assessment = Assessment()
        self.assessment.id = "a"
        self.assessment.school_id = "s"
        self.assessment.user_id = "t"
        self.grid_key = ObjectStore.assessment_subject_correction_grid_key(
            "s", "t", "a"
        )
        self.publish = MagicMock()
        self.student = Student()
        self.student.id = "stu"
        self.student.school_id = "s"
        self.student.user_id = "t"
        self.student.assessment_id = "a"
        self.student.name = "Alice"

    def test_rejects_missing_correction_grid(self) -> None:
        store = MagicMock()
        store.exists.return_value = False
        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ):
            with self.assertRaises(RuntimeError) as ctx:
                self.assessment.jev_correct_students(
                    [self.student],
                    client_factory=lambda: FakeJevClient({}),
                    publish=self.publish,
                )
        self.assertIn("Correction grid is missing", str(ctx.exception))

    def test_corrects_students_and_returns_marks(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.grid_key
        store.get_contents.return_value = json.dumps(SAMPLE_STRICT_GRID)
        submission = MagicMock()
        submission.status = "transcribed"
        submission.save_attributes = MagicMock()
        submission.append_event = MagicMock()
        client = FakeJevClient({"A1-01": 0.9, "A1-02": 0.1}, cost=0.0042)

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
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
            result = self.assessment.jev_correct_students(
                [self.student],
                client_factory=lambda: client,
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
        store.put_contents.assert_called_once()
        self.assertTrue(
            store.put_contents.call_args.args[0].endswith("correction.json")
        )
        saved = json.loads(store.put_contents.call_args.args[1])
        self.assertEqual(saved["mark"], 1.0)
        self.assertEqual(saved["note"], 1.0)
        self.assertEqual(saved["parties"][0]["questions"][0]["points"], 1.0)

        self.assertIsInstance(client.state, str)
        self.assertTrue(client.state.startswith("Correction examen de droit."))
        self.assertIn("copie page", client.state)
        self.assertEqual(set(client.questions), {"A1-01", "A1-02"})

        first = self.publish.call_args_list[0]
        self.assertEqual(first.kwargs["status"], STATUS_UNDER_CORRECTION)
        self.assertEqual(first.kwargs["status_label"], LABEL_UNDER_CORRECTION)
        self.assertTrue(first.kwargs["loading"])

        last = self.publish.call_args_list[-1]
        self.assertEqual(last.kwargs["status"], STATUS_GRADED)
        self.assertEqual(last.kwargs["status_label"], LABEL_CORRECTED)
        self.assertFalse(last.kwargs["loading"])
        self.assertEqual(last.kwargs["mark"], 1.0)

    def test_classic_grid_generates_ids(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.grid_key
        store.get_contents.return_value = json.dumps(SAMPLE_CLASSIC_GRID)
        client = FakeJevClient({"P0-Q0-C0": 0.8, "P0-Q0-C1": 0.7})

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ), patch.object(
            self.student,
            "compile_submission",
            return_value={"pages": ["copie"]},
        ), patch.object(
            self.student, "save"
        ), patch.object(
            self.student, "append_event"
        ), patch.object(
            self.student, "get_submissions", return_value=[]
        ):
            result = self.assessment.jev_correct_students(
                [self.student],
                client_factory=lambda: client,
                publish=self.publish,
            )

        self.assertEqual(result, {"stu": 3.0})
        self.assertEqual(set(client.questions), {"P0-Q0-C0", "P0-Q0-C1"})

    def test_error_sets_status_and_publishes_loading_false(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.grid_key
        store.get_contents.return_value = json.dumps(SAMPLE_STRICT_GRID)

        def failing_factory() -> FakeJevClient:
            client = FakeJevClient({"A1-01": 1.0, "A1-02": 1.0})

            def boom() -> tuple[SimpleNamespace, float]:
                raise RuntimeError("boom")

            client.decide_with_cost = boom  # type: ignore[method-assign]
            return client

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
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
            result = self.assessment.jev_correct_students(
                [self.student],
                client_factory=failing_factory,
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
