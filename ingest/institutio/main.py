"""
main.py
Orchestrates phase 1 of the Institutio pipeline (ingest & tokenisation).
Each step is idempotent/resumable: re-running is safe.

Phases 2-4 (lemma glossary, translation, alignment) are deliberately not
wired up here: they call the Anthropic Batch API and cost money, so they
are run manually and reviewed step by step. See scripts/export_lemmas.py,
scripts/batch_gloss.py, scripts/load_glosses.py, scripts/translate_segments.py,
scripts/align_segments.py and scripts/validate_alignment.py.

Steps:
  1. fetch_sources             - download chapter pages from calvin.reformation.nl
                                  (cached in /data/institutio/raw/pages)
  2. parse_calvin_reformation  - cached HTML -> segments.jsonl
  3. load_segments             - segments.jsonl -> work/segment tables
  4. tokenize_latin            - LatinCy tokenisation + lemmatisation -> token table
"""
import subprocess
import sys
import time

SCRIPTS_DIR = "scripts"


def step(name: str, args: list[str]) -> None:
    print(f"\n{'─' * 60}")
    print(f"  STEP: {name}")
    print(f"{'─' * 60}")
    t0 = time.time()
    subprocess.run([sys.executable, f"{SCRIPTS_DIR}/{args[0]}", *args[1:]], check=True)
    elapsed = time.time() - t0
    print(f"  Done in {elapsed:.1f}s")


def main() -> None:
    step("1/4  Fetch source data", ["fetch_sources.py"])
    step("2/4  Parse chapter pages to segments", [
        "parse_calvin_reformation.py",
        "-o", "/data/institutio/segments.jsonl",
    ])
    step("3/4  Load segments into PostgreSQL", [
        "load_segments.py", "/data/institutio/segments.jsonl",
    ])
    step("4/4  Tokenize + lemmatize with LatinCy", ["tokenize_latin.py"])

    print("\n" + "═" * 60)
    print("  ✓ Phase 1 (ingest & tokenisation) complete.")
    print("═" * 60 + "\n")


if __name__ == "__main__":
    main()
