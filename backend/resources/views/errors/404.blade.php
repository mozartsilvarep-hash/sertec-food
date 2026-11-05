@extends('errors::layout')

@section('title', 'Página não encontrada')
@section('code', 'Erro 404')
@section('icon')<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8.5 11h5"/></svg>@endsection
@section('message')<p>O endereço pode ter sido digitado errado ou a página não existe mais.</p>@endsection
@section('actions')<a class="cheio" href="/" title="Ir para a página inicial">Ir para o início</a>@endsection
