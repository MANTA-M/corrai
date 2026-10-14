"""Unit tests for Assessment.compile_subject / compile_inputs."""

from __future__ import annotations

import json
import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import Assessment
from pycorrai.model.instruction_file import InstructionFile
from pycorrai.model.subject_file import SubjectFile
from pycorrai.store.object_store import ObjectStore


def _subject_file(name: str, file_id: str) -> SubjectFile:
    file = SubjectFile()
    file.id = file_id
    file.school_id = "s"
    file.user_id = "t"
    file.assessment_id = "a"
    file.name = name
    return file


class CompileSubjectTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assessment = Assessment()
        self.assessment.id = "a"
        self.assessment.school_id = "s"
        self.assessment.user_id = "t"
        self.json_key = ObjectStore.assessment_subject_compile_key("s", "t", "a")
        self.legacy_json_key = ObjectStore.assessment_subject_compile_legacy_json_key(
            "s", "t", "a"
        )
        self.legacy_key = ObjectStore.assessment_subject_compile_legacy_key(
            "s", "t", "a"
        )
        self.ocr = MagicMock()

    def test_compile_key_is_compiled_subject_json(self) -> None:
        self.assertTrue(self.json_key.endswith("compiled_subject.json"))
        self.assertTrue(self.legacy_json_key.endswith("compile_subject.json"))
        self.assertTrue(self.legacy_key.endswith("compile_subject"))
        self.assertFalse(self.legacy_key.endswith(".json"))

    def test_returns_existing_json_without_recompile(self) -> None:
        store = MagicMock()
        store.exists.side_effect = lambda key: key == self.json_key
        store.get_json.return_value = {"pages": ["déjà là"]}

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ):
            result = self.assessment.compile_subject("", ocr_runner=self.ocr)

        self.assertEqual(result, {"pages": ["déjà là"]})
        self.ocr.assert_not_called()
        store.put_contents.assert_not_called()

    def test_renames_legacy_compile_subject(self) -> None:
        keys = {self.legacy_key}
        store = MagicMock()

        def exists(key: str) -> bool:
            return key in keys

        def copy(src: str, dest: str) -> None:
            if src in keys:
                keys.add(dest)

        def delete(key: str) -> None:
            keys.discard(key)

        store.exists.side_effect = exists
        store.copy.side_effect = copy
        store.delete.side_effect = delete
        store.get_json.return_value = {"pages": ["legacy"]}

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ):
            result = self.assessment.compile_subject("other", ocr_runner=self.ocr)

        store.copy.assert_called_once_with(self.legacy_key, self.json_key)
        self.assertNotIn(self.legacy_key, keys)
        self.assertIn(self.json_key, keys)
        self.assertEqual(result, {"pages": ["legacy"]})
        self.ocr.assert_not_called()

    def test_renames_legacy_compile_subject_json(self) -> None:
        keys = {self.legacy_json_key}
        store = MagicMock()

        def exists(key: str) -> bool:
            return key in keys

        def copy(src: str, dest: str) -> None:
            if src in keys:
                keys.add(dest)

        def delete(key: str) -> None:
            keys.discard(key)

        store.exists.side_effect = exists
        store.copy.side_effect = copy
        store.delete.side_effect = delete
        store.get_json.return_value = {"pages": ["legacy-json"]}

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ):
            result = self.assessment.compile_subject("other", ocr_runner=self.ocr)

        store.copy.assert_called_once_with(self.legacy_json_key, self.json_key)
        self.assertNotIn(self.legacy_json_key, keys)
        self.assertIn(self.json_key, keys)
        self.assertEqual(result, {"pages": ["legacy-json"]})
        self.ocr.assert_not_called()

    def test_rebuilds_when_re_compile_subject(self) -> None:
        file_a = _subject_file("b.png", "f2")
        file_b = _subject_file("a.png", "f1")
        store = MagicMock()
        store.exists.return_value = True
        store.get_json.side_effect = lambda key: {
            file_b.ocr_result_key(): {"text": "page A"},
            file_a.ocr_result_key(): {"text": "page B"},
            self.json_key: {"pages": ["stale"]},
        }.get(key, {})

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ), patch.object(
            self.assessment,
            "list_file_models",
            return_value=[file_a, file_b],
        ):
            result = self.assessment.compile_subject(
                "re_compile_subject", ocr_runner=self.ocr
            )

        self.ocr.assert_called_once()
        args, kwargs = self.ocr.call_args
        self.assertEqual([f.name for f in args[0]], ["a.png", "b.png"])
        self.assertFalse(kwargs.get("force_re_ocr", True))
        self.assertEqual(result, {"pages": ["page A", "page B"]})
        body = store.put_contents.call_args.args[1]
        self.assertEqual(json.loads(body), {"pages": ["page A", "page B"]})
        self.assertEqual(store.put_contents.call_args.args[0], self.json_key)

    def test_builds_when_missing(self) -> None:
        file = _subject_file("page.png", "f1")
        store = MagicMock()
        store.exists.side_effect = lambda key: key == file.ocr_result_key()
        store.get_json.return_value = {"text": "hello"}

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ), patch.object(self.assessment, "list_file_models", return_value=[file]):
            result = self.assessment.compile_subject(ocr_runner=self.ocr)

        self.ocr.assert_called_once_with([file], force_re_ocr=False)
        self.assertEqual(result, {"pages": ["hello"]})

    def test_includes_instruction_files_under_subject(self) -> None:
        subject = _subject_file("z.png", "f1")
        instruction = InstructionFile()
        instruction.id = "f2"
        instruction.school_id = "s"
        instruction.user_id = "t"
        instruction.assessment_id = "a"
        instruction.name = "a.md"

        store = MagicMock()
        store.exists.side_effect = lambda key: key.endswith("ocr_result.json")
        store.get_json.side_effect = lambda key: {
            instruction.ocr_result_key(): {"text": "instr"},
            subject.ocr_result_key(): {"text": "subj"},
        }.get(key, {})

        with patch(
            "pycorrai.model.compile_inputs.ObjectStore.get_instance",
            return_value=store,
        ), patch.object(
            self.assessment,
            "list_file_models",
            return_value=[subject, instruction],
        ):
            result = self.assessment.compile_subject(
                "re_compile_subject", ocr_runner=self.ocr
            )

        names = [f.name for f in self.ocr.call_args.args[0]]
        self.assertEqual(names, ["a.md", "z.png"])
        self.assertEqual(result, {"pages": ["instr", "subj"]})


if __name__ == "__main__":
    unittest.main()
