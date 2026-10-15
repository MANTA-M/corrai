"""OpenRouter LLM clients (mirrors PHP Corrai\\Clients\\Openrouter)."""

from .claude_sonnet_client import ClaudeSonnetClient
from .deepseek_v32_client import DeepSeekV32Client
from .factory import LlmClientFactory
from .gemini3_client import Gemini3Client
from .gemini_flash_client import GeminiFlashClient
from .gemini_flash_lite_client import GeminiFlashLiteClient
from .gpt4o_mini_client import Gpt4oMiniClient
from .gpt61_sol_client import Gpt61SolClient
from .jev_client import (
    ChoiceAnswer,
    DecisionsResponse,
    DecisionsUsage,
    JevClient,
    NoulAnswer,
    ScoreAnswer,
)
from .mistral_large_client import MistralLargeClient
from .openrouter_client import OpenrouterClient
from .qwen25_vl72b_instruct_client import Qwen25Vl72bInstructClient

create = LlmClientFactory.create

__all__ = [
    "OpenrouterClient",
    "LlmClientFactory",
    "create",
    "ClaudeSonnetClient",
    "GeminiFlashClient",
    "GeminiFlashLiteClient",
    "Gemini3Client",
    "Gpt4oMiniClient",
    "Gpt61SolClient",
    "Qwen25Vl72bInstructClient",
    "DeepSeekV32Client",
    "MistralLargeClient",
    "JevClient",
    "NoulAnswer",
    "ChoiceAnswer",
    "ScoreAnswer",
    "DecisionsResponse",
    "DecisionsUsage",
]
