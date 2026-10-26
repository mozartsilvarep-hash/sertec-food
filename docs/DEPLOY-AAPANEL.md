# Colocar no ar numa VPS com aaPanel (OVH, Ubuntu)

Passo a passo para o primeiro servidor do SerTec Food, com:
- o painel da SerTec em `admin.cardapios.sertecsolution.com.br`;
- a loja da Yris Cakes em `loja.yriscakes.com.br`.

Se o domínio da SerTec for outro, troque `sertecsolution.com.br` em todos os passos.

## Como fica

```
Visitante ──HTTPS──▶ Nginx do aaPanel (portas 80/443, certificados Let's Encrypt)
                          │  http://127.0.0.1:8088 (só dentro do servidor)
                          ▼
                     Caddy (container "web") ──▶ PHP (container "app") ──▶ MariaDB, Redis
```

- O aaPanel continua dono das portas 80 e 443 e dos certificados, como nos outros sites do servidor.
- O SerTec Food roda em containers Docker. Ele só escuta em `127.0.0.1`, portanto não é acessível de fora sem passar pelo Nginx.
- O banco e o Redis do SerTec Food são próprios, dentro do Docker. Não usam o MySQL nem o Redis do aaPanel.

Tempo estimado: 1 a 2 horas, a maior parte esperando o build e a propagação do DNS.

---

## 1. DNS

Crie os registros, trocando `IP` pelo IPv4 da VPS (OVH → Bare Metal Cloud → VPS → IPv4):

| Zona | Nome | Tipo | Valor |
|---|---|---|---|
| sertecsolution.com.br | `admin.cardapios` | A | IP |
| yriscakes.com.br | `loja` | A | IP |

Mais tarde, no passo 9, você também vai criar um TXT na zona `yriscakes.com.br`.

Para conferir, num terminal: `dig +short loja.yriscakes.com.br` deve responder o IP. Pode levar alguns minutos.

## 2. Docker na VPS

Entre na VPS por SSH, como root (ou com `sudo -i`).

```bash
docker --version && docker compose version
```

Se os dois comandos responderem, pule para o passo 3. O Docker pode já ter sido instalado pela App Store do aaPanel. Se faltar, instale o Docker oficial:

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
docker compose version   # precisa ser 2.20 ou mais novo
```

**Firewall:** no aaPanel (Security) e no painel da OVH, mantenha abertas só as portas SSH, 80, 443 e a do próprio aaPanel. **Não abra a 8088 nem a 8443.**

**Saída de internet dos containers:** o ufw do Ubuntu vem com "deny (routed)", e isso impede os containers de acessar a internet. Sem essa saída, o build falha e o sistema não alcança o Mercado Pago nem o servidor de e-mail. Libere e confira:

```bash
ufw default allow routed && ufw reload && systemctl restart docker
docker run --rm alpine sh -c 'wget -q -T 10 -O /dev/null https://registry.npmjs.org/ && echo OK || echo FALHOU'
```

Isso não abre nenhuma porta para quem vem de fora.

## 3. Baixar o sistema

```bash
cd /opt
git clone https://github.com/mozartsilvarep-hash/sertec-food.git
cd /opt/sertec-food
alias sf='docker compose -f docker-compose.prod.yml --env-file .env.production'
echo "alias sf='cd /opt/sertec-food && docker compose -f docker-compose.prod.yml --env-file .env.production'" >> ~/.bashrc
```

Todos os comandos `sf …` daqui em diante rodam em `/opt/sertec-food`.

## 4. Configuração (.env.production)

```bash
cp .env.production.example .env.production
chmod 600 .env.production
# Gere senhas (rode três vezes e use uma em cada campo):
openssl rand -base64 32 | tr -d '/+=' | cut -c1-32
nano .env.production
```

Preencha:

```ini
APP_URL=https://admin.cardapios.sertecsolution.com.br
TENANCY_BASE_DOMAIN=cardapios.sertecsolution.com.br
TENANCY_ADMIN_HOST=admin.cardapios.sertecsolution.com.br
ACME_EMAIL=seu-email@...           # não é usado atrás do aaPanel, mas preencha

DB_PASSWORD=<senha 1>
DB_ROOT_PASSWORD=<senha 2>
BACKUP_PASSPHRASE=<senha 3>

TRUSTED_PROXIES=                   # deixe vazio

# Descomente (tire o #) destas três linhas:
WEB_HTTP_BIND=127.0.0.1:8088
WEB_HTTPS_BIND=127.0.0.1:8443
CADDY_CONFIG=Caddyfile.proxy
```

Se a porta 8088 já estiver em uso no servidor (`ss -ltn | grep 8088`), escolha outra. Use a mesma no passo 7.

Guarde as três senhas num cofre fora do servidor (gerenciador de senhas). A senha do backup é a única forma de abrir os backups.

## 5. Build e primeira subida

O build leva de 5 a 15 minutos.

```bash
sf build
```

Gere a chave da aplicação e copie a linha `base64:…` para `APP_KEY=` no `.env.production`:

```bash
sf run --rm --no-deps -e SERTEC_SKIP_CACHE=1 app php artisan key:generate --show
nano .env.production
```

> A `APP_KEY` criptografa as credenciais do Mercado Pago das lojas. Guarde-a no cofre também.

Suba os containers e prepare o banco:

```bash
sf up -d
sf ps                                   # todos "running"; app fica "healthy" em até 2 min
sf exec app php artisan migrate --force
sf exec app php artisan db:seed --class=PermissionSeeder --force
sf exec app php artisan db:seed --class=RoleSeeder --force
sf exec app php artisan db:seed --class=PlanSeeder --force
sf exec app php artisan platform:create-admin seu-email@... "Seu Nome"
```

O último comando mostra a **senha do painel SerTec uma única vez**. Copie-a.

Teste local, de dentro da VPS:

```bash
curl -s -H 'Host: admin.cardapios.sertecsolution.com.br' http://127.0.0.1:8088/up   # resposta 200
```

## 6. Sites no aaPanel

Crie dois sites no aaPanel: um para o painel SerTec e outro para a loja. Para cada um:

1. **Website → Add site**:
   - Domain: `admin.cardapios.sertecsolution.com.br` (no segundo site, `loja.yriscakes.com.br`);
   - PHP: **Static** (estático);
   - sem banco de dados e sem FTP.
2. No site criado, abra **SSL → Let's Encrypt**. Marque o domínio, escolha verificação por arquivo e clique em **Apply**.
3. Ainda em SSL, ligue **Force HTTPS**.

Emita o certificado **antes** do passo 7: nesse momento o site ainda é estático, então a verificação por arquivo funciona sem ajuste. As renovações automáticas continuam funcionando depois do passo 7.

## 7. Ligar os sites ao SerTec Food

Em cada um dos dois sites: **Website → (site) → Config** (arquivo de configuração).

1. **Apague** estes dois blocos, que o aaPanel cria em todo site:

   ```nginx
   location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$ { ... }
   location ~ .*\.(js|css)?$ { ... }
   ```

   Se ficarem, o Nginx procura as fotos e os arquivos `.js` no disco do site e responde 404.
2. Dentro do bloco `server { … }`, logo depois das linhas de SSL, cole o conteúdo de `ops/aapanel/nginx-proxy.conf`:

   ```nginx
   location / {
       proxy_pass http://127.0.0.1:8088;
       proxy_http_version 1.1;
       proxy_set_header Connection "";
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $remote_addr;
       proxy_set_header X-Forwarded-Proto https;
       proxy_read_timeout 60s;
       client_max_body_size 12m;
   }
   ```

3. Salve. O aaPanel testa a configuração antes de aplicar. Se acusar `duplicate location "/"`, apague o `location /` antigo do arquivo e salve de novo.

Use o arquivo de configuração e **não** a aba "Reverse proxy" do aaPanel. A aba também funciona, mas não remove os blocos de cache do item 1 e acrescenta cabeçalhos próprios.

Teste: `https://admin.cardapios.sertecsolution.com.br` abre a tela de login do painel SerTec com cadeado válido.

## 8. Cadastrar a Yris Cakes

No painel SerTec (entre com o e-mail e a senha do passo 5):

1. **Novo estabelecimento**:
   - Nome: Yris Cakes;
   - Endereço (slug): `yriscakes`;
   - e-mail e nome da proprietária;
   - Plano: **Site Profissional + Cardápio**. Esse plano inclui domínio próprio, cupons, impressão e relatórios.
   - Deixe "Ativar agora" desmarcado.
2. Anote a **senha temporária** da proprietária. Ela aparece uma única vez.

O endereço de plataforma (`yriscakes.cardapios.sertecsolution.com.br`) **não** vai abrir: ele não tem DNS nem certificado nesta instalação, e isso é esperado. A loja usa o domínio próprio, configurado no passo 9.

## 9. Domínio próprio `loja.yriscakes.com.br`

1. Painel SerTec → Yris Cakes → **Adicionar domínio próprio** → `loja.yriscakes.com.br`.
2. O painel mostra um valor `sertec-verify=…`. Na zona `yriscakes.com.br`, crie:

   | Nome | Tipo | Valor |
   |---|---|---|
   | `_sertec-verify.loja` | TXT | o valor mostrado |

   Não crie CNAME: o registro A do passo 1 já resolve. O aviso do painel sobre "certificado automático" não vale nesta instalação, porque quem cuida do certificado é o aaPanel (passo 6).
3. Espere o TXT propagar (`dig +short TXT _sertec-verify.loja.yriscakes.com.br`). Depois clique em **Verificar DNS** e em seguida em **Tornar principal**.
4. Clique em **Ativar** quando o cardápio estiver pronto. Antes disso, a loja mostra "Cardápio indisponível".

Teste:
- `https://loja.yriscakes.com.br/painel` abre o login do painel da loja;
- `https://loja.yriscakes.com.br` abre o cardápio, depois de ativada.

## 10. Configuração da loja

Entre em `https://loja.yriscakes.com.br/painel` com o e-mail e a senha temporária da proprietária e siga o roteiro de `docs/OPERATIONS.md`, item 5, passo 3:
- troque a senha;
- cadastre unidade, horários, entrega e cardápio;
- monte a equipe.

Depois:
- **Mercado Pago:** siga `docs/OPERATIONS.md`, item 6. A URL do webhook é `https://loja.yriscakes.com.br/api/v1/webhooks/mercadopago`.
- **Site yriscakes.com.br:** siga `docs/OPERATIONS.md`, item 6.1, para ligar as promoções.
- **Pedido de teste:** faça um, com pagamento na entrega, e conclua no painel.

## 10.1 E-mail

Para o "Esqueci a senha" funcionar, preencha o bloco `MAIL_*` do `.env.production` com os dados da caixa de envio. Depois rode `sf up -d --force-recreate app worker scheduler` e teste em **Painel SerTec → Sistema**. Detalhes em `docs/OPERATIONS.md`, item 6.2.

## 11. Backups

Os backups diários ficam em `/opt/sertec-food/backups`, criptografados com a `BACKUP_PASSPHRASE`.

- **Copie-os para fora da VPS.** Duas opções:
  - ativar os snapshots automáticos da OVH (pago);
  - criar uma tarefa no aaPanel (Cron → Shell Script) que envie a pasta para outro servidor ou armazenamento.
- Faça um **teste de restauração** por mês: `docs/OPERATIONS.md`, item 7.

## 12. Atualizar uma versão

```bash
sf exec backup bash /ops/backup.sh      # backup antes
git pull
sf build && sf up -d
sf exec app php artisan migrate --force
sf exec app php artisan ops:status
```

## 13. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| 502 Bad Gateway | containers parados ou porta diferente da do passo 4 | `sf ps`; `sf logs --tail 80 web app`; conferir a porta no `proxy_pass` |
| Fotos ou tela em branco (404 em `/assets` ou `/storage`) | blocos de cache do aaPanel não foram apagados | refazer o passo 7, item 1 |
| `loja.yriscakes.com.br` mostra "Cardápio indisponível" | domínio próprio ainda não verificado, ou loja ainda não ativada | passo 9 |
| Certificado inválido | SSL não emitido ou vencido no aaPanel | site → SSL → renovar |
| `sf exec app php artisan ops:status` acusa fila ou agendador | containers `worker` ou `scheduler` parados | `sf up -d`; `sf logs --tail 80 worker scheduler` |

## 14. Próximos restaurantes

Cada restaurante novo precisa de:
- um site no aaPanel com certificado, como no passo 6;
- o mesmo trecho de configuração do passo 7.

Isso vale tanto para o domínio próprio do restaurante quanto para o endereço `slug.cardapios.sertecsolution.com.br` (este exige também um registro A no DNS).

Quando houver muitos restaurantes, vale migrar para um certificado curinga (`*.cardapios…`, via DNS do aaPanel) ou para a instalação sem aaPanel de `docs/OPERATIONS.md`, em que os certificados saem sozinhos.
