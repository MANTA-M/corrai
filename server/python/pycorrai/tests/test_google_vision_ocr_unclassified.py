"""Unit tests for Assessment.google_vision_ocr_unclassified."""

from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import Assessment
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.store.object_store import ObjectStore


class GoogleVisionOcrUnclassifiedTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assessment = Assessment()
        self.assessment.id = "a"
        self.assessment.school_id = "s"
        self.assessment.user_id = "t"

    def test_unclassified_file_ids_requires_attributes(self) -> None:
        store = MagicMock()
        prefix = ObjectStore.assessment_unclassified_files_prefix("s", "t", "a")
        store.list_child_prefixes.return_value = ["f1", "f2"]
        store.exists.side_effect = lambda key: key == prefix + "f1/attributes.json"

        with patch(
            "pycorrai.model.assessment.ObjectStore.get_instance", return_value=store
        ):
            self.assertEqual(self.assessment.unclassified_file_ids(), ["f1"])

        store.list_child_prefixes.assert_called_once_with(prefix)

    def test_runs_ocr_on_unclassified_files(self) -> None:
        file_a = SubmissionFile()
        file_a.id = "f1"
        file_a.name = "b.jpg"
        file_a.school_id = "s"
        file_a.user_id = "t"
        file_a.assessment_id = "a"

        file_b = SubmissionFile()
        file_b.id = "f2"
        file_b.name = "a.jpg"
        file_b.school_id = "s"
        file_b.user_id = "t"
        file_b.assessment_id = "a"

        ocr_runner = MagicMock()
        with patch.object(
            self.assessment, "unclassified_file_ids", return_value=["f1", "f2"]
        ), patch.object(
            self.assessment,
            "get_file",
            side_effect=lambda file_id: file_a if file_id == "f1" else file_b,
        ):
            self.assessment.google_vision_ocr_unclassified(
                force_re_ocr=True, ocr_runner=ocr_runner
            )

        ocr_runner.assert_called_once()
        files, kwargs = ocr_runner.call_args.args[0], ocr_runner.call_args.kwargs
        self.assertEqual([f.id for f in files], ["f2", "f1"])
        self.assertTrue(kwargs["force_re_ocr"])

    def test_noop_when_no_unclassified_files(self) -> None:
        ocr_runner = MagicMock()
        with patch.object(self.assessment, "unclassified_file_ids", return_value=[]):
            self.assessment.google_vision_ocr_unclassified(ocr_runner=ocr_runner)
        ocr_runner.assert_not_called()

    def test_enqueue_pushes_python_ticket(self) -> None:
        redis = MagicMock()
        with patch("pycorrai.consumer.enqueue_assessment_task") as enqueue:
            self.assessment.enqueue_google_vision_ocr_unclassified(redis=redis)
        enqueue.assert_called_once_with(
            "a", "google_vision_ocr_unclassified", redis=redis
        )


if __name__ == "__main__":
    unittest.main()
