#!/usr/bin/env python3
"""Link the Hebrew and Greek words Calvin quotes in a commentary to their
Strong's entries (table work_word_strongs, db/migrate_add_work_word_strongs.sql),
so their word popup shows transliteration, number and first meaning.

Calvin prints Hebrew unpointed (ברא) and Greek with or without accents;
words are compared on their bare letters. Tried in this order:
  chapter  -- a word of the Hebrew text of the chapter commented on (the
              Strong's number of that word in the verse: ברא in Gen. 1)
  lexicon  -- a Strong's lemma, the most frequent one if several share the
              letters (דור: "generation", not "to dwell")
  book     -- a word elsewhere in the book (תולדות)
  forms    -- Greek: an inflected form in the NT (φαινομένων)
For Hebrew each step also tries the word without vowel letters, without a
plural ending and without a prefix letter (see hebrew_variants), in that
order -- the method gets a suffix then ("chapter-plural").
Single letters (Calvin on a letter of a word) are left out.

    python scripts/link_commentary_strongs.py --work calvijn-genesis --book GEN

Idempotent: the work's rows are replaced. Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import collections
import re
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

_FINALS = str.maketrans("ךםןףץ", "כמנפצ")
_PREFIXES = "והבלכמש"


def hebrew(word: str) -> str:
    return re.sub(r"[^א-ת]", "", word).translate(_FINALS)


def defective(form: str) -> str:
    """Without the vowel letters ו and י, which the Bible text and Calvin
    don't always write alike (שילוה / שִׁילֹה, כוהן / כֹּהֵן); the first
    letter stays."""
    return form[:1] + form[1:].replace("ו", "").replace("י", "")


def hebrew_variants(form: str) -> list[tuple[str, str]]:
    """The word as printed, then (method suffix) without a plural ending
    (פרדסים, מכרות -> מכרה), without the vowel letters, without a prefix
    letter (ו ה ב ל כ מ ש). Forms of two letters without their vowel
    letters match too much (דון -> דן, Dan), so those aren't tried."""
    singular = []
    if len(form) > 4 and form.endswith("ימ"):      # (final mem as מ, see hebrew())
        singular = [form[:-2]]
    elif len(form) > 4 and form.endswith("ות"):
        singular = [form[:-2] + "ה", form[:-2]]
    if form.endswith("וה"):                          # שילוה for שִׁילוֹ
        singular.append(form[:-1])
    out = [(form, "")] + [(f, "-plural") for f in singular]
    out += [(defective(f), how) for f, how in [(form, "-defective")] + [(f, "-plural-defective") for f in singular]
            if len(defective(f)) >= 3]
    if len(form) > 3 and form[0] in _PREFIXES:
        out += [(v, "-prefix" + how) for v, how in hebrew_variants(form[1:])]
    return out


def greek(word: str) -> str:
    bare = "".join(c for c in unicodedata.normalize("NFD", word.lower()) if not unicodedata.combining(c))
    return re.sub(r"[^α-ω]", "", bare.replace("ς", "σ").replace("ϑ", "θ"))


def canonical(strongs: str) -> str:
    """H0430G -> H430 (the form of strongs_entries.strongs_id)."""
    return re.sub(r"^([HG])0*(\d+)[A-Za-z]*$", r"\1\2", strongs)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--work", default="calvijn-genesis")
    ap.add_argument("--book", default="GEN", help="USFM code of the Bible book commented on")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("SELECT id FROM work WHERE slug = %s", (args.work,))
        work_id = cur.fetchone()[0]
        cur.execute("SELECT id FROM books WHERE usfm_code = %s", (args.book,))
        book_id = cur.fetchone()[0]

        cur.execute(
            """SELECT DISTINCT COALESCE(s.chapter, 0), t.surface
               FROM token t JOIN segment s ON s.id = t.segment_id
               WHERE s.work_id = %s AND t.is_word
                 AND t.surface ~ '[א-תͰ-Ͽἀ-῿]'""", (work_id,))
        words = cur.fetchall()

        # How often each number occurs in the Bible: the tie-break between
        # lemmas with the same letters.
        cur.execute("""SELECT strongs, COUNT(*) FROM hebrew_words WHERE strongs IS NOT NULL GROUP BY 1
                       UNION ALL
                       SELECT strongs, COUNT(*) FROM greek_words WHERE strongs IS NOT NULL GROUP BY 1""")
        freq: collections.Counter = collections.Counter()
        for s, n in cur.fetchall():
            freq[canonical(s)] += n

        lexicon: dict[str, dict[str, list[str]]] = {"H": collections.defaultdict(list),
                                                    "G": collections.defaultdict(list)}
        cur.execute("SELECT strongs_id, lemma, definition FROM strongs_entries WHERE lemma IS NOT NULL")
        names = set()
        for sid, lemma, definition in cur.fetchall():
            if re.match(r'[^\n]* = "', definition or ""):      # 'Nod = "wandering"'
                names.add(sid)
            if sid[0] == "H":
                for key in {hebrew(lemma), "D:" + defective(hebrew(lemma))}:
                    lexicon["H"][key].append(sid)
            else:
                lexicon["G"][greek(lemma)].append(sid)
        for forms in lexicon.values():
            for key in forms:
                forms[key].sort(key=lambda s: -freq[s])

        chapter_forms: dict[tuple[int, str], collections.Counter] = collections.defaultdict(collections.Counter)
        book_forms: dict[str, collections.Counter] = collections.defaultdict(collections.Counter)
        cur.execute("SELECT chapter, word_text, strongs FROM hebrew_words WHERE book_id = %s AND strongs IS NOT NULL",
                    (book_id,))
        for ch, text, s in cur.fetchall():
            # a word joined by maqaf also counts per part (כִּֽי־טֹ֑וב)
            forms = {hebrew(text)} | {hebrew(p) for p in text.split("־")}
            # and, tried last ("S:"), without its prefixes: Calvin quotes the
            # word (נפלים for הַנְּפִלִים, מועדים for וּלְמוֹעֲדִים)
            stripped = set(forms)
            for _ in range(2):
                stripped |= {f[1:] for f in stripped if (len(f) > 3 and f[0] in _PREFIXES) or f[:1] == "ו"}
            # (keys without vowel letters apart, "D:": only a word without
            # them may match those -- else ענה finds עֵינֶיהָ)
            keys = {k for f in forms for k in (f, "D:" + defective(f))}
            keys |= {"S" + k for f in stripped - forms for k in (":" + f, "D:" + defective(f))}
            for key in keys:
                chapter_forms[(ch, key)][canonical(s)] += 1
                book_forms[key][canonical(s)] += 1
        nt_forms: dict[str, collections.Counter] = collections.defaultdict(collections.Counter)
        cur.execute("SELECT word_text, strongs, COUNT(*) FROM greek_words WHERE strongs IS NOT NULL GROUP BY 1, 2")
        for text, s, n in cur.fetchall():
            nt_forms[greek(text)][canonical(s)] += n

        rows, methods, unlinked = [], collections.Counter(), []
        for ch, surface in words:
            found = None
            if re.search(r"[א-ת]", surface):
                form = hebrew(surface)
                if len(form) < 2:
                    continue
                variants = [(("D:" if "defective" in how else "") + v, how) for v, how in hebrew_variants(form)]
                for key, how in variants:
                    bare = "S" + (key if key.startswith("D:") else ":" + key)
                    if chapter_forms.get((ch, key)):
                        found = (chapter_forms[(ch, key)].most_common(1)[0][0], "chapter" + how)
                    elif chapter_forms.get((ch, bare)):
                        found = (chapter_forms[(ch, bare)].most_common(1)[0][0], "chapter-unprefixed" + how)
                    elif lexicon["H"].get(key):
                        found = (lexicon["H"][key][0], "lexicon" + how)
                    elif book_forms.get(key):
                        found = (book_forms[key].most_common(1)[0][0], "book" + how)
                    if found:
                        # a name in the chapter, a more common word in the
                        # lexicon: Calvin on the word (נוד "to wander" in
                        # 4:12, not the land Nod; but Gad stays Gad)
                        words_ = [w for w in lexicon["H"].get(key, []) if w not in names]
                        if (found[0] in names and words_ and not found[1].startswith("lexicon")
                                and freq[words_[0]] > freq[found[0]]):
                            found = (words_[0], "lexicon" + how)
                        break
            else:
                form = greek(surface)
                if len(form) < 2:
                    continue
                if lexicon["G"].get(form):
                    found = (lexicon["G"][form][0], "lexicon")
                elif nt_forms.get(form):
                    found = (nt_forms[form].most_common(1)[0][0], "forms")
            if found:
                rows.append((work_id, ch, surface, found[0], found[1]))
                methods[found[1]] += 1
            else:
                unlinked.append(f"{ch}:{surface}")

        print(f"[ok]    {len(rows)} of {len(rows) + len(unlinked)} Hebrew/Greek words linked "
              f"({', '.join(f'{m} {n}' for m, n in methods.most_common())})")
        print(f"[info]  not found: {' '.join(unlinked)}")
        if args.dry_run:
            return 0
        cur.execute("DELETE FROM work_word_strongs WHERE work_id = %s", (work_id,))
        cur.executemany("INSERT INTO work_word_strongs (work_id, chapter, surface, strongs_id, method) "
                        "VALUES (%s, %s, %s, %s, %s)", rows)
        print(f"[ok]    wrote {len(rows)} rows to work_word_strongs")
    return 0


if __name__ == "__main__":
    sys.exit(main())
