"""Tests for worker domain models (mocked S3/Redis)."""

from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

from pycorrai.model.assessment import Assessment
from pycorrai.model.input_file import InputFile
from pycorrai.model.s3_file import S3File
from pycorrai.model.student import Student
from pycorrai.model.subject_file import SubjectFile
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.store.exceptions import StoreConflictException


class S3FileTest(unittest.TestCase):
    def test_stores_as_blob(self) -> None:
        self.assertTrue(S3File.stores_as_blob("solution"))
        self.assertTrue(S3File.stores_as_blob("instructions"))
        self.assertFalse(S3File.stores_as_blob("submission"))
        self.assertFalse(S3File.stores_as_blob("subject"))

    def test_at_and_put_json(self) -> None:
        store = MagicMock()
        store.put_contents.return_value = "etag1"
        with patch("pycorrai.model.s3_file.ObjectStore.get_instance", return_value=store):
            with patch.object(S3File, "_invalidate_metadata_cache"):
                f = S3File.at("schools/s/teachers/t/assessments/a/blobs/x")
                f.id = "abc1234"
                f.name = "sol.pdf"
                f.type = "solution"
                f.school_id = "s"
                f.user_id = "t"
                f.assessment_id = "a"
                etag = f.put_json({"ok": True})
        self.assertEqual(etag, "etag1")
        store.put_contents.assert_called_once()


class InputFileResolveTest(unittest.TestCase):
    def test_from_array_submission(self) -> None:
        data = {
            "id": "abc1234",
            "school_id": "s",
            "user_id": "t",
            "assessment_id": "a",
            "name": "copy.pdf",
            "status": "loaded",
            "type": "submission",
            "student_identifier": "X1",
        }
        file = SubmissionFile.from_array(data)
        self.assertEqual(file.status, "stored")
        self.assertEqual(file.student_identifier, "X1")
        self.assertEqual(file.role(), "submission")

    def test_from_hash_resolves_subject(self) -> None:
        store = MagicMock()
        store.resolve_id_pointer.return_value = (
            "schools/s/teachers/t/assessments/a/subject/fid/"
        )
        store.exists.return_value = True
        store.get_json_with_etag.return_value = (
            {
                "name": "sujet.pdf",
                "status": "stored",
                "type": "subject",
                "content_type": "application/pdf",
                "size": 10,
                "created": 1,
            },
            "etag",
        )
        with patch("pycorrai.model.input_file.ObjectStore.get_instance", return_value=store):
            file = InputFile.from_hash("fid1234")
        self.assertIsInstance(file, SubjectFile)
        self.assertEqual(file.name, "sujet.pdf")
        self.assertEqual(file.school_id, "s")

    def test_save_attributes_retries_on_conflict(self) -> None:
        store = MagicMock()
        store.exists.return_value = False
        store.put_json.side_effect = [
            StoreConflictException("conflict", 412),
            "new-etag",
        ]
        store.get_json_with_etag.return_value = (
            {
                "name": "copy.pdf",
                "status": "stored",
                "type": "submission",
                "content_type": "application/pdf",
                "size": 1,
                "created": 1,
            },
            "etag2",
        )
        file = SubmissionFile()
        file.id = "fid1234"
        file.school_id = "s"
        file.user_id = "t"
        file.assessment_id = "a"
        file.name = "copy.pdf"
        file.status = "stored"
        file.etag = "etag1"
        with patch("pycorrai.model.input_file.ObjectStore.get_instance", return_value=store):
            with patch.object(file, "_invalidate_metadata_cache"):
                file.save_attributes(retry=True)
        self.assertEqual(store.put_json.call_count, 2)


class StudentAssessmentTest(unittest.TestCase):
    def test_student_from_array(self) -> None:
        st = Student.from_array(
            {
                "id": "stu1234",
                "school_id": "s",
                "user_id": "t",
                "assessment_id": "a",
                "name": "Alice",
                "status": "pending",
                "mark": 12.5,
            }
        )
        self.assertEqual(st.name, "Alice")
        self.assertEqual(st.mark, 12.5)

    def test_assessment_from_array(self) -> None:
        a = Assessment.from_array(
            {
                "id": "ass1234",
                "school_id": "s",
                "user_id": "t",
                "name": "Exam",
                "subject": "math",
                "status": "draft",
                "date": "2026-01-01",
            }
        )
        self.assertEqual(a.name, "Exam")
        self.assertEqual(a.subject, "math")
        self.assertIn("name", a.attribute_payload())

    def test_assessment_get_file_blob(self) -> None:
        store = MagicMock()
        store.resolve_id_pointer.return_value = (
            "schools/s/teachers/t/assessments/a/blobs/fid"
        )
        blob = S3File.at("schools/s/teachers/t/assessments/a/blobs/fid")
        blob.id = "fid1234"
        blob.school_id = "s"
        blob.user_id = "t"
        blob.assessment_id = "a"
        blob.type = "solution"
        assessment = Assessment()
        assessment.id = "a"
        assessment.school_id = "s"
        assessment.user_id = "t"
        with patch("pycorrai.model.assessment.ObjectStore.get_instance", return_value=store):
            with patch("pycorrai.model.assessment.S3File.from_hash", return_value=blob):
                result = assessment.get_file("fid1234")
        self.assertIs(result, blob)


if __name__ == "__main__":
    unittest.main()
