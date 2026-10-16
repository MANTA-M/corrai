#!/usr/bin/env python3
"""Compat wrapper — prefer ``python -m pycorrai.scripts.rerun_jev_corrections``."""

from pycorrai.scripts.rerun_jev_corrections import main

if __name__ == "__main__":
    raise SystemExit(main())
