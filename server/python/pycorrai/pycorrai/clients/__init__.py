"""LLM provider clients (Python mirrors of PHP Corrai\\Clients).

OCR providers live under ``pycorrai.ocr.providers`` and are re-exported here
for backward compatibility.
"""

from pycorrai.clients.eden import GoogleOCRClient
from pycorrai.clients.exceptions import UnsupportedModelError
from pycorrai.clients.google import MissingCredentialsException, Vision
from pycorrai.clients.mindee import MindeeOCR
from pycorrai.clients.mistral import (
    Client,
    MistralClient,
    MistralDirectClient,
    MistralOcrClient,
    OcrClient,
)
from pycorrai.clients.openrouter import LlmClientFactory

__all__ = [
    "LlmClientFactory",
    "UnsupportedModelError",
    "MistralClient",
    "MistralOcrClient",
    "Client",
    "OcrClient",
    "MistralDirectClient",
    "GoogleOCRClient",
    "Vision",
    "MissingCredentialsException",
    "MindeeOCR",
]
