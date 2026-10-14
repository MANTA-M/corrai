"""Unit tests for Student.compile_submission."""

from __future__ import annotations

import json
import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.student import Student
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.store.object_store import ObjectStore


def _submission(name: str, file_id: str) -> SubmissionFile:
    file = SubmissionFile()
    file.id = file_id
    file.school_id = "s"
    file.user_id = "t"
    file.assessment_id = "a"
    file.student = "stu"
    file.name = name
    return file


class CompileSubmissionTest(unittest.TestCase):
    def setUp(self) -> None:
        self.student = Student()
        self.student.id = "stu"
        self.student.school_id = "s"
        self.student.user_id = "t"
        self.student.assessment_id = "a"
        self.student.name = "Alice"
        self.json_key = ObjectStore.assessment_student_compiled_submission_key(
            "s", "t", "a", "stu"
        )
        self.ocr = MagicMock()

    def test_key_is_compiled_submission_json(self) -> None:
        self.assertTrue(self.json_key.endswith("compiled_submission.json"))
        self.assertEqual(self.student.compiled_submission_key(), self.json_key)

    def test_returns_existing_without_recompile(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.json_key
        store.get_json.return_value = {"pages": ["copie"]}

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ), patch.object(self.student, "get_submissions", return_value=[]):
            result = self.student.compile_submission(ocr_runner=self.ocr)

        self.assertEqual(result, {"pages": ["copie"]})
        self.ocr.assert_not_called()

    def test_builds_ordered_by_name(self) -> None:
        file_a = _submission("b.png", "f2")
        file_b = _submission("a.png", "f1")
        store = MagicMock()
        store.exists.side_effect = lambda key: key.endswith("ocr_result.json")
        store.get_json.side_effect = lambda key: {
            file_b.ocr_result_key(): {"text": "page A"},
            file_a.ocr_result_key(): {"text": "page B"},
        }.get(key, {})

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ), patch.object(
            self.student, "get_submissions", return_value=[file_a, file_b]
        ):
            result = self.student.compile_submission(
                "re_compile_submission", ocr_runner=self.ocr
            )

        names = [f.name for f in self.ocr.call_args.args[0]]
        self.assertEqual(names, ["a.png", "b.png"])
        self.assertEqual(result, {"pages": ["page A", "page B"]})
        self.assertEqual(store.put_contents.call_args.args[0], self.json_key)
        self.assertEqual(
            json.loads(store.put_contents.call_args.args[1]),
            {"pages": ["page A", "page B"]},
        )


if __name__ == "__main__":
    unittest.main()
