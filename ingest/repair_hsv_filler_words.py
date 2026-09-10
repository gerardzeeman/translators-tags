"""
repair_hsv_filler_words.py
One-off repair for a production data gap in the HSV translation (translation_id=2).

Root cause
----------
Production's HSV was originally ingested with an older version of
parse_herziene_statenvertaling.py that did not yet handle the HSV site's
mid-sentence "opvulwoordje" (filler word) markup:

    <span class="verse-span">Wie zijn broeder niet liefheeft ...</span>
    <span class="add">
        <span class="verse-span">zijn</span>   <!-- sibling, not a wrapper -->
    </span>
    <span class="verse-span"> broeder niet liefheeft ...</span>

The current parser handles this correctly (see
parse_herziene_statenvertaling.py:352-364) and dev's database -- freshly
re-scraped -- has the correct text. Production was never re-ingested after
that fix, so it is missing every such filler word entirely: not just an
`is_filler` flag, the word is absent from both `translation_verses.verse_text`
and `translation_words`.

Measured impact (read-only, see chat log): 11,426 of 31,104 HSV verses
affected, 21,377 missing words total. Verified by sampling that the ONLY
difference between dev and production, in every affected verse, is a pure
insertion of dev-only tokens that are all `is_filler = true` -- never a
changed word, a removed word, or a reordering. Also verified that verses with
matching word counts already have matching `is_filler` flags (298 verses
cross-checked, 0 mismatches) -- there is no second bug class to worry about.

Why this script exists instead of just re-running the ingest pipeline
-----------------------------------------------------------------------
`ingest/db/loaders.py:bulk_insert_translation_words()` deletes ALL existing
`translation_words` rows for a verse before reinserting -- and
`word_links.translation_word_id` / `inter_translation_links.word_a_id` /
`word_b_id` are all `ON DELETE CASCADE` (db/schema.sql:166,211-212). Running
the normal scraper against production would silently cascade-delete every
linking row tied to HSV words -- measured at 2,392 `word_links` +
706,780 `inter_translation_links` rows on production. This script NEVER
deletes or re-identifies an existing `translation_words` row. It only:
  - inserts the missing filler-word rows (new ids, nothing references them
    yet, so nothing can break by adding them), and
  - repositions existing rows' `word_position`/`char_start`/`char_end` and
    the `cross_references.word_position` anchors that key off them.
Existing row ids never change, so every existing link stays exactly as it was.

Workflow
--------
    python repair_hsv_filler_words.py --export              # pull dev + prod HSV data (read-only)
    python repair_hsv_filler_words.py --generate             # diff + write hsv_patch.sql / hsv_manual_review.csv
    python repair_hsv_filler_words.py --export --generate    # both

Nothing is ever written to production by this script. Review hsv_patch.sql,
then apply it yourself:

    scp hsv_patch.sql translatorstags-prod:/tmp/hsv_patch.sql
    ssh translatorstags-prod "docker exec -i bible_postgres psql -U bible bible_compare < /tmp/hsv_patch.sql"

Verses whose diff does not cleanly match the "pure filler insertion" pattern
are never touched -- they are written to hsv_manual_review.csv instead.
"""
import argparse
import csv
import difflib
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

SSH_HOST = "translatorstags-prod"
DEV_CMD  = ["docker", "exec", "-i", "bible_postgres", "psql", "-U", "bible", "bible_compare"]
PROD_CMD = ["ssh", SSH_HOST, "docker exec -i bible_postgres psql -U bible bible_compare"]

OUT_DIR = Path(__file__).parent / "hsv_repair_data"

VERSES_SQL = """
COPY (
    SELECT tv.book_id, tv.chapter, tv.verse, tv.id AS verse_id, tv.verse_text
    FROM translation_verses tv
    WHERE tv.translation_id = 2
    ORDER BY tv.book_id, tv.chapter, tv.verse
) TO STDOUT WITH CSV
"""

WORDS_SQL = """
COPY (
    SELECT tv.book_id, tv.chapter, tv.verse, tw.id AS word_id, tw.word_position,
           tw.word_text, tw.word_normalised, tw.char_start, tw.char_end, tw.is_filler
    FROM translation_words tw
    JOIN translation_verses tv ON tv.id = tw.verse_id
    WHERE tv.translation_id = 2
    ORDER BY tv.book_id, tv.chapter, tv.verse, tw.word_position
) TO STDOUT WITH CSV
"""

CROSS_REFS_SQL = """
COPY (
    SELECT cr.id, cr.book_id, cr.chapter, cr.verse, cr.word_position
    FROM cross_references cr
    WHERE cr.source = 'HSV'
    ORDER BY cr.book_id, cr.chapter, cr.verse, cr.word_position
) TO STDOUT WITH CSV
"""


def run_copy(cmd: list[str], sql: str, out_path: Path) -> None:
    with open(out_path, "wb") as out:
        result = subprocess.run(cmd, input=sql.encode("utf-8"), stdout=out, stderr=subprocess.PIPE)
    if result.returncode != 0:
        raise RuntimeError(f"Command {cmd} failed: {result.stderr.decode(errors='replace')}")


def do_export() -> None:
    OUT_DIR.mkdir(exist_ok=True)
    jobs = [
        ("dev_verses.csv",  DEV_CMD,  VERSES_SQL),
        ("dev_words.csv",   DEV_CMD,  WORDS_SQL),
        ("dev_xrefs.csv",   DEV_CMD,  CROSS_REFS_SQL),
        ("prod_verses.csv", PROD_CMD, VERSES_SQL),
        ("prod_words.csv",  PROD_CMD, WORDS_SQL),
        ("prod_xrefs.csv",  PROD_CMD, CROSS_REFS_SQL),
    ]
    for filename, cmd, sql in jobs:
        out_path = OUT_DIR / filename
        print(f"  exporting {filename} ...", flush=True)
        run_copy(cmd, sql, out_path)
        with open(out_path, encoding="utf-8") as f:
            n = sum(1 for _ in f)
        print(f"    {n:,} rows -> {out_path}")


# ─── Loading ────────────────────────────────────────────────────────────────

class Word:
    __slots__ = ("word_id", "word_position", "word_text", "word_normalised",
                 "char_start", "char_end", "is_filler")

    def __init__(self, row):
        self.word_id         = int(row[3])
        self.word_position   = int(row[4])
        self.word_text       = row[5]
        self.word_normalised = row[6]
        self.char_start      = int(row[7])
        self.char_end        = int(row[8])
        self.is_filler       = row[9] == "t"


def load_verses(path: Path) -> dict[tuple[str, str, str], tuple[str, str]]:
    """(book,chapter,verse) -> (verse_id, verse_text)"""
    out = {}
    with open(path, encoding="utf-8") as f:
        for row in csv.reader(f):
            b, c, v, verse_id, verse_text = row
            out[(b, c, v)] = (verse_id, verse_text)
    return out


def load_words(path: Path) -> dict[tuple[str, str, str], list[Word]]:
    out = defaultdict(list)
    with open(path, encoding="utf-8") as f:
        for row in csv.reader(f):
            out[(row[0], row[1], row[2])].append(Word(row))
    for words in out.values():
        words.sort(key=lambda w: w.word_position)
    return out


def load_xrefs(path: Path) -> dict[tuple[str, str, str], list[tuple[str, int]]]:
    """(book,chapter,verse) -> [(xref_id, word_position), ...]"""
    out = defaultdict(list)
    with open(path, encoding="utf-8") as f:
        for row in csv.reader(f):
            xref_id, b, c, v, pos = row
            out[(b, c, v)].append((xref_id, int(pos)))
    return out


# ─── SQL literal helpers ────────────────────────────────────────────────────

def sql_str(s: str) -> str:
    return "'" + s.replace("'", "''") + "'"


def sql_bool(b: bool) -> str:
    return "TRUE" if b else "FALSE"


class OffsetScanError(Exception):
    pass


def compute_char_offsets(verse_text: str, word_texts: list[str]) -> list[tuple[int, int]]:
    """Forward-scan word_texts through verse_text to get GLOBAL (char_start,
    char_end) pairs. Mirrors PassageRepository::addPunctuation()'s scanning
    approach. This does NOT trust any previously-stored char_start/char_end --
    those are span-local artifacts of how the HSV scraper tokenises each
    <span> fragment separately (offsets reset to 0 at every span boundary,
    e.g. right after a filler-word insertion point), not real offsets into
    the full verse_text. Recomputing from scratch is the only way to get
    correct global offsets once a verse spans more than one fragment, which
    is exactly the case for every verse this script touches.

    Raises OffsetScanError instead of guessing if a word can't be found
    forward from the current scan position -- the caller treats that verse
    as a reject rather than writing a wrong offset.
    """
    offsets = []
    scan_pos = 0
    for wt in word_texts:
        idx = verse_text.find(wt, scan_pos)
        if idx == -1:
            raise OffsetScanError(f"word {wt!r} not found from pos {scan_pos} in {verse_text!r}")
        offsets.append((idx, idx + len(wt)))
        scan_pos = idx + len(wt)
    return offsets


# ─── Diff + patch generation ────────────────────────────────────────────────

class VerseResult:
    def __init__(self, key):
        self.key = key
        self.verse_text_changed = False
        self.new_verse_text = None
        self.verse_id = None
        self.existing_updates = []   # list of (word_id, new_pos, new_start, new_end)
        self.new_inserts = []        # list of Word (dev-side) to insert
        self.pos_map = {}            # old prod word_position -> new word_position
        self.xref_updates = []       # list of (xref_id, new_pos)
        self.notes = []              # informational, non-blocking observations

    @property
    def is_noop(self):
        return (not self.verse_text_changed and not self.existing_updates
                and not self.new_inserts and not self.xref_updates)


def diff_verse(key, dev_verse, prod_verse, dev_words, prod_words, prod_xrefs, rejects: list) -> VerseResult | None:
    dev_verse_id, dev_text = dev_verse
    prod_verse_id, prod_text = prod_verse

    result = VerseResult(key)
    result.verse_id = prod_verse_id

    dev_texts  = [w.word_text for w in dev_words]
    prod_texts = [w.word_text for w in prod_words]

    if dev_texts == prod_texts:
        # Word sequence already identical -- nothing to insert. (verse_text
        # itself should already match too; if not, that's a separate, unrelated
        # discrepancy we deliberately do not touch here.)
        return None

    sm = difflib.SequenceMatcher(None, prod_texts, dev_texts, autojunk=False)
    opcodes = sm.get_opcodes()

    # Non-filler words inside an 'insert' block are allowed: verified by hand
    # (spot-checked against the live HSV site, e.g. Genesis 27:35's doubled
    # "je" and Deuteronomium 19:17's "ogen van de" idiom insertion) that the
    # OLD parser dropped plain words too whenever they arrived as an "extra"
    # <span class="verse-span"> fragment for a verse -- not only words inside
    # <span class="add">. The opcode's word-text alignment is still the
    # authority on WHERE to insert; is_filler no longer gates acceptance.
    # Only a genuine 'replace'/'delete' opcode (a real content mismatch, not
    # an addition) still blocks the verse.
    notes: list[str] = []
    for tag, i1, i2, j1, j2 in opcodes:
        if tag == "equal":
            continue
        if tag != "insert":
            rejects.append((key, f"opcode={tag}", f"prod[{i1}:{i2}]={prod_texts[i1:i2]!r} dev[{j1}:{j2}]={dev_texts[j1:j2]!r}"))
            return None
        inserted = dev_words[j1:j2]
        if not all(w.is_filler for w in inserted):
            notes.append(f"insert included non-filler word(s): {[(w.word_text, w.is_filler) for w in inserted]!r}")

    # All non-'equal' opcodes are pure filler insertions -- safe to apply.
    # Build the final word sequence in order (existing prod row id, or None
    # for a brand-new row) alongside dev's word_text/word_normalised/is_filler,
    # which is authoritative now that verse_text == dev_text.
    final_seq = []  # list of dicts: word_id(existing)|None, word_text, word_normalised, is_filler
    for tag, i1, i2, j1, j2 in opcodes:
        if tag == "equal":
            for prod_w, dev_w in zip(prod_words[i1:i2], dev_words[j1:j2]):
                final_seq.append({
                    "word_id": prod_w.word_id,
                    "old_position": prod_w.word_position,
                    "old_char_start": prod_w.char_start,
                    "old_char_end": prod_w.char_end,
                    "word_text": dev_w.word_text,
                    "word_normalised": dev_w.word_normalised,
                    "is_filler": dev_w.is_filler,
                })
        elif tag == "insert":
            insert_texts_lower = {w.word_text.lower() for w in dev_words[j1:j2]}
            # An 'equal' pair immediately adjacent to this insert block, whose
            # text also occurs inside the insert block, is the classic
            # "de/een/van" repeated-word case: the diff had to arbitrarily pick
            # which occurrence is "the same as prod's" and which is "new". Text
            # and position end up correct either way (verified by the
            # self-verify pass below), but the is_filler flag could land on
            # either twin -- flag it so a human can double check the styling,
            # never the content.
            for boundary_idx in (i1 - 1, i2):
                if 0 <= boundary_idx < len(prod_words):
                    neighbour_text = prod_words[boundary_idx].word_text.lower()
                    if neighbour_text in insert_texts_lower:
                        notes.append(
                            f"repeated word {neighbour_text!r} straddles insert block "
                            f"dev[{j1}:{j2}] -- is_filler on that twin may be ambiguous, "
                            f"visible text/position unaffected"
                        )
                        break
            for dev_w in dev_words[j1:j2]:
                final_seq.append({
                    "word_id": None,
                    "old_position": None,
                    "old_char_start": None,
                    "old_char_end": None,
                    "word_text": dev_w.word_text,
                    "word_normalised": dev_w.word_normalised,
                    "is_filler": dev_w.is_filler,
                })

    try:
        offsets = compute_char_offsets(dev_text, [e["word_text"] for e in final_seq])
    except OffsetScanError as exc:
        rejects.append((key, "char-offset-scan-failed", str(exc)))
        return None

    for new_position, (entry, (new_start, new_end)) in enumerate(zip(final_seq, offsets), start=1):
        if entry["word_id"] is None:
            result.new_inserts.append({
                "word_position": new_position,
                "word_text": entry["word_text"],
                "word_normalised": entry["word_normalised"],
                "char_start": new_start,
                "char_end": new_end,
                "is_filler": entry["is_filler"],
            })
        else:
            result.pos_map[entry["old_position"]] = new_position
            if (entry["old_position"] != new_position
                    or entry["old_char_start"] != new_start
                    or entry["old_char_end"] != new_end):
                result.existing_updates.append(
                    (entry["word_id"], new_position, new_start, new_end)
                )

    if prod_text != dev_text:
        result.verse_text_changed = True
        result.new_verse_text = dev_text

    for xref_id, old_pos in prod_xrefs.get(key, []):
        if old_pos == 0:
            continue  # verse-prefix marker, not tied to a specific word
        new_pos = result.pos_map.get(old_pos)
        if new_pos is None:
            # old_pos doesn't correspond to any of prod's CURRENT words -- but
            # cross_references.word_position is populated by a separate
            # scraper (parse_hsv_cross_references.py) that counts every word
            # on the page, filler or not, so it was never affected by the
            # missing-filler-word bug in the first place: its numbers are
            # already expressed in the correct (dev-equivalent) scheme.
            # Verified against the live site (2 Samuel 1:18's cross-reference
            # marker sits exactly after dev's word 24, "de", before
            # "Oprechte"). If old_pos fits within dev's own word count, it's
            # already correct post-patch and needs no UPDATE at all.
            if 1 <= old_pos <= len(final_seq):
                continue
            rejects.append((key, "xref-position-not-mapped", f"xref_id={xref_id} old_pos={old_pos} dev_len={len(final_seq)}"))
            return None
        if new_pos != old_pos:
            result.xref_updates.append((xref_id, new_pos))

    result.notes = notes
    return None if result.is_noop else result


def generate_patch_sql(results: list[VerseResult]) -> str:
    lines = ["-- Auto-generated by repair_hsv_filler_words.py -- review before applying.",
             "\\set ON_ERROR_STOP on", "BEGIN;", ""]

    for r in results:
        b, c, v = r.key
        lines.append(f"-- HSV book={b} chapter={c} verse={v} (verse_id={r.verse_id})")

        if r.verse_text_changed:
            lines.append(
                f"UPDATE translation_verses SET verse_text = {sql_str(r.new_verse_text)} "
                f"WHERE id = {r.verse_id};"
            )

        if r.existing_updates:
            word_ids = ", ".join(str(wid) for wid, *_ in r.existing_updates)
            lines.append(
                f"UPDATE translation_words SET word_position = -word_position "
                f"WHERE verse_id = {r.verse_id} AND id IN ({word_ids});"
            )
            for word_id, new_pos, new_start, new_end in r.existing_updates:
                lines.append(
                    f"UPDATE translation_words SET word_position = {new_pos}, "
                    f"char_start = {new_start}, char_end = {new_end} WHERE id = {word_id};"
                )

        if r.new_inserts:
            values = []
            for w in r.new_inserts:
                values.append(
                    f"({r.verse_id}, {w['word_position']}, {sql_str(w['word_text'])}, "
                    f"{sql_str(w['word_normalised'])}, {w['char_start']}, {w['char_end']}, {sql_bool(w['is_filler'])})"
                )
            lines.append(
                "INSERT INTO translation_words "
                "(verse_id, word_position, word_text, word_normalised, char_start, char_end, is_filler) VALUES\n    "
                + ",\n    ".join(values) + ";"
            )

        for xref_id, new_pos in r.xref_updates:
            lines.append(f"UPDATE cross_references SET word_position = {new_pos} WHERE id = {xref_id};")

        lines.append("")

    lines.append("COMMIT;")
    return "\n".join(lines)


def verify_result(r: VerseResult, prod_words_this_verse: list[Word], dev_words_this_verse: list[Word],
                   final_verse_text: str) -> list[str]:
    """Independently reconstruct what the patch will produce and check it
    against dev, without reusing any of diff_verse()'s internal state."""
    issues = []

    prod_by_id = {w.word_id: w for w in prod_words_this_verse}
    changed = {wid: (pos, cs, ce) for wid, pos, cs, ce in r.existing_updates}

    final_rows = []  # (position, char_start, char_end, word_text)
    for wid, w in prod_by_id.items():
        if wid in changed:
            pos, cs, ce = changed[wid]
        else:
            pos, cs, ce = w.word_position, w.char_start, w.char_end
        final_rows.append((pos, cs, ce, w.word_text))
    for ins in r.new_inserts:
        final_rows.append((ins["word_position"], ins["char_start"], ins["char_end"], ins["word_text"]))

    final_rows.sort(key=lambda t: t[0])

    positions = [row[0] for row in final_rows]
    if positions != list(range(1, len(final_rows) + 1)):
        issues.append(f"positions not contiguous 1..N: {positions}")

    dev_texts = [w.word_text for w in dev_words_this_verse]
    got_texts = [row[3] for row in final_rows]
    if got_texts != dev_texts:
        issues.append(f"final word sequence != dev sequence: {got_texts} vs {dev_texts}")

    prev_end = 0
    for pos, cs, ce, text in final_rows:
        if cs < prev_end:
            issues.append(f"overlap at position {pos}: char_start {cs} < previous char_end {prev_end}")
        if ce - cs != len(text):
            issues.append(f"char range length mismatch at position {pos}: {cs}-{ce} vs len({text!r})")
        if final_verse_text[cs:ce] != text:
            issues.append(f"char range doesn't match verse_text at position {pos}: "
                           f"verse_text[{cs}:{ce}]={final_verse_text[cs:ce]!r} vs {text!r}")
        prev_end = ce
    if prev_end > len(final_verse_text):
        issues.append(f"final char_end {prev_end} exceeds verse_text length {len(final_verse_text)}")

    return issues


def do_generate() -> None:
    dev_verses  = load_verses(OUT_DIR / "dev_verses.csv")
    prod_verses = load_verses(OUT_DIR / "prod_verses.csv")
    dev_words   = load_words(OUT_DIR / "dev_words.csv")
    prod_words  = load_words(OUT_DIR / "prod_words.csv")
    prod_xrefs  = load_xrefs(OUT_DIR / "prod_xrefs.csv")

    rejects: list = []
    results: list[VerseResult] = []

    all_keys = sorted(set(dev_verses) | set(prod_verses),
                       key=lambda k: (int(k[0]), int(k[1]), int(k[2])))

    missing_on_prod = 0
    for key in all_keys:
        if key not in prod_verses:
            missing_on_prod += 1
            rejects.append((key, "verse-missing-on-prod", "no translation_verses row at all"))
            continue
        if key not in dev_verses:
            continue  # nothing to compare against; leave production untouched

        r = diff_verse(
            key, dev_verses[key], prod_verses[key],
            dev_words.get(key, []), prod_words.get(key, []),
            prod_xrefs, rejects,
        )
        if r is not None:
            results.append(r)

    print(f"Independently verifying {len(results):,} generated patches ...")
    verify_failures = 0
    verified_results = []
    for r in results:
        issues = verify_result(r, prod_words.get(r.key, []), dev_words.get(r.key, []),
                                r.new_verse_text if r.verse_text_changed else prod_verses[r.key][1])
        if issues:
            verify_failures += 1
            b, c, v = r.key
            rejects.append((r.key, "self-verify-failed", "; ".join(issues)))
            if verify_failures <= 10:
                print(f"  VERIFY FAILED book={b} chapter={c} verse={v}: {issues}")
        else:
            verified_results.append(r)
    if verify_failures:
        print(f"  {verify_failures} verse(s) failed self-verification and were moved to manual review.")
    else:
        print("  all patches verified OK.")
    results = verified_results

    patch_sql = generate_patch_sql(results)
    patch_path = OUT_DIR / "hsv_patch.sql"
    patch_path.write_text(patch_sql, encoding="utf-8")

    review_path = OUT_DIR / "hsv_manual_review.csv"
    with open(review_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["book_id", "chapter", "verse", "reason", "detail"])
        for key, reason, detail in rejects:
            writer.writerow([key[0], key[1], key[2], reason, detail])

    notes_path = OUT_DIR / "hsv_applied_notes.csv"
    with open(notes_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["book_id", "chapter", "verse", "note"])
        for r in results:
            for note in r.notes:
                writer.writerow([r.key[0], r.key[1], r.key[2], note])

    total_inserts = sum(len(r.new_inserts) for r in results)
    total_repositioned = sum(len(r.existing_updates) for r in results)
    total_xref_updates = sum(len(r.xref_updates) for r in results)

    print(f"Verses patched automatically : {len(results):,}")
    print(f"  words inserted             : {total_inserts:,}")
    print(f"  existing words repositioned: {total_repositioned:,}")
    print(f"  cross_references updated   : {total_xref_updates:,}")
    print(f"Verses flagged for manual review: {len(rejects):,}  -> {review_path}")
    notes_count = sum(len(r.notes) for r in results)
    print(f"Accepted verses with an informational note: {notes_count:,}  -> {notes_path}")
    print(f"Patch written to: {patch_path}")
    print()
    print("Nothing was applied. To apply on production:")
    print(f"  scp {patch_path} {SSH_HOST}:/tmp/hsv_patch.sql")
    print(f'  ssh {SSH_HOST} "docker exec -i bible_postgres psql -U bible bible_compare < /tmp/hsv_patch.sql"')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--export", action="store_true", help="Pull dev + prod HSV data (read-only) into ingest/hsv_repair_data/")
    parser.add_argument("--generate", action="store_true", help="Diff exported data and write hsv_patch.sql + hsv_manual_review.csv")
    args = parser.parse_args()

    if not args.export and not args.generate:
        parser.print_help()
        sys.exit(1)

    if args.export:
        print("Exporting HSV data (read-only) from dev and production ...")
        do_export()

    if args.generate:
        print("\nGenerating patch ...")
        do_generate()


if __name__ == "__main__":
    main()
