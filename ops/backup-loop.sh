#!/bin/sh
# Executa ops/backup.sh todo dia às 06:30 UTC (03:30 em Maceió).
set -u
if [ -n "${BACKUP_PASSPHRASE:-}" ] && ! command -v openssl >/dev/null 2>&1; then
  apt-get update -qq && apt-get install -y -qq --no-install-recommends openssl >/dev/null
fi
while true; do
  now=$(date -u +%s)
  next=$(date -u -d "$(date -u +%Y-%m-%d) 06:30" +%s)
  [ "$next" -le "$now" ] && next=$((next + 86400))
  sleep $((next - now))
  bash /ops/backup.sh || echo "[backup] FALHOU $(date -u)" >&2
done
