@extends('mail.layout', ['title' => 'Teste de envio'])
@section('content')
<h1 style="font-size:22px;margin:8px 0 12px;">Envio de e-mails funcionando</h1>
<p style="margin:0 0 12px;">Olá, {{ $name }}!</p>
<p style="margin:0;">Este é um teste do painel SerTec. Se você está lendo, o servidor de e-mail está configurado e as mensagens de "Esqueci a senha" vão chegar.</p>
@endsection
