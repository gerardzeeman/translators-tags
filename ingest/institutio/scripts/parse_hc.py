#!/usr/bin/env python3
"""Parse the cached heidelblog.net page (fetch_hc.py) into segments (JSONL).

Structure: a short dedicatory preface (Elector Frederick III's charge to the
Palatinate churches/schools), then the 129 numbered "Quaestio"/answer pairs,
grouped under a handful of part headings in source order (the introductory
Q1-2 pair, then "Prima/Secunda/Tertia Pars"). `chapter` numbers these part
groups sequentially (1, 2, 3, 4); `section` is the question's own global
number (1-129) -- simpler and still unique than resetting per part, and it
matches how the Catechism is normally cited ("HC 1" rather than "HC part
2 q. 3"). Preface segments use the same book=NULL/chapter=NULL/"voorwoord"
ref convention as the Canones parser.

Known source quality issues (this is a personal/community transcription,
less rigorously proofread than the NGB page from the same site): the
printed "Quaestio N." numbers drift and can't be trusted, so questions are
counted purely by position instead (confirmed reliable: 127 "Quaestio"-
starting paragraphs found against 129 official questions -- see below).
Two confirmed paragraphs are missing their own question line entirely
(only the answer survived) and are flagged with a bracketed marker rather
than silently dropped or fabricated; two more official questions'
content is very likely present but merged into a neighbouring segment
rather than split out on its own, since an orphaned single-paragraph
answer is structurally indistinguishable from a multi-paragraph answer
that legitimately continues the previous question (both are just plain
<p> tags with no question line) -- this couldn't be resolved by parsing
alone. All of this is fixable post-ingest via the segment_text_correction
editor once it's visible in the app; ingesting a 127/129 result with the
gaps clearly marked was judged better than not ingesting at all.

Usage:
    python scripts/parse_hc.py -o /data/institutio/hc_segments.jsonl
    python scripts/parse_hc.py --dry-run

Requires: beautifulsoup4, lxml
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

from bs4 import BeautifulSoup

RAW_PATH = Path("/data/institutio/raw/hc.html")

_QUAESTIO_RE = re.compile(r"^(?:Quaestio\.?\s*)?(\d+)\.\s*(.*)$", re.DOTALL)
_QUAESTIO_START_RE = re.compile(r"^Quaestio\b", re.IGNORECASE)
# Exactly one question in the whole document (confirmed: question 43) drops
# the word "Quaestio" entirely and is labelled with just the bare number --
# matched separately (rather than loosening _QUAESTIO_START_RE generally)
# since a bare "N." start is otherwise a plausible false positive elsewhere
# in the text; confirmed by exhaustive search that this is the only <p> in
# the whole document starting with a bare "digits + period".
_BARE_NUMBER_START_RE = re.compile(r"^\d+\.\s")
_START_MARKER_RE = re.compile(r"^Catechesis\s+relig", re.IGNORECASE)
# "Prima/Tertia Pars" are <h2> in the source, but "Secunda Pars" is a plain
# <p> (a markup inconsistency, confirmed by inspection) -- matched separately
# so a part boundary is recognised regardless of which tag it landed in.
_PARS_RE = re.compile(r"^(Prima|Secunda|Tertia)\s+Pars\b.*$", re.IGNORECASE)
_MISSING_QUESTION_MARKER = "[Quaestio-tekst ontbreekt in bron.]"
_MISSING_ANSWER_MARKER = "[Antwoord ontbreekt in bron.]"


def _clean(text: str) -> str:
    text = text.replace("�", " ")  # mis-encoded space artifact seen in the source
    return re.sub(r"\s+", " ", text).strip()


def _flatten_and_split(article_tag) -> list[tuple[str, str]]:
    """(tag_name, text) pairs in document order, with any <p> that has a
    "Quaestio N." heading buried mid-text split into two entries at that
    point. Confirmed for question 7: its <p> in the source runs straight on
    from the end of question 6's answer into "Quaestio 7. ..." with no
    paragraph break at all, so the per-paragraph is-this-a-question-start
    check below never sees "Quaestio 7." in isolation -- it silently
    swallows all of question 7 as trailing text of question 6's answer
    instead (confirmed only this one occurrence in the whole document).
    """
    out: list[tuple[str, str]] = []
    for tag in article_tag.find_all(["h1", "h2", "h3", "p"]):
        text = _clean(tag.get_text())
        if not text or tag.name != "p":
            out.append((tag.name, text))
            continue
        m = re.search(r"Quaestio\s*\d+\.", text, re.IGNORECASE)
        if m and m.start() > 0:
            out.append(("p", text[:m.start()].strip()))
            out.append(("p", text[m.start():].strip()))
        else:
            out.append(("p", text))
    return out


def parse(html: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    article_tag = soup.find("article") or soup.find("div", class_=re.compile("entry-content|post-content"))
    if article_tag is None:
        raise ValueError("could not find the article body container in the fetched page")

    segments: list[dict] = []
    seq = 0
    preface_n = 0

    mode = "preface"           # 'preface' | 'qa'
    chapter_num = 0
    chapter_heading: str | None = None
    expected_q = 1
    pending_question: str | None = None
    pending_answer_parts: list[str] = []

    def emit(question: str | None, answer_parts: list[str]) -> None:
        nonlocal seq, expected_q
        answer = " ".join(answer_parts) if answer_parts else None
        if question is None:
            print(f"[warn]  Quaestio {expected_q}: question text missing from source, "
                  f"keeping the answer with a visible placeholder")
            body = f"{_MISSING_QUESTION_MARKER} {answer}"
        elif answer is None:
            print(f"[warn]  Quaestio {expected_q}: answer text missing from source, "
                  f"keeping the question with a visible placeholder")
            body = f"{question} {_MISSING_ANSWER_MARKER}"
        else:
            body = f"{question} {answer}"
        seq += 1
        segments.append({
            "ref": f"HC {expected_q}", "book": None, "chapter": chapter_num, "section": expected_q,
            "kind": None, "heading": chapter_heading, "text": _clean(body),
            "seq": seq, "annotations": [],
        })
        expected_q += 1

    def start_chapter(heading: str) -> None:
        # A pending question must be flushed with the *old* chapter/heading
        # before switching -- otherwise a question that started under one
        # part but wasn't answered (and therefore not yet emitted) until
        # after the next part's heading appears would wrongly inherit the
        # new chapter number (confirmed for question 2, which sits right
        # before "Prima Pars" starts but wasn't flushed until question 3's
        # own question-line forced it, by which point chapter_num had
        # already moved on).
        nonlocal chapter_num, chapter_heading, pending_question, pending_answer_parts
        if pending_question is not None:
            emit(pending_question, pending_answer_parts)
            pending_question, pending_answer_parts = None, []
        chapter_num += 1
        chapter_heading = heading

    for tag_name, text in _flatten_and_split(article_tag):
        if not text:
            continue

        if tag_name in ("h1", "h2", "h3"):
            if tag_name != "h2":
                # h1 is the page title; h3 headings (e.g. "Primum praeceptum")
                # mark subsections *within* a part, interspersed between
                # Quaestio/answer pairs for the Ten Commandments -- neither
                # should touch chapter or pending_question state.
                continue
            if mode == "preface" and _START_MARKER_RE.match(text):
                mode = "qa"
                start_chapter(text)
            elif mode == "qa" and _PARS_RE.match(text) and text != chapter_heading:
                # Any other <h2> in the source's WordPress markup (e.g. a
                # "Subscribe to the newsletter" widget heading, confirmed
                # present in the fetched page) must NOT start a phantom
                # chapter -- only the intro marker above and a genuine
                # "Prima/Secunda/Tertia Pars" heading do. The source also
                # repeats "Tertia Pars: De Gratitudine" verbatim a second
                # time partway through (confirmed by inspection) -- the
                # `!= chapter_heading` guard treats that repeat as a no-op
                # rather than a second, duplicate chapter.
                start_chapter(text)
            continue

        # tag.name == "p"
        if mode == "preface":
            preface_n += 1
            seq += 1
            segments.append({
                "ref": f"HC voorwoord.{preface_n}", "book": None, "chapter": None,
                "section": preface_n, "kind": None, "heading": None, "text": text,
                "seq": seq, "annotations": [],
            })
            continue

        # mode == "qa"
        if _PARS_RE.match(text) and text != chapter_heading:
            start_chapter(text)
            continue

        # The printed "Quaestio N." digit is NOT trustworthy in this source
        # -- confirmed by inspection: dozens of questions carry the wrong
        # number (a running transcription drift, not isolated typos like the
        # NGB source's single mis-numbered article), so trusting it cascades
        # into total desync. Question boundaries are instead detected purely
        # by "this paragraph starts with 'Quaestio'" and numbered by pure
        # position (1, 2, 3, ... in encounter order) -- the one guarantee the
        # source does hold structurally, confirmed against the official
        # 129-question count below. An answer can also legitimately span more
        # than one <p> (confirmed around question ~100, split mid-sentence in
        # the source markup), so every non-question paragraph up to the next
        # question-start is accumulated and joined, not paired 1:1.
        is_question_start = bool(_QUAESTIO_START_RE.match(text)) or bool(_BARE_NUMBER_START_RE.match(text))
        if is_question_start:
            if pending_question is not None:
                # Two question-start paragraphs in a row with nothing but
                # (possibly zero) plain paragraphs between them: if none, the
                # previous question never got an answer at all (confirmed
                # for question 22).
                emit(pending_question, pending_answer_parts)
                pending_question, pending_answer_parts = None, []
            m = _QUAESTIO_RE.match(text)
            rest = _clean(m.group(2)) if m else _clean(text)
            # The question and its answer are occasionally combined into one
            # <p> with no paragraph break between them (confirmed for
            # question ~67: "...deducant? Ita est. Nam Spiritus..." all in a
            # single tag). Split at the first "?" so the answer isn't
            # swallowed into pending_question and then wrongly flagged as
            # missing when no separate answer paragraph ever follows.
            q_end = rest.find("?")
            if q_end != -1 and q_end + 1 < len(rest):
                pending_question = rest[:q_end + 1].strip()
                pending_answer_parts = [rest[q_end + 1:].strip()]
            else:
                pending_question = rest
            continue

        # Not a question-start paragraph -> accumulate as (part of) the
        # current answer. If pending_question is None, two separate gaps
        # coincided (an accumulated-but-orphaned answer with no question at
        # all, confirmed for 7 and 43) -- flush it as its own gap segment
        # immediately rather than silently merging it into whatever question
        # comes next.
        if pending_question is None:
            emit(None, [text])
            continue
        pending_answer_parts.append(text)

    if pending_question is not None:
        emit(pending_question, pending_answer_parts)

    return segments


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("-o", "--output", type=Path, default=Path("/data/institutio/hc_segments.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--input", type=Path, default=RAW_PATH)
    args = ap.parse_args()

    segments = parse(args.input.read_text(encoding="utf-8"))
    n_qa = sum(1 for s in segments if s["chapter"] is not None)
    n_preface = len(segments) - n_qa
    n_chapters = len({s["chapter"] for s in segments if s["chapter"] is not None})
    print(f"[parse] {len(segments)} segments: {n_qa} vragen (expect 129), {n_preface} voorwoord, "
          f"across {n_chapters} onderdelen")

    q_numbers = [s["section"] for s in segments if s["chapter"] is not None]
    if q_numbers != list(range(1, len(q_numbers) + 1)):
        print(f"[warn]  question numbers not a clean 1..N sequence")

    if args.dry_run:
        for s in segments:
            marker = " [GAP]" if _MISSING_QUESTION_MARKER in s["text"] else ""
            print(f"  {s['ref']:<14}{marker} chapter={s['chapter']} len={len(s['text'])}")
        return 0

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as f:
        for s in segments:
            f.write(json.dumps(s, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
