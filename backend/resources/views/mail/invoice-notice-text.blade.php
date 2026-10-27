Olá, {{ $name }}!

@if ($kind === 'paid')
Recebemos o pagamento de {{ $amount }} da mensalidade do {{ $store }}, referente a {{ $period }}. Obrigado!
@elseif ($kind === 'overdue')
A mensalidade do {{ $store }} ({{ $amount }}, período {{ $period }}) venceu em {{ $due }}. Para evitar a suspensão do cardápio, pague pelo Pix no painel.
@else
A mensalidade do {{ $store }} é de {{ $amount }} (período {{ $period }}) e vence em {{ $due }}. Você paga por Pix direto no painel.
@endif
@if ($kind !== 'paid' && $url)

{{ $url }}
@endif

SerTec Food · mensagem automática.
