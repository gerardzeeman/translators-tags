# Institutio pipeline

Latin (1559) -> Dutch pipeline for Calvin's *Institutio christianae
religionis*, in two layers built on the same source tokens:

| Layer | Name          | Technique                          | Output                                   |
|-------|---------------|-------------------------------------|-------------------------------------------|
| A     | Interlinear   | LatinCy lemmatisation + LLM lexicon | Gloss per source word (literal)            |
| B     | Fluent        | LLM per section + SimAlign          | Readable Dutch sentence + word alignment   |

This is a separate corpus from the Hebrew/Greek Bible data already in this
project (`ingest/`, `db/schema.sql`): it uses its own `work`/`segment`/
`token`/`lemma_gloss`/`translation`/`alignment` tables, added by
[`db/migrate_add_institutio_schema.sql`](../../db/migrate_add_institutio_schema.sql),
plus `segment_annotation` (critical-apparatus references, see below), added
by [`db/migrate_add_institutio_annotations.sql`](../../db/migrate_add_institutio_annotations.sql),
and `sentence_alignment` (Latin/Dutch sentence-level grouping for the
`/institutio` display, see phase 3.2 below), added by
[`db/migrate_add_sentence_alignment.sql`](../../db/migrate_add_sentence_alignment.sql).

## Critical-apparatus annotations

The source text has inline reference markers: letters (a, b, c, ...) mark a
textual variant in another edition of the Institutio, digits (1, 2, 3, ...)
mark a citation (usually Scripture). `parse_calvin_reformation.py` captures
both — matched by `fnum` against the page-wide footnote block — as a
character offset into each segment's `text_la`, stored in
`segment_annotation (segment_id, char_position, glyph, kind, note)`. The
`/institutio` page renders these as small hoverable superscripts showing the
edition + variant reading, or the citation reference.

**Glyph renumbering:** the print edition restarts its apparatus lettering
(and citation digits) at every physical page break -- confirmed against the
raw source (a `<span class="pagenum">` sits exactly between glyph "k" and
the next "a" in Inst. 1.1.3). Since a digital segment can span multiple
print pages, the raw scraped glyph would visibly (and confusingly) restart
mid-segment. `parse_chapter_html()` therefore discards the scraped letter/
digit and renumbers continuously per segment (`_variant_glyph()`: full a-z,
then aa/bb/... once letters run out; citations: a plain 1, 2, 3, ...
counter) -- only the reference marker is redone, the note text and char
position still come from the source untouched. The original print-page
letter is not preserved anywhere. Note: the source itself skips 'j' (old
Latin typesetting convention, i/j not distinguished -- confirmed 'j' never
appears as a scraped glyph in the corpus), but this renumbering uses the
full modern 26-letter alphabet rather than matching that convention.

**Inline bracket citations:** besides the marked-up footnote apparatus above,
Calvin's own running prose has plain bracketed citations with no special
markup at all -- e.g. `[Gene. 18. d. 27]`, `[Iesa. 2. c. 10, et d. 19]`,
`[Iudic. 13. d. 22; Iesa. 6. b. 5; Ezech. 2. a. 1, et alibi.]`. Surveyed all
4,125 occurrences across the corpus (only 2 harmless anomalies: a stray
unmatched `[` and one bracket mistakenly closed with `)` instead of `]`,
both left as literal text). `_interleave_bracket_citations()` extracts every
`[...]` as its own citation annotation (whole bracket = one note, even when
it lists several semicolon-separated references), folded into the *same*
continuous per-segment citation counter as the footnote-apparatus digits, in
true character-position order.

A footnote-apparatus marker can fall *inside* a citation bracket (Inst.
1.1.3 has a variant-footnote glyph sitting right inside one) -- when the
bracket text is removed, both markers collapse to the exact same
`char_position`. `segment_annotation` has an `ord` column (added by
[`db/migrate_add_annotation_ordinal.sql`](../../db/migrate_add_annotation_ordinal.sql))
to disambiguate same-position annotations for the `UNIQUE (segment_id,
char_position, ord)` constraint; a first version without it silently
dropped every same-position collision but the last via `ON CONFLICT DO
UPDATE`, undercounting annotations corpus-wide until caught by a full
recount against the parser's own output.

Since bracket citations are removed from the visible Latin text,
`segment.text_la` changes for every affected segment -- re-running phase 1
after this feature was added correctly reset `status` back to `ingested`
for the ~1,050 affected segments (via the existing text_la-equality check in
`load_segments.py`) and required re-translating + re-aligning the subset of
the 20 test segments that happened to contain a bracket citation.

**Displaying citations, and Dutch-formatting them:** the stored `note` for a
citation is the raw Latin bracket text (e.g. "Iesa. 24. d. 23", the middle
letter being a print-page margin marker, not a verse). Two purely
render-time PHP concerns build on this, neither touching stored data or the
char offsets `sentence_alignment` depends on:
- [`InstitutioController::SHOW_RAW_CITATION_BRACKETS`](../../app/src/Controller/InstitutioController.php)
  (temporary, currently `true`) additionally shows the raw `[...]` bracket
  next to the marker on the Latin side, for validating the extraction by
  eye. Flip to `false` once spot-checked across the corpus.
- [`CitationFormatter`](../../app/src/Service/CitationFormatter.php)
  converts recognized old-apparatus Scripture citations to modern Dutch
  form ("Jes. 24:23") for display next to the *translation* -- a deliberately
  conservative regex + book-abbreviation table (only ~34% of citation notes
  match; patristic/classical citations and unrecognized formats fall back to
  the original Latin note rather than risk a wrong/partial conversion).

## Phases

1. **Ingest & tokenisation** (this container's default `main.py`) — fetch
   chapter pages from calvin.reformation.nl (Barth/Niesel critical Latin
   edition), parse them into canonical `book.chapter.section` segments, load
   them into PostgreSQL, tokenize/lemmatize with LatinCy. Free, local,
   resumable.

   > **Source correction:** the project dossier originally specified CCEL's
   > ThML files (`ccel.org/ccel/c/calvin/institutio1.xml`/`institutio2.xml`)
   > as the source, claiming no OCR was needed. That turned out to be wrong
   > for this resource — both CCEL files are page-scan image indexes with
   > zero transcribed Latin text (every `<p>` is an empty
   > `<img alt="Missing page-scan">`). calvin.reformation.nl (Kampen
   > Theological University) has the actual running Latin text, and despite
   > a "Sign in" / "Register" link on the site, the text is served in the
   > plain unauthenticated HTML response — no account needed, verified by
   > fetching with a plain HTTP client, no cookies or login. See
   > `scripts/fetch_sources.py` and `scripts/parse_calvin_reformation.py`.
2. **Lemma glossary** (`scripts/export_lemmas.py` -> `batch_gloss.py` ->
   `load_glosses.py`) — one-off LLM batch run building `lemma_gloss`
   (layer A). **Costs money** (Anthropic Batch API, ~€5-10 estimated for
   the full lemma list).
3. **Fluent translation** (`scripts/translate_segments.py`) — one LLM call
   per segment, stored in `translation` (layer B). **Costs money**
   (~€15-25 estimated for the full corpus).
3.1.1 **Heading translation** (`scripts/translate_headings.py`) — chapter
   headings (`segment.heading`) were never sent to the LLM as their own
   item, only as context inside the section prompt ("maakt deel uit van
   hoofdstuk: ...") with an explicit instruction not to repeat it. Each
   *distinct* heading is translated once here and applied to every segment
   row sharing it, into `heading_nl`. **Costs money** (tiny — ~81 distinct
   headings in the whole corpus, a few hundred tokens each). Independent of
   phase 3 (a chapter's heading can be translated before its body sections
   are), but naturally reaches full coverage once the full corpus is
   translated.

   Found in the front-matter test translation: since front matter's
   "heading" is really the dedicatory letter's salutation line rather than a
   detached chapter title, the LLM had (reasonably) treated it as content
   belonging in the letter and rendered a translated, `#`-prefixed version
   of it as part of the *first sentence's* translation -- duplicating it
   once heading_nl was added. `translate_segments.py`'s `PROMPT_TEMPLATE` now
   explicitly says the heading is shown elsewhere and must not be repeated;
   front matter was re-translated (and re-aligned, since text_nl changed)
   with the fixed prompt to remove the duplicate.
3.2 **Sentence alignment for display** (`scripts/align_sentences.py`) —
   powers the sentence-by-sentence Latin/Dutch view on `/institutio`.
   Naive positional pairing (Latin sentence N <-> Dutch sentence N) breaks as
   soon as a long Calvin period is split into multiple Dutch sentences (the
   translation prompt explicitly allows this, and it happens often in
   practice). Instead, both texts are split into independently-numbered
   sentence lists and Claude returns just the *grouping* between them (which
   Latin sentence(s) correspond to which Dutch sentence(s)) — cheap and far
   more reliable than asking it to reproduce text. Stored in
   `sentence_alignment`, keyed by `translation_id`. **Costs money** (small —
   a few hundred tokens per segment) and requires phase 3 to have run first.

   **Outliers (>`WINDOW_THRESHOLD`=40 sentences per side, e.g. the ~42k-char
   front-matter letter at 260 la / 254 nl sentences)** go through
   `align_windowed()` instead of one whole-document request: asking for the
   full grouping in one shot failed validation on every attempt (always a
   small slip -- a duplicated or skipped index -- somewhere in the middle of
   ~260 items). A sliding window (`WINDOW_SIZE`=25 Latin sentences +
   `WINDOW_MARGIN`=10 extra Dutch sentences as buffer, since splits drift the
   count) is far more reliable in practice -- confirmed: front matter aligned
   with only one single-window retry needed, versus never succeeding whole.
   The window prompt only requires Latin to be *fully* covered; Dutch just
   needs to be a clean prefix from index 0 (the model isn't forced to
   consume padding it doesn't need). The last `WINDOW_TRIM_GROUPS`=2 groups
   of each (non-final) window are dropped before advancing -- least reliable,
   right at the cut point with no visibility into what comes next -- and
   re-attempted with fresh context as part of the next window.
4. **Word alignment** (`scripts/align_segments.py`,
   `scripts/validate_alignment.py`) — SimAlign aligns each Latin token to a
   span in the Dutch translation, independent of the LLM. Free, but only
   meaningful once phase 3 has produced translations.
5. **Annotation UI** and **phase 6 (Corsmannus 1650 as a third layer)** are
   not implemented yet — see `PROJECTDOSSIER.md` / `TECHNISCHE_UITLEG.md`
   (kept alongside this pipeline for full background) for the plan.

Only phase 1 runs by default (`docker compose run institutio`). Phases 2-4
are separate scripts you run manually and review, since they call a paid
API — see each script's docstring.

## Running phase 1

```bash
docker compose --profile institutio build institutio
docker compose --profile institutio run --rm institutio
```

This runs `main.py`, which chains:

```bash
python scripts/fetch_sources.py                                   # caches ~81 chapter pages
python scripts/parse_calvin_reformation.py -o /data/institutio/segments.jsonl
python scripts/load_segments.py /data/institutio/segments.jsonl
python scripts/tokenize_latin.py
```

Actual results from a real run (2026-07): **1,278 segments** (4 books, 80
chapters, plus the front-matter dedicatory letter to Francis I),
**427,726 word tokens**, **11,801 unique lemmas** — closely matching the
dossier's estimates. Sanity check:

```sql
SELECT ref, text_la FROM segment WHERE ref = 'Inst. 1.1.1';
-- starts with "TOTA fere sapientiae nostrae summa..." -- confirmed

SELECT * FROM corpus_stats;
SELECT * FROM lemma_stats LIMIT 30;
```

**Note on `token.surface`:** the LatinCy model ships with its own
`latincy-preprocess` normalisation step that runs before tokenisation, so
`token.surface` is not perfectly diplomatic — e.g. source "vera" comes back
as surface "uera" (classical v->u already applied by the model, not just in
`token.norm` as the schema comment implies). Harmless for lemma/gloss
lookups, but don't rely on `surface` for a character-exact reproduction of
the source page.

## Running phases 2-4 (manual, costs money)

These aren't wired into `main.py`. Run them one at a time, inside the
container (`anthropic` is in requirements.txt; set `ANTHROPIC_API_KEY` in
`.env.local` at the project root, picked up via `docker-compose.yml`):

```bash
python scripts/export_lemmas.py
python scripts/batch_gloss.py --limit 100   # review the top-100 theological terms first
python scripts/batch_gloss.py                 # full run once satisfied
python scripts/load_glosses.py

python scripts/translate_segments.py --limit 20   # test batch
python scripts/translate_segments.py                # full run
# --segment-ids 4,5,13 re-(re)translates specific segments regardless of
# status -- useful for fixing ones that were silently truncated by an old
# MAX_TOKENS/thinking setting, without re-submitting the whole corpus.

python scripts/align_sentences.py --limit 20   # test batch (needs phase 3 first)
python scripts/align_sentences.py                # full run
# --batch-id <id> re-fetches an already-completed batch instead of
# resubmitting (recovery from a parsing bug without paying twice).

pip install simalign   # phase 4 only
python scripts/align_segments.py
python scripts/validate_alignment.py
```

## Why LatinCy + LLM + SimAlign (not an English pivot)

LatinCy is free, local and deterministic, and gives morphology (case,
number, gender, tense, mood) essentially for free — valuable for
theological-philological study, and it means the LLM only has to translate
the ~15-20k unique lemmas once, not every one of the ~500k tokens.

The LLM produces the fluent Dutch translation (it handles neo-Latin and
theological register far better than classical NMT models), but its
*claimed* word alignment can't be trusted — ask an LLM to translate and then
explain which word maps to which, and it fabricates a plausible-sounding
but unverifiable answer after the fact. SimAlign aligns Latin and Dutch
independently via multilingual BERT embeddings, so it checks the LLM's
translation rather than trusting it. Confidence scores below a threshold
flag segments for manual correction (phase 5, not yet built).

See `TECHNISCHE_UITLEG.md` for the full explanation of all three
technologies and how they combine, and `PROJECTDOSSIER.md` for corpus
statistics and the copyright status of each Dutch reference translation.
Both docs predate the source correction above (they still describe the CCEL
plan) — treat everything in them about *fetching/parsing the Latin source*
as superseded by this README and the two erratum notes at the top of each
file; the rest (phase 2-6 design, copyright table, technology explanations)
still applies as written.

## Data layout

Everything lives under the `institutio_data` Docker volume, mounted at
`/data/institutio`:

```
/data/institutio/
├── raw/pages/
│   ├── -1_1.html          # front matter
│   ├── 1_1.html … 1_18.html
│   ├── 2_1.html … 2_17.html
│   ├── 3_1.html … 3_25.html
│   └── 4_1.html … 4_20.html
├── segments.jsonl
├── lemma_stats.csv
├── lemma_glosses.jsonl
```
