> **Erratum (2026-07):** §1, §3 (fase 1) en §6 (R1, R5) beschrijven CCEL
> (`ccel.org/ccel/c/calvin/institutio1.xml`/`institutio2.xml`) als bron, met
> de claim "geen OCR nodig". Dat bleek onjuist: beide CCEL-bestanden zijn
> paginascan-indexen zonder getranscribeerde tekst (elke `<p>` is een lege
> `<img alt="Missing page-scan">`). De pijplijn gebruikt nu
> calvin.reformation.nl (Kampen, Barth/Niesel-editie) als bron — geen
> account nodig ondanks de "Sign in"-link op de site, de Latijnse tekst
> wordt gewoon meegestuurd in de onbeveiligde HTML-respons. Fase 1 is
> hierop opnieuw uitgevoerd en werkt: 1.278 segmenten, 427.726 woordtokens,
> 11.801 unieke lemma's. Zie `README.md` en `scripts/fetch_sources.py` /
> `scripts/parse_calvin_reformation.py` voor de huidige implementatie. De
> rest van dit dossier (fase 2-6 ontwerp, corpusstatistieken-schattingen,
> auteursrechtstabel) staat nog overeind.

# Projectdossier: Institutio-pijplijn
## Latijn (1559) → Nederlands, twee lagen: interlineair + vloeiende vertaling met woordalignment

**Doel:** Een Python/PostgreSQL-pijplijn bouwen die Calvijns *Institutio
christianae religionis* (1559) verwerkt tot een gelaagde, woord-voor-woord
gealigneerde Latijns-Nederlandse editie — geschikt voor studie en verdieping.

**Status bij afsluiting sessie:** Fase 1 (ingest & tokenisatie) volledig
geïmplementeerd, getest en werkend. Alle code staat in `institutio-pipeline.zip`.

---

## 1. Architectuurkeuzes en motivatie

### Twee lagen, één tokenfundament

Het project levert twee lagen over dezelfde Latijnse brontokens:

| Laag | Naam | Techniek | Output |
|------|------|----------|--------|
| A | Interlineair | LatinCy lemmatisatie + LLM-glossenlexicon | Gloss per bronwoord (letterlijk) |
| B | Vloeiende vertaling | LLM per sectie + SimAlign | Leesbare NL-zin + woordalignment met confidence-scores |

Beide lagen verwijzen naar dezelfde `token`-rijen in de database. Hierdoor
kun je op elk token tegelijkertijd de gloss (laag A) én de context in de
vloeiende vertaling (laag B) tonen.

### Waarom LatinCy voor laag A?

- Vrij, lokaal, deterministisch — geen tokenkosten voor het hele corpus.
- Geeft morfologie (naamval, getal, geslacht, tijd, modus) gratis mee —
  waardevol voor theologisch-filologische studie.
- Het corpus heeft vermoedelijk 15.000–20.000 *unieke lemma's*, ook al zijn
  er ~500.000 tokens. Het LLM-glossenlexicon hoeft dus maar eenmalig die
  lemmalijst te vertalen, wat de kosten sterk drukt.

### Waarom LLM + SimAlign voor laag B?

- LLM-vertaalkwaliteit voor neolatijn (Calvijns stijl: lange periodezinnen,
  Cicero-aanse retoriek, dense theologische begrippen) is superieur aan
  NMT-modellen.
- SimAlign alignt Latijns en Nederlands via meertalige BERT-embeddings
  *onafhankelijk* van de LLM — daardoor controleert de aligner de LLM-claims
  in plaats van ze te vertrouwen.
- Confidence-scores onder een drempel worden gemarkeerd voor handmatige
  correctie via een annotatie-UI.

### Waarom de Institutio ideaal is voor dit project

1. **Brontekst digitaal beschikbaar** — CCEL biedt de Latijnse 1559-editie
   (Barth-Niesel / CCEL-editie) als ThML-XML in twee volumes:
   - `https://www.ccel.org/ccel/c/calvin/institutio1.xml`
   - `https://www.ccel.org/ccel/c/calvin/institutio2.xml`
   Geen OCR, geen normalisatie van ligaturen nodig.
2. **Canonieke structuur** — 4 boeken, 80 hoofdstukken, genummerde secties.
   De ref `Inst. 3.21.5` is de universele wetenschappelijke verwijzing en
   ook de primaire sleutel in de database.
3. **Nederlandse vertalingen beschikbaar** — meerdere, met heldere
   auteursrechtsstatus (zie §5).

---

## 2. Corpusstatistieken (schattingen)

| Grootheid | Schatting | Basis |
|-----------|-----------|-------|
| Totaal woordtokens | ~500.000 | Vergelijking met Beveridge-editie (~800 blz.) |
| Unieke lemma's | 15.000–20.000 | Typische variatie neolatijns theologisch corpus |
| Segmenten (secties) | ~1.100–1.200 | 80 hfdst. × gem. ~14 sec. + voorwerk |
| Boeken | 4 | Calvijn 1559 definitieve editie |
| Hoofdstukken | 80 | Definitieve editie (was 6 in 1536) |

*Werkelijke aantallen: run `SELECT * FROM corpus_stats;` na fase 2.*

---

## 3. Volledig stappenplan

### Fase 1 — Ingest & tokenisatie ✅ KLAAR

**Doel:** Brontekst in de database, getokeniseerd, klaar als fundament voor
beide lagen.

#### Stap 1.1 — Omgeving inrichten

```bash
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# LatinCy-model installeren (eenmalig, keuze uit twee groottes)
# Groot (~560 MB, aanbevolen voor productie):
pip install https://huggingface.co/latincy/la_core_web_lg/resolve/main/la_core_web_lg-any-py3-none-any.whl
# Klein (~50 MB, voor snelle test):
pip install https://huggingface.co/latincy/la_core_web_sm/resolve/main/la_core_web_sm-any-py3-none-any.whl

# PostgreSQL-database
createdb institutio
export DATABASE_URL=postgresql://user:pass@localhost:5432/institutio
psql "$DATABASE_URL" -f sql/schema.sql
```

#### Stap 1.2 — Bronbestanden ophalen

```bash
python scripts/fetch_sources.py
# → data/raw/institutio1.xml  (gecachet, ± 1,5 MB per volume verwacht)
# → data/raw/institutio2.xml
```

#### Stap 1.3 — Structuur inspecteren (verplicht)

```bash
python scripts/parse_thml.py --inspect data/raw/institutio1.xml
python scripts/parse_thml.py --inspect data/raw/institutio2.xml
```

**Wat je wilt zien:** `div1 book`, `div2 chapter`, `div3/div4 section` (of
genummerde `<p>` zonder sectie-divs). Controleer dat het totaal uitkomt op 2
boeken per volume. Zie §6 voor risico's bij afwijkende structuur.

#### Stap 1.4 — Parseren

```bash
python scripts/parse_thml.py \
    data/raw/institutio1.xml \
    data/raw/institutio2.xml \
    -o data/segments.jsonl
```

**Verwacht eindresultaat:**
- ~1.100–1.200 segmenten
- 4 boeken, 80 hoofdstukken
- Enkele segmenten met `Inst. front.N` voor de opdrachtbrief aan Frans I en
  de brief aan de lezer.
- Eventuele waarschuwingen over niet-opeenvolgende sectienummers → zie §6.

#### Stap 1.5 — Laden in PostgreSQL

```bash
python scripts/load_segments.py data/segments.jsonl
# Idempotent: veilig om meerdere malen te draaien.
```

#### Stap 1.6 — Tokeniseren en lemmatiseren

```bash
# Smoke-test zonder model (snel, geen lemma's):
python scripts/tokenize_latin.py --blank --limit 10

# Productierun:
python scripts/tokenize_latin.py
```

Na voltooiing toont het script `corpus_stats` (segmenten, woordtokens,
unieke lemma's) en de top-15 meest frequente lemma's. Dit zijn de werkelijke
aantallen die de rest van de planning bepalen.

**Hervatbaar:** bij onderbreking gewoon opnieuw starten — verwerkte
segmenten (status=`tokenized`) worden overgeslagen.

#### Stap 1.7 — Steekproef

```sql
-- Controleer een bekende passage (begin boek 1):
SELECT ref, text_la FROM segment WHERE ref = 'Inst. 1.1.1';

-- Bekijk de tokens:
SELECT position, surface, norm, lemma, upos, morph
FROM token t
JOIN segment s ON s.id = t.segment_id
WHERE s.ref = 'Inst. 1.1.1' AND t.is_word
ORDER BY position;

-- Corpus totalen:
SELECT * FROM corpus_stats;

-- Top lemma's:
SELECT * FROM lemma_stats LIMIT 30;
```

Vergelijk `text_la` van `Inst. 1.1.1` met de Barth-Niesel-editie of
calvin.reformation.nl. De tekst moet beginnen met:
*"Tota fere sapientiae nostrae summa..."*

---

### Fase 2 — Lemma-lexicon (Laag A, stap 1) 🔜

**Doel:** Eenmalige LLM-batchrun over alle unieke lemma's → `lemma_gloss`.

#### Stap 2.1 — Lemmalijst exporteren

```sql
-- Exporteer gesorteerd op frequentie (meest voorkomend eerst, voor prioritering):
COPY (
    SELECT lemma, freq, n_segments
    FROM lemma_stats
    WHERE lemma IS NOT NULL
    ORDER BY freq DESC
) TO '/tmp/lemma_stats.csv' CSV HEADER;
```

#### Stap 2.2 — LLM-batchrun

Gebruik de Anthropic Batch API (goedkoper dan realtime, async). Prompt per lemma:

```
Geef voor het Latijnse lemma "{lemma}" (frequentie: {freq}x in Calvijns
Institutio 1559):
1. De hoofdbetekenis in het Nederlands (1–3 woorden).
2. 1–2 alternatieve betekenissen indien van toepassing.
3. Een korte noot als het een technisch theologisch of filosofisch begrip is.

Antwoord uitsluitend als JSON:
{"gloss_nl": "...", "gloss_alt": ["...", "..."], "note": "..."}
```

**Tip:** Geef context mee voor hoog-frequente theologische kernbegrippen
(sapientia, gratia, fides, electio, providentia e.d.) — die zijn polyseem en
de kale lemmavertaling is voor die gevallen onvoldoende. Overweeg een
handmatige lijst van de top-100 lemma's vóór de batchrun te reviewen.

#### Stap 2.3 — Laden in lemma_gloss

```python
# Itereer over batchresultaten en laad in database:
INSERT INTO lemma_gloss (lemma, gloss_nl, gloss_alt, note, source)
VALUES (%s, %s, %s, %s, 'llm')
ON CONFLICT (lemma) DO UPDATE SET
    gloss_nl = EXCLUDED.gloss_nl,
    gloss_alt = EXCLUDED.gloss_alt,
    note = EXCLUDED.note;
```

**Kosten:** Bij ~17.500 unieke lemma's × gemiddeld ~100 tokens input/output
≈ 3,5M tokens. Tegen Sonnet-batchprijzen ≈ $5–10 totaal.

---

### Fase 3 — Vloeiende vertaling (Laag B, stap 1) 🔜

**Doel:** Eén NL-vertaling per segment, opgeslagen in `translation`.

#### Stap 3.1 — Vertaalprompt

Calvijns stijl vereist een specifiek gerichte prompt. Aanbevolen:

```
Je vertaalt een sectie uit Calvijns Institutio christianae religionis (1559)
naar modern, helder Nederlands. De sectie heeft referentie {ref} en maakt
deel uit van hoofdstuk: "{heading}".

Regels:
- Bewaar theologische terminologie nauwkeurig (gratia = genade, fides =
  geloof, electio = verkiezing, etc.)
- Calvijns lange Latijnse perioden mogen in het Nederlands gesplitst worden
  mits de betekenis volledig behouden blijft.
- Vertaal Schriftcitaten naar de Statenvertaling-equivalent indien herkenbaar.
- Geef alleen de vertaling terug, geen uitleg of toelichting.

Te vertalen Latijns:
{text_la}
```

#### Stap 3.2 — Batchpijplijn

Segmenten verwerken in batches van 50–100, met status-tracking:
- `ingested` → tekst in database
- `tokenized` → tokens aanwezig
- `translated` → vertaling in `translation`-tabel
- `aligned` → alignment in `alignment`-tabel

Bij elke crash of rate-limit: herstart, reeds-vertaalde segmenten worden
overgeslagen (ON CONFLICT in translation-tabel).

**Kosten laag B:** ~500.000 Latijnse tokens × gemiddeld 1,5× uitbreidingsfactor
in het Nederlands = ~750.000 output tokens. Met Sonnet batch ≈ $15–25 totaal.

---

### Fase 4 — Woordalignment (Laag B, stap 2) 🔜

**Doel:** Koppel elk Latijns token aan de corresponderende span in de NL-vertaling.

#### Stap 4.1 — SimAlign installeren

```bash
pip install simalign
# of: pip install git+https://github.com/cisnlp/simalign.git
```

#### Stap 4.2 — Alignment per segment

```python
from simalign import SentenceAligner
aligner = SentenceAligner(model="bert", token_type="bpe", matching_methods="mai")

# Per segment:
src_tokens = [t.surface for t in segment_tokens if t.is_word]
tgt_tokens  = translation_text.split()   # rudimentaire NL-tokenisatie
alignments  = aligner.get_word_aligns(src_tokens, tgt_tokens)
# alignments["mai"] = lijst van (src_idx, tgt_idx)-paren
```

**Opmerking:** SimAlign geeft index-paren, geen character-offsets. Converteer
naar char-offsets in de NL-vertaling voor opslag in `alignment.target_start/end`.
Sla confidence-score op als `NULL` (SimAlign geeft geen expliciete scores bij
"mai"-methode; gebruik "inter"-methode voor probabilistische scores).

#### Stap 4.3 — Validatie

Query om lage-confidence of ontbrekende alignments te vinden:

```sql
-- Tokens zonder alignment:
SELECT s.ref, t.surface, t.position
FROM token t
JOIN segment s ON s.id = t.segment_id
LEFT JOIN alignment a ON a.token_id = t.id
WHERE t.is_word AND a.id IS NULL
  AND s.status = 'aligned'
ORDER BY s.seq, t.position;

-- Gemiddelde coverage per boek:
SELECT s.book,
       count(t.id) FILTER (WHERE t.is_word) AS tokens,
       count(a.id) AS aligned
FROM segment s
JOIN token t ON t.segment_id = s.id
LEFT JOIN alignment a ON a.token_id = t.id
GROUP BY s.book;
```

---

### Fase 5 — Annotatie-UI 🔜

**Doel:** Scherm in Symfony om alignments onder een confidence-drempel handmatig
te corrigeren.

Minimale functionaliteit:
- Toon segment: Latijnse tekst boven, NL-vertaling eronder.
- Klikken op een Latijns woord markeert de corresponderende NL-span.
- Wijziging opslaan als `source = 'manual'`.
- Filter: toon alleen segmenten met minstens één alignment `source = 'simalign'`
  die handmatige review behoeft (laag confidence of flagged).

**Stack:** Symfony 7 + FrankenPHP + Tailwind + Stimulus/Turbo —
aansluitend op je bestaande SaaS/Bijbelapp-stack.

---

### Fase 6 — Optioneel: Corsmannus 1650 als derde laag 🔜

De vertaling van Wilhelmus Corsmannus (1650), opnieuw uitgegeven door Abraham
Kuyper in 1889, is volledig publiek domein. Ze is beschikbaar via:
- Google Books (scan Kuyper 1889)
- Mogelijk ook Digibron

Meerwaarde: 17e-eeuws Nederlands naast Latijn 1559 is historisch, cultureel
en taalkundig waardevol. Statistische alignment (eflomal of fast_align) werkt
goed op dit parallelle paar.

```sql
-- Derde laag in dezelfde tabel:
INSERT INTO translation (segment_id, layer, text_nl, model)
VALUES (%s, 'corsmannus1650', %s, 'manual-transcription');
```

---

## 4. Datamodel — overzicht

```
work (id, slug, title, language, source)
  └── segment (id, work_id, book, chapter, section, ref, seq, heading, text_la, status)
        └── token (id, segment_id, position, surface, norm, lemma, upos, morph,
                   char_start, char_end, is_word)
              ├── [laag A] lemma_gloss (id, lemma, gloss_nl, gloss_alt, note, source, reviewed)
              └── [laag B] alignment (id, token_id, translation_id, target_start,
                                      target_end, target_text, confidence, source)
                              ↑
                  translation (id, segment_id, layer, text_nl, model, created_at)

Views:
  corpus_stats  → totalen per work (segmenten, woordtokens, unieke lemma's)
  lemma_stats   → frequentie per lemma, aantal segmenten
```

**Segment-status progressie:**
`ingested` → `tokenized` → `translated` → `aligned`

Elke fase-script is hervatbaar: het pikt segmenten op bij de laagste status.

---

## 5. Auteursrecht — status alle relevante teksten

| Tekst | Vertaler/editeur | Jaar | Status | Gebruik in pijplijn |
|-------|-----------------|------|--------|---------------------|
| Latijnse brontekst 1559 | Calvijn | 1559 | Publiek domein | ✅ Vrij voor alles |
| CCEL ThML-editie | CCEL (typografie) | ~2000 | CCEL eigen rechten; vrij voor persoonlijk/onderzoek gebruik | ✅ Bron; niet doorpubliceren |
| Corsmannus vertaling | W. Corsmannus | 1650 | Publiek domein | ✅ Vrij voor alles |
| Kuyper-editie Corsmannus | A. Kuyper | 1889 | Publiek domein | ✅ Vrij voor alles |
| Weijenberg vertaling | W.J. Weijenberg | 1865–68 | Publiek domein | ✅ Vrij voor alles |
| Sizoo vertaling | A. Sizoo (†1961) | 1931 | **Beschermd tot 1 jan 2032** | ⚠️ Alleen privé-validatie |
| De Niet vertaling | C.A. de Niet | 2009 | **Beschermd** | ❌ Niet gebruiken |
| Beveridge (Engels) | H. Beveridge (†1864) | 1845 | Publiek domein | ✅ Optioneel, als pivot |

**Praktische regel:** Gebruik Sizoo uitsluitend als handmatige validatiebron
(ogen op papier), nooit als trainingsdata of in de database. Voor een
publiek-domein NL-referentie: Corsmannus/Kuyper 1889.

---

## 6. Risico's en mitigaties

### R1 — ThML-structuurafwijkingen in de CCEL-bestanden ⚠️ HOOG

**Risico:** CCEL-ThML is niet gestandaardiseerd. Boek/hoofdstuk/sectie kunnen
in `div1..div6` zitten met wisselende `type`-attributen, Latijnse titels
("LIBER PRIMUS"), Romeinse cijfers of geen attribuut. Sectie-nummering kan
als genummerde `<p>` of als `<div3>` zijn uitgedrukt.

**Symptomen:** Parser rapporteert 0 segmenten, onjuist aantal boeken/hoofdstukken,
of waarschuwingen over dubbele/ontbrekende sectienummers.

**Mitigatie:**
1. Voer altijd `--inspect` uit vóór de productierun.
2. De parser bevat twee extractiepaden: sectie-`<div>` en genummerde `<p>`.
   Pas `classify_div()` aan als de `type`-attributen afwijken.
3. Controleer eindtotalen: verwacht 4 boeken, 80 hoofdstukken, ~1.100–1.200
   secties.
4. Fallback: als ThML niet werkt, is de platte tekst via
   `https://www.ccel.org/ccel/c/calvin/institutio1/cache/institutio1.txt`
   beschikbaar (minder structuur, maar bruikbaar als noodoplossing).

**Workaround bij hardnekkige structuurproblemen:**
```bash
# Platte tekst downloaden en handmatig op secties splitsen via regex:
# r'^\s*(\d{1,3})\.\s+' als sectie-opener
```

### R2 — LatinCy-kwaliteit op neolatijn ⚠️ MIDDEL

**Risico:** LatinCy is getraind op klassiek Latijn (Caesar, Cicero,
Vergilius). Calvijns 16e-eeuwse neolatijn heeft eigenaardigheden:
- Lange, Cicero-aanse hypotaxe die de POS-tagger kan verwarren.
- Theologisch vocabulaire zonder klassiek precedent (justificatio,
  praedestinatio, sacramentum in protestantse zin).
- Ablativus absolutus en participiumconstructies die moeilijk te lemmatiseren
  zijn.

**Gevolg:** Lemmafouten in `token.lemma` → verkeerde glossenkoppeling in
laag A.

**Mitigatie:**
1. Review de top-500 meest frequente lemma's handmatig vóór de LLM-batchrun.
2. Voor polyseme thelogische kernwoorden: handmatig de `lemma_gloss.note`
   invullen en `reviewed = TRUE` zetten.
3. Overweeg contextualiseringslaag: laat de LLM per token de gloss kiezen
   uit de gesuggereerde alternatieven (dit is fase 6, niet urgent voor MVP).

### R3 — LLM-alignment is onbetrouwbaar ⚠️ HOOG

**Risico:** Als je een LLM vraagt "koppel elk Latijns woord aan zijn
Nederlandse vertaling", verzint het paren die intern consistent lijken maar
feitelijk onjuist zijn. Dit is de reden waarom SimAlign als onafhankelijke
aligner wordt gebruikt.

**Mitigatie:**
- Vertrouw nooit LLM-alignment-claims. Gebruik de LLM alleen voor de
  vloeiende NL-vertaling; alignment altijd via SimAlign.
- Valideer: na elke batchrun, controleer of alle is_word-tokens in een
  segment minimaal één alignment-rij hebben. Ontbrekende alignments zijn
  beter dan gefabriceerde.

### R4 — SimAlign-kwaliteit bij vrije vertalingen ⚠️ MIDDEL

**Risico:** Bij zeer vrije vertalingen (parafrase, expliciete toevoeging of
omissie) vindt SimAlign geen betrouwbare koppeling. Calvijns lange zinnen
leiden in de NL-vertaling soms tot gesplitste zinnen → de positie van een
Latijns woord in de embedding-ruimte klopt dan niet met de NL-tegenhanger.

**Mitigatie:**
- Gebruik de "itermax"-methode van SimAlign in plaats van standaard "mai"
  voor hogere precision.
- Segmenteer bij het vertalen niet op segmentniveau maar op zinniveau als
  Calvijns zinnen extreem lang zijn (> 80 Latijnse tokens).
- Lage confidence → handmatige annotatie via de UI.

### R5 — CCEL-serverbelasting en toegankelijkheid ⚠️ LAAG

**Risico:** CCEL is een kleinschalige non-profit. Agressief scrapen belast
hun servers.

**Mitigatie:**
- `fetch_sources.py` cached lokaal: de 2 XML-bestanden worden maar één keer
  gedownload.
- Nooit HTML-pagina's per sectie scrapen — gebruik altijd de bulk-XML.
- Als alternatief: Internet Archive heeft de Corpus Reformatorum-editie
  gedigitaliseerd (`archive.org/details/ioanniscalvinii00cunigoog`).

### R6 — Hervatbaarheid bij lange runs ⚠️ MIDDEL

**Risico:** De tokenisatiefase (~500k tokens) en vertaalfase (1.100+ API-calls)
kunnen uren duren. Een crash of rate-limit gooit je terug naar het begin.

**Mitigatie:**
- Het `status`-veld op `segment` is de veiligheidsgordel: elk fase-script
  filtert op de vorige status en slaat voltooide segmenten over.
- LLM-vertaling: gebruik de Anthropic Batch API (async, niet realtime) en
  schrijf resultaten weg per segment direct na ontvangst, niet gebatcht.
- Maak een PostgreSQL-dump na elke fase:
  ```bash
  pg_dump institutio > backup_fase1.sql
  ```

### R7 — Groei van de `alignment`-tabel ⚠️ LAAG (maar weet het)

Bij ~500k woordtokens en gemiddeld 1 alignment per token = 500k rijen in
`alignment`. PostgreSQL handelt dat moeiteloos, maar zet `idx_alignment_translation`
wel vóór de bulk-insert aan (het schema doet dit al).

---

## 7. Technische stack en versies

| Component | Keuze | Versie (getest) |
|-----------|-------|-----------------|
| Python | — | 3.12.3 |
| PostgreSQL | — | 16 |
| psycopg | — | 3.3.4 |
| lxml | ThML-parsing | 6.0.2 |
| spaCy | NLP-framework | 3.8.14 |
| LatinCy | Latijns taalmodel | la_core_web_lg (aanbevolen) |
| SimAlign | Word alignment | pip install simalign |
| Anthropic API | LLM-vertaling + glossenlexicon | claude-sonnet-4-6 |
| Symfony | Annotatie-UI | 7 (bestaande stack) |
| FrankenPHP | Webserver | bestaande stack |
| Tailwind + Stimulus | Frontend | bestaande stack |

---

## 8. Bestandsstructuur van het project

```
institutio-pipeline/
├── README.md                  ← korte gebruiksinstructies
├── PROJECTDOSSIER.md          ← dit bestand
├── requirements.txt           ← Python-afhankelijkheden
│
├── sql/
│   └── schema.sql             ← volledig PostgreSQL-schema (idempotent)
│
├── scripts/
│   ├── fetch_sources.py       ← stap 1.2: CCEL-XML ophalen met caching
│   ├── parse_thml.py          ← stap 1.3+1.4: ThML → JSONL segmenten
│   ├── load_segments.py       ← stap 1.5: JSONL → PostgreSQL (idempotent)
│   └── tokenize_latin.py      ← stap 1.6: LatinCy tokenisatie + lemmatisatie
│
├── data/                      ← gegenereerd, staat niet in versiebeheer
│   ├── raw/
│   │   ├── institutio1.xml    ← CCEL ThML volume 1
│   │   └── institutio2.xml    ← CCEL ThML volume 2
│   └── segments.jsonl         ← parseresultaat
│
└── tests/
    └── fixture_thml.xml       ← synthetisch testbestand, dekt beide structuurvarianten
```

**Scripts die nog geschreven moeten worden (fase 2–5):**
```
scripts/
├── export_lemmas.py           ← fase 2.1: CSV-export unieke lemma's
├── batch_gloss.py             ← fase 2.2: LLM-batchrun glossenlexicon
├── load_glosses.py            ← fase 2.3: batchresultaten → lemma_gloss
├── translate_segments.py      ← fase 3: LLM-vertaling per segment
├── align_segments.py          ← fase 4: SimAlign → alignment-tabel
└── validate_alignment.py      ← fase 4.3: coverage-rapportage
```

---

## 9. Wat een volgende sessie als eerste moet doen

1. **Download de zip** (`institutio-pipeline.zip`) en pak uit.
2. **Richt de omgeving in** (§3, stap 1.1).
3. **Voer fase 1 uit** (stap 1.2 t/m 1.7).
4. **Draai `SELECT * FROM corpus_stats;`** en noteer de werkelijke aantallen.
5. **Vergelijk `Inst. 1.1.1`** in de database met de originele tekst —
   dit is de betrouwbaarste sanity check voor de parser.
6. **Bekijk de waarschuwingen** die de parser heeft gegeven bij stap 1.4.
   Zijn er meer dan ~20? Dan is er waarschijnlijk een structuurafwijking in
   de CCEL-bestanden die handmatige correctie in `classify_div()` vereist.

Daarna is fase 2 (lemma-lexicon) de logische volgende stap — het kortste pad
naar zichtbaar resultaat, omdat je na die batchrun al een volledig interlineair
(laag A) kunt genereren voor de gehele Institutio.

---

## 10. Nuttige externe bronnen

| Bron | URL | Gebruik |
|------|-----|---------|
| CCEL Institutio (ThML) | `ccel.org/ccel/c/calvin/institutio1.xml` | Brontekst vol. 1 |
| CCEL Institutio (ThML) | `ccel.org/ccel/c/calvin/institutio2.xml` | Brontekst vol. 2 |
| Calvin Reformation NL | `calvin.reformation.nl` | Parallelle weergave Latijn/Frans/NL/EN/DE, steekproefvalidatie |
| CCEL platte tekst vol. 1 | `.../institutio1/cache/institutio1.txt` | Noodplan als ThML-parsing faalt |
| Internet Archive (Corpus Reformatorum) | `archive.org/details/ioanniscalvinii00cunigoog` | Alternatieve Latijnse bron |
| LatinCy HuggingFace | `huggingface.co/latincy` | Taalmodellen |
| SimAlign GitHub | `github.com/cisnlp/simalign` | Woordalignment |
| Anthropic Batch API docs | `docs.anthropic.com` | Goedkopere LLM-batchruns |
| Digibron (Sizoo-artikel) | `digibron.nl` | Achtergrond Nederlandse vertalingen |

---

*Dossier opgesteld: juli 2026. Kernbeslissing om te bewaren: de architectuur
kiest voor een __onafhankelijke aligner (SimAlign) naast de LLM-vertaling__,
zodat alignment-kwaliteit verifieerbaar is en niet blind op LLM-claims berust.*
