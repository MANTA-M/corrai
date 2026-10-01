"""OCR a file and print one JSON object per recognized word."""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from pathlib import Path
from typing import Any

# Paddle's oneDNN path fails on these PP-OCRv6 models with
# ConvertPirAttribute2RuntimeAttribute. Force the plain CPU engine
# before Paddle or PaddleX is imported.
os.environ["PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT"] = "0"
os.environ["FLAGS_use_mkldnn"] = "0"


def words_from_pages(pages: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Flatten PaddleOCR page results into word records.

    Each record is ``{"text", "page", "box"}`` where ``box`` is
    ``[left, top, right, bottom]`` in pixels of that page, origin top-left.
    """
    words: list[dict[str, Any]] = []
    for page_index, page in enumerate(pages):
        page_no = page.get("page_index")
        if page_no is None:
            page_no = page_index
        line_texts = page.get("text_word") or []
        line_boxes = page.get("text_word_boxes") or []
        for texts, boxes in zip(line_texts, line_boxes):
            for text, box in zip(texts, boxes):
                if isinstance(text, tuple):
                    text = text[0]
                left, top, right, bottom = (int(round(float(value))) for value in box)
                words.append(
                    {
                        "text": str(text),
                        "page": int(page_no),
                        "box": [left, top, right, bottom],
                    }
                )
    return words


def suffix_for(data: bytes) -> str:
    """Pick a filename suffix so PaddleOCR can tell an image from a PDF."""
    if data.startswith(b"%PDF"):
        return ".pdf"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return ".png"
    if data.startswith(b"\xff\xd8\xff"):
        return ".jpg"
    if data.startswith((b"GIF87a", b"GIF89a")):
        return ".gif"
    if data.startswith(b"RIFF") and data[8:12] == b"WEBP":
        return ".webp"
    if data.startswith(b"BM"):
        return ".bmp"
    if data.startswith((b"II*\x00", b"MM\x00*")):
        return ".tif"
    raise ValueError("Unsupported file data. Expected an image or a PDF.")


# Long-lived PaddleOCR engines keyed by language. Loaded once per process so a
# systemd / docker consumer does not reload models between tickets.
_engines: dict[str, Any] = {}


def get_engine(lang: str) -> Any:
    """Return a cached PaddleOCR engine for ``lang``, creating it on first use."""
    os.environ["PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT"] = "0"
    os.environ["FLAGS_use_mkldnn"] = "0"
    engine = _engines.get(lang)
    if engine is not None:
        return engine

    from paddleocr import PaddleOCR

    engine = PaddleOCR(
        lang=lang,
        use_doc_orientation_classify=False,
        use_doc_unwarping=False,
        use_textline_orientation=False,
        return_word_box=True,
    )
    _engines[lang] = engine
    return engine


def recognize_bytes(data: bytes, lang: str) -> list[dict[str, Any]]:
    """Recognize words from file bytes read on stdin."""
    suffix = suffix_for(data)
    with tempfile.NamedTemporaryFile(suffix=suffix) as handle:
        handle.write(data)
        handle.flush()
        return recognize(Path(handle.name), lang)


def recognize(path: Path, lang: str) -> list[dict[str, Any]]:
    """Run OCR and return word coordinates for every page."""
    engine = get_engine(lang)
    pages = engine.predict(str(path), return_word_box=True)
    return words_from_pages(pages)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="pycorrai",
        description="OCR file bytes from stdin and print each word with its bounding box as JSON.",
    )
    parser.add_argument(
        "--lang",
        default="fr",
        help="PaddleOCR language code (default: fr).",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    data = sys.stdin.buffer.read()
    if not data:
        print("No file data on stdin", file=sys.stderr)
        return 1
    try:
        words = recognize_bytes(data, args.lang)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    json.dump(words, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0
