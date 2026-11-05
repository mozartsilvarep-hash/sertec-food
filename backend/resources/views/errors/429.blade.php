@extends('errors::layout')

@section('title', 'Muitas tentativas')
@section('code', 'Erro 429')
@section('icon')<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>@endsection
@section('message')<p>Recebemos muitos acessos seguidos deste aparelho. Aguarde um minuto e tente de novo.</p>@endsection
@section('actions')<a class="cheio" href="" title="Carregar a página de novo">Tentar de novo</a>@endsection
