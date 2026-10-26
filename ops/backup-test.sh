#!/bin/bash
# Teste de restauração (DoD da Sprint 5): restaura o backup mais recente num
# banco temporário, confere tabelas e contagens contra produção e apaga o temporário.
#   docker compose -f docker-compose.prod.yml --env-file .env.production run --rm backup bash /ops/backup-test.sh
set -euo pipefail
LATEST=$(ls -1t "${BACKUP_DIR:-/backups}"/db-* 2>/dev/null | head -n1)
[ -n "$LATEST" ] || { echo "Nenhum backup em /backups" >&2; exit 1; }
TMP="sertec_restore_test"
trap 'mariadb --host="$DB_HOST" --user="${DB_ROOT_USER:-root}" --password="$DB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$TMP\`" >/dev/null 2>&1 || true' EXIT
bash "$(dirname "$0")/restore.sh" "$LATEST" "$TMP"

q() { mariadb --host="$DB_HOST" --user="${DB_ROOT_USER:-root}" --password="$DB_ROOT_PASSWORD" -N -B -e "$1"; }
FAIL=0
for t in tenants tenant_domains users orders order_items payments audit_logs; do
  restored=$(q "SELECT COUNT(*) FROM \`$TMP\`.\`$t\`")
  live=$(q "SELECT COUNT(*) FROM \`$DB_DATABASE\`.\`$t\`")
  echo "  $t: backup=$restored produção=$live"
  [ "$restored" -le "$live" ] || FAIL=1
done
MIG=$(q "SELECT COUNT(*) FROM \`$TMP\`.migrations")
echo "  migrations: $MIG"
q "DROP DATABASE \`$TMP\`"
[ "$FAIL" = 0 ] && [ "$MIG" -gt 0 ] && echo "[backup-test] OK: $LATEST restaurável" || { echo "[backup-test] FALHOU" >&2; exit 1; }
