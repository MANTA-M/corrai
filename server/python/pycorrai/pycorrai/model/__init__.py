"""Domain models for Corrai workers."""

from pycorrai.model.assessment import Assessment
from pycorrai.model.compile_inputs import compile_inputs
from pycorrai.model.input_file import InputFile
from pycorrai.model.instruction_file import InstructionFile
from pycorrai.model.ocr_result import OCRResult
from pycorrai.model.ocr_word import OCRWord
from pycorrai.model.s3_file import S3File
from pycorrai.model.student import Student
from pycorrai.model.subject_file import SubjectFile
from pycorrai.model.submission_file import SubmissionFile

__all__ = [
    "Assessment",
    "compile_inputs",
    "InputFile",
    "InstructionFile",
    "OCRResult",
    "OCRWord",
    "S3File",
    "Student",
    "SubjectFile",
    "SubmissionFile",
]
