"""PaddleOCR backend."""

from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from pycorrai.ocr.common import suffix_for

# Paddle's oneDNN path fails on these PP-OCRv6 models with
# ConvertPirAttribute2RuntimeAttribute. Force the plain CPU engine
# before Paddle or PaddleX is imported.
os.environ["PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT"] = "0"
os.environ["FLAGS_use_mkldnn"] = "0"

# Long-lived PaddleOCR engines keyed by language. Loaded once per process so a
# systemd / docker consumer does not reload models between tickets.
_engines: dict[str, Any] = {}


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


def recognize(path: Path, lang: str) -> list[dict[str, Any]]:
    """Run PaddleOCR and return word coordinates for every page."""
    engine = get_engine(lang)
    pages = engine.predict(str(path), return_word_box=True)
    return words_from_pages(pages)


def recognize_bytes(data: bytes, lang: str) -> list[dict[str, Any]]:
    """Recognize words from file bytes with PaddleOCR."""
    suffix = suffix_for(data)
    with tempfile.NamedTemporaryFile(suffix=suffix) as handle:
        handle.write(data)
        handle.flush()
        return recognize(Path(handle.name), lang)
