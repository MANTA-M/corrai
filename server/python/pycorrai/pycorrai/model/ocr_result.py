"""OCR words in the format shared by Google OCR and PaddleOCR.

Coordinates are proportions of the page, not pixels. ``left`` and ``right``
are fractions of the page width. ``top`` and ``bottom`` are fractions of the
page height. Origin is the top-left corner. A corner may fall outside
``[0, 1]`` when Google Vision places the first vertex of a turned box off
the page.

JSON shape::

    {
      "text": "Une makine",
      "words": [
        {
          "text": "Une",
          "page": 0,
          "left": 0.0435,
          "top": 0.0406,
          "right": 0.1242,
          "bottom": 0.0656
        }
      ]
    }

``text`` is the full transcription. ``page`` is zero-based.
"""

from __future__ import annotations

import json
import math
import re
from typing import Any

from pycorrai.model.ocr_word import OCRWord

_QUARTER_TURNS = frozenset({0, 90, 180, 270})


class OCRResult:
    def __init__(self, words: list[OCRWord], text: str) -> None:
        for word in words:
            if not isinstance(word, OCRWord):
                raise ValueError("OCR words must be OCRWord instances")
        self.words = list(words)
        self.text = text
        # Clockwise angle of the page relative to upright text: 0, 90, 180, or 270.
        self.rotation = 0

    @classmethod
    def from_google(cls, output: dict[str, Any]) -> OCRResult:
        """Build from Google OCR output (Vision / GoogleOCRClient).

        Bounding boxes already use fractions of the page: ``left``, ``top``,
        ``width``, and ``height``. They are stored here as corner proportions.
        """
        text = output.get("text")
        if not isinstance(text, str):
            raise ValueError("Google OCR response has no text")
        boxes = output.get("bounding_boxes")
        if not isinstance(boxes, list):
            raise ValueError("Google OCR response has no bounding boxes")

        words: list[OCRWord] = []
        for box in boxes:
            if not isinstance(box, dict):
                raise ValueError("Google OCR bounding box must be an object")
            word_text = box.get("text")
            if not isinstance(word_text, str):
                raise ValueError("Google OCR word text must be a string")
            left = cls._number(box.get("left"), "left")
            top = cls._number(box.get("top"), "top")
            width = cls._number(box.get("width"), "width")
            height = cls._number(box.get("height"), "height")
            x1 = left
            x2 = left + width
            y1 = top
            y2 = top + height
            words.append(
                OCRWord(
                    word_text,
                    min(x1, x2),
                    min(y1, y2),
                    max(x1, x2),
                    max(y1, y2),
                    cls._page(box.get("page")),
                )
            )

        result = cls(words, text)
        if "rotation" in output:
            result.rotation = cls._quarter_turn(output["rotation"])
        return result

    @classmethod
    def from_paddle(
        cls,
        words: list[dict[str, Any]],
        width: int,
        height: int,
        page_sizes: dict[int, tuple[int, int]] | None = None,
    ) -> OCRResult:
        """Build from PaddleOCR word records with pixel boxes.

        Each word has ``text``, ``page``, and ``box`` as
        ``[left, top, right, bottom]`` in pixels. ``width`` / ``height`` are
        the default page size; ``page_sizes`` overrides per page index.
        """
        page_sizes = page_sizes or {}
        cls._assert_size(width, height)
        for page, size in page_sizes.items():
            if not isinstance(page, int) or page < 0:
                raise ValueError("Page sizes must map a page index to [width, height]")
            if not isinstance(size, (list, tuple)) or len(size) != 2:
                raise ValueError("Page sizes must map a page index to [width, height]")
            cls._assert_size(size[0], size[1])

        ocr_words: list[OCRWord] = []
        for word in words:
            if not isinstance(word, dict):
                raise ValueError("PaddleOCR word must be an object")
            word_text = word.get("text")
            if not isinstance(word_text, str):
                raise ValueError("PaddleOCR word text must be a string")
            box = word.get("box")
            if not isinstance(box, (list, tuple)) or len(box) < 4:
                raise ValueError("PaddleOCR box must be [left, top, right, bottom]")
            page = cls._page(word.get("page"))
            page_width = width
            page_height = height
            if page in page_sizes:
                page_width, page_height = page_sizes[page]
            ocr_words.append(
                OCRWord.from_pixels(
                    word_text,
                    cls._number(box[0], "left"),
                    cls._number(box[1], "top"),
                    cls._number(box[2], "right"),
                    cls._number(box[3], "bottom"),
                    page_width,
                    page_height,
                    page,
                )
            )
        return cls(ocr_words, cls._join_text(ocr_words))

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> OCRResult:
        words_data = data.get("words")
        if not isinstance(words_data, list):
            raise ValueError("OCR result is missing words")

        words = []
        for word in words_data:
            if not isinstance(word, dict):
                raise ValueError("OCR word must be an object")
            words.append(OCRWord.from_array(word))

        text = data.get("text")
        if text is None:
            text = cls._join_text(words)
        elif not isinstance(text, str):
            raise ValueError("OCR text must be a string")

        result = cls(words, text)
        if "rotation" in data:
            result.rotation = cls._quarter_turn(data["rotation"])
        return result

    @classmethod
    def from_json(cls, raw: str) -> OCRResult:
        try:
            data = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ValueError("Invalid OCR result JSON") from exc
        if not isinstance(data, dict):
            raise ValueError("OCR result JSON must be an object")
        return cls.from_array(data)

    def to_json(self, pretty: bool = False) -> str:
        if pretty:
            return json.dumps(
                self.to_array(), ensure_ascii=False, indent=2, separators=(",", ": ")
            )
        return json.dumps(self.to_array(), ensure_ascii=False, separators=(",", ":"))

    def to_array(self) -> dict[str, Any]:
        data: dict[str, Any] = {
            "text": self.text,
            "words": [word.to_array() for word in self.words],
        }
        if self.rotation != 0:
            data["rotation"] = self.rotation
        return data

    def is_rotated(self) -> bool:
        """True when the page is turned (``rotation`` is 90, 180, or 270)."""
        return self.rotation != 0

    def rotate_upright(self) -> None:
        """Rotate every word so the text is upright, then set ``rotation`` to 0.

        The page is turned clockwise by ``rotation``. Each corner moves back by
        that angle. Proportions stay fractions of the page.
        """
        if self.rotation == 0:
            return
        words: list[OCRWord] = []
        for word in self.words:
            xs: list[float] = []
            ys: list[float] = []
            for x, y in (
                (word.left, word.top),
                (word.right, word.top),
                (word.right, word.bottom),
                (word.left, word.bottom),
            ):
                nx, ny = self._unrotate(x, y)
                xs.append(nx)
                ys.append(ny)
            words.append(
                OCRWord(word.text, min(xs), min(ys), max(xs), max(ys), word.page)
            )
        self.words = words
        self.rotation = 0

    def get_global_box(self, margin: float) -> dict[str, float]:
        """Axis-aligned box around every word, expanded by ``margin`` on each side.

        ``margin`` uses the same unit as the coordinates: a fraction of the page.
        """
        if not self.words:
            raise ValueError("OCR result has no words")
        if not math.isfinite(margin) or margin < 0.0:
            raise ValueError("OCR margin must be a positive number or zero")

        left = math.inf
        top = math.inf
        right = -math.inf
        bottom = -math.inf
        for word in self.words:
            left = min(left, word.left)
            top = min(top, word.top)
            right = max(right, word.right)
            bottom = max(bottom, word.bottom)
        left -= margin
        top -= margin
        right += margin
        bottom += margin
        if right <= left or bottom <= top:
            raise ValueError("OCR global box has no area")
        return {
            "left": left,
            "top": top,
            "right": right,
            "bottom": bottom,
        }

    def resize_to_global(self, box: dict[str, float]) -> None:
        """Remap every word into the global box, so that box becomes the page."""
        width = box["right"] - box["left"]
        height = box["bottom"] - box["top"]
        words: list[OCRWord] = []
        for word in self.words:
            words.append(
                OCRWord(
                    word.text,
                    (word.left - box["left"]) / width,
                    (word.top - box["top"]) / height,
                    (word.right - box["left"]) / width,
                    (word.bottom - box["top"]) / height,
                    word.page,
                )
            )
        self.words = words

    def detect_rotation(self) -> int:
        """Set ``rotation`` to the clockwise angle of the page: 0, 90, 180, or 270."""
        scores = {0: 0.0, 90: 0.0, 180: 0.0, 270: 0.0}
        horizontal = 0.0
        vertical = 0.0
        for line in self._lines():
            if line["vertical"]:
                vertical += line["length"]
            else:
                horizontal += line["length"]
            if line["angle"] is not None:
                scores[line["angle"]] += line["length"]

        best = 0
        best_score = 0.0
        for angle, score in scores.items():
            if score > best_score:
                best = angle
                best_score = score
        if best_score <= 0.0 and vertical > horizontal:
            self.rotation = 90
            return 90
        self.rotation = best
        return best

    def _lines(self) -> list[dict[str, Any]]:
        by_page: dict[int, list[OCRWord]] = {}
        for word in self.words:
            by_page.setdefault(word.page, []).append(word)

        lines: list[dict[str, Any]] = []
        for page_words in by_page.values():
            lines.extend(self._lines_of(page_words))
        return lines

    @classmethod
    def _lines_of(cls, words: list[OCRWord]) -> list[dict[str, Any]]:
        lines: list[dict[str, Any]] = []
        for word in words:
            width = word.right - word.left
            height = word.bottom - word.top
            if width <= 0.0 and height <= 0.0:
                continue
            vertical = height > width
            index = cls._matching_line(lines, word, vertical)
            if index is None:
                lines.append(
                    {
                        "vertical": vertical,
                        "left": word.left,
                        "top": word.top,
                        "right": word.right,
                        "bottom": word.bottom,
                        "words": [word],
                    }
                )
                continue
            lines[index]["left"] = min(lines[index]["left"], word.left)
            lines[index]["top"] = min(lines[index]["top"], word.top)
            lines[index]["right"] = max(lines[index]["right"], word.right)
            lines[index]["bottom"] = max(lines[index]["bottom"], word.bottom)
            lines[index]["words"].append(word)

        result: list[dict[str, Any]] = []
        for line in lines:
            length = (
                line["bottom"] - line["top"]
                if line["vertical"]
                else line["right"] - line["left"]
            )
            if length <= 0.0:
                continue
            result.append(
                {
                    "vertical": line["vertical"],
                    "length": length,
                    "angle": cls._line_angle(line["words"], line["vertical"]),
                }
            )
        return result

    @staticmethod
    def _line_angle(words: list[OCRWord], vertical: bool) -> int | None:
        if len(words) < 2:
            return None
        first = words[0]
        last = words[-1]
        if vertical:
            delta = ((last.top + last.bottom) - (first.top + first.bottom)) / 2
            if delta > 0.0:
                return 90
            if delta < 0.0:
                return 270
            return None
        delta = ((last.left + last.right) - (first.left + first.right)) / 2
        if delta > 0.0:
            return 0
        if delta < 0.0:
            return 180
        return None

    def _unrotate(self, x: float, y: float) -> tuple[float, float]:
        """Undo a clockwise page rotation. y grows downward."""
        if self.rotation == 0:
            return x, y
        if self.rotation == 90:
            return y, 1.0 - x
        if self.rotation == 180:
            return 1.0 - x, 1.0 - y
        if self.rotation == 270:
            return 1.0 - y, x
        raise ValueError("OCR rotation must be 0, 90, 180, or 270")

    @staticmethod
    def _quarter_turn(value: Any) -> int:
        if isinstance(value, bool):
            raise ValueError("OCR rotation must be 0, 90, 180, or 270")
        if isinstance(value, int) and value in _QUARTER_TURNS:
            return value
        raise ValueError("OCR rotation must be 0, 90, 180, or 270")

    @staticmethod
    def _matching_line(
        lines: list[dict[str, Any]], word: OCRWord, vertical: bool
    ) -> int | None:
        for index, line in enumerate(lines):
            if line["vertical"] is not vertical:
                continue
            if vertical:
                overlap = min(line["right"], word.right) - max(line["left"], word.left)
                limit = min(line["right"] - line["left"], word.right - word.left)
            else:
                overlap = min(line["bottom"], word.bottom) - max(line["top"], word.top)
                limit = min(line["bottom"] - line["top"], word.bottom - word.top)
            if limit > 0.0 and overlap >= limit * 0.5:
                return index
        return None

    @staticmethod
    def _join_text(words: list[OCRWord]) -> str:
        return " ".join(word.text for word in words)

    @staticmethod
    def _assert_size(width: Any, height: Any) -> None:
        if (
            not isinstance(width, int)
            or isinstance(width, bool)
            or not isinstance(height, int)
            or isinstance(height, bool)
            or width < 1
            or height < 1
        ):
            raise ValueError("Image width and height must be positive")

    @staticmethod
    def _number(value: Any, name: str) -> float:
        if isinstance(value, bool):
            raise ValueError(f"OCR {name} must be a number")
        if isinstance(value, int):
            return float(value)
        if isinstance(value, float):
            return value
        if isinstance(value, str) and value.strip() != "":
            try:
                return float(value)
            except ValueError as exc:
                raise ValueError(f"OCR {name} must be a number") from exc
        raise ValueError(f"OCR {name} must be a number")

    @staticmethod
    def _page(value: Any) -> int:
        if value is None:
            return 0
        if isinstance(value, bool):
            raise ValueError("OCR page must be a non-negative integer")
        if isinstance(value, int) and value >= 0:
            return value
        if isinstance(value, str) and re.fullmatch(r"\d+", value):
            return int(value)
        raise ValueError("OCR page must be a non-negative integer")
