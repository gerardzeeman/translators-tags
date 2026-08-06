"""
main.py
Orchestrates the full ingest pipeline in the correct order.
Each step is idempotent: re-running is safe.

Steps:
  1. fetch_sources   – clone / download all source data
  2. parse_tahot     – Hebrew OT words → hebrew_words
  3. parse_elzevir   – Greek NT words  → greek_words
  4. verse_boundary_corrections – fix known Greek/Dutch verse-split mismatches
     (must run right after parse_elzevir — see the module docstring's CAVEAT)
  5. parse_statenvertaling – Dutch verses + words → translation_verses / translation_words
  6. align_heuristic – positional + proper-noun fallback → word_links (method=heuristic)
  7. parse_strongs   – Strong's dictionary → strongs_entries
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
    step("1/7  Fetch source data", fetch_all)

    from parse_tahot import parse_tahot
    step("2/7  Parse Hebrew OT (TAHOT)", parse_tahot)

    from parse_elzevir import parse_elzevir
    step("3/7  Parse Greek NT (Elzevir)", parse_elzevir)

    from verse_boundary_corrections import apply_corrections
    step("4/7  Verse boundary corrections", apply_corrections)

    from parse_statenvertaling import parse_statenvertaling
    step("5/7  Parse Statenvertaling", parse_statenvertaling)

    from align_heuristic import align_heuristic
    step("6/7  Heuristic alignment (fallback)", align_heuristic)

    from parse_strongs import parse_strongs
    step("7/7  Strong's dictionary", parse_strongs)

    print("\n" + "═" * 60)
    print("  ✓ Ingest pipeline complete.")
    print("═" * 60 + "\n")


if __name__ == "__main__":
    main()
