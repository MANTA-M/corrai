"""Unit tests for page zone and angle detection."""

from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

import cv2
import numpy as np

from pycorrai.page import (
    crop_document,
    detect_page,
    find_page_zone,
    image_from_bytes,
)


def _sheet(width: int = 400, height: int = 500) -> np.ndarray:
    """Dark background with an axis-aligned white page inset from the edges."""
    image = np.full((height, width, 3), 20, dtype=np.uint8)
    cv2.rectangle(image, (40, 30), (width - 50, height - 40), (255, 255, 255), -1)
    return image


def _rotated_sheet(angle: float) -> np.ndarray:
    image = np.full((700, 600, 3), 20, dtype=np.uint8)
    rect = ((300, 350), (340, 460), angle)
    corners = cv2.boxPoints(rect).astype(np.int32)
    cv2.fillConvexPoly(image, corners, (255, 255, 255))
    return image


class FindPageZoneTest(unittest.TestCase):
    def test_axis_aligned_page_box_and_angle(self) -> None:
        image = _sheet()
        zone = find_page_zone(image)
        self.assertIsNotNone(zone)
        assert zone is not None
        x, y, width, height = zone.box
        self.assertAlmostEqual(x, 40, delta=4)
        self.assertAlmostEqual(y, 30, delta=4)
        self.assertAlmostEqual(width, 310, delta=8)
        self.assertAlmostEqual(height, 430, delta=8)
        self.assertAlmostEqual(zone.angle, 0.0, delta=2.0)
        self.assertEqual(len(zone.quad), 4)

    def test_rotated_page_reports_tilt(self) -> None:
        image = _rotated_sheet(12.0)
        zone = find_page_zone(image)
        self.assertIsNotNone(zone)
        assert zone is not None
        self.assertAlmostEqual(zone.angle, 12.0, delta=3.0)
        self.assertGreater(zone.box[2], 300)
        self.assertGreater(zone.box[3], 300)

    def test_skips_a_larger_contour_that_is_not_a_quadrilateral(self) -> None:
        image = np.full((400, 500, 3), 20, dtype=np.uint8)
        cv2.circle(image, (250, 300), 120, (255, 255, 255), -1)
        cv2.rectangle(image, (20, 20), (120, 140), (255, 255, 255), -1)
        zone = find_page_zone(image)
        self.assertIsNotNone(zone)
        assert zone is not None
        x, y, width, height = zone.box
        self.assertLess(x + width, 150)
        self.assertLess(y + height, 160)

    def test_blank_image_has_no_zone(self) -> None:
        image = np.full((200, 200, 3), 20, dtype=np.uint8)
        self.assertIsNone(find_page_zone(image))


class CropDocumentTest(unittest.TestCase):
    def test_crop_matches_the_page_box(self) -> None:
        image = _sheet()
        cropped = crop_document(image)
        zone = find_page_zone(image)
        assert zone is not None
        _x, _y, width, height = zone.box
        self.assertEqual(cropped.shape[0], height)
        self.assertEqual(cropped.shape[1], width)
        self.assertGreater(int(cropped.mean()), 200)

    def test_missing_page_returns_the_original_pixels(self) -> None:
        image = np.full((80, 60, 3), 40, dtype=np.uint8)
        cropped = crop_document(image)
        self.assertEqual(cropped.shape, image.shape)
        self.assertTrue(np.array_equal(cropped, image))
        cropped[0, 0] = (0, 0, 0)
        self.assertEqual(tuple(image[0, 0]), (40, 40, 40))


class DetectPageTest(unittest.TestCase):
    def test_classify_angle_runs_on_the_crop(self) -> None:
        image = _sheet()
        seen: list[tuple[int, int]] = []

        def classify_angle(cropped: np.ndarray) -> int:
            seen.append((cropped.shape[1], cropped.shape[0]))
            return 180

        detection = detect_page(image, classify_angle=classify_angle)
        self.assertEqual(detection.orientation, 180)
        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0], (detection.image.shape[1], detection.image.shape[0]))
        record = detection.as_dict()
        self.assertEqual(record["orientation"], 180)
        self.assertIsNotNone(record["zone"])
        self.assertEqual(len(record["zone"]["quad"]), 4)
        self.assertEqual(len(record["zone"]["box"]), 4)
        self.assertAlmostEqual(record["angle"], 0.0, delta=2.0)

    def test_classify_false_skips_orientation(self) -> None:
        detection = detect_page(_sheet(), classify=False)
        self.assertIsNone(detection.orientation)
        self.assertIsNotNone(detection.zone)
        self.assertIsNone(detection.as_dict()["orientation"])

    def test_no_zone_still_classifies_the_original(self) -> None:
        image = np.full((90, 70, 3), 15, dtype=np.uint8)
        detection = detect_page(image, classify_angle=lambda _cropped: 90)
        self.assertIsNone(detection.zone)
        self.assertEqual(detection.orientation, 90)
        self.assertEqual(detection.as_dict()["angle"], 0.0)
        self.assertIsNone(detection.as_dict()["zone"])
        self.assertEqual(detection.image.shape, image.shape)

    def test_rejects_an_orientation_that_is_not_a_right_angle(self) -> None:
        with self.assertRaises(ValueError):
            detect_page(_sheet(), classify_angle=lambda _cropped: 15)


class OrientationModelTest(unittest.TestCase):
    def test_classify_reads_the_model_label(self) -> None:
        import pycorrai.page as page

        fake = MagicMock()
        fake.predict.return_value = [{"label_names": [["270"]], "scores": [[0.99]]}]
        image = _sheet()
        with patch.object(page, "_classifier", fake):
            detection = page.detect_page(image)
        self.assertEqual(detection.orientation, 270)
        fake.predict.assert_called_once()


class ImageBytesTest(unittest.TestCase):
    def test_round_trip_png_bytes(self) -> None:
        image = _sheet(120, 140)
        ok, encoded = cv2.imencode(".png", image)
        self.assertTrue(ok)
        decoded = image_from_bytes(encoded.tobytes())
        self.assertEqual(decoded.shape, image.shape)

    def test_empty_bytes_rejected(self) -> None:
        with self.assertRaises(ValueError):
            image_from_bytes(b"")


if __name__ == "__main__":
    unittest.main()
