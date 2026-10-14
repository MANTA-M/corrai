"""Mistral LLM and OCR clients."""

from pycorrai.clients.mistral.mistral_client import MistralClient
from pycorrai.clients.mistral.mistral_ocr_client import MistralOcrClient

# Aliases matching PHP Client / OcrClient / MistralDirectClient subclasses.
Client = MistralClient
OcrClient = MistralOcrClient
MistralDirectClient = MistralClient

__all__ = [
    "MistralClient",
    "MistralOcrClient",
    "Client",
    "OcrClient",
    "MistralDirectClient",
]
