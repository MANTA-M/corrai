"""Unit tests for OCRResult (mirrors PHP OCRResultTest)."""

from __future__ import annotations

import unittest

from pycorrai.model.ocr_result import OCRResult
from pycorrai.model.ocr_word import OCRWord


class OCRResultTest(unittest.TestCase):
    def test_json_round_trip(self) -> None:
        result = OCRResult(
            [
                OCRWord("Une", 0.0435, 0.0406, 0.1242, 0.0656, 0),
                OCRWord("makine", 0.13, 0.04, 0.28, 0.07, 0),
            ],
            "Une makine",
        )

        restored = OCRResult.from_json(result.to_json())

        self.assertEqual(restored.text, "Une makine")
        self.assertEqual(len(restored.words), 2)
        self.assertEqual(restored.words[1].text, "makine")
        self.assertEqual(restored.words[1].page, 0)
        self.assertAlmostEqual(restored.words[1].left, 0.13, places=12)
        self.assertAlmostEqual(restored.words[1].top, 0.04, places=12)
        self.assertAlmostEqual(restored.words[1].right, 0.28, places=12)
        self.assertAlmostEqual(restored.words[1].bottom, 0.07, places=12)
        self.assertEqual(result.to_array(), restored.to_array())

    def test_missing_text_is_joined_from_words(self) -> None:
        result = OCRResult.from_array(
            {
                "words": [
                    {
                        "text": "Une",
                        "left": 0.1,
                        "top": 0.2,
                        "right": 0.3,
                        "bottom": 0.4,
                    },
                    {
                        "text": "makine",
                        "left": 0.3,
                        "top": 0.2,
                        "right": 0.5,
                        "bottom": 0.4,
                    },
                ],
            }
        )

        self.assertEqual(result.text, "Une makine")
        self.assertEqual(result.words[0].page, 0)

    def test_google_boxes_stay_proportions(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "Une makine",
                "bounding_boxes": [
                    {
                        "text": "Une",
                        "left": 0.0435244161358811,
                        "top": 0.040625,
                        "width": 0.08067940552016985,
                        "height": 0.025,
                    },
                ],
                "usage": None,
            }
        )

        word = result.words[0]
        self.assertEqual(result.text, "Une makine")
        self.assertEqual(word.page, 0)
        self.assertAlmostEqual(word.left, 0.0435244161358811, places=12)
        self.assertAlmostEqual(word.top, 0.040625, places=12)
        self.assertAlmostEqual(
            word.right, 0.0435244161358811 + 0.08067940552016985, places=12
        )
        self.assertAlmostEqual(word.bottom, 0.040625 + 0.025, places=12)

    def test_google_boxes_with_negative_width_or_height_are_normalized(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "Examen",
                "bounding_boxes": [
                    {
                        "text": "Examen",
                        "left": 0.936,
                        "top": 0.346,
                        "width": -0.006,
                        "height": 0.059,
                    },
                ],
                "usage": None,
            }
        )

        word = result.words[0]
        self.assertEqual(word.text, "Examen")
        self.assertAlmostEqual(word.left, 0.930, places=4)
        self.assertAlmostEqual(word.right, 0.936, places=4)
        self.assertGreaterEqual(word.right, word.left)

    def test_paddle_pixels_become_proportions_of_the_image(self) -> None:
        result = OCRResult.from_paddle(
            [
                {"text": "Une", "page": 0, "box": [40, 80, 140, 120]},
                {"text": "page", "page": 1, "box": [10, 20, 30, 40]},
            ],
            1000,
            2000,
            {1: (100, 400)},
        )

        self.assertEqual(result.text, "Une page")
        self.assertAlmostEqual(result.words[0].left, 0.04, places=12)
        self.assertAlmostEqual(result.words[0].top, 0.04, places=12)
        self.assertAlmostEqual(result.words[0].right, 0.14, places=12)
        self.assertAlmostEqual(result.words[0].bottom, 0.06, places=12)
        self.assertAlmostEqual(result.words[1].left, 0.1, places=12)
        self.assertAlmostEqual(result.words[1].top, 0.05, places=12)
        self.assertAlmostEqual(result.words[1].right, 0.3, places=12)
        self.assertAlmostEqual(result.words[1].bottom, 0.1, places=12)
        self.assertEqual(result.words[1].page, 1)

    def test_upright_lines_are_not_rotated(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "Une makine sur la page",
                "bounding_boxes": [
                    {
                        "text": "Une",
                        "left": 0.04,
                        "top": 0.04,
                        "width": 0.10,
                        "height": 0.02,
                    },
                    {
                        "text": "makine",
                        "left": 0.15,
                        "top": 0.04,
                        "width": 0.16,
                        "height": 0.02,
                    },
                    {
                        "text": "sur",
                        "left": 0.32,
                        "top": 0.04,
                        "width": 0.08,
                        "height": 0.02,
                    },
                    {
                        "text": "la",
                        "left": 0.04,
                        "top": 0.08,
                        "width": 0.06,
                        "height": 0.02,
                    },
                    {
                        "text": "page",
                        "left": 0.11,
                        "top": 0.08,
                        "width": 0.12,
                        "height": 0.02,
                    },
                ],
            }
        )

        result.detect_rotation()
        self.assertEqual(result.rotation, 0)
        self.assertFalse(result.is_rotated())

    def test_negative_first_vertices_mark_a_turned_page(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "Une makine",
                "bounding_boxes": [
                    {
                        "text": "Une",
                        "left": 0.10,
                        "top": -0.08,
                        "width": 0.02,
                        "height": 0.18,
                    },
                    {
                        "text": "makine",
                        "left": 0.10,
                        "top": 0.12,
                        "width": 0.025,
                        "height": 0.30,
                    },
                    {
                        "text": "I",
                        "left": 0.40,
                        "top": 0.20,
                        "width": 0.04,
                        "height": 0.02,
                    },
                ],
            }
        )

        word = result.words[0]
        result.detect_rotation()
        self.assertLess(word.top, 0.0)
        self.assertEqual(result.rotation, 90)
        self.assertTrue(result.is_rotated())

    def test_negative_origin_on_a_horizontal_line_is_not_rotated(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "Une makine",
                "bounding_boxes": [
                    {
                        "text": "Une",
                        "left": -0.04,
                        "top": 0.10,
                        "width": 0.12,
                        "height": 0.02,
                    },
                    {
                        "text": "makine",
                        "left": 0.10,
                        "top": 0.10,
                        "width": 0.20,
                        "height": 0.02,
                    },
                ],
            }
        )

        result.detect_rotation()
        self.assertLess(result.words[0].left, 0.0)
        self.assertEqual(result.rotation, 0)
        self.assertFalse(result.is_rotated())

    def test_a_single_vertical_word_sets_rotation(self) -> None:
        result = OCRResult.from_google(
            {
                "text": "I",
                "bounding_boxes": [
                    {
                        "text": "I",
                        "left": 0.40,
                        "top": 0.10,
                        "width": 0.02,
                        "height": 0.40,
                    },
                ],
            }
        )

        result.detect_rotation()
        self.assertEqual(result.rotation, 90)
        self.assertTrue(result.is_rotated())

    def test_rotate_upright_restores_each_quarter_turn(self) -> None:
        upright = [
            OCRWord("Une", 0.10, 0.10, 0.28, 0.15),
            OCRWord("makine", 0.32, 0.10, 0.58, 0.15),
            OCRWord("page", 0.10, 0.22, 0.30, 0.27),
        ]
        for angle in (0, 90, 180, 270):
            turned = [self._turn_word(word, angle) for word in upright]
            result = OCRResult(turned, "Une makine page")

            result.detect_rotation()
            self.assertEqual(result.rotation, angle)
            result.rotate_upright()
            self.assertEqual(result.rotation, 0)
            self.assertFalse(result.is_rotated())
            for index, word in enumerate(upright):
                self.assertAlmostEqual(word.left, result.words[index].left, places=9)
                self.assertAlmostEqual(word.top, result.words[index].top, places=9)
                self.assertAlmostEqual(word.right, result.words[index].right, places=9)
                self.assertAlmostEqual(
                    word.bottom, result.words[index].bottom, places=9
                )

    def test_global_box_grows_by_the_margin(self) -> None:
        result = OCRResult(
            [
                OCRWord("Une", 0.20, 0.30, 0.50, 0.60),
                OCRWord("makine", 0.40, 0.10, 0.45, 0.20),
            ],
            "Une makine",
        )

        box = result.get_global_box(0.05)

        self.assertAlmostEqual(box["left"], 0.15, places=12)
        self.assertAlmostEqual(box["top"], 0.05, places=12)
        self.assertAlmostEqual(box["right"], 0.55, places=12)
        self.assertAlmostEqual(box["bottom"], 0.65, places=12)

    def test_resize_to_global_fits_the_text_in_the_page(self) -> None:
        result = OCRResult(
            [OCRWord("Une", 0.25, 0.25, 0.75, 0.75)],
            "Une",
        )

        result.resize_to_global(result.get_global_box(0.25))

        word = result.words[0]
        self.assertAlmostEqual(word.left, 0.25, places=12)
        self.assertAlmostEqual(word.top, 0.25, places=12)
        self.assertAlmostEqual(word.right, 0.75, places=12)
        self.assertAlmostEqual(word.bottom, 0.75, places=12)

    def test_invalid_json_is_rejected(self) -> None:
        with self.assertRaises(ValueError):
            OCRResult.from_json("{")

    def test_inverted_box_is_rejected(self) -> None:
        with self.assertRaises(ValueError):
            OCRWord("Une", 0.5, 0.1, 0.2, 0.3)

    def _turn_word(self, word: OCRWord, angle: int) -> OCRWord:
        xs: list[float] = []
        ys: list[float] = []
        for x, y in (
            (word.left, word.top),
            (word.right, word.top),
            (word.right, word.bottom),
            (word.left, word.bottom),
        ):
            if angle == 0:
                nx, ny = x, y
            elif angle == 90:
                nx, ny = 1.0 - y, x
            elif angle == 180:
                nx, ny = 1.0 - x, 1.0 - y
            elif angle == 270:
                nx, ny = y, 1.0 - x
            else:
                raise ValueError("Unexpected angle")
            xs.append(nx)
            ys.append(ny)
        return OCRWord(word.text, min(xs), min(ys), max(xs), max(ys), word.page)


if __name__ == "__main__":
    unittest.main()
