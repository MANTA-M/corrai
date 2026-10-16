"""Assessment-related LLM system prompts."""

from pycorrai.model.prompts.correction_grid import (
    CORRECTION_GRID_SYSTEM_PROMPT,
    MATH_CORRECTION_GRID_SYSTEM_PROMPT,
    STRICT_CORRECTION_GRID_SYSTEM_PROMPT,
)
from pycorrai.model.prompts.law_correction import law_correction_system_prompt
from pycorrai.model.prompts.math_correction import math_correction_system_prompt

__all__ = [
    "CORRECTION_GRID_SYSTEM_PROMPT",
    "MATH_CORRECTION_GRID_SYSTEM_PROMPT",
    "STRICT_CORRECTION_GRID_SYSTEM_PROMPT",
    "law_correction_system_prompt",
    "math_correction_system_prompt",
]
