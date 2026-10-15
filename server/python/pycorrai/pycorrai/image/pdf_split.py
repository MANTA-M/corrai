"""Split a PDF ``InputFile`` into one JPEG ``InputFile`` per page."""

from __future__ import annotations

import glob
import logging
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

from pycorrai.image.resize import mime_type_for_filename
from pycorrai.model.input_file import InputFile
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.store.hash_id import HashId
from pycorrai.store.object_store import ObjectStore

logger = logging.getLogger(__name__)

_PDF_DPI = 120
_JPEG_QUALITY = 82


def image_pdf_split(source: InputFile) -> list[InputFile]:
    """Create one sibling ``InputFile`` per PDF page, content stored as JPEG.

    Each new file directory is created next to the source file directory
    (same parent prefix: same role / student area).
    """
    if source.id is None or source.id == "":
        raise ValueError("Cannot split PDF without a source file id")
    source.validate()

    store = ObjectStore.get_instance()
    pdf_path: str | None = None
    work_dir: str | None = None
    try:
        pdf_path = store.download_to_temp(source.content_key())
        _assert_pdf(pdf_path, source.name)
        work_dir = tempfile.mkdtemp(prefix="corrai_pdf_split_")
        jpeg_paths = _pdf_pages_to_jpeg(pdf_path, work_dir)
        if not jpeg_paths:
            raise RuntimeError("PDF produced no pages")

        pages: list[InputFile] = []
        stem = Path(source.name).stem or "page"
        for index, jpeg_path in enumerate(jpeg_paths, start=1):
            jpeg_bytes = Path(jpeg_path).read_bytes()
            if not jpeg_bytes:
                raise RuntimeError(f"Empty JPEG for PDF page {index}")
            page = _new_sibling_file(
                source,
                name=f"{stem}_page_{index}.jpg",
                content_type="image/jpeg",
                size=len(jpeg_bytes),
            )
            store.put_contents(page.content_key(), jpeg_bytes, page.content_type)
            page.save_attributes(retry=False)
            page.append_event("Stored")
            pages.append(page)
        return pages
    finally:
        _unlink_quiet(pdf_path)
        if work_dir is not None:
            shutil.rmtree(work_dir, ignore_errors=True)


def _assert_pdf(path: str, filename: str) -> None:
    mime = mime_type_for_filename(filename).split(";", 1)[0].strip().lower()
    head = Path(path).read_bytes()[:5]
    if head.startswith(b"%PDF") or mime == "application/pdf":
        return
    raise ValueError(f"InputFile content is not a PDF: {filename}")


def _pdf_pages_to_jpeg(pdf_path: str, work_dir: str) -> list[str]:
    """Rasterize every PDF page to JPEG via ImageMagick ``convert``."""
    pattern = os.path.join(work_dir, "page-%d.jpg")
    convert = shutil.which("convert")
    if convert is None:
        raise RuntimeError("ImageMagick convert is required to split PDF pages")

    command = [
        convert,
        "-density",
        str(_PDF_DPI),
        pdf_path,
        "-quality",
        str(_JPEG_QUALITY),
        pattern,
    ]
    logger.info("[image_pdf_split] %s", " ".join(command))
    completed = subprocess.run(
        command,
        capture_output=True,
        text=True,
        check=False,
    )
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout or "").strip()
        raise RuntimeError(
            f"Failed to convert PDF pages to JPEG (exit {completed.returncode}): {detail}"
        )

    paths = sorted(
        glob.glob(os.path.join(work_dir, "page-*.jpg")),
        key=_page_sort_key,
    )
    return paths


def _page_sort_key(path: str) -> tuple[int, str]:
    stem = Path(path).stem  # page-0, page-1, ...
    suffix = stem.rsplit("-", 1)[-1]
    try:
        return (int(suffix), path)
    except ValueError:
        return (10**9, path)


def _new_sibling_file(
    source: InputFile,
    *,
    name: str,
    content_type: str,
    size: int,
) -> InputFile:
    """Clone source identity into a new InputFile of the same concrete class."""
    page = type(source)()
    page.id = HashId.create()
    page.school_id = source.school_id
    page.user_id = source.user_id
    page.assessment_id = source.assessment_id
    page.name = name
    page.size = size
    page.created = int(time.time())
    page.status = "stored"
    page.content_type = content_type
    page.thumbnail = False
    if isinstance(source, SubmissionFile) and isinstance(page, SubmissionFile):
        page.student = source.student
    return page


def _unlink_quiet(path: str | None) -> None:
    if path is None:
        return
    try:
        if os.path.isfile(path):
            os.unlink(path)
    except OSError:
        pass
