# Deployment: DigitalOcean Droplet (multi-app)

> Doel: de applicatie draaien op een DigitalOcean droplet naast andere applicaties,
> bereikbaar via een eigen domein, met automatische TLS via Let's Encrypt.

---

## Inhoudsopgave

1. [Architectuurkeuze: multi-app op één droplet](#1-architectuurkeuze)
2. [Productie-gereedheid: wat ontbreekt nog](#2-productie-gereedheid)
3. [Stap-voor-stap deployment](#3-stap-voor-stap-deployment)
4. [Omgevingsvariabelen](#4-omgevingsvariabelen)
5. [Updates deployen](#5-updates-deployen)
6. [Beveiliging checklist](#6-beveiliging-checklist)

---

## 1. Architectuurkeuze

### Probleem: FrankenPHP/Caddy bezet poort 80 en 443

De huidige `docker-compose.yml` laat FrankenPHP direct op poorten 80 en 443 luisteren. Op een droplet met meerdere applicaties kan maar één proces poort 80/443 innemen. Er zijn twee oplossingen:

### Optie A — Caddy als centrale reverse proxy (aanbevolen)

Eén globale Caddy-instantie op de droplet vangt al het verkeer op poorten 80/443 op en stuurt door naar de juiste applicatie op een intern poort. Let's Encrypt wordt door déze Caddy afgehandeld.

```
Internet → Caddy (host, :80/:443)
              ├── translatorstags.nl  → bible_app container (:8080)
              └── andere-app.nl       → andere container (:8081)
```

**Voordeel:** één TLS-beheerder, eenvoudig uit te breiden met nieuwe apps.

### Optie B — Nginx/Caddy reverse proxy per app (eenvoudiger te starten)

Elke app-container luistert op een intern poort; Nginx op de host proxiet op basis van `Host`-header.

**Nadeel:** minder schaalbaar, twee lagen configuratie.

**→ Dit document volgt Optie A.**

---

## 2. Productie-gereedheid: wat ontbreekt nog

### Kritiek (moet vóór go-live)

| # | Probleem | Bestand | Actie |
|---|---|---|---|
| 1 | **Poort 5432 (PostgreSQL) publiek exposed** | `docker-compose.yml` r.14 | Verwijder `ports: - "5432:5432"` — alleen intern netwerk nodig |
| 2 | **Adminer publiek exposed op :8081** | `docker-compose.yml` r.50-52 | Verwijder of zet achter authenticatie / VPN |
| 3 | **Standaard wachtwoorden** in `.env` (`changeme`) | `.env` | Vervangen door sterke secrets (zie §4) |
| 4 | **`APP_SECRET` is niet uniek** | `.env` | Genereer: `openssl rand -hex 32` |
| 5 | **`REMEMBER_ME_SECRET` ontbreekt in prod** | `docker-compose.yml` | Voeg toe als secret (zie §4) |
| 6 | **Geen database-backups** | — | Stel automatische pg_dump-cron in |
| 7 | **Poorten 80/443 conflict** bij multi-app | `docker-compose.yml` | Aanpak uit §3 volgen |

### Hoog (sterk aanbevolen)

| # | Probleem | Actie |
|---|---|---|
| 8 | **Geen health-check op app-container** | Voeg `healthcheck` toe (zie §3) |
| 9 | **Geen log-rotatie** | Stel Docker log driver in met `max-size` |
| 10 | **Geen SMTP-configuratie** | Stel `MAILER_DSN` in als de app e-mail verstuurt |

### Medium

| # | Probleem | Actie |
|---|---|---|
| 11 | **Geen rate limiting** op login-endpoint | Symfony RateLimiter of Caddy `rate_limit` |
| 12 | **`APP_ENV=dev` in de container** (de cache:clear zei "dev environment") | Controleer of `APP_ENV=prod` correct doorgegeven wordt |

---

## 3. Stap-voor-stap deployment

### 3.1 Droplet aanmaken

1. Maak een DigitalOcean droplet aan via **Marketplace → Docker** (Ubuntu 22.04 + Docker voorgeïnstalleerd).  
   Minimale grootte: **2 GB RAM / 1 vCPU** (4 GB aanbevolen voor meerdere apps).
2. Stel SSH-toegang in met een key pair.
3. Wijs een **domeinnaam** toe: maak een DNS A-record aan dat naar het IP van de droplet wijst.  
   Let's Encrypt vereist een echte domeinnaam — bare IP-adressen worden niet ondersteund.

### 3.2 Droplet inrichten

```bash
# Aanmelden
ssh root@<droplet-ip>

# Centrale mappen aanmaken
mkdir -p /srv/caddy/{data,config}
mkdir -p /translatorstags
```

### 3.3 Centrale Caddy reverse proxy

Maak `/srv/caddy/docker-compose.yml`:

```yaml
services:
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"   # HTTP/3
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - caddy_net

volumes:
  caddy_data:
  caddy_config:

networks:
  caddy_net:
    external: true
```

Maak `/srv/caddy/Caddyfile`:

```caddy
translatorstags.nl, www.translatorstags.nl {
    reverse_proxy bible_app:80
}

# Andere apps komen hier:
# andere-app.nl {
#     reverse_proxy andere_app:80
# }
```

Maak het gedeelde netwerk en start Caddy:

```bash
docker network create caddy_net
cd /srv/caddy && docker compose up -d
```

### 3.4 Applicatie deployen naar `/translatorstags/`

```bash
cd /translatorstags

# Code ophalen
git clone <repo-url> .
# Of via SCP/rsync als de repo privé is zonder deploy key

# Productie-omgevingsvariabelen instellen
cp .env .env.local  # nooit .env aanpassen op de server
nano .env.local     # zie §4 voor de waarden
```

Pas `docker-compose.yml` aan voor de multi-app setup — **verwijder de conflicterende poorten** en **verbind met het gedeelde Caddy-netwerk**:

```yaml
services:
  postgres:
    # ... bestaande config ...
    ports: []          # ← verwijder de publieke 5432-binding

  app:
    # ... bestaande config ...
    container_name: bible_app
    ports: []          # ← geen directe 80/443 binding meer; Caddy proxiet
    networks:
      - bible_net
      - caddy_net      # ← toevoegen zodat Caddy de container kan bereiken
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 40s

  adminer:
    # Verwijder in productie, of bind alleen aan localhost:
    ports:
      - "127.0.0.1:8081:8080"   # alleen bereikbaar via SSH-tunnel

networks:
  bible_net:
    driver: bridge
  caddy_net:
    external: true      # ← het gedeelde netwerk van de centrale Caddy
```

Image bouwen en starten:

```bash
cd /translatorstags
docker compose build --pull --no-cache
docker compose up -d
```

Controleer of de app draait:

```bash
docker compose ps
docker compose logs app --tail=50
```

### 3.5 TLS verifiëren

Caddy vraagt automatisch een Let's Encrypt-certificaat aan zodra het eerste verzoek binnenkomt op de domeinnaam. Controleer:

```bash
curl -I https://translatorstags.nl
# Verwacht: HTTP/2 200
```

---

## 4. Omgevingsvariabelen

Maak `/translatorstags/.env.local` aan (nooit committen):

```dotenv
# Database
DB_NAME=bible_compare
DB_USER=bible
DB_PASSWORD=<sterk-wachtwoord>

# Symfony
APP_ENV=prod
APP_SECRET=<openssl rand -hex 32>
REMEMBER_ME_SECRET=<openssl rand -hex 32>

# Caddy/FrankenPHP — intern; de reverse proxy doet de domeinnaam
SERVER_NAME=:80
```

> **Let op:** `SERVER_NAME=:80` zorgt dat FrankenPHP op intern poort 80 luistert
> zonder zelf TLS te proberen. De centrale Caddy buiten de container beheert TLS.

Genereer secrets:

```bash
openssl rand -hex 32   # voor APP_SECRET
openssl rand -hex 32   # voor DB_PASSWORD
openssl rand -hex 32   # voor REMEMBER_ME_SECRET
```

---

## 5. Updates deployen

```bash
cd /translatorstags

# Nieuwe code ophalen
git pull

# Image herbouwen (--no-cache voor verse composer install)
docker compose build --pull --no-cache

# Vervangen met zero-downtime (Compose start nieuwe container vóór stop)
docker compose up -d --remove-orphans

# Migaties draaien (indien van toepassing)
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Controleer
docker compose ps
docker compose logs app --tail=20
```

### Automatisch deployen (optioneel)

Voeg een GitHub Actions workflow toe (`.github/workflows/deploy.yml`) die bij een push naar `main` via SSH inlogt en bovenstaande commando's uitvoert. Gebruik een **deploy key** of **SSH secret** in de repo-instellingen.

---

## 6. Beveiliging checklist

- [ ] Poort 5432 (PostgreSQL) niet publiek exposed
- [ ] Adminer niet publiek exposed (of verwijderd)
- [ ] `APP_SECRET` uniek en minimaal 32 bytes
- [ ] `DB_PASSWORD` sterk en uniek
- [ ] `REMEMBER_ME_SECRET` ingesteld
- [ ] `.env.local` staat in `.gitignore` (controleer: `git check-ignore .env.local`)
- [ ] SSH-toegang alleen via key (wachtwoord-login uitgeschakeld in `/etc/ssh/sshd_config`)
- [ ] UFW firewall: alleen poorten 22, 80, 443 open
- [ ] Automatische database-backups (cron + `pg_dump`)
- [ ] `APP_ENV=prod` in alle containers actief
- [ ] Log-rotatie geconfigureerd (`/etc/docker/daemon.json`)

### UFW instellen

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp   # HTTP/3
ufw enable
```

### Log-rotatie (`/etc/docker/daemon.json`)

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
```

---

## Referenties

- [symfony-docker production docs](https://github.com/dunglas/symfony-docker/blob/main/docs/production.md)
- [FrankenPHP documentatie](https://frankenphp.dev)
- [Caddy reverse proxy docs](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy)
- [DigitalOcean Docker Marketplace](https://marketplace.digitalocean.com/apps/docker)
