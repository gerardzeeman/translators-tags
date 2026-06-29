# Database backups

## Architectuur

Twee lagen:

| Laag | Wat | Retentie |
|---|---|---|
| **Lokaal** | Docker volume op de droplet | 7 dagen dagelijks, 4 weken wekelijks, 6 maanden maandelijks |
| **Offsite** | DigitalOcean Spaces (S3) | 30 dagen |

De lokale laag beschermt tegen dataverlies door een slechte deploy of een fout in de applicatie.
De offsite laag beschermt als de droplet zelf verloren gaat.

---

## Lokale backups — `backup`-service

De `backup`-service in `docker-compose.yml` gebruikt het image
`prodrigestivill/postgres-backup-local`. Het draait automatisch op schema,
maakt gecomprimeerde `.sql.gz`-dumps en roteert oude bestanden.

### Instellingen (via `.env`)

```dotenv
BACKUP_SCHEDULE=@daily        # cron-formaat of @daily/@hourly
BACKUP_KEEP_DAYS=7            # dagelijkse backups bewaren
BACKUP_KEEP_WEEKS=4           # wekelijkse backups bewaren
BACKUP_KEEP_MONTHS=6          # maandelijkse backups bewaren
```

### Backups inzien

```bash
# Lijst alle backups
docker exec bible_backup ls -lh /backups/daily/
docker exec bible_backup ls -lh /backups/weekly/
docker exec bible_backup ls -lh /backups/monthly/

# Of via het host-volume
ls -lh /var/lib/docker/volumes/translatorstags_backup_data/_data/
```

### Handmatige backup triggeren

```bash
docker exec bible_backup /backup.sh
```

---

## Offsite backups — DigitalOcean Spaces

### Eenmalige setup

**1. Spaces-bucket aanmaken**

Ga naar DigitalOcean → Spaces → Create Space.
Kies regio `ams3` (Amsterdam, dichtst bij NL).
Noteer de bucket-naam.

**2. Access keys aanmaken**

Ga naar DigitalOcean → API → Spaces access keys → Generate New Key.
Noteer de key en het secret.

**3. AWS CLI installeren op de droplet**

```bash
apt-get install -y awscli
```

**4. Configuratiebestand aanmaken**

```bash
cp .env.backup.example .env.backup
nano .env.backup   # vul SPACES_BUCKET, SPACES_KEY, SPACES_SECRET in
```

**5. Script uitvoerbaar maken**

```bash
chmod +x /translatorstags/scripts/backup-to-spaces.sh
```

**6. Handmatige test**

```bash
/translatorstags/scripts/backup-to-spaces.sh
```

**7. Cron instellen**

```bash
crontab -e
```

Voeg toe (dagelijks om 03:15, nadat de lokale backup om 03:00 klaar is):

```
15 3 * * * /translatorstags/scripts/backup-to-spaces.sh >> /var/log/backup-spaces.log 2>&1
```

---

## Herstel

### Herstel van lokale backup

```bash
# Identificeer de gewenste backup
docker exec bible_backup ls /backups/daily/

# Herstel (vervangt de huidige database!)
docker exec -i bible_postgres psql -U bible bible_compare \
    < <(docker exec bible_backup zcat /backups/daily/<bestandsnaam>.sql.gz)
```

### Herstel van Spaces-backup

```bash
# Download van Spaces
AWS_ACCESS_KEY_ID=<key> AWS_SECRET_ACCESS_KEY=<secret> \
aws s3 cp s3://<bucket>/bible-backups/<datum>/<bestand>.sql.gz /tmp/restore.sql.gz \
    --endpoint-url https://ams3.digitaloceanspaces.com

# Herstel
zcat /tmp/restore.sql.gz | docker exec -i bible_postgres psql -U bible bible_compare
```

---

## Monitoring

De backup-container luistert op poort 8080 voor health checks.
Als de laatste backup mislukt is, geeft de health check een fout terug.

```bash
# Status opvragen
curl http://localhost:8080/

# Of via Docker
docker inspect --format='{{.State.Health.Status}}' bible_backup
```

Overweeg een cron-job die de health controleert en een e-mail stuurt bij falen:

```bash
# /etc/cron.d/backup-monitor
0 6 * * * root docker inspect --format='{{.State.Health.Status}}' bible_backup | grep -q healthy || mail -s "BACKUP FAILED" gerardzeeman1@gmail.com < /dev/null
```
