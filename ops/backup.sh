#!/bin/bash
# Backup do SerTec Food: banco (dump consistente) + fotos do catálogo.
# Uso (container "backup" do docker-compose.prod.yml ou manual):
#   DB_HOST=... DB_USERNAME=... DB_PASSWORD=... DB_DATABASE=... bash ops/backup.sh
# Variáveis: BACKUP_DIR (/backups), BACKUP_KEEP_DAYS (14), BACKUP_PASSPHRASE (opcional:
# criptografa com AES-256 via openssl — guarde a senha fora do servidor!).
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
STORAGE_DIR="${STORAGE_DIR:-/data/storage}"
mkdir -p "$BACKUP_DIR"

encrypt() {
  if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
    command -v openssl >/dev/null 2>&1 || { echo "BACKUP_PASSPHRASE definida mas openssl ausente" >&2; exit 1; }
    openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE
  else
    cat
  fi
}
ext() { [ -n "${BACKUP_PASSPHRASE:-}" ] && echo ".enc" || echo ""; }

DB_FILE="$BACKUP_DIR/db-$STAMP.sql.gz$(ext)"
echo "[backup] banco -> $DB_FILE"
mariadb-dump --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
  --single-transaction --quick --routines --triggers --hex-blob --default-character-set=utf8mb4 \
  "$DB_DATABASE" | gzip -9 | encrypt > "$DB_FILE.part"
mv "$DB_FILE.part" "$DB_FILE"

if [ -d "$STORAGE_DIR" ]; then
  FILES_FILE="$BACKUP_DIR/files-$STAMP.tar.gz$(ext)"
  echo "[backup] fotos -> $FILES_FILE"
  tar -C "$STORAGE_DIR" -czf - . | encrypt > "$FILES_FILE.part"
  mv "$FILES_FILE.part" "$FILES_FILE"
fi

# Retenção local. Copie também para fora do servidor (docs/OPERATIONS.md).
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*' -o -name 'files-*' \) -mtime "+$KEEP" -print -delete
echo "[backup] ok $STAMP"
