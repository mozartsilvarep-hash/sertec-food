# Manual de operação — SerTec Food

Como colocar no ar, cadastrar restaurantes, manter backups e reagir a incidentes.
Público: equipe técnica da SerTec Solution.

---

## 1. Requisitos (decisões e custos da SerTec)

| Item | Recomendação para o piloto (2–3 restaurantes) | Observação |
|---|---|---|
| Servidor | VPS Linux com 2 vCPU, 4 GB de RAM e 40 GB de SSD; Docker e Docker Compose | Custo mensal do provedor escolhido |
| Domínio | Uma zona da SerTec, ex.: `cardapios.sertecsolution.com.br` | — |
| DNS | Registros `*.cardapios…` e `admin.cardapios…` apontando para o servidor | Ver item 2 |
| Certificados | Let's Encrypt, automático pelo Caddy | Gratuito |
| Cópia externa dos backups | Opcional, mas recomendada: armazenamento S3 ou outro servidor | Pago. Fica desligado até a SerTec decidir |
| Monitoramento externo | Opcional: qualquer serviço de "uptime" consultando `https://admin…/up` | Existem planos gratuitos |

Nada disso é contratado pelo sistema.

## 2. DNS

Na zona da SerTec, crie:

```
admin.cardapios.sertecsolution.com.br.   A     <IP do servidor>
*.cardapios.sertecsolution.com.br.       A     <IP do servidor>
cname.cardapios.sertecsolution.com.br.   A     <IP do servidor>   ; alvo para domínios próprios
```

Use `AAAA` também se o servidor tiver IPv6.

## 3. Primeira instalação

> **Servidor com aaPanel** (ou outro Nginx/Apache já usando as portas 80/443): siga `docs/DEPLOY-AAPANEL.md`.

```bash
git clone https://github.com/mozartsilvarep-hash/sertec-food.git && cd sertec-food
cp .env.production.example .env.production   # preencha domínios, e-mail ACME e senhas fortes
docker compose -f docker-compose.prod.yml --env-file .env.production build
# Chave da aplicação (copie para APP_KEY no .env.production):
docker compose -f docker-compose.prod.yml --env-file .env.production run --rm --no-deps -e SERTEC_SKIP_CACHE=1 app php artisan key:generate --show
docker compose -f docker-compose.prod.yml --env-file .env.production up -d
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan db:seed --class=PermissionSeeder --force
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan db:seed --class=RoleSeeder --force
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan db:seed --class=PlanSeeder --force
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php artisan platform:create-admin voce@sertec.com.br "Seu Nome"
```

A senha do administrador aparece **uma vez**. Depois disso, acesse `https://admin.cardapios…`.

> **APP_KEY** criptografa as credenciais do Mercado Pago. Guarde-a num cofre fora do servidor. Se ela for perdida, os restaurantes precisam colar o token de novo.

Atalho para os comandos acima:

```bash
alias sf='docker compose -f docker-compose.prod.yml --env-file .env.production'
```

## 4. Atualizar uma versão

```bash
git pull
sf build && sf up -d
sf exec app php artisan migrate --force
sf exec app php artisan ops:status
```

As migrations vêm com rollback testado no CI.

> **Builds reproduzíveis:** o repositório ainda não versiona o `backend/composer.lock` (o ambiente onde o código foi escrito não alcança o Packagist). Numa máquina com internet, rode uma vez `cd backend && composer update --lock` (ou `composer install`), confira os testes e faça commit do `composer.lock`. A partir daí o build de produção usa exatamente essas versões. Antes de uma atualização grande, faça um backup manual: `sf exec backup bash /ops/backup.sh`.

## 5. Cadastrar um restaurante (onboarding)

1. **Painel SerTec → Novo estabelecimento:** nome, endereço (slug), proprietário e plano. Marque "Ativar agora" só quando o cardápio estiver pronto.
2. Envie ao proprietário, por canal seguro:
   - o endereço `https://<slug>.cardapios…/painel`;
   - o e-mail de acesso;
   - a **senha temporária**.
3. O proprietário, no painel do restaurante:
   1. **Minha conta:** troca a senha.
   2. **Unidades:** cadastra telefone ou WhatsApp e endereço.
   3. **Horários** e **Entrega:** define horários, bairros, taxas e retirada.
   4. **Cardápio** e **Opções e sabores:** monta o cardápio e a marca.
   5. **Equipe:** cadastra atendentes e cozinha (cada um recebe uma senha temporária).
   6. **Pagamentos** (opcional): segue o item 6.
4. Faça um pedido de teste com pagamento na entrega e conclua no painel.
5. **Impressão:**
   - No computador do balcão, use o Chrome com a impressora térmica como padrão.
   - Para imprimir sem a janela de confirmação, inicie o Chrome com `--kiosk-printing`.
   - O botão "Imprimir" fica no detalhe do pedido; escolha papel de 58 ou 80 mm.
6. **Alertas:** no painel, clique em "Ativar som de novos pedidos" e permita as notificações. Deixe a aba aberta; pode ficar minimizada.

### Domínio próprio do restaurante (opcional)

1. Painel SerTec → estabelecimento → **Adicionar domínio próprio**, por exemplo `pedidos.restaurante.com.br`.
2. O cliente cria no DNS dele:
   - um **TXT** `_sertec-verify.pedidos…` com o valor exibido;
   - um **CNAME** `pedidos…` apontando para `cname.cardapios…`.
3. Clique em **Verificar DNS**. Depois disso o certificado HTTPS sai automaticamente no primeiro acesso.
4. Se quiser, clique em **Tornar principal**: o link compartilhado e o canonical passam a usar esse domínio.

Domínio não verificado não abre o cardápio e não recebe certificado.

## 6. Mercado Pago de cada restaurante

O dinheiro cai direto na conta do restaurante; a SerTec não intermedia. No painel do restaurante, em **Pagamentos**:

1. O restaurante entra em `mercadopago.com.br/developers` → **Suas integrações** → cria uma aplicação ("Pagamentos online"; produto Checkout Pro ou Checkout Transparente).
2. Para testar, cola as **credenciais de teste**: o token começa com `TEST-`, e nada é cobrado. Para receber de verdade, cola as de produção (`APP_USR-`).
3. Em **Webhooks** da aplicação:
   - cadastra a URL que o painel mostra (`https://<host>/api/v1/webhooks/mercadopago`), com o evento **Pagamentos**;
   - copia a **assinatura secreta** para o painel.
4. Faz um Pix de teste e confirma que o pedido aparece em "Novos".

Sem webhook, os pagamentos ainda são confirmados pela reconciliação, que roda a cada minuto, mas com mais atraso.

## 6.1 Promoções no site do restaurante (ex.: Yris Cakes)

O site institucional pode mostrar as promoções e a faixa de aviso direto da loja:

1. O restaurante usa um **domínio próprio** para a loja (item 5), por exemplo `loja.yriscakes.com.br`, e o torna principal. Os links do feed precisam ser do domínio do site.
2. Painel do restaurante → **Promoções → Vitrine e aviso**: em "Sites autorizados", cadastrar `https://yriscakes.com.br` e `https://www.yriscakes.com.br`, se o site usar os dois.
3. No site, em `public/assets/js/config.js`:
   - `feedLoja: "https://loja.yriscakes.com.br/api/v1/vitrine/promocoes.json"`;
   - `usarFeedLoja: true`.
4. Na política de segurança do site (CSP), acrescentar `https://loja.yriscakes.com.br` em `img-src` para as fotos.

O feed segue o contrato v1, tem cache de 60 s e não contém dados de clientes.

## 6.2 E-mail ("Esqueci a senha")

O sistema envia e-mails pelo servidor SMTP da SerTec. No piloto é a caixa `noreply@sertecsolution.com.br`, no Mail Server do aaPanel.

1. No `.env.production`, preencha o bloco `MAIL_*` (modelo em `.env.production.example`). A senha da caixa fica só nesse arquivo.
2. Aplique:
   ```bash
   sf up -d --force-recreate app worker scheduler
   ```
3. No **Painel SerTec → Sistema**:
   - a situação deve aparecer como "Configurado (SMTP)";
   - clique em **Enviar e-mail de teste para mim**;
   - se o servidor recusar, a mensagem de erro aparece na tela.

Dicas:
- Se a mensagem for parar no spam, confira no DNS de `sertecsolution.com.br` os registros SPF, DKIM e DMARC. O aaPanel mostra os valores na tela do domínio, no Mail Server.
- Se a conexão falhar dentro do Docker, confira o passo do firewall em `docs/DEPLOY-AAPANEL.md` (tráfego roteado liberado no ufw).
- O link de "Esqueci a senha":
  - vale 60 minutos e uma única vez;
  - só é enviado a quem pode entrar naquele painel;
  - nunca fica gravado em log, fila ou banco (lá fica só o hash do token).

## 6.3 Mensalidade dos restaurantes (cobrança SerTec)

Os restaurantes pagam a mensalidade por Pix, direto na conta Mercado Pago da SerTec. O dinheiro não passa pela conta deles.

**Configurar uma vez (Painel SerTec → Cobrança):**
1. **Mercado Pago:**
   - cole o **Access Token de produção** da conta da SerTec (Mercado Pago → Suas integrações → Credenciais de produção);
   - o painel valida o token e guarda criptografado; depois mostra só os 4 últimos caracteres.
2. **Webhook:**
   - no Mercado Pago, cadastre o endereço mostrado na tela (`https://admin.../api/v1/billing/webhooks/mercadopago`), evento **Pagamentos**;
   - cole a **assinatura secreta** no painel.
   - Sem o webhook, a confirmação ainda acontece: pela tela do restaurante, que confere a cada poucos segundos, e pela rotina de hora em hora.
3. **Planos:** defina o preço de cada período (mensal, trimestral, semestral, anual) e os recursos incluídos.
   - Período vazio = não oferecido.
   - Mudança de preço vale só para as próximas faturas.

**Para cada restaurante** (Painel SerTec → estabelecimento → Mensalidade):
- **Plano e período.** Em "Valor próprio", deixe vazio para usar o preço do plano, ou informe outro valor (`0` = cortesia, nunca cobra).
- **Pago até:** use para registrar o que já foi pago antes do sistema. A próxima fatura começa no dia seguinte.
- **Gerar cobrança agora:** cria a fatura sem esperar a rotina.

**Como funciona sozinho** (`billing:run`, de hora em hora no scheduler):

| Quando | O que acontece |
|---|---|
| 7 dias antes do fim do período pago | Gera a fatura do próximo período, com vencimento no primeiro dia dele. A primeira fatura de um restaurante vence em 5 dias. |
| 3 dias antes do vencimento | E-mail "vence em breve" para o proprietário; faixa de aviso no painel do restaurante. |
| No dia seguinte ao vencimento | E-mail "em atraso"; situação "Em atraso". |
| Quando o Pix é pago | Baixa automática, "pago até" avança e o proprietário recebe o e-mail "pagamento confirmado". |

- **Atraso não desliga nada sozinho.** Para suspender, use **Suspender** no estabelecimento: o cardápio fica fora do ar até reativar.
- **Pagamento por fora** (transferência, dinheiro): em Cobrança → Faturas, use **Dar baixa** com uma observação.
- **Conferir pela linha de comando:** `sf exec app php artisan billing:run`. Pode rodar quantas vezes quiser: não duplica fatura nem e-mail.

## 7. Backups

O container `backup` roda `ops/backup.sh` todo dia às 03:30 de Maceió:

- faz o dump consistente do banco e um tar das fotos;
- comprime e, se `BACKUP_PASSPHRASE` estiver definida, criptografa com AES-256;
- grava em `BACKUP_DIR` (padrão `./backups` no servidor) e mantém `BACKUP_KEEP_DAYS` dias.

**Copie os backups para fora do servidor.** Um backup que só existe no próprio servidor se perde junto com ele. Por exemplo, com rsync diário para outra máquina ou com a cópia externa do item 1.

**Teste de restauração** (faça uma vez por mês e registre o resultado):

```bash
sf run --rm backup bash /ops/backup-test.sh
```

O teste restaura o backup mais recente num banco temporário, compara as contagens com produção e apaga o temporário. A senha de criptografia errada faz o teste falhar.

**Restaurar de verdade** (incidente):

1. Pare a aplicação: `sf stop app worker scheduler`.
2. Restaure: `sf run --rm backup bash /ops/restore.sh /backups/db-AAAAMMDD….sql.gz.enc sertec_food`.
3. Para restaurar as fotos, extraia o `files-….tar.gz(.enc)` no volume `storage`.
4. Suba de novo: `sf up -d` e confira com `sf exec app php artisan ops:status`.

## 8. Monitoramento e logs

| Verificação | Como |
|---|---|
| Saúde (banco, Redis, fila, agendador) | `sf exec app php artisan ops:status`, ou `GET /api/v1/internal/health` na rede interna. O container `app` tem healthcheck. |
| Disponibilidade externa | `https://admin.cardapios…/up` (200 = no ar) |
| Logs | `sf logs -f app worker scheduler web`. JSON por linha, com `request_id` (o mesmo devolvido nos erros da API). |
| Pagamentos travados | `sf exec app php artisan payments:reconcile` (já roda a cada minuto) |
| Fila parada | Se `ops:status` acusar fila acima de 100 ou agendador sem batimento: `sf restart worker scheduler` |

Os logs nunca contêm tokens, senhas, corpo de webhook nem telefone em claro.

## 9. LGPD

- **Papéis:** o restaurante é o controlador e a SerTec Solution, a operadora. Formalize em contrato (DPA) com cada restaurante.
- **Política:** cada cardápio tem `/privacidade`, com um texto modelo. **Revise com um advogado antes do piloto.**
- **Retenção:** `privacy:anonymize` roda todo dia às 04:10. Pedidos finalizados há mais de `PRIVACY_RETENTION_MONTHS` meses (padrão 24) perdem nome, telefone, e-mail, endereço e observações; valores e itens ficam.
- **Pedido do titular:**
  - o restaurante usa **Privacidade (LGPD)** no painel: busca pelo celular, mostra os dados e apaga;
  - fica registrado na auditoria sem o telefone em claro;
  - se o pedido chegar à SerTec, encaminhe ao restaurante.
- **Exportação CSV:** não inclui telefone nem endereço.

## 10. Incidentes

| Situação | Ação imediata |
|---|---|
| Suspeita de credencial vazada de um restaurante | Painel SerTec → **Gerar nova senha do proprietário**, que encerra as sessões. Peça ao restaurante para gerar um novo Access Token no Mercado Pago e colar em **Pagamentos**. |
| Restaurante inadimplente ou pedido de pausa | Painel SerTec → **Suspender**. O cardápio e o painel ficam indisponíveis na hora; reativar devolve tudo como estava. |
| Servidor perdido | Novo servidor → item 3 → restaurar o backup externo (item 7) → apontar o DNS. Os certificados são reemitidos sozinhos. |
| Vazamento da APP_KEY | Gere uma nova chave, faça o deploy e peça a todos os restaurantes para colar o token do Mercado Pago de novo (as credenciais antigas não são mais lidas). Registre o incidente e avalie a comunicação à ANPD. |

Toda ação administrativa fica na **Auditoria**, tanto no painel SerTec quanto no do restaurante.
