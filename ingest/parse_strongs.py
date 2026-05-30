"""
parse_strongs.py
Parses the openscriptures/strongs Hebrew and Greek XML dictionaries
and populates the strongs_entries table.

Sources (cloned by fetch_sources.py):
  /data/sources/strongs/hebrew/StrongHebrewG.xml
  /data/sources/strongs/greek/StrongsGreekDictionaryXML_1.4/strongsgreek.xml

Hebrew XML structure (OSIS format):
  <div type="entry" n="1">
    <w ID="H1" lemma="אָב" xlit="ʼâb" POS="awb" morph="n-m" xml:lang="heb">אב</w>
    <list><item>1) father of an individual</item>...</list>
    <note type="exegesis">a primitive word;</note>
    <note type="explanation"><hi>father</hi>, …</note>
    <note type="translation">chief, (fore-) father(-less), …</note>
  </div>

Greek XML structure:
  <entry strongs="1">
    <strongs>1</strongs>
    <greek unicode="Α" translit="A"/>
    <pronunciation strongs="al'-fah"/>
    <strongs_derivation>of Hebrew origin; …</strongs_derivation>
    <strongs_def>ALPHA as a numeral …</strongs_def>
    <kjv_def>Alpha. Often used …</kjv_def>
  </entry>
"""

import re
from pathlib import Path
from xml.etree import ElementTree as ET

from db.connection import get_connection

SOURCES_DIR = Path("/data/sources")
HEBREW_XML  = SOURCES_DIR / "strongs/hebrew/StrongHebrewG.xml"
GREEK_XML   = SOURCES_DIR / "strongs/greek/StrongsGreekDictionaryXML_1.4/strongsgreek.xml"

OSIS_NS = "http://www.bibletechnologies.net/2003/OSIS/namespace"


# ── helpers ───────────────────────────────────────────────────────────────────

def _text(el) -> str:
    """Return all inner text of an element, stripping extra whitespace."""
    return " ".join((el.itertext())).split()
    # itertext() flattens mixed content (e.g. <hi> tags inside <note>)


def _clean(text: str | None) -> str | None:
    if not text:
        return None
    text = re.sub(r"\s+", " ", text).strip()
    return text or None


def _canonical_id(prefix: str, raw: str) -> str:
    """Return canonical Strong's ID: prefix + integer (no leading zeros).

    e.g. ('H', '0853') -> 'H853', ('G', '00037') -> 'G37'
    """
    try:
        return f"{prefix}{int(raw)}"
    except (ValueError, TypeError):
        return f"{prefix}{raw}"


def _inner_text(el) -> str:
    """Flatten all text content inside an element (handles child tags like <hi>)."""
    return _clean("".join(el.itertext())) or ""


# ── Hebrew parser ─────────────────────────────────────────────────────────────

def _parse_hebrew(path: Path) -> list[dict]:
    print(f"  Parsing Hebrew XML: {path}")
    rows = []

    tree = ET.parse(path)
    root = tree.getroot()

    # The file uses the OSIS namespace on every element.
    ns = {"o": OSIS_NS}

    for entry in root.findall(".//o:div[@type='entry']", ns):
        w_el = entry.find("o:w", ns)
        if w_el is None:
            continue

        raw_id = w_el.get("ID", "")           # e.g. "H1" or "H0853"
        if not raw_id.startswith("H"):
            continue
        # Strip any trailing letter variant suffix (e.g. "H7225G" -> "H7225")
        raw_num = re.sub(r"[A-Za-z]+$", "", raw_id[1:])
        strongs_id = _canonical_id("H", raw_num)

        lemma           = w_el.get("lemma")  # pointed form with vowel marks
        transliteration = w_el.get("xlit")
        pronunciation   = w_el.get("POS")    # phonetic key
        pos             = w_el.get("morph")  # e.g. "n-m"

        # Definitions: collect all <item> text inside <list>
        items = []
        for item in entry.findall(".//o:list/o:item", ns):
            t = _inner_text(item)
            if t:
                items.append(t)
        definition = "\n".join(items) or None

        # Notes
        etymology      = None
        short_def      = None
        kjv_renderings = None
        for note in entry.findall("o:note", ns):
            note_type = note.get("type")
            text = _inner_text(note)
            if note_type == "exegesis":
                etymology = text
            elif note_type == "explanation":
                short_def = text
            elif note_type == "translation":
                kjv_renderings = text

        rows.append({
            "strongs_id":      strongs_id,
            "lang":            "HE",
            "lemma":           _clean(lemma),
            "transliteration": _clean(transliteration),
            "pronunciation":   _clean(pronunciation),
            "pos":             _clean(pos),
            "morph":           None,
            "definition":      definition,
            "etymology":       etymology,
            "kjv_renderings":  kjv_renderings,
            "short_def":       short_def,
        })

    print(f"  ✓ {len(rows)} Hebrew entries parsed")
    return rows


# ── Greek parser ──────────────────────────────────────────────────────────────

def _parse_greek(path: Path) -> list[dict]:
    print(f"  Parsing Greek XML: {path}")
    rows = []

    tree = ET.parse(path)
    root = tree.getroot()

    # strongsgreek.xml has no namespace — entries are <entry strongs="N">
    for entry in root.findall(".//entry"):
        num = entry.get("strongs")
        if not num:
            # Try child <strongs> element
            s_el = entry.find("strongs")
            num = s_el.text.strip() if s_el is not None and s_el.text else None
        if not num:
            continue

        strongs_id = _canonical_id("G", num)

        # Greek word
        greek_el = entry.find("greek")
        lemma           = greek_el.get("unicode")   if greek_el is not None else None
        transliteration = greek_el.get("translit")  if greek_el is not None else None

        # Pronunciation
        pron_el     = entry.find("pronunciation")
        pronunciation = pron_el.get("strongs") if pron_el is not None else None

        # Definitions / derivation
        deriv_el   = entry.find("strongs_derivation")
        etymology  = _inner_text(deriv_el) if deriv_el is not None else None

        def_el     = entry.find("strongs_def")
        short_def  = _inner_text(def_el)   if def_el  is not None else None

        kjv_el     = entry.find("kjv_def")
        kjv_renderings = _inner_text(kjv_el) if kjv_el is not None else None

        # Some files use <meaning> instead of <strongs_def>
        if not short_def:
            m_el = entry.find("meaning")
            short_def = _inner_text(m_el) if m_el is not None else None

        rows.append({
            "strongs_id":      strongs_id,
            "lang":            "GR",
            "lemma":           _clean(lemma),
            "transliteration": _clean(transliteration),
            "pronunciation":   _clean(pronunciation),
            "pos":             None,
            "morph":           None,
            "definition":      None,       # Greek file stores def in short_def
            "etymology":       _clean(etymology),
            "kjv_renderings":  _clean(kjv_renderings),
            "short_def":       _clean(short_def),
        })

    print(f"  ✓ {len(rows)} Greek entries parsed")
    return rows


# ── DB insert ─────────────────────────────────────────────────────────────────

def _upsert_entries(rows: list[dict]) -> None:
    if not rows:
        return
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.executemany(
                """
                INSERT INTO strongs_entries
                    (strongs_id, lang, lemma, transliteration, pronunciation,
                     pos, morph, definition, etymology, kjv_renderings, short_def)
                VALUES
                    (%(strongs_id)s, %(lang)s, %(lemma)s, %(transliteration)s,
                     %(pronunciation)s, %(pos)s, %(morph)s, %(definition)s,
                     %(etymology)s, %(kjv_renderings)s, %(short_def)s)
                ON CONFLICT (strongs_id) DO UPDATE SET
                    lemma           = EXCLUDED.lemma,
                    transliteration = EXCLUDED.transliteration,
                    pronunciation   = EXCLUDED.pronunciation,
                    pos             = EXCLUDED.pos,
                    morph           = EXCLUDED.morph,
                    definition      = EXCLUDED.definition,
                    etymology       = EXCLUDED.etymology,
                    kjv_renderings  = EXCLUDED.kjv_renderings,
                    short_def       = EXCLUDED.short_def
                """,
                rows,
            )
    print(f"  ✓ {len(rows)} entries upserted into strongs_entries")


# ── Entry point ───────────────────────────────────────────────────────────────

def parse_strongs() -> None:
    all_rows = []

    if HEBREW_XML.exists():
        all_rows.extend(_parse_hebrew(HEBREW_XML))
    else:
        print(f"  ⚠ Hebrew XML not found at {HEBREW_XML}, skipping")

    if GREEK_XML.exists():
        all_rows.extend(_parse_greek(GREEK_XML))
    else:
        print(f"  ⚠ Greek XML not found at {GREEK_XML}, skipping")

    _upsert_entries(all_rows)


if __name__ == "__main__":
    parse_strongs()
