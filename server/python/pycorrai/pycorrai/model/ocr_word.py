"""One recognized word and its axis-aligned box.

``left`` and ``right`` are fractions of the page width.
``top`` and ``bottom`` are fractions of the page height.
Origin is the top-left corner.
"""

from __future__ import annotations

import math
import re
from typing import Any


class OCRWord:
    def __init__(
        self,
        text: str,
        left: float,
        top: float,
        right: float,
        bottom: float,
        page: int = 0,
    ) -> None:
        self._assert_proportion(left, "left")
        self._assert_proportion(top, "top")
        self._assert_proportion(right, "right")
        self._assert_proportion(bottom, "bottom")
        if right < left:
            raise ValueError("OCR right must be greater than or equal to left")
        if bottom < top:
            raise ValueError("OCR bottom must be greater than or equal to top")
        if page < 0:
            raise ValueError("OCR page must be a non-negative integer")
        self.text = text
        self.left = left
        self.top = top
        self.right = right
        self.bottom = bottom
        self.page = page

    @classmethod
    def from_pixels(
        cls,
        text: str,
        left: float,
        top: float,
        right: float,
        bottom: float,
        width: int,
        height: int,
        page: int = 0,
    ) -> OCRWord:
        """Build a word from a pixel box ``[left, top, right, bottom]``."""
        if width < 1 or height < 1:
            raise ValueError("Image width and height must be positive")
        return cls(
            text,
            left / width,
            top / height,
            right / width,
            bottom / height,
            page,
        )

    @classmethod
    def from_array(cls, data: dict[str, Any]) -> OCRWord:
        text = data.get("text")
        if not isinstance(text, str):
            raise ValueError("OCR word text must be a string")
        return cls(
            text,
            cls._number(data.get("left"), "left"),
            cls._number(data.get("top"), "top"),
            cls._number(data.get("right"), "right"),
            cls._number(data.get("bottom"), "bottom"),
            cls._page(data.get("page")),
        )

    def to_array(self) -> dict[str, Any]:
        return {
            "text": self.text,
            "page": self.page,
            "left": self.left,
            "top": self.top,
            "right": self.right,
            "bottom": self.bottom,
        }

    @staticmethod
    def _assert_proportion(value: float, name: str) -> None:
        if not math.isfinite(value):
            raise ValueError(f"OCR {name} must be finite")

    @staticmethod
    def _number(value: Any, name: str) -> float:
        if isinstance(value, bool):
            raise ValueError(f"OCR {name} must be a number")
        if isinstance(value, int):
            return float(value)
        if isinstance(value, float):
            return value
        if isinstance(value, str) and value.strip() != "" and _is_numeric(value):
            return float(value)
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


def _is_numeric(value: str) -> bool:
    try:
        float(value)
    except ValueError:
        return False
    return True
