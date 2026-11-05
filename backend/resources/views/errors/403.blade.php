@extends('errors::layout')

@section('title', 'Acesso negado')
@section('code', 'Erro 403')
@section('icon')<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>@endsection
@section('message')<p>Você não tem permissão para abrir esta página.</p>@endsection
@section('actions')<a class="cheio" href="/" title="Ir para a página inicial">Ir para o início</a>@endsection
