@extends('errors::layout')

@section('title', 'Algo deu errado')
@section('code', 'Erro 500')
@section('icon')<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>@endsection
@section('message')<p>Tivemos um problema ao abrir esta página. O erro ficou registrado para a equipe analisar.</p><p>Tente de novo em instantes.</p>@endsection
@section('actions')<a class="cheio" href="" title="Carregar a página de novo">Tentar de novo</a><a class="vazado" href="/" title="Ir para a página inicial">Início</a>@endsection
