# SerTec Food — Product Requirements v0.1

## Objetivo
SaaS multi-tenant de cardápios digitais, pedidos e pagamentos, vendido isoladamente ou integrado a sites SerTec.

## Personas
- Operador SerTec: administra tenants, planos e suporte.
- Dono/gerente: gerencia estabelecimento, cardápio e pedidos.
- Atendente/cozinha: opera pedidos conforme permissões.
- Consumidor: compra sem cadastro obrigatório e acompanha pedido.

## MVP obrigatório
1. Cadastro e ativação manual de tenants e unidades.
2. Autenticação de funcionários e controle de acesso por tenant/unidade.
3. Resolução de tenant por subdomínio e domínio verificado.
4. Catálogo: categorias, produtos, variantes, adicionais e disponibilidade.
5. Carrinho, checkout, entrega/retirada, cálculo no servidor e histórico imutável de itens.
6. Pagamento Mercado Pago com webhook verificado; pagamento na entrega.
7. Painel de pedidos em tempo real, histórico de status e acompanhamento por token.
8. Painel SerTec com administração e auditoria.
9. Página responsiva, acessível, com SEO básico e personalização de marca.

## Fora do MVP
IA, PDV fiscal, estoque complexo, entregadores com GPS, marketplace de descoberta, impressão automática, split de pagamentos, assinatura self-service, WhatsApp automatizado.

## Critérios de aceitação essenciais
- Tenant A jamais lê/escreve dados do tenant B, mesmo com IDs conhecidos.
- Totais são recalculados no servidor; preço do navegador é ignorado.
- Webhook duplicado não duplica pagamento nem pedido.
- Alterar produto não altera valor/nome de item de pedido antigo.
- Pedido com pagamento online não é marcado pago pelo retorno do navegador.
- Token público de acompanhamento não revela dados sensíveis.
- Domínio só é ativado após verificação de titularidade e HTTPS.
- Usuário sem permissão recebe 403.
