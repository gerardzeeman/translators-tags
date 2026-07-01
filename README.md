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

Maak een bestand `app/.env.local` aan (wordt nooit gecommit):

```dotenv
APP_ENV=dev
APP_SECRET=<genereer: openssl rand -hex 32>
REMEMBER_ME_SECRET=<genereer: openssl rand -hex 32>
DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8"
```

Voor lokaal gebruik zijn de standaardwaarden in `app/.env` voldoende als startpunt.

### 3. PHP-dependencies installeren

```powershell
cd app
docker compose build app
docker compose run --rm app composer install
```

### 4. Stack starten

```powershell
docker compose up -d
```

Dit start de PostgreSQL-database en de FrankenPHP-webserver. Controleer of beide containers actief zijn:

```powershell
docker compose ps
```

### 5. Applicatie openen

Ga naar **https://localhost** in je browser. FrankenPHP gebruikt een zelf-ondertekend certificaat voor localhost — klik de beveiligingswaarschuwing weg via "Geavanceerd → Toch doorgaan".

### 6. Database vullen (ingest pipeline)

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

# Database bekijken (DBeaver / TablePlus op localhost:5432, db: app, user: app)
docker compose exec database psql -U app -d app

# Stack stoppen (data blijft bewaard in Docker volume)
docker compose down

# Stack + data volledig resetten
docker compose down -v && docker compose up -d
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
