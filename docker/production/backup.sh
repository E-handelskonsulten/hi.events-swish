#!/bin/sh
# Daily PostgreSQL dump for the Biljettera production stack. Installed in the deploy user's crontab.
set -eu

BASE_DIR="${BILJETTERA_DIR:-/srv/biljettera}"
BACKUP_DIR="${BILJETTERA_BACKUP_DIR:-$BASE_DIR/backups}"
KEEP_DAYS="${BILJETTERA_BACKUP_KEEP_DAYS:-14}"
COMPOSE="docker compose --env-file $BASE_DIR/.env -f $BASE_DIR/app/docker/production/docker-compose.yml"

. "$BASE_DIR/.env"

mkdir -p "$BACKUP_DIR"
target="$BACKUP_DIR/hievents-$(date +%Y-%m-%d_%H%M).sql.gz"

$COMPOSE exec -T postgres pg_dump -U "${POSTGRES_USER:-hievents}" "${POSTGRES_DB:-hievents}" | gzip > "$target.part"
mv "$target.part" "$target"
chmod 600 "$target"

find "$BACKUP_DIR" -name 'hievents-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

echo "backup written: $target ($(du -h "$target" | cut -f1))"
