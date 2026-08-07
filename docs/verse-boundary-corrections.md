# Vers-grensmismatches tussen grondtekst en vertaling

## Probleemstelling

`word_links` / `align_heuristic.py` koppelen bronwoorden (Hebreeuws/Grieks) en
Nederlandse woorden uitsluitend binnen exact dezelfde `(boek, hoofdstuk, vers)`-
combinatie aan beide kanten. Als de vers-grens bij de Griekse/Hebreeuwse tekst
op een andere plek ligt dan bij de Nederlandse vertaling, koppelt de
alignment stilzwijgend fout — of helemaal niet — voor elk woord aan weerszijden
van die naad. Er is geen enkele bestaande check die dat opmerkt.

**Concreet voorbeeld: Galaten 5:22-23** (de vrucht van de Geest). De Elzevir-
brontekst tagt πραοτης en εγκρατεια ("zachtmoedigheid", "matigheid"/
"zelfbeheersing") als de eerste twee woorden van vers 23. Zowel de SV
(Zefania-XML) als de HSV (herzienestatenvertaling.nl) — twee onafhankelijk van
elkaar gedigitaliseerde bronnen — rekenen diezelfde twee woorden juist tot het
*einde* van vers 22.

---

## Detectie

### Wat niet werkt: woordtelling

De voor de hand liggende aanpak — de verhouding NL-woorden/Grieks-woorden per
vers vergelijken met het hoofdstukgemiddelde, eventueel met een lokaal window
en cross-translation-corroboratie — is geprobeerd en verworpen. Resultaat: 247
treffers over het hele NT, vrijwel allemaal ruis (normale variatie in
vertaalvolheid, bijvoorbeeld een lang citaat, overstemt een verschuiving van
1-3 woorden volledig), en het bekende Galaten-geval viel er zelf niet eens uit.
Woordtelling is domweg het verkeerde signaal voor dit probleem.

### Wat wel werkt: een gerichte structurele zoekopdracht

[`ingest/find_verse_boundary_candidates.py`](../ingest/find_verse_boundary_candidates.py)
zoekt specifiek naar de *vorm* van deze fout: een vers dat eindigt op een kaal
opsommingsitem (`", woord."`), direct gevolgd door een Grieks vers dat kort is
(standaard ≤ 10 woorden). Die combinatie is zeldzaam — 6 treffers over het
hele NT voor SV, 6 voor HSV — en bevatte het Galaten-geval meteen,
terwijl gewone zinnen die toevallig op een komma-clausule eindigen er niet in
voorkomen.

```bash
python find_verse_boundary_candidates.py                 # SV + HSV
python find_verse_boundary_candidates.py --translation SV
python find_verse_boundary_candidates.py --next-max-words 12
```

Het script is een **kandidatenzoeker, geen oordeel**. Elke treffer moet nog
handmatig (of door een LLM met echte Grieks/Nederlandse taalkennis)
gecontroleerd worden: vertalen de laatste woorden van het gevlagde vers
aannemelijk de eerste Griekse woorden van het volgende vers? Het script drukt
daarom ook de Strong's/KJV-glossen van die openingswoorden af als
controlehulp.

**Cross-translation corroboratie is het sterkste signaal.** Als zowel SV als
HSV — onafhankelijk gedigitaliseerd/geschraapt, via compleet verschillende
bronnen — dezelfde afwijkende grens tonen, is dat sterk bewijs dat het een
echte, langdurige Nederlandse verstraditie is en geen toevallige
transcriptiefout in één bron. Het script markeert dit expliciet (`★
corroborated by multiple translations`).

---

## Welke kant wordt gecorrigeerd?

Dit is een bewuste keuze, geen toeval. Twee opties zijn mogelijk:

1. De vertaling aanpassen aan de grondtekst (`translation_verses`/
   `translation_words` verschuiven).
2. De grondtekst-tagging aanpassen aan de vertaling (`greek_words`/
   `hebrew_words` verschuiven).

Voor het Galaten-geval is gekozen voor **optie 2**: de Elzevir-tagging komt
van een moderne digitalisering (niet van de Statenvertalers zelf), terwijl
twee onafhankelijke Nederlandse bronnen die het met elkaar eens zijn zwaarder
wegen als bewijs voor de daadwerkelijke, historische verstraditie. De
Nederlandse tekst zelf — inclusief de verstraditie — blijft zo ongewijzigd en
getrouw aan wat daadwerkelijk gepubliceerd is.

Dit is niet per definitie de juiste kant voor élk toekomstig geval. Als een
vertaling zelf overduidelijk een parse-fout bevat (in plaats van een
legitieme, andersluidende verstraditie), ligt optie 1 meer voor de hand. Een
eerdere versie van dit mechanisme deed precies dat (zie de git-historie van
`ingest/verse_boundary_corrections.py`, vóór het is omgedraaid) — bruikbaar
als uitgangspunt mocht dat ooit weer nodig zijn.

---

## Het correctiemechanisme

[`ingest/verse_boundary_corrections.py`](../ingest/verse_boundary_corrections.py)
bevat een handmatig gecureerde `CORRECTIONS`-lijst:

```python
CORRECTIONS: list[dict] = [
    {
        "book": "GAL",
        "chapter": 5,
        "verse_from": 22,
        "words": 2,
        "check": ["G4240", "G1466"],   # verwachte Strong's-nummers, in volgorde
        "note": "...",
    },
]
```

Voor elke entry worden de eerste `words` woorden van Grieks vers
`verse_from + 1` verplaatst naar het einde van vers `verse_from`, binnen
hetzelfde boek/hoofdstuk:

- `greek_words`-rijen worden geïdentificeerd en verplaatst via hun primaire
  sleutel (`id`), niet via hun `(vers, woordpositie)`-combinatie — bestaande
  `word_links` die naar die rijen wijzen blijven dus automatisch correct
  gekoppeld, zonder dat er iets opnieuw gelinkt hoeft te worden.
- **Idempotent en defensief**: `check` bevat de verwachte Strong's-nummers van
  de te verplaatsen woorden. Komt de staart/kop van het vers daar niet mee
  overeen (bronmateriaal veranderd, of de correctie is al toegepast), dan
  wordt de stap overgeslagen met een waarschuwing in plaats van blind
  uitgevoerd.
- Strong's-nummers zijn hier een stabiele identifier omdat er maar één
  Griekse/Hebreeuwse brontekst is (in tegenstelling tot de Nederlandse kant,
  waar de precieze woordvorm per vertaling verschilt — vandaar dat een
  eerdere, inmiddels vervangen versie van dit mechanisme dat wél per
  vertaling moest bijhouden).

### Automatisch bij elke ingest

De correctie draait automatisch mee in [`ingest/main.py`](../ingest/main.py),
als stap **4/7**, direct na `parse_elzevir`:

```
1. fetch_sources
2. parse_tahot
3. parse_elzevir
4. verse_boundary_corrections   ← hier
5. parse_statenvertaling
6. align_heuristic
7. parse_strongs
```

**⚠️ Waarschuwing:** deze stap moet altijd *direct* na `parse_elzevir` draaien.
`parse_elzevir.py`'s bulk-insert doet een upsert op de oorspronkelijke
`(boek, hoofdstuk, vers, woordpositie)`-sleutel. Draai je `parse_elzevir.py`
los, tegen een database waar al een correctie op is toegepast, zonder deze
module er meteen achteraan — dan voegt de upsert-logica de oorspronkelijke
(foute) woorden gewoon weer toe op hun oude positie, en kan die in het ergste
geval zelfs het woord overschrijven dat inmiddels op die (vrijgekomen)
positie staat. Draai dit dus altijd via de volledige pipeline, of handmatig
meteen na een losse `parse_elzevir.py`-run.

---

## Een nieuwe correctie toevoegen

1. Draai `find_verse_boundary_candidates.py` (eventueel met een ruimere
   `--next-max-words`) en loop de kandidaten door.
2. Controleer per kandidaat of de laatste woorden van het gevlagde vers
   aannemelijk de geglosste openingswoorden van het volgende vers vertalen.
   Corroboratie door zowel SV als HSV is een sterke aanwijzing, geen bewijs
   op zich — bekijk de tekst.
3. Bepaal welke kant klopt (zie hierboven) en voeg een entry toe aan
   `CORRECTIONS`, met de Strong's-nummers van de te verplaatsen woorden als
   `check`.
4. Draai de pipeline (of alleen `verse_boundary_corrections.py`) op **dev**,
   controleer het resultaat rechtstreeks in de database.
5. Draai `align_heuristic.py --book <USFM>` opnieuw voor de betrokken
   boek(en)/vertaling(en) — bestaande koppelingen over de oude (foute) grens
   heen worden niet vanzelf ongedaan gemaakt.
6. Herhaal op productie (zie hieronder).

---

## Toegepast op productie

`ingest`-scripts draaien normaal **nooit** op productie — te zwaar voor de
droplet, zie [`docs/data-sync.md`](data-sync.md). `verse_boundary_corrections.py`
is bewust licht gehouden (geen scraping, geen ML, alleen een paar gerichte
UPDATE-statements) en kan daarom wél veilig rechtstreeks op productie draaien:

```bash
ssh translatorstags-prod
cd /translatorstags
docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml \
  --profile ingest run --rm ingest python verse_boundary_corrections.py --dry-run
# ziet er goed uit? dan zonder --dry-run
docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml \
  --profile ingest run --rm ingest python verse_boundary_corrections.py
```

Daarna `align_heuristic.py --book <USFM> --translation <CODE>` opnieuw draaien
voor het betrokken boek, voor elke vertaling — ook dit is scoped genoeg om
rechtstreeks op productie te doen.

---

## Toegepaste correcties tot nu toe

| Referentie | Verplaatst | Van → naar | Reden |
|---|---|---|---|
| Galaten 5:22-23 | πραοτης (G4240), εγκρατεια (G1466) | Grieks vers 23 → einde vers 22 | SV én HSV rekenen deze woorden onafhankelijk van elkaar tot vers 22; Elzevir-tagging aangepast aan de Nederlandse traditie |

---

## Gerelateerde bevinding: `created_by_user_id` in `link_confidence`

Tijdens dit werk is `align_heuristic.py` voor het eerst ooit rechtstreeks
tegen de **productie**-database gedraaid (normaal alleen op dev, zie
`data-sync.md`). Daarbij kwam aan het licht dat `insert_link_confidence()` in
[`ingest/db/loaders.py`](../ingest/db/loaders.py) nog de kolomnaam `created_by`
gebruikte, terwijl die kolom op zowel dev als productie al `created_by_user_id`
heet sinds een Doctrine-migratie aan de app-kant. Elke automatische
link-insert faalde daardoor stilzwijgend (`except Exception: pass`), met
duizenden `word_links`-rijen zonder bijbehorende `link_confidence`-rij tot
gevolg — niet alleen bij dit werk, ook bij eerder, ongerelateerd gebruik.
Gefixt, orphaned rijen opgeruimd op zowel dev als productie. Zie
[PR #37](https://github.com/gerardzeeman/translators-tags/pull/37) voor de
volledige analyse.

In dezelfde periode is ook een losstaande bug in `ingest/main.py` gevonden en
gefixt: de `align_heuristic`-stap riep de functie aan zonder het verplichte
`translation_id`-argument, waardoor een volledige `python main.py`-run altijd
op die stap crashte. Zie
[PR #38](https://github.com/gerardzeeman/translators-tags/pull/38).

---

## Bekende beperkingen

- **Verschuivingen van 1 woord in een verder normaal vers zijn niet
  betrouwbaar te detecteren.** De structurele zoekopdracht mist die (het
  vereist een kale opsommings-staart), en er is geen Grieks/Nederlands
  woordenboek in de database om zulke gevallen automatisch semantisch te
  bevestigen (`strongs_entries` heeft alleen Engelse KJV-glossen voor
  Grieks, geen Nederlandse).
- **Romeinen 1:29-30 (SV)** toont een vergelijkbaar patroon (een woord dat in
  de Griekse telling bij vers 29 hoort, maar in SV als eerste woord van
  vers 30 lijkt te staan) — maar dit wordt *niet* bevestigd door HSV, dus
  bewust niet automatisch gecorrigeerd. Wacht op handmatige review.
