# Alef-Omega — Bijbelwoord-koppeltool

Een webapplicatie voor het woord-voor-woord koppelen van Nederlandse bijbelvertalingen aan de Hebreeuwse en Griekse grondtekst. Het doel is om voor elk woord in de Statenvertaling (SV) en de Herziene Statenvertaling (HSV) te kunnen zien welk Hebreeuws of Grieks woord eraan ten grondslag ligt — en omgekeerd.

---

## Wat doet de applicatie?

### Bijbellezer

De kernpagina toont een vers in drie kolommen naast elkaar: de Hebreeuwse of Griekse grondtekst, de Statenvertaling en de Herziene Statenvertaling. Klik op een woord om de koppelingen te zien: de verbonden woorden in de andere kolommen lichten op. Elk Hebreeuws woord toont zijn Strong's-nummer, transliteratie en morfologische code. Via het Strong's-nummer kom je bij de woordenboekvermelding met Nederlandse vertaling.

### Koppelinterface

Vertaalwoorden worden handmatig gekoppeld aan bronwoorden, vers voor vers. De interface toont links de grondtekst en rechts de Nederlandse vertaling. Na het koppelen worden koppelingen opgeslagen met een betrouwbaarheidsscore op basis van de gebruikte methode (handmatig, pivot, volgorde of positioneel).

Er is ook een Strong's-koppelinterface: kies een Strong's-nummer en koppel alle voorkomens van dat woord in één overzicht.

### Automatische SV↔HSV-uitlijning

Een Symfony-console-commando koppelt HSV-woorden automatisch aan SV-woorden via drie methoden:

1. **Bron-pivot** — HSV-woorden die via de SV hetzelfde bronwoord delen.
2. **Volgordeuitlijning** — tekst- en positiegelijkenis (Needleman-Wunsch).
3. **Positioneel** — laatste redmiddel op basis van woordvolgorde.

Daarna kunnen de automatische koppelingen handmatig worden gecontroleerd en gecorrigeerd.

### Beheer

Beheerders kunnen via `/admin/users` gebruikers aanmaken en rollen toewijzen. Er zijn vijf rollen: `ROLE_VIEWER`, `ROLE_VIEWER_HSV`, `ROLE_LINKER`, `ROLE_EDIT_STRONG_TRNL` en `ROLE_ADMIN`.

### Institutio-pijplijn (los onderdeel)

Naast de Bijbeltekst bevat het project een aparte, in opzet vergelijkbare pijplijn die Calvijns *Institutio christianae religionis* (1559, Latijn) verwerkt tot een gelaagde Latijns-Nederlandse editie (interlineaire glossen + vloeiende LLM-vertaling met SimAlign-woordalignment). Dit gebruikt eigen tabellen (`work`/`segment`/`token`/`lemma_gloss`/`translation`/`alignment`) naast de bestaande Hebreeuws/Grieks-tabellen. Zie [`ingest/institutio/README.md`](ingest/institutio/README.md).

---

## Technische stack

| Laag | Technologie |
|------|-------------|
| Web framework | Symfony 7 (PHP 8.3) |
| Web server | FrankenPHP + Caddy |
| Database | PostgreSQL 16 |
| Frontend | Twig, Stimulus (Hotwire), Turbo |
| Ingest pipeline | Python 3 |
| Container | Docker Compose |
| CI/CD | GitHub Actions → DigitalOcean |

---

## Installatie (lokaal, Windows)

**Vereisten:** Docker Desktop met WSL 2, Git.

### 1. Project ophalen

```powershell
git clone https://github.com/gerardzeeman/translators-tags.git alef-omega
cd alef-omega
```

### 2. Omgevingsvariabelen instellen

Maak in de **projectroot** (naast `docker-compose.yml`) een bestand `.env.local` aan (staat in `.gitignore`, wordt nooit gecommit):

```dotenv
DB_PASSWORD=<genereer: openssl rand -hex 16>
APP_SECRET=<genereer: openssl rand -hex 32>
REMEMBER_ME_SECRET=<genereer: openssl rand -hex 32>
```

De overige standaardwaarden in `.env` (root) zijn voor lokaal gebruik voldoende als startpunt.

Optioneel: zet `GOOGLE_ANALYTICS_ID=G-XXXXXXXXXX` en/of `ANTHROPIC_API_KEY=...` (voor de Institutio-pijplijn) in dezelfde `.env.local`.

### 3. Stack starten

```powershell
docker compose --env-file .env.local up -d --build
```

Dit bouwt de FrankenPHP-webserver-image (incl. `composer install`) en start die samen met de PostgreSQL-database. Controleer of beide containers actief zijn:

```powershell
docker compose ps
```

> **Belangrijk — gebruik altijd `--env-file .env.local`**, ook bij een latere herstart (`docker compose up -d`). Zonder deze vlag leest Docker Compose stilzwijgend het standaard `.env`-bestand, dat alleen een placeholder-wachtwoord (`changeme`) bevat. Als de PostgreSQL-container al eerder met het échte wachtwoord uit `.env.local` is aangemaakt, blijft die het echte wachtwoord verwachten — een herstart zonder `--env-file .env.local` geeft dan op elke pagina een `PDOException` / `SQLSTATE[08006] password authentication failed for user`, ook al lijkt de stack verder gewoon te draaien.

### 4. Applicatie openen

Ga naar **https://localhost** in je browser. FrankenPHP gebruikt een zelf-ondertekend certificaat voor localhost — klik de beveiligingswaarschuwing weg via "Geavanceerd → Toch doorgaan".

### 5. Database vullen (ingest pipeline)

```powershell
docker compose --profile ingest run --rm ingest
```

De pipeline doorloopt zeven stappen (Hebreeuws OT, Grieks NT, Statenvertaling, uitlijning, Strong's-woordenboek) en duurt afhankelijk van internetsnelheid 20–45 minuten. Bij voltooiing verschijnt:

```
══════════════════════════════════════════════════════════════
  ✓ Ingest pipeline complete.
══════════════════════════════════════════════════════════════
```

Ververs daarna de browser. Je ziet nu de bijbelboeken met tekst en koppelingen.

---

## Veelgebruikte commando's

```powershell
# Symfony console (cache, routes, ...)
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console debug:router

# Automatische SV↔HSV koppeling uitvoeren
docker compose exec app php bin/console app:link:translations:auto

# Database bekijken (DBeaver / TablePlus op localhost:5432, db: bible_compare, user: bible)
docker compose exec postgres psql -U bible -d bible_compare

# Stack stoppen (data blijft bewaard in Docker volume)
docker compose down

# Stack + data volledig resetten
docker compose down -v && docker compose --env-file .env.local up -d
```

---

## Documentatie

| Document | Inhoud |
|----------|--------|
| [`docs/koppelgids.md`](docs/koppelgids.md) | Stap-voor-stap handleiding voor het koppelproces |
| [`docs/SETUP.md`](docs/SETUP.md) | Uitgebreide installatiegids voor Windows |
| [`docs/deployment.md`](docs/deployment.md) | Productie-deployment op DigitalOcean |
| [`docs/backups.md`](docs/backups.md) | Database-backups naar DigitalOcean Spaces |
| [`docs/data-sync.md`](docs/data-sync.md) | Database synchronisatie prod ↔ dev |
| [`docs/security.md`](docs/security.md) | Security audit-rapporten |
| [`ingest/institutio/README.md`](ingest/institutio/README.md) | Institutio-pijplijn (Latijn → Nederlands) |
