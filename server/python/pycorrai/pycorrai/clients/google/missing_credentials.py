"""Raised when Google Vision credentials are missing or unreadable."""


class MissingCredentialsException(RuntimeError):
    """Google Vision was used without a readable service-account key file."""
