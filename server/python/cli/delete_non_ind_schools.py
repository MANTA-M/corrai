#!/usr/bin/env python3
"""Compat wrapper — prefer ``python -m pycorrai.scripts.delete_non_ind_schools``."""

from pycorrai.scripts.delete_non_ind_schools import main

if __name__ == "__main__":
    raise SystemExit(main())
