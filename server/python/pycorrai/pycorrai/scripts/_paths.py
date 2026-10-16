"""Shared path helpers for operational scripts."""

from __future__ import annotations

from pathlib import Path


def repo_root() -> Path:
    """Locate the Corrai repository root (directory containing ``server/``)."""
    here = Path(__file__).resolve()
    for parent in here.parents:
        if (parent / "server" / "python").is_dir() and (parent / "server" / "php").is_dir():
            return parent
    raise RuntimeError(f"Cannot locate Corrai repo root from {here}")


def test_files_root() -> Path:
    return repo_root() / "doc" / "test_files"
