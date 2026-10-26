@extends('mail.layout', ['title' => 'Redefinir sua senha'])
@section('content')
<h1 style="font-size:22px;margin:8px 0 12px;">Redefinir sua senha</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0 0 20px;">Recebemos um pedido para criar uma senha nova no <strong>{{ $panel }}</strong>. Toque no botão abaixo para continuar:</p>
<p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#c2410c;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px;">Criar senha nova</a></p>
<p style="margin:0 0 12px;font-size:14px;color:#57534e;">O link vale por {{ $minutes }} minutos e só pode ser usado uma vez. Se você não pediu, ignore este e-mail: sua senha continua a mesma.</p>
<p style="margin:0;font-size:12px;color:#78716c;word-break:break-all;">Se o botão não funcionar, copie este endereço no navegador:<br>{{ $url }}</p>
@endsection
