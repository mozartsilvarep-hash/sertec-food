# Testes ponta a ponta (navegador)

Cenários reais no Chromium contra o ambiente local (Nginx + PHP-FPM + fila + Vite):

| Arquivo | O que cobre |
|---|---|
| `s0-fundacao.mjs` | cardápio pelo host, host desconhecido, login, sessão não vale em outro tenant, atendente restrito à unidade, painel SerTec |
| `s1-catalogo.mjs` | meio a meio, adicionais, criar produto com foto (WebP), sabores por tamanho, marca, esgotado por unidade |
| `s2-pedidos.mjs` | carrinho, checkout com entrega e troco, duplo clique = 1 pedido, fluxo de status até concluído |
| `s3-pagamento.mjs` | conectar Mercado Pago, Pix com QR, webhook assinado via Nginx, assinatura falsa = 401, reembolso, cartão (Checkout Pro) e retorno |
| `s4-operacao.mjs` | alerta ao vivo em segundos, comanda 58/80 mm, histórico, CSV, auditoria |
| `s6-promocoes.mjs` | promoção "de/por", cupom de primeiro pedido, faixa, feed v1 com CORS, link profundo, desconto no checkout |
| `s7-clientes.mjs` | senha em português, zona vazia/remover, bairros de Aracaju, WhatsApp com o pedido, mesmo aparelho preenche, outro aparelho com endereço parcial, acompanhar pelo celular, painel com endereço completo |
| `s8-cardapio.mjs` | produto só em certos dias (bloqueado com aviso), combo com produtos do cardápio e preço "de", "leve 2, pague 1" no carrinho/checkout/acompanhamento, "Chamar no WhatsApp" no painel |
| `s10-caixa.mjs` | abrir caixa, venda de balcão com troco, sangria, gaveta esperada, fechar com falta, relatório do caixa e relatórios de pedidos |
| `s11-fidelidade.mjs` | regra do cartão (2 selos = R$ 5), ajuste manual vira prêmio, faixa no cardápio, prêmio no checkout e no acompanhamento, histórico no painel |
| `s12-marca.mjs` | logo e capa enviadas, CNPJ inválido recusado, cor/razão social/Instagram/WhatsApp salvos; cardápio com logo, contatos, cor e rodapé com CNPJ; outro restaurante não herda nada |
| `s13-cobranca.mjs` | SerTec conecta a conta MP e define preço do plano; mensalidade da Pizzaria Demo; dono paga por Pix e a tela confirma sozinha; painel SerTec mostra a fatura paga |
| `s98-telas.mjs` | todas as telas (cardápio, painel e SerTec) em 360 px e 768 px sem rolagem lateral |
| `s99-senha.mjs` | "Esqueci a senha" no painel da loja (link do e-mail, regra de senha, entra com a nova, link não vale 2×) e no painel SerTec. Lê o link do log (`MAIL_MAILER=log`); rode por último |

## Como rodar

> **Zera o banco local de demonstração.** Nunca aponte para produção.

```bash
# 1. Ambiente local no ar (make setup / make up) e dados limpos:
docker compose exec php php artisan migrate:fresh --seed --force
docker compose exec php php artisan demo:prepare-e2e e2e-local-123

# 2. Mercado Pago falso (cenário s3): rode na máquina e aponte o backend para ele
#    (backend/.env: MERCADOPAGO_BASE_URL=http://host.docker.internal:9911 e LOGIN_PER_MINUTE=100)
cd e2e && npm install && npx playwright install chromium
node fake-mercadopago.mjs &

# 3. Cenários
E2E_OWNER_PASSWORD=e2e-local-123 E2E_ATTENDANT_PASSWORD=e2e-local-123 E2E_ADMIN_PASSWORD=e2e-local-123 node run.mjs
# um só: node run.mjs s3
```

Saída: uma linha `OK`/`FALHOU` por verificação; capturas de tela em `e2e/shots/`. O processo termina com código 1 se algo falhar.
Variáveis opcionais: `E2E_BASE_DOMAIN` (padrão `cardapios.localhost`), `PW_CHROMIUM` (Chromium já instalado), `E2E_SHOTS`.

O `demo:prepare-e2e` (só `APP_ENV=local`) define a senha dos usuários `*.localhost`, deixa as lojas de demonstração abertas a qualquer hora e desconecta o Mercado Pago.
