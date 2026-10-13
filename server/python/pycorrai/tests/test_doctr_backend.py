"""Unit tests for the python-doctr OCR backend helpers."""

from __future__ import annotations

import unittest

from pycorrai.ocr.doctr_backend import geometry_to_box, words_from_export


class GeometryToBoxTest(unittest.TestCase):
    def test_two_corners(self) -> None:
        self.assertEqual(
            geometry_to_box(((0.1, 0.2), (0.4, 0.5)), width=100, height=200),
            [10, 40, 40, 100],
        )

    def test_flat_box(self) -> None:
        self.assertEqual(
            geometry_to_box([0.1, 0.2, 0.4, 0.5], width=100, height=200),
            [10, 40, 40, 100],
        )

    def test_polygon(self) -> None:
        self.assertEqual(
            geometry_to_box(
                ((0.1, 0.2), (0.4, 0.2), (0.4, 0.5), (0.1, 0.5)),
                width=100,
                height=200,
            ),
            [10, 40, 40, 100],
        )


class WordsFromExportTest(unittest.TestCase):
    def test_flattens_pages_blocks_lines_words(self) -> None:
        exported = {
            "pages": [
                {
                    "page_idx": 0,
                    "dimensions": (200, 100),
                    "blocks": [
                        {
                            "lines": [
                                {
                                    "words": [
                                        {
                                            "value": "Bonjour",
                                            "geometry": ((0.1, 0.2), (0.4, 0.5)),
                                        },
                                        {
                                            "value": "monde",
                                            "geometry": ((0.5, 0.2), (0.9, 0.5)),
                                        },
                                    ]
                                }
                            ]
                        }
                    ],
                }
            ]
        }
        self.assertEqual(
            words_from_export(exported),
            [
                {"text": "Bonjour", "page": 0, "box": [10, 40, 40, 100]},
                {"text": "monde", "page": 0, "box": [50, 40, 90, 100]},
            ],
        )


if __name__ == "__main__":
    unittest.main()
