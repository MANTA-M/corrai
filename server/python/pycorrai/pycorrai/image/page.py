"""Detect the page zone and its angle on a scanned or photographed sheet."""

from __future__ import annotations

import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable

import cv2
import numpy as np

# Share of the contour perimeter used to simplify it to a polygon.
_APPROX_EPSILON = 0.02

# Long-lived document-orientation classifier. Loaded once per process.
_classifier: Any = None


@dataclass(frozen=True)
class PageZone:
    """Page quadrilateral and the axis-aligned box that contains it.

    ``quad`` is ordered top-left, top-right, bottom-right, bottom-left.
    ``box`` is ``(x, y, width, height)`` in pixels, origin top-left.
    ``angle`` is the tilt of the top edge in degrees. Positive means the
    right end of that edge sits lower in the image.
    """

    quad: tuple[tuple[int, int], tuple[int, int], tuple[int, int], tuple[int, int]]
    box: tuple[int, int, int, int]
    angle: float


@dataclass(frozen=True)
class PageDetection:
    """Cropped page together with the zone and orientation that produced it."""

    zone: PageZone | None
    orientation: int | None
    image: np.ndarray

    def as_dict(self) -> dict[str, Any]:
        """JSON-ready angle and zone. The cropped image is not included."""
        if self.zone is None:
            zone: dict[str, Any] | None = None
            angle = 0.0
        else:
            zone = {
                "quad": [list(point) for point in self.zone.quad],
                "box": list(self.zone.box),
            }
            angle = round(self.zone.angle, 2)
        return {
            "angle": angle,
            "orientation": self.orientation,
            "zone": zone,
        }


def find_page_zone(image: np.ndarray) -> PageZone | None:
    """Return the largest 4-point page contour, or None when none is found."""
    edges = _edges(image)
    contours, _hierarchy = cv2.findContours(
        edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    contours = sorted(contours, key=cv2.contourArea, reverse=True)
    for contour in contours:
        perimeter = cv2.arcLength(contour, True)
        approx = cv2.approxPolyDP(contour, _APPROX_EPSILON * perimeter, True)
        if len(approx) != 4:
            continue
        quad = _order_quad(approx.reshape(4, 2))
        x, y, width, height = cv2.boundingRect(approx)
        return PageZone(
            quad=_as_points(quad),
            box=(int(x), int(y), int(width), int(height)),
            angle=_top_edge_angle(quad),
        )
    return None


def crop_document(image: np.ndarray) -> np.ndarray:
    """Crop ``image`` to the page bounding box.

    Returns a copy of ``image`` when no page quadrilateral is found.
    """
    zone = find_page_zone(image)
    if zone is None:
        return image.copy()
    return _crop_box(image, zone.box)


def detect_page(
    image: np.ndarray,
    *,
    classify: bool = True,
    classify_angle: Callable[[np.ndarray], int] | None = None,
) -> PageDetection:
    """Find the page zone, crop it, and classify which way is up.

    ``classify_angle`` replaces the PaddleOCR document-orientation model.
    Pass ``classify=False`` to skip orientation and only measure the zone.
    """
    zone = find_page_zone(image)
    cropped = image.copy() if zone is None else _crop_box(image, zone.box)
    orientation: int | None
    if classify_angle is not None:
        orientation = _normalize_orientation(classify_angle(cropped))
    elif classify:
        orientation = classify_page_orientation(cropped)
    else:
        orientation = None
    return PageDetection(zone=zone, orientation=orientation, image=cropped)


def classify_page_orientation(image: np.ndarray) -> int:
    """Return the page orientation in degrees: 0, 90, 180, or 270.

    Uses PaddleOCR's document-orientation model, the replacement for
    ``use_angle_cls`` on a whole page. The model is loaded once per process.
    """
    classifier = get_orientation_classifier()
    results = classifier.predict(image)
    if not results:
        raise ValueError("Document orientation model returned no result")
    return _orientation_from_result(results[0])


def get_orientation_classifier() -> Any:
    """Return the cached document-orientation classifier."""
    global _classifier
    if _classifier is not None:
        return _classifier

    import os

    os.environ["PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT"] = "0"
    os.environ["FLAGS_use_mkldnn"] = "0"
    from paddleocr import DocImgOrientationClassification

    _classifier = DocImgOrientationClassification()
    return _classifier


def read_image(path: str | Path) -> np.ndarray:
    """Read a color image from ``path``."""
    image = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError(f"Cannot read image: {path}")
    return image


def image_from_bytes(data: bytes) -> np.ndarray:
    """Decode image bytes into a BGR array."""
    if not data:
        raise ValueError("Image bytes must not be empty")
    array = np.frombuffer(data, dtype=np.uint8)
    image = cv2.imdecode(array, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("Unsupported image data")
    return image


def _edges(image: np.ndarray) -> np.ndarray:
    gray = _gray(image)
    blur = cv2.GaussianBlur(gray, (5, 5), 0)
    return cv2.Canny(blur, 75, 200)


def _gray(image: np.ndarray) -> np.ndarray:
    if image.ndim == 2:
        return image
    channels = image.shape[2]
    if channels == 4:
        return cv2.cvtColor(image, cv2.COLOR_BGRA2GRAY)
    return cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)


def _order_quad(points: np.ndarray) -> np.ndarray:
    """Order four points as top-left, top-right, bottom-right, bottom-left."""
    ordered = np.zeros((4, 2), dtype=np.float32)
    sums = points.sum(axis=1)
    ordered[0] = points[np.argmin(sums)]
    ordered[2] = points[np.argmax(sums)]
    diffs = np.diff(points, axis=1).reshape(-1)
    ordered[1] = points[np.argmin(diffs)]
    ordered[3] = points[np.argmax(diffs)]
    return ordered


def _top_edge_angle(quad: np.ndarray) -> float:
    top_left, top_right = quad[0], quad[1]
    dx = float(top_right[0] - top_left[0])
    dy = float(top_right[1] - top_left[1])
    return math.degrees(math.atan2(dy, dx))


def _as_points(
    quad: np.ndarray,
) -> tuple[tuple[int, int], tuple[int, int], tuple[int, int], tuple[int, int]]:
    points = []
    for x, y in quad:
        points.append((int(round(float(x))), int(round(float(y)))))
    return (points[0], points[1], points[2], points[3])


def _crop_box(image: np.ndarray, box: tuple[int, int, int, int]) -> np.ndarray:
    x, y, width, height = box
    if width <= 0 or height <= 0:
        return image.copy()
    return image[y : y + height, x : x + width].copy()


def _orientation_from_result(result: Any) -> int:
    labels = result.get("label_names") if hasattr(result, "get") else None
    label = _first_label(labels)
    if label is None:
        raise ValueError("Document orientation result has no label")
    return _normalize_orientation(label)


def _first_label(value: Any) -> str | None:
    if isinstance(value, str):
        return value
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, np.ndarray):
        return _first_label(value.tolist())
    if isinstance(value, (list, tuple)):
        for item in value:
            label = _first_label(item)
            if label is not None and label != "":
                return label
    return None


def _normalize_orientation(value: Any) -> int:
    angle = int(round(float(value))) % 360
    if angle not in (0, 90, 180, 270):
        raise ValueError(f"Page orientation must be 0, 90, 180, or 270, got {value}")
    return angle
