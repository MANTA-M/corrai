"""Shared client-layer exceptions."""

from __future__ import annotations


class UnsupportedModelError(ValueError):
    """Raised when an LLM factory cannot resolve a model id."""

    def __init__(self, model: str) -> None:
        self.model = model
        super().__init__(f"Model not supported: {model}")
