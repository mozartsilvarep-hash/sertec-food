@extends('mail.layout', ['title' => 'Mensalidade SerTec Food'])
@section('content')
@if ($kind === 'paid')
<h1 style="font-size:22px;margin:8px 0 12px;">Pagamento confirmado</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">Recebemos o pagamento de <strong>{{ $amount }}</strong> da mensalidade do <strong>{{ $store }}</strong>, referente a {{ $period }}. Obrigado!</p>
@elseif ($kind === 'overdue')
<h1 style="font-size:22px;margin:8px 0 12px;">Mensalidade em atraso</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">A mensalidade do <strong>{{ $store }}</strong> ({{ $amount }}, período {{ $period }}) venceu em {{ $due }}. Sem o pagamento, o sistema será bloqueado em <strong>{{ $blockDate }}</strong>. Pague pelo Pix no painel:</p>
@elseif ($kind === 'block_soon')
<h1 style="font-size:22px;margin:8px 0 12px;">O sistema será bloqueado em {{ $blockDate }}</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">A mensalidade do <strong>{{ $store }}</strong> ({{ $amount }}) venceu em {{ $due }} e ainda está em aberto. Em <strong>{{ $blockDate }}</strong> a loja online, o PDV e as mesas param de funcionar. Pague pelo Pix para não ser bloqueado:</p>
@elseif ($kind === 'blocked')
<h1 style="font-size:22px;margin:8px 0 12px;">Sistema bloqueado</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">A mensalidade do <strong>{{ $store }}</strong> ({{ $amount }}) está em atraso desde {{ $due }}, e o sistema foi bloqueado: a loja online, o PDV e as mesas estão parados. Seus dados estão guardados. Pague pelo Pix e tudo volta a funcionar na hora:</p>
@else
<h1 style="font-size:22px;margin:8px 0 12px;">Sua mensalidade vence em {{ $due }}</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">A mensalidade do <strong>{{ $store }}</strong> é de <strong>{{ $amount }}</strong> (período {{ $period }}). Você paga por Pix direto no painel, em poucos segundos:</p>
@endif
@if ($kind !== 'paid' && $url)
<p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#c2410c;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px;">Pagar com Pix</a></p>
@endif
<p style="margin:0;font-size:14px;color:#57534e;">Dúvidas? Responda para a equipe SerTec pelo WhatsApp de suporte.</p>
@endsection
