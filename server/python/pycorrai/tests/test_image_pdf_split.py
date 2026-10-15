"""Tests for ``image_pdf_split``."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

from pycorrai.image.pdf_split import image_pdf_split
from pycorrai.model.subject_file import SubjectFile
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.store.object_store import ObjectStore


def _make_two_page_pdf(path: Path) -> None:
    gs = shutil.which("gs")
    if gs is None:
        raise unittest.SkipTest("ghostscript (gs) required to build fixture PDF")
    completed = subprocess.run(
        [
            gs,
            "-q",
            "-sDEVICE=pdfwrite",
            f"-o{path}",
            "-c",
            "/Helvetica findfont 24 scalefont setfont "
            "100 700 moveto (Page 1) show showpage "
            "100 700 moveto (Page 2) show showpage",
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    if completed.returncode != 0 or not path.is_file():
        raise unittest.SkipTest(f"could not build fixture PDF: {completed.stderr}")


class ImagePdfSplitTest(unittest.TestCase):
    def setUp(self) -> None:
        if shutil.which("convert") is None:
            self.skipTest("ImageMagick convert required")

    def test_splits_pdf_into_sibling_jpeg_input_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pdf_path = Path(tmp) / "sujet.pdf"
            _make_two_page_pdf(pdf_path)

            store = MagicMock()
            store.download_to_temp.return_value = str(pdf_path)
            store.put_contents.return_value = "etag"
            store.exists.return_value = False
            store.put_json.return_value = "etag-attr"

            source = SubjectFile()
            source.id = "src1234"
            source.school_id = "s"
            source.user_id = "t"
            source.assessment_id = "a"
            source.name = "sujet.pdf"
            source.content_type = "application/pdf"
            source.status = "stored"
            source.size = pdf_path.stat().st_size
            source.created = 1

            created_ids = ["page001", "page002"]

            with (
                patch(
                    "pycorrai.image.pdf_split.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.model.input_file.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.model.s3_file.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.store.hash_id.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.image.pdf_split.HashId.create",
                    side_effect=created_ids,
                ),
                patch.object(SubjectFile, "_invalidate_metadata_cache"),
            ):
                pages = image_pdf_split(source)

            self.assertEqual(len(pages), 2)
            self.assertEqual([p.name for p in pages], ["sujet_page_1.jpg", "sujet_page_2.jpg"])
            for page in pages:
                self.assertIsInstance(page, SubjectFile)
                self.assertEqual(page.content_type, "image/jpeg")
                self.assertEqual(page.status, "stored")
                self.assertEqual(page.school_id, "s")
                self.assertEqual(page.user_id, "t")
                self.assertEqual(page.assessment_id, "a")
                self.assertGreater(page.size, 0)
                # Sibling of source under subject/
                self.assertEqual(
                    page.prefix(),
                    ObjectStore.assessment_file_prefix(
                        "s", "t", "a", page.id or "", "subject", None
                    ),
                )
                self.assertEqual(
                    Path(page.prefix()).parent,
                    Path(source.prefix()).parent,
                )

            content_puts = [
                call
                for call in store.put_contents.call_args_list
                if call.args and str(call.args[0]).endswith("/content")
            ]
            self.assertEqual(len(content_puts), 2)
            for call in content_puts:
                key, body, content_type = call.args[:3]
                self.assertEqual(content_type, "image/jpeg")
                self.assertTrue(body.startswith(b"\xff\xd8\xff"))

    def test_submission_keeps_student_and_parent_dir(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pdf_path = Path(tmp) / "copie.pdf"
            _make_two_page_pdf(pdf_path)

            store = MagicMock()
            store.download_to_temp.return_value = str(pdf_path)
            store.put_contents.return_value = "etag"
            store.exists.return_value = False
            store.put_json.return_value = "etag-attr"

            source = SubmissionFile()
            source.id = "src9999"
            source.school_id = "s"
            source.user_id = "t"
            source.assessment_id = "a"
            source.name = "copie.pdf"
            source.student = "stu0001"
            source.content_type = "application/pdf"
            source.status = "stored"
            source.size = 1
            source.created = 1

            with (
                patch(
                    "pycorrai.image.pdf_split.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.model.input_file.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.model.s3_file.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.store.hash_id.ObjectStore.get_instance",
                    return_value=store,
                ),
                patch(
                    "pycorrai.image.pdf_split.HashId.create",
                    side_effect=["pg00001", "pg00002"],
                ),
                patch.object(SubmissionFile, "_invalidate_metadata_cache"),
            ):
                pages = image_pdf_split(source)

            self.assertEqual(len(pages), 2)
            for page in pages:
                self.assertIsInstance(page, SubmissionFile)
                self.assertEqual(page.student, "stu0001")
                self.assertEqual(
                    Path(page.prefix()).parent,
                    Path(source.prefix()).parent,
                )

    def test_rejects_non_pdf(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            png_path = Path(tmp) / "not.pdf"
            png_path.write_bytes(b"\x89PNG\r\n\x1a\n")

            store = MagicMock()
            store.download_to_temp.return_value = str(png_path)

            source = SubjectFile()
            source.id = "src1234"
            source.school_id = "s"
            source.user_id = "t"
            source.assessment_id = "a"
            source.name = "photo.png"
            source.content_type = "image/png"
            source.status = "stored"

            with patch(
                "pycorrai.image.pdf_split.ObjectStore.get_instance",
                return_value=store,
            ):
                with self.assertRaises(ValueError):
                    image_pdf_split(source)


if __name__ == "__main__":
    unittest.main()
