"""python-doctr backend."""

from __future__ import annotations

import tempfile
from pathlib import Path
from typing import Any

from pycorrai.ocr.common import suffix_for

# Long-lived docTR predictor. Language is ignored (pretrained multilingual).
_predictor: Any = None


def get_predictor() -> Any:
    """Return a cached docTR OCR predictor, creating it on first use."""
    global _predictor
    if _predictor is not None:
        return _predictor

    from doctr.models import ocr_predictor

    _predictor = ocr_predictor(pretrained=True)
    return _predictor


def geometry_to_box(geometry: Any, width: float, height: float) -> list[int]:
    """Convert docTR relative geometry to ``[left, top, right, bottom]`` pixels."""
    if not isinstance(geometry, (list, tuple)) or len(geometry) == 0:
        raise ValueError(f"Unsupported docTR geometry: {geometry!r}")

    # Two opposite corners: ((xmin, ymin), (xmax, ymax))
    if len(geometry) == 2 and all(
        isinstance(point, (list, tuple)) and len(point) == 2 for point in geometry
    ):
        xs = (float(geometry[0][0]), float(geometry[1][0]))
        ys = (float(geometry[0][1]), float(geometry[1][1]))
        return [
            int(round(min(xs) * width)),
            int(round(min(ys) * height)),
            int(round(max(xs) * width)),
            int(round(max(ys) * height)),
        ]

    # Flat [xmin, ymin, xmax, ymax]
    if len(geometry) == 4 and all(isinstance(value, (int, float)) for value in geometry):
        xmin, ymin, xmax, ymax = (float(value) for value in geometry)
        return [
            int(round(min(xmin, xmax) * width)),
            int(round(min(ymin, ymax) * height)),
            int(round(max(xmin, xmax) * width)),
            int(round(max(ymin, ymax) * height)),
        ]

    # Four-point polygon
    points: list[tuple[float, float]] = []
    for item in geometry:
        if isinstance(item, (list, tuple)) and len(item) == 2:
            points.append((float(item[0]), float(item[1])))
    if not points:
        raise ValueError(f"Unsupported docTR geometry: {geometry!r}")
    xs = [point[0] for point in points]
    ys = [point[1] for point in points]
    return [
        int(round(min(xs) * width)),
        int(round(min(ys) * height)),
        int(round(max(xs) * width)),
        int(round(max(ys) * height)),
    ]


def words_from_export(exported: dict[str, Any]) -> list[dict[str, Any]]:
    """Flatten a docTR ``Document.export()`` dict into word records."""
    words: list[dict[str, Any]] = []
    pages = exported.get("pages") or []
    for page_index, page in enumerate(pages):
        page_no = page.get("page_idx")
        if page_no is None:
            page_no = page_index
        dimensions = page.get("dimensions") or (0, 0)
        if isinstance(dimensions, (list, tuple)) and len(dimensions) >= 2:
            height = float(dimensions[0])
            width = float(dimensions[1])
        else:
            height = 0.0
            width = 0.0
        for block in page.get("blocks") or []:
            for line in block.get("lines") or []:
                for word in line.get("words") or []:
                    text = word.get("value")
                    if text is None:
                        continue
                    box = geometry_to_box(word.get("geometry"), width, height)
                    words.append(
                        {
                            "text": str(text),
                            "page": int(page_no),
                            "box": box,
                        }
                    )
    return words


def recognize(path: Path, lang: str) -> list[dict[str, Any]]:
    """Run docTR and return word coordinates for every page.

    ``lang`` is accepted for API symmetry with Paddle but unused.
    """
    del lang
    from doctr.io import DocumentFile

    predictor = get_predictor()
    suffix = path.suffix.lower()
    if suffix == ".pdf":
        doc = DocumentFile.from_pdf(str(path))
    else:
        doc = DocumentFile.from_images(str(path))
    result = predictor(doc)
    return words_from_export(result.export())


def recognize_bytes(data: bytes, lang: str) -> list[dict[str, Any]]:
    """Recognize words from file bytes with docTR."""
    suffix = suffix_for(data)
    with tempfile.NamedTemporaryFile(suffix=suffix) as handle:
        handle.write(data)
        handle.flush()
        return recognize(Path(handle.name), lang)
