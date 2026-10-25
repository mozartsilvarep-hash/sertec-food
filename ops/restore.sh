#!/bin/bash
# Restaura um dump do banco num banco de destino (por padrão, um banco NOVO).
#   bash ops/restore.sh /backups/db-20261025T063000Z.sql.gz[.enc] [banco_destino]
# Restaurar por cima do banco de produção é uma decisão consciente: pare app,
# worker e scheduler antes e informe o nome do banco de produção.
set -euo pipefail
FILE="$1"
TARGET="${2:-sertec_restore_$(date -u +%Y%m%d%H%M%S)}"

decrypt() {
  case "$FILE" in
    *.enc) openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$FILE" ;;
    *) cat "$FILE" ;;
  esac
}

echo "[restore] $FILE -> banco $TARGET"
mariadb --host="$DB_HOST" --user="${DB_ROOT_USER:-root}" --password="$DB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
decrypt | gunzip | mariadb --host="$DB_HOST" --user="${DB_ROOT_USER:-root}" --password="$DB_ROOT_PASSWORD" "$TARGET"
echo "[restore] ok: $TARGET"
