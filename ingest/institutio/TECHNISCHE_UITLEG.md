> **Erratum (2026-07):** stap 1.2/1.3 hieronder beschrijven CCEL als bron.
> Die bleek geen bruikbare Latijnse tekst te bevatten (paginascans zonder
> OCR) — de pijplijn haalt de tekst nu op van calvin.reformation.nl. Zie de
> erratum bovenaan `PROJECTDOSSIER.md` en `README.md` voor details. De
> uitleg over LatinCy, de LLM en SimAlign (deel I en II) blijft ongewijzigd
> van toepassing.

# Technische uitleg: hoe de pijplijn werkt

## Over dit document

Dit document legt in begrijpelijke taal uit hoe de drie technologische
kernen van de pijplijn werken — LatinCy, SimAlign en de Large Language
Model (LLM) — en hoe ze in dit systeem samenwerken om Calvijns *Institutio*
woord voor woord van Latijn naar Nederlands te vertalen en te koppelen.

Aan het einde staat een beknopte samenvatting van elke processtap.

---

## Deel I — De drie technologieën

---

### 1. LatinCy: een computationeel taalmodel voor Latijn

#### Wat is het probleem dat LatinCy oplost?

Een computer begrijpt tekst van nature niet. Als je een programma de zin

> *"Tota fere sapientiae nostrae summa duabus partibus constat"*

geeft, ziet de computer een reeks letters met spaties ertussen. Hij weet
niet dat *sapientiae* de genitief enkelvoud is van *sapientia* (wijsheid),
niet dat *constat* een persoonsvorm is van het werkwoord *constare* (bestaan
uit), en al helemaal niet dat *duabus partibus* een ablativus is die "uit
twee delen" betekent.

Voor mensen is dit vanzelfsprekend: we hebben taal geleerd. Een computer
moet die kennis ook ergens vandaan halen.

LatinCy is een software-bibliotheek die die talige kennis voor het Latijn
bevat. Het is gebouwd bovenop het populaire Python-pakket **spaCy** — een
algemeen gereedschapskist voor taalverwerking — maar dan specifiek gericht
op Latijn.

#### Hoe is LatinCy gemaakt? (training)

LatinCy is getraind op grote hoeveelheden Latijnse tekst die al handmatig
door klassieke filologen van annotaties is voorzien. Zulke datasets heten
**treebanks**: verzamelingen van zinnen waarbij elk woord al een label heeft
gekregen — "dit is een substantief, genitief enkelvoud vrouwelijk" — en
waarbij ook de grammaticale relaties tussen woorden zijn ingetekend ("dit
substantief is het onderwerp van dat werkwoord").

Bekende Latijnse treebanks zijn onder andere:
- **Perseus Latin Dependency Treebank** (klassieke teksten: Cicero, Caesar,
  Vergilius)
- **PROIEL Treebank** (Nieuwtestamentisch Latijn en andere teksten)
- **LASLA** (Latijnse analyses van de Universiteit van Luik)

Het model heeft tijdens training geleerd: "als een woord op *-ae* eindigt,
een bepaalde positie in de zin heeft, en naast bepaalde andere woordsoorten
staat, dan is de kans groot dat het een genitief enkelvoud of nominatief
meervoud is." Die kansen zijn opgeslagen in honderdduizenden getallen —
de **gewichten** van het model.

#### Wat doet LatinCy precies met een tekst?

LatinCy voert een reeks bewerkingen uit op de invoertekst, achtereenvolgens:

**Stap A — Tokenisatie**
De tekst wordt gesplitst in afzonderlijke eenheden: woorden, leestekens,
afkortingen. Dit klinkt triviaal maar is dat niet: *"non-solum"* moeten
worden gesplitst in *"non"* en *"solum"*; een punt na een afkorting is
geen zineinde.

Resultaat in ons systeem: elke token krijgt een rij in de `token`-tabel
met zijn positie in het segment, zijn beginteken (`char_start`) en zijn
eindteken (`char_end`). Zo weet het systeem altijd exact waar elk woord
in de originele tekst staat.

**Stap B — Normalisatie**
De tokens worden genormaliseerd. In dit systeem wordt daarbij ook de
klassieke spelling toegepast: de letter *v* wordt omgezet naar *u*
(want in de edities die Calvijn gebruikte werden deze door elkaar gebruikt:
*vita* en *uita* zijn hetzelfde woord) en *j* wordt *i*. Dit zorgt ervoor
dat *viuit* en *uiuit* herkend worden als dezelfde woordvorm.

Dit staat opgeslagen in het veld `token.norm`.

**Stap C — Part-of-speech tagging (POS-tagging)**
Elk woord krijgt een woordsoortlabel: zelfstandig naamwoord (NOUN),
werkwoord (VERB), bijvoeglijk naamwoord (ADJ), voornaamwoord (PRON),
voegwoord (CCONJ, SCONJ), voorzetsels (ADP), enzovoort.

In het systeem opgeslagen als `token.upos` (Universal POS: een
gestandaardiseerd internationaal labelset dat voor alle talen hetzelfde is).

**Stap D — Morfologische analyse**
Dit is de meest waardevolle stap voor Latijn. Latijn is een sterk
inflectionerende taal: de uitgang van een woord vertelt je de grammaticale
functie. LatinCy probeert die uitgang te ontleden en geeft labels zoals:

```
Case=Nom|Number=Sing|Gender=Masc
```

Dit betekent: "nominatief, enkelvoud, mannelijk". Voor werkwoorden:

```
Mood=Ind|Number=Sing|Person=3|Tense=Pres|VerbForm=Fin|Voice=Act
```

Dit betekent: "aantonende wijs, enkelvoud, derde persoon, tegenwoordige
tijd, persoonsvorm, actief". Dit is precies wat een grammaticaboek ook
zou zeggen, maar dan geautomatiseerd en voor elk woord in het corpus.

In het systeem opgeslagen als `token.morph`.

**Stap E — Lemmatisatie**
Dit is de kern van het systeem voor laag A (de interlineaire laag).
Het model herleid elk woordtoken naar zijn **lemma**: de woordenboek­vorm.
*"sapientiae"*, *"sapientiam"* en *"sapientiarum"* worden alle drie
teruggebracht tot het lemma *"sapientia"*. *"constat"*, *"constabat"*
en *"constitit"* worden alle drie *"constare"*.

Waarom is dit zo belangrijk? Omdat er voor elk uniek lemma maar één keer
een Nederlandse vertaling hoeft te worden opgeslagen. In de `lemma_gloss`-tabel
staat: *sapientia → wijsheid*. Elke token met lemma *sapientia*, waar ook
in de Institutio, krijgt automatisch die gloss. Dat spaart enorm veel werk.

In het systeem opgeslagen als `token.lemma`.

#### Wat zijn de grenzen van LatinCy voor dit project?

LatinCy is getraind op **klassiek Latijn** (Cicero, Vergilius, Caesar,
geschreven rond 50 v.Chr. – 100 n.Chr.). Calvijn schreef in **neolatijn**:
de schrijftaal van geleerde humanisten in de 16e eeuw. Dat is niet hetzelfde
Latijn.

Calvijns stijl is bewust Ciceroniaans: hij imiteerde de klassieke periodenstructuur.
Maar zijn vocabulaire bevat begrippen die Cicero niet kende:

- *justificatio* (rechtvaardiging) in de protestantse theologische zin
- *praedestinatio* (predestinatie) als heilsleerterm
- *sacramentum* in protestantse betekenis (heel anders dan in klassiek Latijn)
- *regeneratio* (wedergeboorte) als theologisch begrip

Voor zulke woorden kan LatinCy foute lemma's produceren, of het lemma niet
herkennen. Ook zijn syntactische structuren soms zo complex (Calvijn bouwt
zinnen van soms 80+ woorden) dat de POS-tagger de kluts kwijtraakt.

**Praktische gevolg:** Het systeem zet het veld `token.lemma` op `NULL`
als LatinCy geen lemma kan bepalen. Die tokens krijgen geen automatische
gloss en zijn kandidaten voor handmatige correctie.

---

### 2. De Large Language Model (LLM): vertalen en glosseren

#### Wat is een Large Language Model?

Een Large Language Model (LLM) — zoals Claude, GPT-4 of Gemini — is een
computersysteem dat tekst begrijpt en genereert. Het is getraind op een
enorme hoeveelheid tekst: boeken, websites, wetenschappelijke artikelen,
vertaalde teksten in tientallen talen. In die training heeft het model
ontelbaar veel patrenen geleerd over hoe taal werkt, hoe begrippen zich
verhouden tot andere begrippen, en hoe dezelfde gedachte in verschillende
talen wordt uitgedrukt.

Een LLM werkt anders dan een klassiek computerprogramma. Een klassiek
programma volgt regels die een programmeur heeft opgeschreven. Een LLM
heeft zijn "regels" zelf geleerd uit de data — en die regels zijn niet
expliciet: ze zitten opgesloten in miljarden getallen (parameters) die
tijdens training zijn afgestemd.

#### Wat maakt een LLM geschikt voor neolatijn?

Klassieke vertaalsoftware (zoals Google Translate of het oudere DeepL) werkt
goed voor talen met veel parallelcorpora: teksten die in beide talen
beschikbaar zijn, zodat de software patronen kan leren. Voor Latijn →
Nederlands is dat corpus dun.

Een LLM daarentegen heeft zoveel achtergrondkennis over het Latijn (uit
grammaticaboeken, woordenboeken, filologische studies, Engelstalige
vertalingen van Calvijn, Latiniste commentaren) dat het ook goed presteert
op neolatijn zonder dat er een apart Latijns-Nederlands trainingskorpus
nodig is.

Bovendien snapt een LLM **context**. Bij het woord *gratia* in een Calvijn-tekst
weet het model dat het waarschijnlijk "genade" betekent (in protestantse
heilsleersin), niet "dankbaarheid" of "gunst" — want het herkent de
theologische context van de zin.

#### Twee rollen van de LLM in dit systeem

De LLM wordt in dit project op twee manieren ingezet:

**Rol 1 — Het glossenlexicon bouwen (eenmalig, laag A)**

Na de tokenisatie-fase heeft het systeem een lijst van alle unieke lemma's
in de Institutio, gesorteerd op frequentie. Die lijst bevat wellicht 17.000
unieke woorden, maar het overgrote deel daarvan (voorzetsels, voegwoorden,
voornaamwoorden, veelgebruikte werkwoorden) is vanzelfsprekend.

De LLM krijgt per lemma een vraag in de trant van:

> "Wat is de Nederlandse hoofdbetekenis van het Latijnse lemma *'sapientia'*,
> zoals Calvijn het gebruikt in de *Institutio* (1559)? Geef ook eventuele
> alternatieve betekenissen en een toelichting als het een technisch
> theologisch begrip is. Antwoord alleen als JSON."

De LLM antwoordt met een gestructureerd antwoord dat in de `lemma_gloss`-tabel
wordt opgeslagen. Dit hoeft maar **éénmalig** te gebeuren voor het hele
corpus — daarna kan elke token van een gloss worden voorzien door simpelweg
de tabel op te zoeken.

**Rol 2 — Vloeiende Nederlandse vertaling (per segment, laag B)**

Per sectie van de Institutio (bijv. *Inst. 1.1.1*) krijgt de LLM de
volledige Latijnse tekst, met de instructie die tekst te vertalen naar
helder, modern Nederlands. De LLM produceert een vloeiende vertaling die
leesbaar is als zelfstandige Nederlandse tekst.

De LLM weet daarbij:
- dat Calvijn soms zinnen bouwt van 80 woorden die in het Nederlands gesplitst
  moeten worden
- dat theologische kernbegrippen consistent moeten worden weergegeven
- dat Schriftcitaten in de Statenvertaling-versie kunnen worden opgenomen

#### Wat kan een LLM *niet* goed?

**Woordalignment.** Als je een LLM vraagt: "vertaal dit en vertel me daarna
welk Latijns woord bij welk Nederlands woord hoort", geeft het een antwoord
dat intern logisch klinkt maar feitelijk onbetrouwbaar is. Het model
"bedenkt" de koppeling achteraf, als een redenering — maar die redenering
is niet gebaseerd op hoe het de vertaling daadwerkelijk heeft geproduceerd.
Het model heeft geen intern mechanisme dat bijhoudt "dit Nederlandse woord
heb ik gegenereerd als vertaling van dat Latijnse woord."

Dit is de reden dat SimAlign wordt ingezet als onafhankelijke aligner.

**Consistentie.** Een LLM is niet deterministisch: dezelfde tekst twee keer
laten vertalen geeft twee licht verschillende vertalingen. Dat is geen
probleem voor de kwaliteit, maar wel voor de reproduceerbaarheid. Vandaar dat
vertaalresultaten worden opgeslagen in de database zodra ze zijn gegenereerd,
zodat de pijplijn consistent is bij herstart.

---

### 3. SimAlign: woorden over talen heen koppelen

#### Het alignmentprobleem

Als je een Latijnse zin en zijn Nederlandse vertaling naast elkaar legt,
wil je weten: welk Nederlands woord is de vertaling van welk Latijns woord?

> Latijn: *Tota fere sapientiae nostrae summa duabus partibus constat*
>
> Nederlands: *Bijna de gehele som van onze wijsheid bestaat uit twee delen*

De koppeling:
- *Tota* → "gehele"
- *fere* → "Bijna"
- *sapientiae* → "wijsheid"
- *nostrae* → "onze"
- *summa* → "som"
- *duabus partibus* → "twee delen" (één Latijns woord koppelt aan twee Nederlandse)
- *constat* → "bestaat"

Dit lijkt eenvoudig, maar in de praktijk zijn er complicaties:
- Latijn heeft een vrije woordvolgorde; de volgorde in de NL-vertaling
  wijkt af.
- Soms vertaalt één Latijns woord naar meerdere Nederlandse woorden, of
  omgekeerd.
- Sommige Latijnse woorden (als voegwoord of lidwoord-equivalent) hebben geen
  directe tegenhanger in het Nederlands.
- Bij vrije vertaling voegt de vertaler woorden toe die in het Latijn impliciet
  zijn.

#### Hoe werkt SimAlign?

SimAlign maakt gebruik van **meertalige embeddings**. Dit concept vereist een
korte uitleg.

**Embeddings** zijn een manier om woorden als getallen voor te stellen.
Stel je voor dat elk woord een punt is in een enorme ruimte met honderden
dimensies (vergelijkbaar met een 3D-ruimte, maar dan met 768 of meer
dimensies). Woorden die van betekenis op elkaar lijken, liggen in die ruimte
dicht bij elkaar. *"Koning"* ligt dicht bij *"koningin"* en *"vorst"*.
*"Hond"* ligt dicht bij *"kat"*.

**Meertalige embeddings** gaan een stap verder: ze plaatsen woorden uit
*verschillende talen* in dezelfde ruimte, op basis van hun betekenis. Het
Latijnse *"sapientia"* en het Nederlandse *"wijsheid"* liggen dan dicht bij
elkaar in die ruimte — ook al hebben ze nooit samen in een trainingszin
gestaan.

SimAlign gebruikt het model **mBERT** (multilingual BERT) van Google, dat
getraind is op teksten in 104 talen tegelijkertijd. mBERT heeft geleerd dat
woorden in verschillende talen dezelfde positie in de embeddingruimte
kunnen innemen als ze dezelfde betekenis hebben.

**Wat SimAlign dan doet:**

1. Het verdeelt de Latijnse tekst in tokens (woorden).
2. Het verdeelt de Nederlandse vertaling in tokens.
3. Het berekent voor elk Latijns token zijn positie in de embeddingruimte,
   rekening houdend met de context (het omliggende woorden — want *gratia*
   in een zin over verlossing heeft een iets andere positie dan *gratia* in
   een brief die met dankbetuigingen begint).
4. Het doet hetzelfde voor elk Nederlands token.
5. Het berekent de **cosinus-gelijkheid** tussen elke Latijnse en elke
   Nederlandse tokenembedding: een getal tussen 0 (helemaal niet verwant) en
   1 (identiek). Dit levert een matrix op: voor elk koppel (Latijns woord,
   Nederlands woord) een gelijkenisscore.
6. Het zoekt in die matrix de beste koppeling: welke toewijzing van Latijnse
   aan Nederlandse woorden geeft de hoogste totale gelijkenis?

Het resultaat zijn **alignmentparen**: (Latijns token X, NL-span Y), met
bijbehorende gelijkenisscores die als betrouwbaarheidsmaat dienen.

#### Waarom is SimAlign betrouwbaarder dan de LLM voor alignment?

SimAlign handelt op basis van een vergelijking die je kunt controleren: de
afstand tussen embeddings. Die berekening is deterministisch en transparant.
Als SimAlign zegt dat *"sapientiae"* koppelt aan *"wijsheid"* met een score
van 0.87, dan is dat een concrete, herhaalbare meting.

De LLM produceert alignment als een *bewering* ("dit woord is de vertaling
van dat woord") zonder dat je kunt nagaan hoe die bewering tot stand is
gekomen. SimAlign meet; de LLM beweert.

In dit systeem zijn ze complementair: de LLM levert de beste vertaling,
SimAlign levert de betrouwbaarste alignment van die vertaling.

#### Grenzen van SimAlign

SimAlign werkt beter naarmate de vertaling letterlijker is. Vrije
vertalingen — waarbij Calvijns lange periodezinnen worden gesplitst, woorden
worden toegevoegd voor leesbaarheid, of concepten worden omschreven —
leveren lagere alignmentscores op. Dat is juist, want dan is de koppeling
ook minder eenduidig.

Lage scores zijn een signaal, geen fout. Ze markeren precies die plekken
die filologisch interessant zijn — waar de vertaling afwijkt van de bron —
en die het meeste baat hebben bij handmatige verificatie.

---

## Deel II — Hoe de drie technologieën samenwerken

De pijplijn heeft twee parallelle uitvoerlagen die allebei vertrekken
vanuit hetzelfde fundament: de `token`-tabel.

```
Latijnse tekst (ThML-XML)
       │
       ▼
   [PARSER]
   Structuur ontleden (boek, hoofdstuk, sectie)
       │
       ▼
   [PostgreSQL: segment-tabel]
       │
       ├──────────────────────────────────────────┐
       │                                          │
       ▼                                          ▼
  [LatinCy]                                 [LatinCy]
  Tokenisatie + lemmatisatie             (zelfde run)
       │
       ▼
  [PostgreSQL: token-tabel]
       │
       ├─────────────────────────────┐
       │                             │
       ▼                             ▼
 [LLM: batchrun lemma's]      [LLM: vertaling per segment]
 lemma_gloss vullen            translation-tabel vullen
       │                             │
       ▼                             ▼
  LAAG A klaar:            [SimAlign: alignment berekenen]
  interlineaire gloss            │
  per token                      ▼
                        [PostgreSQL: alignment-tabel]
                               │
                               ▼
                          LAAG B klaar:
                          vloeiende NL-vertaling
                          + woordkoppeling per token
```

Het cruciale punt: beide lagen delen dezelfde `token`-rijen. Dat betekent
dat de eindgebruiker op elk Latijns woord klikt en tegelijkertijd ziet:
- de woordsoort en morfologie (uit LatinCy)
- de interlineaire gloss (uit LLM via lemma_gloss)
- het corresponderende woord of woordgroep in de vloeiende vertaling
  (uit LLM + SimAlign)

---

## Deel III — Processtap voor processtap

Hieronder volgt een beknopte samenvatting van elke stap, bedoeld als
naslagwerk voor iemand die de pijplijn oppakt.

---

### Fase 1 — Ingest en tokenisatie

---

#### Stap 1.1 — Omgeving inrichten

**Wat er gebeurt:** Python-afhankelijkheden installeren, het LatinCy-taalmodel
downloaden, een PostgreSQL-database aanmaken en het schema laden.

**Waarom:** Alle volgende stappen draaien in Python en slaan resultaten op in
PostgreSQL. Het schema definieert de tabellen (`segment`, `token`,
`lemma_gloss`, `translation`, `alignment`) en hun onderlinge relaties.

**Resultaat:** Een lege database met het juiste schema, klaar om gevuld te
worden.

**Risico:** Het LatinCy-model (`la_core_web_lg`) is ~560 MB en vereist een
pip-installatie via een directe URL naar HuggingFace. Als die URL verandert,
gebruik dan het kleinere `la_core_web_sm` als tijdelijk alternatief.

---

#### Stap 1.2 — Bronbestanden ophalen

**Wat er gebeurt:** De twee ThML-XML-bestanden van CCEL worden gedownload
en lokaal opgeslagen in `data/raw/`. Als de bestanden al aanwezig zijn,
wordt de download overgeslagen.

**Waarom:** CCEL biedt de volledige Latijnse 1559-tekst (Barth-Niesel-editie)
in machineleesbare XML-opmaak. Dit zijn de twee volumes samen het complete
corpus.

**Resultaat:** `data/raw/institutio1.xml` en `data/raw/institutio2.xml`,
de Latijnse brontekst.

**Risico:** Als CCEL de URL's wijzigt of de server niet beschikbaar is,
zijn de platte tekst-versies (`.../institutio1/cache/institutio1.txt`)
een alternatief, maar die missen de structuurinformatie.

---

#### Stap 1.3 — Structuur inspecteren

**Wat er gebeurt:** Het `--inspect`-commando toont welke XML-elementen in de
bestanden aanwezig zijn en hoe de parser ze classificeert (boek, hoofdstuk,
sectie of onbekend).

**Waarom:** De ThML-opmaak van CCEL is niet gestandaardiseerd. De verdeling
in boeken, hoofdstukken en secties kan zijn uitgedrukt via `type`-attributen,
Latijnse titels, Romeinse cijfers of genummerde paragrafen. De parser probeert
dit automatisch te detecteren, maar verificatie vóór de productierun is
verplicht.

**Resultaat:** Een overzicht van de div-hiërarchie op de terminal. Verwacht:
2 boeken per volume, in totaal 4 boeken en 80 hoofdstukken.

**Risico:** Als de structuurdetectie faalt (bijv. alles wordt als "other"
geclassificeerd), moet de `classify_div()`-functie in `parse_thml.py` worden
aangepast op basis van wat `--inspect` toont.

---

#### Stap 1.4 — Parseren naar segmenten (JSONL)

**Wat er gebeurt:** De parser doorloopt de twee XML-bestanden, herkent de
structuur (boek, hoofdstuk, sectie), haalt de tekst van elke sectie op —
zonder voetnoten, Schriftreferenties en paginanummers in de lopende tekst —
en schrijft elke sectie als één JSON-regel naar `data/segments.jsonl`. Elke
sectie krijgt een canonieke referentie (`Inst. 1.1.1`), zijn volgorde in
het werk en de hoofdstuktitel als context.

**Waarom:** De segmenten zijn de werkeenheden voor alle volgende stappen.
Door de structuur al hier te verankeren in de referentie `Inst. boek.hfdst.sec`
wordt het systeem direct gekoppeld aan de wetenschappelijke citeerpraktijk.

**Resultaat:** `data/segments.jsonl`, één JSON-regel per sectie, met velden
`ref`, `book`, `chapter`, `section`, `heading`, `text`, `seq`.

**Te controleren:** Verwacht ~1.100–1.200 segmenten. Waarschuwingen over
niet-opeenvolgende sectienummers kunnen wijzen op structuurproblemen of op
normale CCEL-annotatie-afwijkingen.

---

#### Stap 1.5 — Segmenten laden in PostgreSQL

**Wat er gebeurt:** De `segments.jsonl` wordt ingelezen en in de
`segment`-tabel geschreven. Als een segment al bestaat (zelfde `work_id` +
`ref`), wordt het bijgewerkt, niet gedupliceerd. De tabel `work` krijgt ook
een rij voor de Institutio als geheel.

**Waarom:** PostgreSQL is het centrale opslagsysteem voor de hele pijplijn.
Alle vervolgstappen lezen segmenten uit de database en schrijven resultaten
terug, zodat de voortgang altijd is bij te houden.

**Resultaat:** De `segment`-tabel is gevuld. Alle segmenten staan op status
`ingested`.

**Idempotent:** Veilig om meerdere keren te draaien zonder duplicaten.

---

#### Stap 1.6 — Tokeniseren en lemmatiseren

**Wat er gebeurt:** Elk segment met status `ingested` wordt door LatinCy
verwerkt. LatinCy splitst de tekst in tokens, bepaalt voor elk token de
woordsoort, de morfologie en het lemma. De resultaten worden per token
opgeslagen in de `token`-tabel. Elk token heeft zijn exacte positie in de
tekst (tekenoffsets) zodat later de koppeling naar de NL-vertaling precies
klopt. Na verwerking wordt het segment op status `tokenized` gezet.

**Waarom:** De `token`-tabel is het fundament van beide lagen. Zonder
tokenisatie zijn er geen ankerpunten om glosses of alignments aan te hangen.
De lemmatisatie maakt het mogelijk om hetzelfde lemma (bijv. *sapientia*)
maar één keer te vertalen, ongeacht in welke naamvalsvorm het woord
voorkomt.

**Resultaat:** De `token`-tabel bevat alle woordtokens van het volledige
corpus (~500.000 verwacht). Na voltooiing toont het script `corpus_stats`:
het aantal segmenten, woordtokens en unieke lemma's. Die aantallen bepalen
de planning en kosten van de volgende fasen.

**Hervatbaar:** Onderbreken en herstarten is altijd veilig; verwerkte
segmenten worden overgeslagen.

---

#### Stap 1.7 — Steekproefcontrole

**Wat er gebeurt:** Een selectie van bekende passages worden in de database
opgezocht en vergeleken met de gedrukte editie of een betrouwbare online
bron (bijv. calvin.reformation.nl).

**Waarom:** Eventuele parsefouten of normalisatiefouten worden hier zichtbaar
voordat de dure LLM-fasen beginnen. Een fout in de tokenisatie die nu wordt
ontdekt, kost een kwartiertje; dezelfde fout na de vertaalfase betekent
hertaling van duizenden secties.

**Te controleren:**
- Tekst van `Inst. 1.1.1` begint met *"Tota fere sapientiae..."*
- Voetnoten zijn nergens in de tekstvelden terecht gekomen
- Lemma's van bekende woorden zijn correct (*sapientia*, *Deus*, *fides*)

---

### Fase 2 — Lemma-glossenlexicon (Laag A, stap 1)

---

#### Stap 2.1 — Lemmalijst exporteren

**Wat er gebeurt:** Via de `lemma_stats`-view worden alle unieke lemma's uit
de database geëxporteerd naar een CSV-bestand, gesorteerd op frequentie.

**Waarom:** Lemma's die 1.000 keer voorkomen (zoals *esse*, *Deus*, *homo*)
zijn van groter belang dan lemma's die éénmalig voorkomen. Sortering op
frequentie maakt het mogelijk om de handmatige review van theologische
kernbegrippen te prioriteren vóórdat de LLM-batchrun begint.

**Resultaat:** Een CSV met kolommen `lemma`, `freq`, `n_segments`.

---

#### Stap 2.2 — LLM-batchrun voor het glossenlexicon

**Wat er gebeurt:** Per uniek lemma wordt een vraag naar de LLM gestuurd
(via de Anthropic Batch API, die goedkoper en asynchroon werkt dan
realtime-calls). De LLM geeft per lemma de Nederlandse hoofdbetekenis,
eventuele alternatieve betekenissen en een toelichting voor theologische
vakbegrippen.

**Waarom:** Dit is de meest efficiënte manier om het lexicon te vullen:
één LLM-call per lemma, niet per token. De ~17.500 unieke lemma's vertalen
is daarmee een eenmalige investering van naar schatting €5–10, waarna elk
van de ~500.000 tokens een gloss kan krijgen via een simpele database-lookup.

**Resultaat:** Een bestand met JSON-antwoorden per lemma, klaar om te laden.

**Aandachtspunt:** De top-100 meest frequente theologische kernlemma's
(*gratia*, *fides*, *electio*, *justificatio*, *praedestinatio* e.d.) bij
voorkeur handmatig reviewen vóór de batchrun, want die zijn polyseem en de
meest zichtbare woorden in het eindproduct.

---

#### Stap 2.3 — Glosses laden in de database

**Wat er gebeurt:** De batchresultaten worden ingelezen en per lemma
opgeslagen in de `lemma_gloss`-tabel. Bij reeds-bestaande lemma's worden
de velden bijgewerkt.

**Resultaat:** De `lemma_gloss`-tabel is gevuld. Laag A — de interlineaire
vertaling — is nu technisch gereed: elk token met een bekend lemma heeft
een gloss.

---

### Fase 3 — Vloeiende vertaling (Laag B, stap 1)

---

#### Stap 3.1 — Vertaalpijplijn per segment

**Wat er gebeurt:** Elk segment met status `tokenized` wordt naar de LLM
gestuurd met een vertaalprompt. De prompt bevat de Latijnse tekst, de
canonieke referentie, de hoofdstuktitel (als context) en instructies over
het bewaren van theologische terminologie en Statenvertaling-citaten. De
NL-vertaling wordt opgeslagen in de `translation`-tabel met layer `'llm'`.

**Waarom:** De vloeiende vertaling is de input voor SimAlign (fase 4) en het
eindproduct van laag B. De vertaling per sectie (niet per zin) geeft de LLM
voldoende context om Calvijns perioden goed te vertalen.

**Resultaat:** De `translation`-tabel bevat een NL-vertaling per segment.

**Hervatbaar:** Segmenten met een reeds bestaande vertaling worden overgeslagen.
Rate-limit-fouten worden afgevangen en de pijplijn start automatisch verder
bij herstart.

---

### Fase 4 — Woordalignment (Laag B, stap 2)

---

#### Stap 4.1 — SimAlign-alignment per segment

**Wat er gebeurt:** Per segment worden de Latijnse tokens (uit de
`token`-tabel) en de Nederlandse vertaling (uit de `translation`-tabel) naast
elkaar gelegd. SimAlign berekent voor elk Latijns token welk Nederlands woord
of welke woordgroep er het nauwst mee verwant is. De koppeling wordt
opgeslagen in de `alignment`-tabel, met character-offsets in de NL-tekst
zodat de koppeling precies is.

**Waarom:** Zonder alignment weet je wel dat *sapientia* "wijsheid" betekent
(laag A), maar niet *waar* in de NL-zin het corresponderende woord staat.
Alignment maakt het mogelijk om in de eindtoepassing een Latijns woord aan
te klikken en de bijbehorende plek in de vloeiende vertaling te markeren.

**Resultaat:** De `alignment`-tabel is gevuld. Laag B is compleet.

---

#### Stap 4.2 — Validatie van de alignment

**Wat er gebeurt:** Via SQL-queries wordt gecheckt welk percentage van de
woordtokens een alignment heeft, en of er systematische gaten zijn in
bepaalde boeken of hoofdstukken.

**Waarom:** SimAlign mist soms woorden bij vrije vertalingen of zeer lange
zinnen. Weten waar de gaten zitten, bepaalt welke segmenten in fase 5 voor
handmatige correctie worden aangeboden.

**Resultaat:** Een coverage-rapport per boek, en een lijst van tokens zonder
alignment die prioriteit krijgen in de annotatie-UI.

---

### Fase 5 — Handmatige correctie via annotatie-UI

---

#### Stap 5.1 — Annotatie-UI bouwen (Symfony)

**Wat er gebeurt:** Een webinterface in Symfony toont per segment de Latijnse
tekst boven en de NL-vertaling eronder. Tokens zonder alignment of met lage
confidence zijn gemarkeerd. De gebruiker klikt een Latijns woord aan en
selecteert de corresponderende NL-span. De correctie wordt opgeslagen met
`source = 'manual'`.

**Waarom:** Volledige automatische alignment is niet haalbaar voor een hele
tekst — er zullen altijd gevallen zijn waarbij de vrije vertaling of
syntactische complexiteit de aligner in de war brengt. Handmatige correctie
concentreert het menselijk oordeel juist op die gevallen.

**Resultaat:** Stapsgewijs verbeterde alignment, prioriteit op hoog-frequente
segmenten en op theologisch kernvocabulaire.

---

### Fase 6 (optioneel) — Corsmannus 1650 als derde laag

---

#### Stap 6.1 — Historische vertaling als extra laag

**Wat er gebeurt:** De publiek-domein vertaling van Corsmannus (1650),
herdrukt door Kuyper (1889), wordt gedigitaliseerd of via Google Books/Archive.org
verkregen. Na OCR en zin-alignment wordt de tekst als extra laag
(`layer = 'corsmannus1650'`) in de `translation`-tabel geladen. Statistische
alignment (eflomal of fast_align) koppelt de tokens aan de historische NL-tekst.

**Waarom:** 17e-eeuws Nederlands naast 16e-eeuws Latijn biedt unieke
historische en taalkundige diepte — dezelfde woorden uit de Statenvertaling-
periode. Dit is de meest waardevolle uitbreiding na het basissysteem, maar
vereist extra werk (OCR, zin-alignment) en past dus beter als latere fase.

**Resultaat:** Drie parallelle lagen per segment: interlineair, modern
LLM-Nederlands en 17e-eeuws Nederlands — alle drie per woord gealigneerd
op hetzelfde Latijnse fundament.

---

## Slotwoord: de samenhang in één zin per technologie

**LatinCy** leest het Latijn als een filoloog en geeft elk woord een
grammaticale identiteitskaart — woordsoort, naamval, lemma — zodat het
systeem weet wat elk woord *is*.

**De LLM** begrijpt de betekenis van de tekst in zijn theologische en
historische context en vertaalt die betekenis naar vloeiend Nederlands —
zodat het systeem weet wat de tekst *zegt*.

**SimAlign** legt de Latijnse en Nederlandse tekst naast elkaar in een
gedeelde betekenisruimte en meet welke woorden het dichtste bij elkaar
liggen — zodat het systeem weet wat de tekst zegt *en waar het dat zegt*.
