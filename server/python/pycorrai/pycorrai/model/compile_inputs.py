"""Compile OCR text from a list of InputFile into a ``{"pages": [...]}`` JSON object."""

from __future__ import annotations

import json
import logging
from collections.abc import Callable, Sequence
from typing import Any

from pycorrai.model.input_file import InputFile
from pycorrai.store.object_store import ObjectStore

OcrRunner = Callable[..., None]

logger = logging.getLogger(__name__)


def compile_inputs(
    files: Sequence[InputFile],
    output_key: str,
    *,
    re_compile: bool = False,
    legacy_keys: Sequence[str] | None = None,
    ocr_runner: OcrRunner | None = None,
) -> dict[str, Any]:
    """Run OCR when needed and write ``{"pages": [...]}`` to ``output_key``.

    When ``re_compile`` is false, reuse an existing compile file (renaming any
    ``legacy_keys`` onto ``output_key`` first). Otherwise, or when no compile
    exists, run ``gemini_flash_ocr`` then rebuild from each file's
    ``ocr_result.json`` ``text``, keeping the given file order.
    """
    store = ObjectStore.get_instance()
    legacy = list(legacy_keys or [])

    if not re_compile:
        existing = _load_compile(store, output_key, legacy)
        if existing is not None:
            return existing

    ordered = list(files)
    runner = ocr_runner
    if runner is None:
        from pycorrai.ocr.gemini_flash_ocr import gemini_flash_ocr

        runner = gemini_flash_ocr
    runner(ordered, force_re_ocr=False)

    pages = [_ocr_text_for_file(store, file) for file in ordered]
    payload: dict[str, Any] = {"pages": pages}
    body = (
        json.dumps(payload, ensure_ascii=False, indent=2, separators=(",", ": "))
        + "\n"
    )
    store.put_contents(output_key, body, "application/json")
    for legacy_key in legacy:
        if store.exists(legacy_key):
            store.delete(legacy_key)
    return payload


def _load_compile(
    store: ObjectStore,
    json_key: str,
    legacy_keys: list[str],
) -> dict[str, Any] | None:
    for legacy_key in legacy_keys:
        if not store.exists(legacy_key):
            continue
        if not store.exists(json_key):
            store.copy(legacy_key, json_key)
        store.delete(legacy_key)
    if not store.exists(json_key):
        return None
    data = store.get_json(json_key)
    if not isinstance(data, dict):
        return None
    return data


def _ocr_text_for_file(store: ObjectStore, file: InputFile) -> str:
    ocr_key = file.ocr_result_key()
    if not store.exists(ocr_key):
        logger.warning("Missing ocr_result.json for file %s", file.id)
        return ""
    try:
        data = store.get_json(ocr_key)
    except Exception:
        logger.exception("Unreadable ocr_result.json for file %s", file.id)
        return ""
    text = data.get("text") if isinstance(data, dict) else None
    return text if isinstance(text, str) else ""
