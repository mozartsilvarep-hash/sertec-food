# Projetos de referência (open source)

Avaliados em 23/10/2026. Ambos MIT. Nenhum é base de código do SerTec Food; servem de referência de **desenho**. Se algum trecho for portado, registrar em `THIRD_PARTY_NOTICES.md` mantendo o aviso de copyright.

## Por que nenhum serve de base
| | TastyIgniter (core + extensões cart/local/payregister) | RestoPOS |
|---|---|---|
| Multi-tenancy | Nenhuma (1 restaurante por instalação) | Banco por tenant (stancl/tenancy) — oposto do nosso modelo |
| Stack | Laravel 13, Blade/Bootstrap 5, plataforma de extensões | Laravel 8, Vue 2, laravel-mix, Passport (fim de vida) |
| Foco | Pedido online, retirada/entrega, pagamentos | PDV de salão (mesas, KOT, GST indiano, estoque) |
| Mercado Pago | Não tem | Não tem |

## O que estudar, por sprint
| Sprint | Fonte | O que reaproveitar como desenho |
|---|---|---|
| 1 Catálogo | TI `ti-ext-cart` `MenuItemOption`, `menu_item_option_linked_values`, `free_quantity` | Regras de adicionais: obrigatório, mín./máx., quantidade grátis, opções dependentes. **Acrescentar pizza meio a meio** (não existe em nenhum dos dois): grupo "sabores fracionados" com regra de preço configurável (maior preço ou média). |
| 1 Catálogo | TI `ti-ext-local` `WorkingSchedule`, `WorkingPeriod`, `WorkingTimeslot` | Horários com virada de meia-noite, exceções/feriados, próximos horários e agendamento com tempo de preparo. Portar o algoritmo usando o fuso do tenant. |
| 2 Pedidos | TI `CartConditions` + `OrderTotal` | Totais em etapas encadeadas (subtotal → cupom → frete → taxa da forma de pagamento → total), gravando cada etapa no pedido → criar tabela `order_totals`. |
| 2 Pedidos | TI `CoveredArea` + `CoveredAreaCondition` | Zona de entrega por bairro, raio ou polígono, com regras por valor ("grátis acima de R$ X"). |
| 2 Pedidos | TI `StatusWorkflowManager`; RestoPOS tipos de pedido | Aceitar com tempo estimado, recusar com motivo padronizado; status com "notificar cliente" → templates de WhatsApp (Meta exige aprovação prévia). |
| 3 Pagamento | TI `BasePaymentGateway`, Stripe + `ProcessStripeWebhookJob` | Interface de gateway; webhook com assinatura obrigatória, processado em fila, tratando webhook antes do retorno do navegador. **Não copiar** a deduplicação (`isPaymentProcessed()` sem trava sofre condição de corrida) — usar UNIQUE + `SELECT … FOR UPDATE`. |
| 3 Pagamento | Mercado Pago (ideia) | Vincular a conta do restaurante via OAuth em vez de colar chaves; permite comissão por pedido no futuro. Requer aprovação (integração externa/decisão comercial). |
| 4 Operação | Impressão (RestoPOS usa `mike42/escpos-php`, MIT) | MVP: layout 58/80 mm + Chrome em `--kiosk-printing`. Depois: "SerTec Print Agent" local com ESC/POS e setores (cozinha/bar). |
| Pós-MVP | RestoPOS `ReportController` | Checklist de relatórios: por categoria, produto, forma de pagamento, consolidado. |
| Pós-MVP | RestoPOS ficha técnica / `Consumption_item`; split payment | Baixa de ingredientes por venda; múltiplas formas de pagamento no PDV. |

## Testes como especificação
TI tem ~77 testes de carrinho, ~41 de horários/entregas e ~26 de pagamento. Antes de cada sprint, ler os cenários e reescrevê-los como testes Pest do nosso domínio multi-tenant.

## Não usar
Arquitetura de nenhum dos dois; extensões/temas pagos do marketplace TastyIgniter (outra licença); nomes, logos e imagens dos projetos.
