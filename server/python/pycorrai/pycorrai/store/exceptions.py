"""Object store exceptions."""

from __future__ import annotations


class StoreConflictException(Exception):
    """Raised when an If-Match conditional put fails (HTTP 412)."""

    def __init__(
        self,
        message: str = "Object store conflict",
        code: int = 412,
        previous: BaseException | None = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.__cause__ = previous
