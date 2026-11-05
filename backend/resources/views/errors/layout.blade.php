{{-- Páginas de erro fora da API (ex.: HTML do cardápio). A API responde JSON (ErrorRenderer). --}}
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title')</title>
<style>:root{color-scheme:light dark;--bg:#fafaf9;--card:#fff;--ink:#1c1917;--muted:#57534e;--line:#e7e5e4;--accent:#c2410c}
@media (prefers-color-scheme:dark){:root{--bg:#0c0a09;--card:#1c1917;--ink:#fafaf9;--muted:#a8a29e;--line:#292524;--accent:#fb923c}}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;display:grid;place-items:center;padding:24px 16px;background:var(--bg);color:var(--ink);font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased}
main{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:36px 28px;text-align:center}
.codigo{display:inline-block;font-size:.8rem;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
.icone{width:56px;height:56px;margin:0 auto 16px;display:block;color:var(--accent)}
h1{margin:6px 0 10px;font-size:clamp(1.4rem,5vw,1.75rem);line-height:1.2;text-wrap:balance}
p{margin:0 0 8px;color:var(--muted);text-wrap:pretty}
.acoes{margin-top:22px;display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
a,button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:999px;font:600 .95rem/1 inherit;text-decoration:none;cursor:pointer;border:1.5px solid var(--ink)}
.cheio{background:var(--ink);color:var(--bg)}.vazado{background:transparent;color:var(--ink)}
a:hover,button:hover{opacity:.88}a:focus-visible,button:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
small{display:block;margin-top:18px;font-size:.78rem;color:var(--muted)}</style>
</head>
<body>
<main>
@yield('icon')
<span class="codigo">@yield('code')</span>
<h1>@yield('title')</h1>
@yield('message')
<div class="acoes">@yield('actions')</div>
@php($requestId = \App\Http\Middleware\AssignRequestId::current(request()))
@if ($requestId)
<small title="Informe este código ao suporte para localizar o problema">Código do atendimento: {{ $requestId }}</small>
@endif
</main>
</body>
</html>
