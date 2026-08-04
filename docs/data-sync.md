# Data-synchronisatie: productie ↔ ontwikkeling

## Probleemstelling

De ingest- en align-scripts draaien op de **dev-machine** (te zwaar voor de productie-droplet).
Productie heeft manuele links die op elk moment kunnen groeien.

Er zijn twee datastroom-richtingen nodig:

| Richting | Wat | Waarom |
|---|---|---|
| **Prod → Dev** | Manuele links | Zodat de ingest-scripts manuele links meenemen als hint |
| **Dev → Prod** | Berekende links | Berekende links zijn up-to-date na een verse ingest-run |

**Kritieke eis:** berekende links van dev mogen nooit manuele links op prod overschrijven,
ook niet als die manuele links er ná de laatste dev-sync bijgekomen zijn.

---

## Gegevensmodel: manueel vs. berekend

Het onderscheid staat al in de database:

### `link_confidence.method`
| Waarde | Type | Aangemaakt door |
|---|---|---|
| `manual` | **Manueel** | Gebruiker via UI |
| `manual_hint` | Berekend | `align_heuristic.py` |
| `proper_noun` | Berekend | `align_heuristic.py` |
| `positional` | Berekend | `align_heuristic.py` |

### `inter_translation_links.method`
| Waarde | Type |
|---|---|
| `manual`, `manual_empty` | **Manueel** |
| `auto_source_pivot`, `auto_sequence`, `auto_positional` | Berekend |

### `manual_empty_links`
Altijd manueel (intentionele "geen koppeling"-annotatie).

---

## Voorstel: twee SQL-scripts

De synchronisatie verloopt via **pg_dump-fragmenten** (gefilterde exports) en
bijbehorende **import-scripts met conflict-logica**. Geen externe tooling nodig;
werkt met de bestaande PostgreSQL-setup.

### Datastroom 1 — Prod → Dev (manuele links ophalen)

**Wat wordt geëxporteerd:**
- `word_links` + `link_confidence` waar method = `'manual'`
- `manual_empty_links` (altijd manueel)
- `inter_translation_links` waar method IN (`'manual'`, `'manual_empty'`)

**Export-commando (op prod):**

```bash
# Draai op de productie-droplet of via SSH
docker exec bible_postgres psql -U bible bible_compare \
  -c "\COPY (
    SELECT wl.id, wl.source_language, wl.hebrew_word_id, wl.greek_word_id,
           wl.translation_word_id, lc.method, lc.score, lc.created_at, lc.created_by, lc.notes
    FROM word_links wl
    JOIN link_confidence lc ON lc.link_id = wl.id
    WHERE lc.method = 'manual'
  ) TO STDOUT WITH CSV HEADER" > manual_word_links.csv

docker exec bible_postgres psql -U bible bible_compare \
  -c "\COPY manual_empty_links TO STDOUT WITH CSV HEADER" > manual_empty_links.csv

docker exec bible_postgres psql -U bible bible_compare \
  -c "\COPY (
    SELECT * FROM inter_translation_links
    WHERE method IN ('manual', 'manual_empty')
  ) TO STDOUT WITH CSV HEADER" > manual_itl.csv
```

**Import-commando (op dev) — upsert, nooit overschrijven:**

```sql
-- Tijdelijke tabel laden
CREATE TEMP TABLE import_manual_word_links (
    id INTEGER,
    source_language CHAR(2),
    hebrew_word_id INTEGER,
    greek_word_id INTEGER,
    translation_word_id INTEGER,
    method VARCHAR(20),
    score NUMERIC(4,3),
    created_at TIMESTAMPTZ,
    created_by TEXT,
    notes TEXT
);
\COPY import_manual_word_links FROM 'manual_word_links.csv' WITH CSV HEADER;

-- Voeg word_links in die nog niet bestaan (matchen op bron + doelwoord)
INSERT INTO word_links (source_language, hebrew_word_id, greek_word_id, translation_word_id)
SELECT DISTINCT source_language, hebrew_word_id, greek_word_id, translation_word_id
FROM import_manual_word_links i
WHERE NOT EXISTS (
    SELECT 1 FROM word_links wl
    WHERE (wl.hebrew_word_id IS NOT DISTINCT FROM i.hebrew_word_id)
      AND (wl.greek_word_id  IS NOT DISTINCT FROM i.greek_word_id)
      AND wl.translation_word_id = i.translation_word_id
);

-- Voeg link_confidence toe voor de zojuist ingevoegde links
INSERT INTO link_confidence (link_id, method, score, created_at, created_by, notes)
SELECT wl.id, i.method, i.score, i.created_at, i.created_by, i.notes
FROM import_manual_word_links i
JOIN word_links wl
  ON (wl.hebrew_word_id IS NOT DISTINCT FROM i.hebrew_word_id)
 AND (wl.greek_word_id  IS NOT DISTINCT FROM i.greek_word_id)
 AND wl.translation_word_id = i.translation_word_id
ON CONFLICT (link_id, method) DO NOTHING;  -- bestaande manuele links ongemoeid laten

-- manual_empty_links
\COPY import_mel FROM 'manual_empty_links.csv' WITH CSV HEADER;
INSERT INTO manual_empty_links
    SELECT * FROM import_mel
    ON CONFLICT DO NOTHING;

-- inter_translation_links
INSERT INTO inter_translation_links (word_a_id, word_b_id, method, confidence)
SELECT word_a_id, word_b_id, method, confidence
FROM (SELECT * FROM 'manual_itl.csv') itl_import  -- via tijdelijke tabel
ON CONFLICT (word_a_id, word_b_id) DO NOTHING;
```

---

### Datastroom 2 — Dev → Prod (berekende links toepassen)

**Strategie: vervang berekende links, raak manuele nooit aan**

Op prod worden per bron-vertaling-pair de berekende links vervangen door de
dev-versie, mits er op prod geen manuele link voor hetzelfde woord bestaat.

**Export-commando (op dev):**

```bash
# Exporteer alle berekende word_links
docker exec bible_postgres psql -U bible bible_compare \
  -c "\COPY (
    SELECT wl.id, wl.source_language, wl.hebrew_word_id, wl.greek_word_id,
           wl.translation_word_id, lc.method, lc.score
    FROM word_links wl
    JOIN link_confidence lc ON lc.link_id = wl.id
    WHERE lc.method != 'manual'
  ) TO STDOUT WITH CSV HEADER" > computed_word_links.csv

docker exec bible_postgres psql -U bible bible_compare \
  -c "\COPY (
    SELECT * FROM inter_translation_links
    WHERE method NOT IN ('manual', 'manual_empty')
  ) TO STDOUT WITH CSV HEADER" > computed_itl.csv
```

**Import-commando (op prod) — met manuele-link-bescherming:**

```sql
CREATE TEMP TABLE import_computed (
    id INTEGER,
    source_language CHAR(2),
    hebrew_word_id INTEGER,
    greek_word_id INTEGER,
    translation_word_id INTEGER,
    method VARCHAR(20),
    score NUMERIC(4,3)
);
\COPY import_computed FROM 'computed_word_links.csv' WITH CSV HEADER;

-- Stap 1: verwijder bestaande BEREKENDE links waarvan dev een nieuwe versie heeft,
--         maar ALLEEN als er geen manuele link bestaat voor hetzelfde woord-paar.
DELETE FROM word_links wl
WHERE EXISTS (
    -- Dev heeft een nieuw berekend resultaat voor dit woord-paar
    SELECT 1 FROM import_computed i
    WHERE (wl.hebrew_word_id IS NOT DISTINCT FROM i.hebrew_word_id)
      AND (wl.greek_word_id  IS NOT DISTINCT FROM i.greek_word_id)
      AND wl.translation_word_id = i.translation_word_id
)
AND NOT EXISTS (
    -- Maar er bestaat al een manuele link voor dit bronwoord
    SELECT 1 FROM link_confidence lc
    WHERE lc.link_id = wl.id
      AND lc.method = 'manual'
);

-- Stap 2: voeg nieuwe berekende links in, sla over als een manuele link al bestaat
INSERT INTO word_links (source_language, hebrew_word_id, greek_word_id, translation_word_id)
SELECT DISTINCT source_language, hebrew_word_id, greek_word_id, translation_word_id
FROM import_computed i
WHERE NOT EXISTS (
    -- Skip als prod al een manuele link heeft voor dit bronwoord
    SELECT 1 FROM word_links wl
    JOIN link_confidence lc ON lc.link_id = wl.id AND lc.method = 'manual'
    WHERE (wl.hebrew_word_id IS NOT DISTINCT FROM i.hebrew_word_id)
      AND (wl.greek_word_id  IS NOT DISTINCT FROM i.greek_word_id)
      AND wl.translation_word_id = i.translation_word_id
);

-- Stap 3: voeg link_confidence toe voor de nieuwe links
INSERT INTO link_confidence (link_id, method, score)
SELECT wl.id, i.method, i.score
FROM import_computed i
JOIN word_links wl
  ON (wl.hebrew_word_id IS NOT DISTINCT FROM i.hebrew_word_id)
 AND (wl.greek_word_id  IS NOT DISTINCT FROM i.greek_word_id)
 AND wl.translation_word_id = i.translation_word_id
ON CONFLICT (link_id, method) DO UPDATE
    SET score = EXCLUDED.score;  -- update score als methode al bestaat

-- inter_translation_links: vervang auto-links, raak manual/manual_empty niet aan
CREATE TEMP TABLE import_computed_itl (
    word_a_id INTEGER, word_b_id INTEGER,
    method VARCHAR(30), confidence SMALLINT
);
\COPY import_computed_itl FROM 'computed_itl.csv' WITH CSV HEADER;

DELETE FROM inter_translation_links
WHERE method NOT IN ('manual', 'manual_empty')
  AND EXISTS (
      SELECT 1 FROM import_computed_itl i
      WHERE i.word_a_id = inter_translation_links.word_a_id
        AND i.word_b_id = inter_translation_links.word_b_id
  );

INSERT INTO inter_translation_links (word_a_id, word_b_id, method, confidence)
SELECT word_a_id, word_b_id, method, confidence
FROM import_computed_itl
ON CONFLICT (word_a_id, word_b_id) DO NOTHING;
```

---

## Technische verbinding: dev-pc ↔ DigitalOcean

PostgreSQL op prod is niet publiek bereikbaar, en zelfs niet bereikbaar op de droplet
zijn **eigen** `localhost` — de `postgres`-service heeft in `docker-compose.yml`
(basisbestand) helemaal geen `ports:`-sectie, en `docker-compose.prod.yml` (waarmee
productie daadwerkelijk draait, zie de [deploy-workflow](../.github/workflows/deploy.yml))
voegt er ook geen toe. Poort 5432 is dus alleen bereikbaar *binnen* het interne
`bible_net`-Docker-netwerk, via de containernaam `postgres` — niet van buiten de
container, ook niet vanaf de droplet zelf. De enige toegangspoort van buitenaf is SSH
(poort 22).

> **Vorige aanpak (SSH-tunnel naar Postgres) werkt hierdoor niet.** Een eerdere versie
> van dit document adviseerde `ssh -L 5433:localhost:5432 root@<droplet-ip>` en dan
> vanaf dev met een aangepaste `DATABASE_URL` via `host.docker.internal` verbinden. Dat
> lijkt logisch, maar `localhost:5432` bestaat simpelweg niet op de droplet zelf — SSH
> accepteert de lokale kant van de tunnel keurig, maar sluit het kanaal zodra het aan
> productie-kant probeert te verbinden en dat meteen faalt. Resultaat: `SQLSTATE[08006]
> ... server closed the connection unexpectedly`, ook al staat de tunnel "gewoon" open.

**De aanpak die wél werkt:** elk sync-commando draait **lokaal**, op de machine die de
data al heeft — dev-commando's tegen de dev-database, prod-commando's tegen de
prod-database (via `docker exec bible_app ...`, met de eigen `DATABASE_URL` van die
container, die `postgres` intern prima kan resolven). Er hoeft nooit een druppel
databaseverkeer tussen dev en prod te lopen; alleen de kleine CSV-bestanden reizen mee,
via `docker cp` (container ↔ hostmachine) en `scp` (hostmachine ↔ hostmachine). Zie het
[stappenplan](#stappenplan-volledige-synchronisatie-uitvoeren) hieronder voor de
volledige, uitvoerbare commandoreeks.

### SSH-config voor gemak

Voeg toe aan `~/.ssh/config` op de dev-machine (alleen voor het gemak van `ssh
translatorstags-prod` als login-snelkoppeling — geen `LocalForward` nodig, want er is
geen Postgres-tunnel meer):

```
Host translatorstags-prod
    HostName <droplet-ip>
    User root
    IdentityFile ~/.ssh/id_ed25519_do
```

Voor Adminer (web-GUI op de database) bestaat al een aparte, wél werkende tunnel: die
poort (8081) is op productie gebonden aan `127.0.0.1`, zie het commentaar in
[`docker-compose.prod.yml`](../docker-compose.prod.yml) — `ssh -L
8081:localhost:8081 root@<droplet-ip>` en dan `http://localhost:8081` openen.

---

## Implementatie als Symfony-commands

**Status: geïmplementeerd.** De SQL-logica hierboven is verpakt in vier
Symfony-console-commands ([`app/src/Command/`](../app/src/Command)), traceerbaar via de
gewone Symfony-logging en gebruik makend van de bestaande database-verbinding.

| Command | Beschrijving | Opties |
|---|---|---|
| `app:sync:export-manual` | Exporteert manuele links naar CSV (draait lokaal op prod, via ssh) | `--output-dir` (default `./sync`) |
| `app:sync:import-manual` | Importeert manuele links van prod naar dev (draait lokaal op dev) | `--input-dir` (default `./sync`), `--dry-run` |
| `app:sync:export-computed` | Exporteert berekende links naar CSV (draait lokaal op dev) | `--output-dir` (default `./sync`) |
| `app:sync:import-computed` | Importeert berekende links op prod, met manuele-link-bescherming (draait lokaal op prod, via ssh) | `--input-dir` (default `./sync`), `--dry-run` |

Beide export-commands en beide import-commands streamen rij voor rij (Doctrine's
`iterateAssociative()` bij export, een generator bij het inlezen van CSV bij import) —
nodig omdat `inter_translation_links` alleen al 700k+ berekende rijen bevat, wat het
standaard PHP-geheugenlimiet (128MB) overschrijdt als je alles in één array laadt.

---

## Werkstroom in de praktijk

Beide richtingen draaien lokaal tegen de database die de data al heeft — nooit via een
tunnel (Postgres is op productie niet eens op de droplet's eigen `localhost` bereikbaar,
zie [Technische verbinding](#technische-verbinding-dev-pc--digitalocean) hierboven).
Alleen de CSV-bestanden reizen mee, via `docker cp` (container ↔ hostmachine) en `scp`
(hostmachine ↔ hostmachine).

```
┌─────────────────────────────────────────────────────┐
│                 PRODUCTIE-DROPLET                    │
│                                                      │
│  1. app:sync:export-manual (lokaal, leest prod)      │
│  2. docker cp csv's → droplet-bestandssysteem        │
└──────────────────────────┬──────────────────────────┘
                           │ scp manual_*.csv → dev
                           ▼
┌─────────────────────────────────────────────────────┐
│                    DEV-MACHINE                       │
│                                                      │
│  3. app:sync:import-manual  (lokaal, schrijft dev)   │
│  4. align_heuristic.py      (lokaal, alignment)      │
│  5. app:link:translations:auto  (lokaal, SV↔HSV)     │
│  6. app:sync:export-computed (lokaal, leest dev)     │
│  7. scp computed_*.csv → productie                   │
└──────────────────────────┬──────────────────────────┘
                           ▼
┌─────────────────────────────────────────────────────┐
│                 PRODUCTIE-DROPLET                    │
│                                                      │
│  8. docker cp csv's → bible_app-container            │
│  9. app:sync:import-computed (lokaal, met guard op   │
│     manuele links)                                    │
└─────────────────────────────────────────────────────┘
```

**Frequentie:** typisch na elke ingest-run (wekelijks/maandelijks).

Het volledige, uitvoerbare stappenplan staat hieronder.

---

## Stappenplan: volledige synchronisatie uitvoeren

Ga in deze volgorde te werk — begin altijd met **Prod → Dev**, anders overschrijft een
berekende dev-link straks een manuele link die na de laatste sync op prod is
bijgekomen.

**Voorwaarden:**
- Dev-stack draait: `docker compose --env-file .env.local up -d` (zie [README](../README.md))
- SSH-alias `translatorstags-prod` staat in `~/.ssh/config` (zie hierboven)
- Op Windows: draai de commando's hieronder in **Git Bash** (onderdeel van de Git-installatie
  uit de README-vereisten), niet in Command Prompt of PowerShell. De commando's gebruiken
  bash-syntax (`\`-regelvervolg, `mkdir -p`, enz.) die in `cmd.exe` fouten geeft als
  `The syntax of the command is incorrect` — commando's die je ná `ssh translatorstags-prod`
  typt draaien sowieso al in een Linux-shell op de droplet/container en zijn dus geen
  probleem, maar commando's die je in je eigen lokale terminal typt (zoals `mkdir -p`,
  `scp`) wel.

Er loopt nooit databaseverkeer tussen dev en prod — elk `bin/console`-commando draait
lokaal, tegen de database die daar al staat, met de eigen (niet-overschreven)
`DATABASE_URL` van die machine. Alleen de kleine CSV-bestanden reizen mee tussen de
machines.

> **Waar draai je wat?** Commando's met `docker compose exec app ...` draaien **op de
> dev-machine**, in je eigen terminal. Commando's met `docker exec bible_app ...`
> (zonder `compose`) draaien **op productie**, ná `ssh translatorstags-prod` — en dan
> blijf je in die sessie totdat de stap het aangeeft. Dat onderscheid — `docker compose
> exec app` vs. `docker exec bible_app` — is bewust: het vertelt je meteen op welke
> machine een commando hoort te draaien.

> **Werkmap:** draai elk `docker compose ...`-commando hieronder vanuit de
> **projectroot** (waar `docker-compose.yml` staat) — niet vanuit `app/` en niet vanuit
> `docs/`. Vanuit de verkeerde map geeft `docker compose` de foutmelding
> `no configuration file provided: not found` (er is dan geen `docker-compose.yml` te
> vinden in de huidige map; Docker Compose zoekt niet in bovenliggende mappen). Let op:
> `app/` heeft zelf ook een (ongebruikte) `compose.yaml` — die hoort niet bij deze
> workflow.

### Fase 1 — Prod → Dev: manuele links ophalen

Manuele links zijn een klein datavolume (op deze database: 1713 + 38 + 62 rijen) — geen
reden om iets anders te doen dan wat fase 3 ook doet: lokaal exporteren, klein
bestandje overzetten.

```bash
# 1. Inloggen op productie — en hierna WEL op productie blijven werken
ssh translatorstags-prod

# 2. (op productie) Manuele links exporteren — gebruikt de eigen DATABASE_URL van de
#    prod-container, geen tunnel of override nodig
docker exec bible_app php bin/console app:sync:export-manual

# 3. (op productie) CSV's uit de container halen naar de droplet zelf
#    (docker cp maakt de doelmap aan als die nog niet bestaat)
docker cp bible_app:/app/sync /tmp/sync

# 4. Terug op dev (nieuw terminalvenster, of exit de ssh-sessie): CSV's ophalen
#    (doelmap bestaat hier meestal nog niet, in tegenstelling tot fase 3 — eerst aanmaken)
mkdir -p app/sync
scp translatorstags-prod:/tmp/sync/manual_word_links.csv \
    translatorstags-prod:/tmp/sync/manual_empty_links.csv \
    translatorstags-prod:/tmp/sync/manual_itl.csv \
    app/sync/

# 5. Importeren in de lokale dev-database — eerst dry-run, dan echt
docker compose exec app php bin/console app:sync:import-manual --dry-run
docker compose exec app php bin/console app:sync:import-manual
```

### Fase 2 — Dev: alignment draaien

**Routinematig (meest voorkomend):** alleen de heuristische alignment opnieuw draaien,
zonder de brontekst opnieuw op te halen. Dit produceert de `word_links` met
`link_confidence.method` = `manual_hint`, `proper_noun` of `positional`:

```bash
docker compose --profile ingest run --rm ingest python align_heuristic.py

# Optioneel beperkt tot één boek of vertaling:
docker compose --profile ingest run --rm ingest python align_heuristic.py --book=GEN
docker compose --profile ingest run --rm ingest python align_heuristic.py --translation=SV
```

**Volledige verse ingest (alleen bij nieuwe brontekst):**

```bash
docker compose --profile ingest run --rm ingest
```

> ⚠️ **Bekend probleem:** `ingest/main.py` roept in stap 5/6 nog `align_pivot.py` aan,
> een script dat is uitgefaseerd (het bestand doet nu niets anders dan
> `raise SystemExit(...)` — zie [`ingest/align_pivot.py`](../ingest/align_pivot.py)). De
> methode `pivot` bestaat ook niet meer in de `link_confidence`-schemabeperking. Een
> volledige `python main.py`-run **crasht** daardoor op die stap. Draai tot dit is
> opgelost de stappen los, in deze volgorde (elk via
> `docker compose --profile ingest run --rm ingest python <script>.py`):
> `fetch_sources.py` → `parse_tahot.py` → `parse_elzevir.py` →
> `parse_statenvertaling.py` → `align_heuristic.py` → `parse_strongs.py`.

Daarna de vertaling-naar-vertaling koppeling (SV↔HSV, los van de ingest-pipeline
hierboven):

```bash
docker compose exec app php bin/console app:link:translations:auto --dry-run
docker compose exec app php bin/console app:link:translations:auto

# Optioneel beperkt tot één familie of boek:
docker compose exec app php bin/console app:link:translations:auto --family=SV --book=GEN

# Om bestaande auto-links eerst te wissen en volledig opnieuw te berekenen:
docker compose exec app php bin/console app:link:translations:auto --reset
```

### Fase 3 — Dev → Prod: berekende links uploaden

`computed_word_links.csv` en `computed_itl.csv` kunnen samen goed 800k+ rijen bevatten
(op deze database: 142.689 + 703.852). `import-computed` doet per rij 1–2 SQL-queries
— via de SSH-tunnel zou dat honderdduizenden netwerk-roundtrips zijn en potentieel uren
duren. Daarom draait deze fase, in tegenstelling tot fase 1, het *import*-commando
**lokaal op productie** (tegen de eigen, lokale databaseverbinding van de prod-container
— snel, geen tunnel nodig), en gebruiken we `scp` + `docker cp` om de CSV's er te
krijgen.

```bash
# 1. Exporteren vanuit de lokale dev-database (draait op dev)
docker compose exec app php bin/console app:sync:export-computed
# → landt op de hostmachine in app/sync/ (dev bind-mount)

# 2. CSV's naar productie kopiëren (draait op dev)
ssh translatorstags-prod "mkdir -p /tmp/sync"
scp app/sync/computed_word_links.csv app/sync/computed_itl.csv \
    translatorstags-prod:/tmp/sync/

# 3. Inloggen op productie — en hierna WEL op productie blijven werken
ssh translatorstags-prod

# 4. (op productie) CSV's de container in kopiëren — prod heeft geen bind-mount.
#    docker cp faalt als de doelmap nog niet bestaat, dus eerst aanmaken:
docker exec bible_app mkdir -p /tmp/sync
docker cp /tmp/sync/computed_word_links.csv bible_app:/tmp/sync/computed_word_links.csv
docker cp /tmp/sync/computed_itl.csv        bible_app:/tmp/sync/computed_itl.csv

# 5. (op productie) Importeren met manuele-link-bescherming — eerst dry-run, dan echt.
#    Let op: dit gebruikt de EIGEN DATABASE_URL van de prod-container (geen -e
#    override, geen host.docker.internal nodig — we zíjn al op productie).
docker exec bible_app php bin/console app:sync:import-computed --input-dir=/tmp/sync --dry-run
docker exec bible_app php bin/console app:sync:import-computed --input-dir=/tmp/sync
```

---

## Risico's en aandachtspunten

| Risico | Mitigatie |
|---|---|
| Word-IDs kunnen verschillen tussen dev en prod als tabellen opnieuw zijn geladen | Exporteer op basis van `(hebrew_word_id, greek_word_id, translation_word_id)`, niet op basis van `word_links.id` |
| Manuele link op prod die op dev nog niet bestaat → wordt gerespecteerd | De `NOT EXISTS (manual)`-guard in import-computed dekt dit af |
| Conflict: zelfde bronwoord heeft berekende link op prod én manuele link op prod | Manuele wint altijd — berekende wordt niet overschreven |
| CSV-bestanden bevatten gevoelige data (linkanotaties) | Gaan alleen via `scp`/`docker cp` over SSH, nooit via een publieke URL |
| `link_confidence.created_by_user_id` verwijst naar een user-ID dat op prod iets anders kan betekenen dan op dev | Wordt bewust niet gesynchroniseerd (zie `import-manual`) |
