"""OCR a file and print one JSON object per recognized word."""

from __future__ import annotations

import argparse
import json
import sys

from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES, recognize, recognize_bytes
from pycorrai.ocr.paddle_backend import get_engine, words_from_pages

__all__ = [
    "DEFAULT_ENGINE",
    "ENGINES",
    "build_parser",
    "get_engine",
    "main",
    "recognize",
    "recognize_bytes",
    "words_from_pages",
]


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="pycorrai",
        description="OCR file bytes from stdin and print each word with its bounding box as JSON.",
    )
    parser.add_argument(
        "--lang",
        default="fr",
        help="PaddleOCR language code (default: fr; ignored by docTR).",
    )
    parser.add_argument(
        "--engine",
        default=DEFAULT_ENGINE,
        choices=sorted(ENGINES),
        help=f"OCR backend (default: {DEFAULT_ENGINE}).",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    data = sys.stdin.buffer.read()
    if not data:
        print("No file data on stdin", file=sys.stderr)
        return 1
    try:
        words = recognize_bytes(data, args.lang, args.engine)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    json.dump(words, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0
