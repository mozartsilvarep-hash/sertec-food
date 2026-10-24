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
