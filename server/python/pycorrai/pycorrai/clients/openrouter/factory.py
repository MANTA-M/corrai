"""LLM client factory mirroring PHP LlmClientFactory."""

from __future__ import annotations

from typing import Any

from pycorrai.clients.exceptions import UnsupportedModelError
from pycorrai.clients.mistral import MistralClient

from .claude_sonnet_client import ClaudeSonnetClient
from .deepseek_v32_client import DeepSeekV32Client
from .gemini3_client import Gemini3Client
from .gemini_flash_client import GeminiFlashClient
from .gemini_flash_lite_client import GeminiFlashLiteClient
from .gpt4o_mini_client import Gpt4oMiniClient
from .gpt61_sol_client import Gpt61SolClient
from .qwen25_vl72b_instruct_client import Qwen25Vl72bInstructClient


def native_mistral_model_id(model: str) -> str:
    """Map OpenRouter-style slugs (``mistralai/...``) to native Mistral API ids."""
    name = model.split("/", 1)[-1].strip() if "/" in model else model.strip()
    if name in ("", "mistral-large", "mistral-large-2512"):
        return "mistral-large-latest"
    return name


class LlmClientFactory:
    @staticmethod
    def create(model: str) -> Any:
        """Return an OpenRouter client, or the direct Mistral API client for Mistral."""
        lower = model.lower()
        if "qwen" in lower:
            return Qwen25Vl72bInstructClient(model)
        if "deepseek" in lower:
            return DeepSeekV32Client(model)
        if "gpt-6.1-sol" in lower or model == "openai/gpt-6.1-sol":
            return Gpt61SolClient(model)
        if "gpt-4o-mini" in lower or model == "openai/gpt-4o-mini":
            return Gpt4oMiniClient(model)
        if "anthropic" in lower or "claude" in lower:
            return ClaudeSonnetClient()
        if "google" in lower or "gemini" in lower:
            if "flash-lite" in lower:
                return GeminiFlashLiteClient()
            if "flash" in lower and "flash-image" not in lower:
                return GeminiFlashClient()
            return Gemini3Client(model)
        if "mistral" in lower:
            return MistralClient(native_mistral_model_id(model))
        raise UnsupportedModelError(model)
