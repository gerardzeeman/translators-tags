"""
fetch_sources.py
Downloads the three source repositories / files into /data/sources.
Designed to be re-run safely: existing repos are updated with git pull.
"""
import os
import subprocess
import requests
from pathlib import Path

SOURCES_DIR = Path("/data/sources")

REPOS = [
    {
        "name": "stepbible",
        "url": "https://github.com/STEPBible/STEPBible-Data.git",
        "dest": SOURCES_DIR / "stepbible",
        "depth": 1,
    },
    {
        "name": "elzevir",
        "url": "https://github.com/byztxt/greektext-elzevir.git",
        "dest": SOURCES_DIR / "elzevir",
        "depth": 1,
    },
]

DIRECT_FILES = [
    {
        "name": "Statenvertaling XML",
        "url": (
            "https://raw.githubusercontent.com/seven1m/open-bibles/"
            "master/dut-statenvertaling.zefania.xml"
        ),
        "dest": SOURCES_DIR / "dut-statenvertaling.zefania.xml",
    },
]


def clone_or_pull(repo: dict) -> None:
    dest: Path = repo["dest"]
    if (dest / ".git").exists():
        print(f"  Updating {repo['name']} …")
        subprocess.run(["git", "-C", str(dest), "pull", "--ff-only"],
                       check=True, capture_output=True)
    else:
        print(f"  Cloning {repo['name']} …")
        dest.parent.mkdir(parents=True, exist_ok=True)
        cmd = ["git", "clone", "--depth", str(repo["depth"]),
               repo["url"], str(dest)]
        subprocess.run(cmd, check=True)
    print(f"  ✓ {repo['name']}")


def download_file(item: dict) -> None:
    dest: Path = item["dest"]
    if dest.exists():
        print(f"  ✓ {item['name']} already present, skipping download")
        return
    dest.parent.mkdir(parents=True, exist_ok=True)
    print(f"  Downloading {item['name']} …")
    resp = requests.get(item["url"], timeout=120, stream=True)
    resp.raise_for_status()
    with open(dest, "wb") as f:
        for chunk in resp.iter_content(chunk_size=65536):
            f.write(chunk)
    print(f"  ✓ {item['name']} → {dest}")


def fetch_all() -> None:
    print("\n=== Fetching source data ===")
    SOURCES_DIR.mkdir(parents=True, exist_ok=True)
    for repo in REPOS:
        clone_or_pull(repo)
    for item in DIRECT_FILES:
        download_file(item)
    print("=== All sources ready ===\n")


if __name__ == "__main__":
    fetch_all()
