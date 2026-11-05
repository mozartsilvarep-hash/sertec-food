@extends('errors::layout')

@section('title', 'Sua sessão expirou')
@section('code', 'Erro 419')
@section('icon')<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>@endsection
@section('message')<p>Por segurança, a página ficou aberta tempo demais. Recarregue e tente de novo.</p>@endsection
@section('actions')<a class="cheio" href="" title="Carregar a página de novo">Tentar de novo</a>@endsection
