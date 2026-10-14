"""Tests for ObjectStore path builders and parse_node_prefix."""

from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

from pycorrai.store.hash_id import HashId
from pycorrai.store.object_store import ObjectStore


class PathBuildersTest(unittest.TestCase):
    def test_assessment_tree(self) -> None:
        self.assertEqual(
            ObjectStore.assessment_prefix("s", "t", "a"),
            "schools/s/teachers/t/assessments/a/",
        )
        self.assertEqual(
            ObjectStore.assessment_attr_key("s", "t", "a"),
            "schools/s/teachers/t/assessments/a/attributes.json",
        )
        self.assertEqual(
            ObjectStore.assessment_blob_key("s", "t", "a", "fid"),
            "schools/s/teachers/t/assessments/a/blobs/fid",
        )
        self.assertEqual(
            ObjectStore.id_index_key("AbC1234"),
            "_id/AbC1234",
        )

    def test_file_areas(self) -> None:
        self.assertEqual(ObjectStore.assessment_file_area("subject", None), "subject")
        self.assertEqual(ObjectStore.assessment_file_area("submission", None), "unclassified")
        self.assertEqual(ObjectStore.assessment_file_area("submission", "stu"), "student")
        prefix = ObjectStore.assessment_file_prefix("s", "t", "a", "f1", "subject", None)
        self.assertEqual(prefix, "schools/s/teachers/t/assessments/a/subject/f1/")


class ParseNodePrefixTest(unittest.TestCase):
    def test_blob(self) -> None:
        parsed = ObjectStore.parse_node_prefix(
            "schools/s/teachers/t/assessments/a/blobs/fid"
        )
        self.assertEqual(parsed["kind"], "blob")
        self.assertEqual(parsed["file_id"], "fid")

    def test_subject_file(self) -> None:
        parsed = ObjectStore.parse_node_prefix(
            "schools/s/teachers/t/assessments/a/subject/fid/"
        )
        self.assertEqual(parsed["kind"], "file")
        self.assertEqual(parsed["area"], "subject")

    def test_student_file(self) -> None:
        parsed = ObjectStore.parse_node_prefix(
            "schools/s/teachers/t/assessments/a/students/stu/fid"
        )
        self.assertEqual(parsed["kind"], "file")
        self.assertEqual(parsed["student_id"], "stu")
        self.assertEqual(parsed["area"], "student")

    def test_invalid(self) -> None:
        with self.assertRaises(Exception):
            ObjectStore.parse_node_prefix("not/a/valid/path")


class HashIdTest(unittest.TestCase):
    def test_is_valid(self) -> None:
        self.assertTrue(HashId.is_valid("abc1234"))
        self.assertFalse(HashId.is_valid("short"))
        self.assertFalse(HashId.is_valid("too_longg"))

    def test_create_checks_existence(self) -> None:
        store = MagicMock()
        store.exists.side_effect = [True, False]
        with patch(
            "pycorrai.store.hash_id.ObjectStore.get_instance", return_value=store
        ):
            hid = HashId.create()
        self.assertTrue(HashId.is_valid(hid))
        self.assertEqual(store.exists.call_count, 2)


class ObjectStoreOpsTest(unittest.TestCase):
    def test_put_json_stamps_schema(self) -> None:
        client = MagicMock()
        client.put_object.return_value = {"ETag": '"abc"'}
        store = ObjectStore(client=client, bucket="corrai")
        store._bucket_ready = True
        etag = store.put_json("k.json", {"name": "x"})
        self.assertEqual(etag, "abc")
        body = client.put_object.call_args.kwargs["Body"]
        if isinstance(body, bytes):
            body = body.decode("utf-8")
        self.assertIn('"schema":1', body.replace(" ", ""))


if __name__ == "__main__":
    unittest.main()
