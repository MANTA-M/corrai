"""CLI entry points for pycorrai (OCR stdin + operational scripts)."""

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
        description="Corrai Python tools: OCR from stdin, or operational scripts.",
    )
    sub = parser.add_subparsers(dest="command")

    ocr = sub.add_parser(
        "ocr",
        help="OCR file bytes from stdin and print each word as JSON (default).",
    )
    ocr.add_argument(
        "--lang",
        default="fr",
        help="PaddleOCR language code (default: fr; ignored by docTR).",
    )
    ocr.add_argument(
        "--engine",
        default=DEFAULT_ENGINE,
        choices=sorted(ENGINES),
        help=f"OCR backend (default: {DEFAULT_ENGINE}).",
    )

    scripts = sub.add_parser("script", help="Run an operational script.")
    scripts.add_argument(
        "name",
        choices=[
            "rerun-gpt",
            "rerun-jev",
            "rerun-mistral",
            "delete-non-ind-schools",
        ],
        help="Script to run.",
    )
    scripts.add_argument(
        "script_args",
        nargs=argparse.REMAINDER,
        help="Arguments forwarded to the script.",
    )

    # Legacy flat flags (no subcommand) for backward compatibility.
    parser.add_argument(
        "--lang",
        default=None,
        help=argparse.SUPPRESS,
    )
    parser.add_argument(
        "--engine",
        default=None,
        choices=sorted(ENGINES),
        help=argparse.SUPPRESS,
    )
    return parser


def _run_ocr(*, lang: str, engine: str) -> int:
    data = sys.stdin.buffer.read()
    if not data:
        print("No file data on stdin", file=sys.stderr)
        return 1
    try:
        words = recognize_bytes(data, lang, engine)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    json.dump(words, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0


def _run_script(name: str, script_args: list[str]) -> int:
    mapping = {
        "rerun-gpt": "pycorrai.scripts.rerun_gpt_corrections",
        "rerun-jev": "pycorrai.scripts.rerun_jev_corrections",
        "rerun-mistral": "pycorrai.scripts.rerun_mistral_corrections",
        "delete-non-ind-schools": "pycorrai.scripts.delete_non_ind_schools",
    }
    module_name = mapping[name]
    module = __import__(module_name, fromlist=["main"])
    # Scripts that use argparse (e.g. delete_non_ind_schools) read sys.argv.
    # Replace argv so their parsers only see forwarded flags.
    saved = sys.argv
    sys.argv = [module_name, *script_args]
    try:
        return int(module.main())
    finally:
        sys.argv = saved


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    # Default to OCR when no subcommand is given (historical CLI).
    if not argv or argv[0].startswith("-"):
        parser = argparse.ArgumentParser(
            prog="pycorrai",
            description=(
                "OCR file bytes from stdin and print each word with its "
                "bounding box as JSON."
            ),
        )
        parser.add_argument("--lang", default="fr")
        parser.add_argument(
            "--engine",
            default=DEFAULT_ENGINE,
            choices=sorted(ENGINES),
        )
        args = parser.parse_args(argv)
        return _run_ocr(lang=args.lang, engine=args.engine)

    args = build_parser().parse_args(argv)
    if args.command == "ocr":
        return _run_ocr(lang=args.lang, engine=args.engine)
    if args.command == "script":
        return _run_script(args.name, list(args.script_args))
    # Fallback: treat as legacy OCR if somehow reached.
    lang = args.lang if args.lang is not None else "fr"
    engine = args.engine if args.engine is not None else DEFAULT_ENGINE
    return _run_ocr(lang=lang, engine=engine)
