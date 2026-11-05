#!/usr/bin/env bash
# Atualiza o SerTec Food na VPS com um comando só:
#
#   sudo bash /opt/sertec-food/ops/deploy.sh
#
# Faz, nesta ordem, e para no primeiro erro:
#   1. backup do banco e das fotos (antes de qualquer mudança);
#   2. baixa a versão nova do GitHub (só avança: nunca apaga mudança local);
#   3. compila as imagens com as correções de segurança mais novas (--pull);
#   4. sobe os containers novos (o cardápio fica fora por segundos; quem
#      acessar nesse meio tempo vê "Voltamos em instantes");
#   5. roda as migrations;
#   6. confere a saúde (banco, cache, fila, agendador) e o /up pela borda.
#
# Variáveis opcionais:
#   VERSAO=<commit>  instala uma versão específica (ex.: voltar para a anterior);
#   BRANCH=main      ramo a acompanhar (padrão main);
#   SKIP_BACKUP=1    não faz backup (não recomendado).
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
BRANCH="${BRANCH:-main}"
ENV_FILE="$ROOT/.env.production"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mERRO: %s\033[0m\n' "$*" >&2; exit 1; }
compose() { docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE" "$@"; }
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"' || true; }

[ -f "$ENV_FILE" ] || die ".env.production não encontrado em $ROOT (docs/DEPLOY-AAPANEL.md, passo 4)."
docker info >/dev/null 2>&1 || die "sem acesso ao Docker. Rode com sudo: sudo bash $ROOT/ops/deploy.sh"
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  git status --short --untracked-files=no
  die "há arquivos do sistema alterados à mão neste servidor (lista acima). Guarde ou desfaça antes de atualizar."
fi

BEFORE="$(git rev-parse --short HEAD)"

if [ "${SKIP_BACKUP:-0}" = 1 ]; then
  say "1/6 Backup PULADO (SKIP_BACKUP=1)"
else
  say "1/6 Backup do banco e das fotos"
  compose exec -T backup bash /ops/backup.sh
fi

if [ -n "${VERSAO:-}" ]; then
  say "2/6 Indo para a versão $VERSAO"
  git fetch --quiet origin "$BRANCH" || true
  git checkout --quiet --detach "$VERSAO" || die "versão $VERSAO não encontrada."
else
  say "2/6 Baixando a versão nova ($BRANCH)"
  git fetch --quiet origin "$BRANCH"
  git checkout --quiet "$BRANCH"
  git merge --ff-only "origin/$BRANCH" || die "a versão do servidor divergiu do GitHub; nada foi alterado."
fi
AFTER="$(git rev-parse --short HEAD)"
if [ "$BEFORE" = "$AFTER" ]; then
  echo "Já estava na versão mais nova ($AFTER). Recompilando assim mesmo (correções das imagens base)."
else
  git --no-pager log --oneline "$BEFORE..$AFTER" | head -n 20
fi

say "3/6 Compilando as imagens (pode levar alguns minutos)"
compose build --pull

say "4/6 Subindo os containers novos"
compose up -d --remove-orphans

say "5/6 Migrations"
for i in $(seq 1 40); do
  if compose exec -T app php artisan migrate --force; then break; fi
  [ "$i" = 40 ] && die "o banco não respondeu às migrations. Veja: docker compose -f docker-compose.prod.yml --env-file .env.production logs app"
  sleep 3
done

say "6/6 Conferindo"
compose exec -T app php artisan ops:status
ADMIN_HOST="$(env_get TENANCY_ADMIN_HOST)"
BIND="$(env_get WEB_HTTP_BIND)"
if [ -n "$BIND" ] && [ -n "$ADMIN_HOST" ]; then
  # Atrás do aaPanel: pergunta direto ao Caddy local.
  url="http://$BIND/up"
  ok=0
  for i in $(seq 1 20); do
    if curl -fsS -H "Host: $ADMIN_HOST" -o /dev/null "$url"; then ok=1; break; fi
    sleep 3
  done
  [ "$ok" = 1 ] || die "a borda (Caddy) não respondeu em $url."
  echo "Borda ok: $url (Host: $ADMIN_HOST)"
fi

# Imagens antigas sem uso ocupam disco; só as órfãs são apagadas.
docker image prune -f >/dev/null || true

say "Pronto: $BEFORE -> $AFTER"
if [ "$BEFORE" != "$AFTER" ]; then
  echo "Para voltar à versão anterior (o banco fica como está; o backup de agora fica em backups/):"
  echo "  sudo VERSAO=$BEFORE bash $ROOT/ops/deploy.sh"
fi
