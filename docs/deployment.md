# Deployment: DigitalOcean Droplet (multi-app)

> Doel: de applicatie draaien op een DigitalOcean droplet naast andere applicaties,
> bereikbaar via een eigen domein, met automatische TLS via Let's Encrypt.

---

## Inhoudsopgave

1. [Architectuurkeuze: multi-app op één droplet](#1-architectuurkeuze)
2. [Productie-gereedheid](#2-productie-gereedheid)
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
              ├── translatorstags.nl  → bible_app container (:80 intern)
              └── andere-app.nl       → andere container (:80 intern)
```

**Voordeel:** één TLS-beheerder, eenvoudig uit te breiden met nieuwe apps.

**→ Dit document volgt Optie A.**

---

## 2. Productie-gereedheid

Legenda: ✅ gereed · ⚠️ deels · ❌ nog te doen

### Kritiek (moet vóór go-live)

| Status | # | Punt | Bestand | Actie |
|---|---|---|---|---|
| ❌ | 1 | **Poort 5432 (PostgreSQL) publiek exposed** | `docker-compose.yml` r.22 | Verwijder `ports: - "5432:5432"` — alleen intern netwerk nodig in productie |
| ❌ | 2 | **Adminer publiek exposed op :8081** | `docker-compose.yml` r.69-75 | Verwijder in productie, of bind aan `127.0.0.1:8081` (SSH-tunnel) |
| ❌ | 3 | **Standaard wachtwoorden in `.env`** (`changeme`) | `.env` r.4,9 | Vervangen in `.env.local` op de server door sterke secrets (zie §4) |
| ❌ | 4 | **`APP_SECRET` is niet uniek** | `.env` r.9 | Genereer: `openssl rand -hex 32` |
| ⚠️ | 5 | **`REMEMBER_ME_SECRET` in docker-compose als fallback** | `docker-compose.yml` r.39 | Staat nu als `change_me_in_production_32chars`; stel in via `.env.local` |
| ✅ | 6 | **Automatische database-backups** | `docker-compose.yml` r.77-97 | Geïmplementeerd via `backup`-service + `scripts/backup-to-spaces.sh` |
| ❌ | 7 | **Poorten 80/443 conflict bij multi-app** | `docker-compose.yml` r.32-34 | Aanpak uit §3 volgen (centrale Caddy, ports verwijderen) |

### Hoog (sterk aanbevolen)

| Status | # | Punt | Actie |
|---|---|---|---|
| ❌ | 8 | **Geen health-check op app-container** | Voeg `healthcheck` toe (zie §3.4) |
| ❌ | 9 | **Geen log-rotatie** | Stel Docker log driver in met `max-size` (zie §6) |
| ❌ | 10 | **Offsite backup naar Spaces nog niet ingesteld** | `scripts/backup-to-spaces.sh` is klaar; cron en `.env.backup` moeten worden ingesteld op de droplet (zie `docs/backups.md`) |
| ⚠️ | 11 | **Geen SMTP-configuratie** | App verstuurt momenteel geen e-mail; instellen zodra dit nodig is via `MAILER_DSN` |

### Medium

| Status | # | Punt | Actie |
|---|---|---|---|
| ❌ | 12 | **Geen rate limiting op login-endpoint** | Symfony RateLimiter of Caddy `rate_limit`-directive |
| ⚠️ | 13 | **`APP_ENV` in container** | In `docker-compose.yml` staat `APP_ENV: prod` hardcoded (r.37) — correct. Controleer na deploy via `docker exec bible_app php bin/console about` |
| ❌ | 14 | **Geen GitHub Actions deploy-workflow** | Handmatig deployen via SSH; automatiseer met een workflow (zie §5) |

---

## 3. Stap-voor-stap deployment

### 3.1 Droplet aanmaken

1. Maak een DigitalOcean droplet aan via **Marketplace → Docker** (Ubuntu 22.04 + Docker voorgeïnstalleerd).
   Minimale grootte: **2 GB RAM / 1 vCPU** (4 GB aanbevolen voor meerdere apps).
2. Stel SSH-toegang in met een key pair. Schakel wachtwoord-login uit:
   ```bash
   sed -i 's/^PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
   systemctl reload sshd
   ```
3. Wijs een **domeinnaam** toe: maak een DNS A-record aan dat naar het IP van de droplet wijst.
   Let's Encrypt vereist een echte domeinnaam — bare IP-adressen worden niet ondersteund.

### 3.2 Droplet inrichten

```bash
ssh root@<droplet-ip>

# UFW firewall instellen (zie ook §6)
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp   # HTTP/3
ufw enable

# Log-rotatie instellen
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF
systemctl restart docker

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

# Code ophalen (gebruik een deploy key als de repo privé is)
git clone https://github.com/gerardzeeman/translators-tags.git .

# Productie-omgevingsvariabelen instellen
cp .env .env.local
nano .env.local   # zie §4 voor de waarden
```

Pas `docker-compose.yml` aan voor productie — de onderstaande wijzigingen zijn
**niet** in de repo doorgevoerd (ze zijn server-specifiek en horen in `.env.local`
of een lokale override):

```yaml
services:
  postgres:
    ports: []          # ← verwijder de publieke 5432-binding

  app:
    container_name: bible_app
    ports: []          # ← geen directe 80/443 binding; Caddy proxiet
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
    ports:
      - "127.0.0.1:8081:8080"   # alleen via SSH-tunnel bereikbaar

networks:
  bible_net:
    driver: bridge
  caddy_net:
    external: true
```

> **Tip:** gebruik `docker-compose.override.yml` voor server-specifieke overrides
> zodat de hoofd-`docker-compose.yml` ongewijzigd blijft en `git pull` nooit
> conflicten geeft.

Image bouwen en starten:

```bash
docker compose build --pull --no-cache
docker compose up -d
```

Controleer:

```bash
docker compose ps
docker compose logs app --tail=50
docker exec bible_app php bin/console about   # bevestig APP_ENV=prod
```

### 3.5 Backups activeren

Zie `docs/backups.md` voor de volledige setup. Kort samengevat:

```bash
cp .env.backup.example .env.backup
nano .env.backup   # Spaces-credentials invullen

chmod +x scripts/backup-to-spaces.sh

# Cron: dagelijks om 03:15
(crontab -l 2>/dev/null; echo "15 3 * * * /translatorstags/scripts/backup-to-spaces.sh >> /var/log/backup-spaces.log 2>&1") | crontab -
```

### 3.6 TLS verifiëren

Caddy vraagt automatisch een Let's Encrypt-certificaat aan zodra het eerste verzoek
binnenkomt. Controleer:

```bash
curl -I https://translatorstags.nl
# Verwacht: HTTP/2 200
```

---

## 4. Omgevingsvariabelen

Maak `/translatorstags/.env.local` aan (nooit committen — staat in `.gitignore`):

```dotenv
# Database
DB_NAME=bible_compare
DB_USER=bible
DB_PASSWORD=<genereer: openssl rand -hex 32>

# Symfony
APP_ENV=prod
APP_SECRET=<genereer: openssl rand -hex 32>
REMEMBER_ME_SECRET=<genereer: openssl rand -hex 32>

# FrankenPHP — intern; de reverse proxy doet TLS
SERVER_NAME=:80

# Backup-schema (optioneel, standaard @daily)
# BACKUP_SCHEDULE=@daily
# BACKUP_KEEP_DAYS=7
```

> **Let op:** `SERVER_NAME=:80` zorgt dat FrankenPHP op intern poort 80 luistert
> zonder zelf TLS te proberen. De centrale Caddy buiten de container beheert TLS.

Secrets genereren:

```bash
openssl rand -hex 32   # APP_SECRET
openssl rand -hex 32   # DB_PASSWORD
openssl rand -hex 32   # REMEMBER_ME_SECRET
```

---

## 5. Updates deployen

```bash
cd /translatorstags

# Nieuwe code ophalen
git pull

# Image herbouwen
docker compose build --pull --no-cache

# Herstarten (Compose start nieuwe container vóór stop)
docker compose up -d --remove-orphans

# Cache vernieuwen
docker exec bible_app php bin/console cache:clear
docker exec bible_app php bin/console asset-map:compile

# Controleer
docker compose ps
docker compose logs app --tail=20
```

### Automatisch deployen via GitHub Actions (optioneel)

Maak `.github/workflows/deploy.yml` aan:

```yaml
name: Deploy to production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.DROPLET_IP }}
          username: root
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            cd /translatorstags
            git pull
            docker compose build --pull --no-cache
            docker compose up -d --remove-orphans
            docker exec bible_app php bin/console cache:clear
            docker exec bible_app php bin/console asset-map:compile
```

Voeg `DROPLET_IP` en `SSH_PRIVATE_KEY` toe als GitHub repository secrets.

---

## 6. Beveiliging checklist

| Status | Punt |
|---|---|
| ❌ | Poort 5432 (PostgreSQL) niet publiek exposed — verwijder uit `docker-compose.yml` op de server |
| ❌ | Adminer niet publiek exposed — bind aan `127.0.0.1` of verwijder |
| ❌ | `APP_SECRET` uniek en minimaal 32 bytes — genereer via `openssl rand -hex 32` |
| ❌ | `DB_PASSWORD` sterk en uniek |
| ❌ | `REMEMBER_ME_SECRET` ingesteld |
| ✅ | `.env.local` en `.env.backup` staan in `.gitignore` |
| ❌ | SSH-toegang alleen via key — `PasswordAuthentication no` in `/etc/ssh/sshd_config` |
| ❌ | UFW firewall: alleen poorten 22, 80, 443 open |
| ✅ | Automatische lokale database-backups (`backup`-service in docker-compose) |
| ❌ | Offsite backup naar Spaces geconfigureerd en getest (zie `docs/backups.md`) |
| ❌ | `APP_ENV=prod` bevestigd na deploy (`docker exec bible_app php bin/console about`) |
| ❌ | Log-rotatie geconfigureerd in `/etc/docker/daemon.json` |
| ❌ | GitHub Actions deploy-workflow opgezet (optioneel maar aanbevolen) |

---

## Referenties

- [`docs/backups.md`](backups.md) — backup-setup en herstelstappen
- [`docs/data-sync.md`](data-sync.md) — prod↔dev database-synchronisatie
- [symfony-docker production docs](https://github.com/dunglas/symfony-docker/blob/main/docs/production.md)
- [FrankenPHP documentatie](https://frankenphp.dev)
- [Caddy reverse proxy docs](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy)
- [DigitalOcean Docker Marketplace](https://marketplace.digitalocean.com/apps/docker)
