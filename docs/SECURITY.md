# Segurança e conformidade v0.1

- Isolamento de tenant por contexto, políticas, escopos, FKs e testes negativos.
- Credenciais de provedores criptografadas; secrets via ambiente/secret manager.
- Proteção CSRF/XSS/SQLi, validação, limitação de taxa e permissões mínimas.
- Checkout server-side; preço e desconto sempre revalidados; transações e idempotência.
- Webhooks autenticados, deduplicados e reconciliados; sem confiar em redirects.
- Dados pessoais minimizados; política de privacidade, retenção, exclusão, backups e direitos LGPD.
- Não armazenar PAN/CVV; usar checkout seguro do processador.
- HTTPS, headers de segurança, logs sem segredos, auditoria de acessos administrativos.
- Backups com teste de restauração e plano de resposta a incidentes.

## Revisão de segurança — 27/10/2026

### PHP "php2shell" (CVE-2026-109618 e CVE-2026-109619, divulgadas em 21/10/2026)
São duas falhas críticas de execução remota de código no próprio PHP, que já estão sendo exploradas. A correção é a versão **8.4.27** (a imagem usa a linha 8.4).
- O build da imagem `app` agora **falha** se o PHP da imagem base for anterior à 8.4.27.
- Atualizações usam `sf build --pull` (DEPLOY-AAPANEL, item 12), que baixa a imagem corrigida.
- **Camada extra:** `session.upload_progress.enabled=0` (vetor da CVE-2026-109619; o sistema não usa esse recurso), `allow_url_include=Off` e funções de shell desligadas (`exec`, `system`, `shell_exec`, `passthru`, `pcntl_exec`, `dl`).
  - `proc_open` continua ligada porque o agendador do Laravel precisa dela.

### Laravel (CVE-2026-114509, falha no TokenGuard)
- Não nos afeta: o sistema usa sessão via Sanctum, não o TokenGuard.
- Mesmo assim, o mínimo agora é `laravel/framework ^13.29`, a versão corrigida.
- O CI roda `composer audit` e `pnpm audit --prod` a cada mudança.

### Invasão pelo navegador (XSS, CSRF, clickjacking)
- **XSS:** nenhum `v-html`/`innerHTML` nos apps e nenhum `{!! !!}` nos e-mails. Os links digitados pelo restaurante (faixa de aviso) só aceitam `https://`.
- **CSP sem `unsafe-eval`:** scripts só da própria origem e nenhum script de terceiros.
- **CSRF:** cookie de sessão `HttpOnly`, `Secure`, `SameSite=Lax`, com token XSRF do Sanctum. O CORS está desligado.
- **Clickjacking:** bloqueado (`X-Frame-Options: DENY`, `frame-ancestors 'none'`).
- **Cabeçalhos novos:** `Cross-Origin-Opener-Policy: same-origin` e `X-Permitted-Cross-Domain-Policies: none`. O cabeçalho que revelava o servidor foi removido.
- **SQL injection:** só Eloquent e bindings. Os poucos `selectRaw` usam texto fixo, sem dado do usuário.

### Abuso, spam e DDoS
- **Por rota (Laravel):** login, "esqueci a senha", cardápio, celular, pagamentos e webhooks já tinham limite.
- **Novo no checkout:** máximo de 20 pedidos por hora por celular em cada loja (pega robô trocando de IP) e teto de 60 pedidos por minuto por loja. Há também um campo invisível: o robô que preenche tudo é recusado.
- **Novo nos painéis:** limite geral de 600 requisições por minuto por usuário.
- **PHP-FPM:** requisição presa morre em 60 s.
- **Nginx do aaPanel:** timeouts contra conexões lentas (slowloris). Limites por IP (`limit_req`/`limit_conn`) são opcionais e estão documentados.
- **DDoS de volume:** só um serviço na frente (ex.: Cloudflare) segura. Está documentado como próximo passo opcional.

### Banco e Redis
- Nenhum dos dois é exposto fora da rede interna do Docker.
- **MariaDB:** ganhou `local-infile=0`, `skip-name-resolve` e `max-connections=150`; o buffer subiu para 512 MB (mais rápido no servidor de 8 GB).
- Usuário próprio só do banco do sistema. Credenciais de pagamento e o token da SerTec ficam criptografados com a APP_KEY.

### LGPD e cookies
- **Cardápio:** não grava cookie nenhum (conferido nas respostas), nem de terceiros. Por isso não há banner de cookies.
- **Política de privacidade** do cardápio agora explica:
  - o armazenamento no aparelho;
  - o cartão fidelidade;
  - a segurança;
  - a identificação do restaurante (razão social e CNPJ).
- **Checkout:** a pessoa escolhe "Lembrar meus dados neste aparelho".
- **Painéis:** usam só o cookie de sessão, essencial para o login.
