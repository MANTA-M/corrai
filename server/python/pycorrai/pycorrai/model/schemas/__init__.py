"""Assessment-related JSON schemas."""

from pycorrai.model.schemas.correction_grid import (
    correction_grid_schema,
    math_correction_grid_schema,
    strict_correction_grid_schema,
)
from pycorrai.model.schemas.law_correction import law_correction_schema
from pycorrai.model.schemas.math_correction import math_correction_schema

__all__ = [
    "correction_grid_schema",
    "math_correction_grid_schema",
    "strict_correction_grid_schema",
    "law_correction_schema",
    "math_correction_schema",
]
