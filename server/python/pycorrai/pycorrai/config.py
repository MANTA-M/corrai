"""Load shared Corrai env from server/php/.env (same file as PHP)."""

from __future__ import annotations

import os
from pathlib import Path

_loaded = False


def default_env_path() -> Path:
    """Resolve server/php/.env relative to this package."""
    # .../server/python/pycorrai/pycorrai/config.py → parents[3] == server/
    here = Path(__file__).resolve()
    return here.parents[3] / "php" / ".env"


def parse_env_line(line: str) -> tuple[str, str] | None:
    """Parse a KEY=VALUE line the same way PHP Utils::parseEnvLine does."""
    line = line.strip()
    if line == "" or line.startswith("#"):
        return None
    eq = line.find("=")
    if eq < 0:
        return None
    key = line[:eq].strip()
    value = line[eq + 1 :].strip()
    if key == "":
        return None
    quoted = len(value) >= 2 and (
        (value[0] == '"' and value[-1] == '"') or (value[0] == "'" and value[-1] == "'")
    )
    if quoted:
        value = value[1:-1]
    else:
        hash_pos = value.find("#")
        if hash_pos >= 0:
            value = value[:hash_pos].rstrip()
    return key, value


def load_key_value_file(path: str | Path) -> dict[str, str]:
    path = Path(path)
    if not path.is_file():
        raise FileNotFoundError(f"Value key file {path} does not exists")
    result: dict[str, str] = {}
    with path.open("r", encoding="utf-8") as fh:
        for line in fh:
            parsed = parse_env_line(line)
            if parsed is not None:
                result[parsed[0]] = parsed[1]
    return result


def load_dotenv(path: str | Path | None = None, *, overwrite: bool = False) -> Path:
    """Load KEY=VALUE into os.environ.

    By default, keys already present in the process environment are kept
    (same rule as PHP CLI consumers that only putenv when unset).
    """
    global _loaded
    env_path = Path(path) if path is not None else default_env_path()
    values = load_key_value_file(env_path)
    for key, value in values.items():
        if overwrite or key not in os.environ or os.environ.get(key, "") == "":
            os.environ[key] = value
    _loaded = True
    return env_path


def ensure_env_loaded(path: str | Path | None = None) -> None:
    """Idempotent load of the shared .env file."""
    global _loaded
    if _loaded:
        return
    env_path = Path(path) if path is not None else default_env_path()
    if env_path.is_file():
        load_dotenv(env_path)
    else:
        _loaded = True


def env(key: str, default: str = "") -> str:
    """Read an env var after ensuring the shared .env is loaded."""
    ensure_env_loaded()
    value = os.environ.get(key)
    if value is None or value == "":
        return default
    return value


def reset_env_loaded_for_tests() -> None:
    """Test helper to re-run ensure_env_loaded."""
    global _loaded
    _loaded = False
