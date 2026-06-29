#!/usr/bin/env bash
# Uploadt de meest recente dagelijkse backup naar DigitalOcean Spaces.
# Draai dit via cron na de nachtelijke pg_dump, bijv.:
#   15 3 * * * /translatorstags/scripts/backup-to-spaces.sh >> /var/log/backup-spaces.log 2>&1

set -euo pipefail

# ── Configuratie (via omgevingsvariabelen of .env.backup) ────────────────────
ENV_FILE="$(dirname "$0")/../.env.backup"
if [[ -f "$ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$ENV_FILE"
fi

SPACES_BUCKET="${SPACES_BUCKET:?Stel SPACES_BUCKET in}"
SPACES_REGION="${SPACES_REGION:-ams3}"
SPACES_KEY="${SPACES_KEY:?Stel SPACES_KEY in}"
SPACES_SECRET="${SPACES_SECRET:?Stel SPACES_SECRET in}"
BACKUP_DIR="${BACKUP_DIR:-/var/lib/docker/volumes/translatorstags_backup_data/_data/daily}"
SPACES_PATH="${SPACES_PATH:-bible-backups}"
RETENTION_DAYS="${SPACES_RETENTION_DAYS:-30}"

ENDPOINT="https://${SPACES_REGION}.digitaloceanspaces.com"

# ── Nieuwste dagelijkse backup vinden ────────────────────────────────────────
LATEST=$(find "$BACKUP_DIR" -name "*.sql.gz" -type f -printf '%T@ %p\n' \
    | sort -n | tail -1 | cut -d' ' -f2-)

if [[ -z "$LATEST" ]]; then
    echo "[$(date -u +%FT%TZ)] FOUT: Geen backup gevonden in $BACKUP_DIR"
    exit 1
fi

FILENAME=$(basename "$LATEST")
DATE=$(date -u +%Y-%m-%d)

echo "[$(date -u +%FT%TZ)] Upload $FILENAME naar Spaces..."

# ── Upload via AWS CLI (compatibel met DO Spaces) ────────────────────────────
AWS_ACCESS_KEY_ID="$SPACES_KEY" \
AWS_SECRET_ACCESS_KEY="$SPACES_SECRET" \
aws s3 cp "$LATEST" \
    "s3://${SPACES_BUCKET}/${SPACES_PATH}/${DATE}/${FILENAME}" \
    --endpoint-url "$ENDPOINT" \
    --no-progress

echo "[$(date -u +%FT%TZ)] Upload geslaagd: s3://${SPACES_BUCKET}/${SPACES_PATH}/${DATE}/${FILENAME}"

# ── Oude bestanden opruimen (ouder dan RETENTION_DAYS dagen) ─────────────────
CUTOFF=$(date -u -d "-${RETENTION_DAYS} days" +%Y-%m-%d 2>/dev/null \
    || date -u -v "-${RETENTION_DAYS}d" +%Y-%m-%d)  # macOS fallback

echo "[$(date -u +%FT%TZ)] Ruim Spaces-bestanden op ouder dan $RETENTION_DAYS dagen..."

AWS_ACCESS_KEY_ID="$SPACES_KEY" \
AWS_SECRET_ACCESS_KEY="$SPACES_SECRET" \
aws s3 ls "s3://${SPACES_BUCKET}/${SPACES_PATH}/" \
    --endpoint-url "$ENDPOINT" \
    --recursive \
    | awk '{print $4}' \
    | while read -r KEY; do
        KEYDATE=$(echo "$KEY" | grep -oP '\d{4}-\d{2}-\d{2}' | head -1 || true)
        if [[ -n "$KEYDATE" && "$KEYDATE" < "$CUTOFF" ]]; then
            echo "  Verwijder: $KEY"
            AWS_ACCESS_KEY_ID="$SPACES_KEY" \
            AWS_SECRET_ACCESS_KEY="$SPACES_SECRET" \
            aws s3 rm "s3://${SPACES_BUCKET}/${KEY}" \
                --endpoint-url "$ENDPOINT"
        fi
    done

echo "[$(date -u +%FT%TZ)] Klaar."
