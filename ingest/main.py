"""
main.py
Orchestrates the full ingest pipeline in the correct order.
Each step is idempotent: re-running is safe.

Steps:
  1. fetch_sources   – clone / download all source data
  2. parse_tahot     – Hebrew OT words → hebrew_words
  3. parse_elzevir   – Greek NT words  → greek_words
  4. parse_statenvertaling – Dutch verses + words → translation_verses / translation_words
  5. align_heuristic – positional + proper-noun fallback → word_links (method=heuristic)
  6. parse_strongs   – Strong's dictionary → strongs_entries
"""
import sys
import time


def step(name: str, fn):
    print(f"\n{'─' * 60}")
    print(f"  STEP: {name}")
    print(f"{'─' * 60}")
    t0 = time.time()
    fn()
    elapsed = time.time() - t0
    print(f"  Done in {elapsed:.1f}s")


def main():
    from fetch_sources import fetch_all
    step("1/6  Fetch source data", fetch_all)

    from parse_tahot import parse_tahot
    step("2/6  Parse Hebrew OT (TAHOT)", parse_tahot)

    from parse_elzevir import parse_elzevir
    step("3/6  Parse Greek NT (Elzevir)", parse_elzevir)

    from parse_statenvertaling import parse_statenvertaling
    step("4/6  Parse Statenvertaling", parse_statenvertaling)

    from align_heuristic import align_heuristic
    step("5/6  Heuristic alignment (fallback)", align_heuristic)

    from parse_strongs import parse_strongs
    step("6/6  Strong's dictionary", parse_strongs)

    print("\n" + "═" * 60)
    print("  ✓ Ingest pipeline complete.")
    print("═" * 60 + "\n")


if __name__ == "__main__":
    main()
