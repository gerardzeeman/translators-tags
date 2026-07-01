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
7. [Server-taken die nog open staan](#7-server-taken-die-nog-open-staan)

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
| ✅ | 1 | **Poort 5432 (PostgreSQL) publiek exposed** | `docker-compose.yml` | Verwijderd — alleen beschikbaar via `docker-compose.override.yml` in dev |
| ✅ | 2 | **Adminer publiek exposed op :8081** | `docker-compose.yml` | Verwijderd uit basis-compose; prod bindt aan `127.0.0.1` via `docker-compose.prod.yml` |
| ❌ | 3 | **Standaard wachtwoorden in `.env`** (`changeme`) | `.env` r.4,9 | Vervangen in `.env.local` op de server door sterke secrets (zie §4) |
| ❌ | 4 | **`APP_SECRET` is niet uniek** | `.env` r.9 | Genereer: `openssl rand -hex 32` |
| ⚠️ | 5 | **`REMEMBER_ME_SECRET` in docker-compose als fallback** | `docker-compose.yml` r.39 | Staat nu als `change_me_in_production_32chars`; stel in via `.env.local` |
| ✅ | 6 | **Automatische database-backups** | `docker-compose.yml` r.77-97 | Geïmplementeerd via `backup`-service + `scripts/backup-to-spaces.sh` |
| ✅ | 7 | **Poorten 80/443 conflict bij multi-app** | `docker-compose.yml` | Verwijderd uit basis-compose; `docker-compose.prod.yml` voegt `caddy_net` toe |

### Hoog (sterk aanbevolen)

| Status | # | Punt | Actie |
|---|---|---|---|
| ✅ | 8 | **Geen health-check op app-container** | Toegevoegd in `docker-compose.yml` — curl op `/up` elke 30s |
| ❌ | 9 | **Geen log-rotatie** | Stel Docker log driver in met `max-size` (zie §6) |
| ❌ | 10 | **Offsite backup naar Spaces nog niet ingesteld** | `scripts/backup-to-spaces.sh` is klaar; cron en `.env.backup` moeten worden ingesteld op de droplet (zie `docs/backups.md`) |
| ⚠️ | 11 | **Geen SMTP-configuratie** | App verstuurt momenteel geen e-mail; instellen zodra dit nodig is via `MAILER_DSN` |

### Medium

| Status | # | Punt | Actie |
|---|---|---|---|
| ✅ | 12 | **Rate limiting op login-endpoint** | Geconfigureerd via `login_throttling` in `security.yaml` (5 pogingen per 15 min) |
| ⚠️ | 13 | **`APP_ENV` in container** | In `docker-compose.yml` staat `APP_ENV: prod` hardcoded (r.37) — correct. Controleer na deploy via `docker exec bible_app php bin/console about` |
| ✅ | 14 | **Geen GitHub Actions deploy-workflow** | Aangemaakt in `.github/workflows/deploy.yml` — deploy bij push op `main` |

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

De productie-overrides (caddy_net, adminer SSH-only) staan in `docker-compose.prod.yml` in de repo.
Geen handmatige aanpassingen nodig op de server.

Image bouwen en starten:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build --pull --no-cache
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Controleer:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs app --tail=50
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
docker compose -f docker-compose.yml -f docker-compose.prod.yml build --pull --no-cache

# Herstarten (Compose start nieuwe container vóór stop)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --remove-orphans

# Cache vernieuwen
docker exec bible_app php bin/console cache:clear
docker exec bible_app php bin/console asset-map:compile

# Controleer
docker compose ps
docker compose logs app --tail=20
```

### Automatisch deployen via GitHub Actions

De workflow staat al in `.github/workflows/deploy.yml` en deployt automatisch bij elke push op `main`.

Voeg de volgende secrets toe in de GitHub repository (Settings → Secrets → Actions):

| Secret | Waarde |
|---|---|
| `DROPLET_IP` | IP-adres van de droplet |
| `SSH_PRIVATE_KEY` | Inhoud van de private SSH-key die toegang heeft tot de droplet |

---

## 6. Beveiliging checklist

| Status | Punt |
|---|---|
| ✅ | Poort 5432 (PostgreSQL) niet publiek exposed — verwijderd uit `docker-compose.yml` |
| ✅ | Adminer niet publiek exposed — bind aan `127.0.0.1` via `docker-compose.prod.yml` |
| ❌ | `APP_SECRET` uniek en minimaal 32 bytes — genereer via `openssl rand -hex 32` |
| ❌ | `DB_PASSWORD` sterk en uniek |
| ❌ | `REMEMBER_ME_SECRET` ingesteld |
| ✅ | `.env.local`, `.env.dev` en `.env.backup` staan in `.gitignore` |
| ❌ | SSH-toegang alleen via key — `PasswordAuthentication no` in `/etc/ssh/sshd_config` |
| ❌ | UFW firewall: alleen poorten 22, 80, 443 open |
| ✅ | Automatische lokale database-backups (`backup`-service in docker-compose) |
| ❌ | Offsite backup naar Spaces geconfigureerd en getest (zie `docs/backups.md`) |
| ❌ | `APP_ENV=prod` bevestigd na deploy (`docker exec bible_app php bin/console about`) |
| ❌ | Log-rotatie geconfigureerd in `/etc/docker/daemon.json` |
| ✅ | GitHub Actions deploy-workflow opgezet — `.github/workflows/deploy.yml` |

---

## 7. Server-taken die nog open staan

Per ❌-punt uit §2: wat doe je precies op de server.

---

### 7.1 Sterke secrets instellen (punten 3, 4, 5)

```bash
ssh root@<droplet-ip>
cd /translatorstags

# Genereer drie unieke secrets
APP_SECRET=$(openssl rand -hex 32)
DB_PASSWORD=$(openssl rand -hex 32)
REMEMBER_ME_SECRET=$(openssl rand -hex 32)

# Schrijf .env.local
cat > .env.local <<EOF
DB_NAME=bible_compare
DB_USER=bible
DB_PASSWORD=${DB_PASSWORD}

APP_ENV=prod
APP_SECRET=${APP_SECRET}
REMEMBER_ME_SECRET=${REMEMBER_ME_SECRET}

SERVER_NAME=:80
EOF

# Controleer dat het bestand er goed uitziet
cat .env.local
```

> `.env.local` staat in `.gitignore` en wordt nooit gecommit.

Herstart de app zodat de nieuwe waarden actief worden:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d app
```

---

### 7.2 SSH: wachtwoord-login uitschakelen

```bash
# Controleer eerst of je SSH-key al werkt in een tweede terminal!
ssh root@<droplet-ip> "echo ok"

# Schakel wachtwoord-login uit
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config

# Controleer de instelling
grep PasswordAuthentication /etc/ssh/sshd_config

# Activeer
systemctl reload sshd
```

---

### 7.3 UFW firewall instellen

```bash
# Sta SSH, HTTP en HTTPS toe
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp   # HTTP/3 (optioneel)

# Activeer (bevestig met 'y')
ufw enable

# Controleer
ufw status verbose
```

> **Let op:** doe dit ná het controleren van SSH-toegang (7.2), anders sluit je jezelf buitenshuis.

---

### 7.4 Log-rotatie instellen (punt 9)

```bash
# Stel maximale log-grootte in voor alle Docker-containers
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF

systemctl restart docker

# Herstart de app-containers zodat ze de nieuwe log-driver gebruiken
cd /translatorstags
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --remove-orphans
```

---

### 7.5 GitHub Actions secrets instellen (voor automatisch deployen)

1. Genereer een SSH-key pair voor de deploy-workflow (als je er nog geen hebt):
   ```bash
   # Op je eigen pc (niet de server)
   ssh-keygen -t ed25519 -f ~/.ssh/translatorstags_deploy -C "github-actions-deploy"
   ```

2. Voeg de publieke key toe aan de server:
   ```bash
   ssh root@<droplet-ip> "echo '$(cat ~/.ssh/translatorstags_deploy.pub)' >> ~/.ssh/authorized_keys"
   ```

3. Voeg de secrets toe in GitHub:
   - Ga naar [github.com/gerardzeeman/translators-tags](https://github.com/gerardzeeman/translators-tags) → **Settings → Secrets and variables → Actions → New repository secret**

   | Secret | Waarde |
   |---|---|
   | `DROPLET_IP` | IP-adres van de droplet |
   | `DROPLET_USER` | Gebruikersnaam op de droplet (bv. `deploy`, niet `root`) |
   | `SSH_PRIVATE_KEY` | Inhoud van `~/.ssh/translatorstags_deploy` (de **private** key) |

4. Test de workflow: push een commit op `main` en kijk bij **Actions** of de deploy slaagt.

---

### 7.6 Offsite backup naar DigitalOcean Spaces instellen (punt 10)

Zie [`docs/backups.md`](backups.md) voor de volledige setup. Kort samengevat:

```bash
ssh root@<droplet-ip>
cd /translatorstags

# Configuratiebestand aanmaken
cp .env.backup.example .env.backup
nano .env.backup
# Vul in:
#   SPACES_BUCKET=<naam-van-je-bucket>
#   SPACES_REGION=ams3
#   SPACES_KEY=<access-key>
#   SPACES_SECRET=<secret>
#   SPACES_PATH=bible-backups
#   SPACES_RETENTION_DAYS=30

# AWS CLI installeren (als dat er nog niet op staat)
apt-get install -y awscli

# Script uitvoerbaar maken en handmatig testen
chmod +x scripts/backup-to-spaces.sh
./scripts/backup-to-spaces.sh

# Cron instellen: dagelijks om 03:15
(crontab -l 2>/dev/null; echo "15 3 * * * /translatorstags/scripts/backup-to-spaces.sh >> /var/log/backup-spaces.log 2>&1") | crontab -

# Controleer
crontab -l
```

---

### 7.7 Rate limiting op login-endpoint (punt 12) — ✅ Geïmplementeerd

Rate limiting is geconfigureerd via `login_throttling` in `app/config/packages/security.yaml`:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
```

Na 5 mislukte pogingen per 15 minuten wordt het loginformulier geblokkeerd. Geen verdere actie vereist.

---

## Referenties

- [`docs/backups.md`](backups.md) — backup-setup en herstelstappen
- [`docs/data-sync.md`](data-sync.md) — prod↔dev database-synchronisatie
- [symfony-docker production docs](https://github.com/dunglas/symfony-docker/blob/main/docs/production.md)
- [FrankenPHP documentatie](https://frankenphp.dev)
- [Caddy reverse proxy docs](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy)
- [DigitalOcean Docker Marketplace](https://marketplace.digitalocean.com/apps/docker)
