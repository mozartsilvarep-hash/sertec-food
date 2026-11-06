Olá, {{ $name }}!

@if ($kind === 'paid')
Recebemos o pagamento de {{ $amount }} da mensalidade do {{ $store }}, referente a {{ $period }}. Obrigado!
@elseif ($kind === 'overdue')
A mensalidade do {{ $store }} ({{ $amount }}, período {{ $period }}) venceu em {{ $due }}. Sem o pagamento, o sistema será bloqueado em {{ $blockDate }}. Pague pelo Pix no painel.
@elseif ($kind === 'block_soon')
A mensalidade do {{ $store }} ({{ $amount }}) venceu em {{ $due }} e ainda está em aberto. Em {{ $blockDate }} a loja online, o PDV e as mesas param de funcionar. Pague pelo Pix no painel para não ser bloqueado.
@elseif ($kind === 'blocked')
A mensalidade do {{ $store }} ({{ $amount }}) está em atraso desde {{ $due }} e o sistema foi bloqueado (loja online, PDV e mesas parados). Seus dados estão guardados. Pague pelo Pix no painel e tudo volta na hora.
@else
A mensalidade do {{ $store }} é de {{ $amount }} (período {{ $period }}) e vence em {{ $due }}. Você paga por Pix direto no painel.
@endif
@if ($kind !== 'paid' && $url)

{{ $url }}
@endif

SerTec Food · mensagem automática.
