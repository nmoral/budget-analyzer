import os
from pathlib import Path

os.environ.setdefault(
    "BUDGET_DATA_DIR", str(Path(__file__).resolve().parents[2] / "data")
)
