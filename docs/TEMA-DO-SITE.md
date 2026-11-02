# Tema da loja e `tema.json` do site

Cada loja pode ter a "cara" do site do restaurante: cores de fundo e texto, fontes e cantos. O layout (cardápio, carrinho, checkout) é o mesmo para todos; muda só a pele.

## O que o tema controla

| Campo | Onde aparece | Regra |
|---|---|---|
| Cor principal (`brand_color`) | botões, selos, carrinho | contraste AA com texto branco |
| `colors.canvas` (fundo) | fundo da página | — |
| `colors.surface` (cartões) | produtos, carrinho, formulários | — |
| `colors.ink` (texto) | títulos e textos; fundo dos avisos | AA sobre fundo e cartões; AA com texto branco |
| `colors.muted` (texto suave) | descrições | AA sobre fundo e cartões |
| `colors.line` (linhas) | bordas | — |
| `colors.accent` (destaque) | faixa do topo sem capa | vazio = cor principal |
| `fonts.display` / `fonts.body` / `fonts.script` | títulos / texto / nome da loja em cursiva | lista fechada (abaixo) |
| `radius` | cantos de botões e cartões | `square`, `soft` (padrão), `round` |
| Padronagem (`pattern`) | desenho repetido na faixa do topo sem capa | PNG/WebP, vira azulejo quadrado de 600 px |

Fontes disponíveis (OFL, auto-hospedadas pelo app, sem Google Fonts):
- **Títulos:** `bricolage-grotesque` (padrão), `gilda-display`, `playfair-display`, `fraunces`, `dm-serif-display`, `cormorant-garamond`, `poppins`, `montserrat`, `oswald`, `bebas-neue`, `baloo-2`, `lobster`.
- **Texto:** `system` (padrão), `jost`, `inter`, `nunito`, `poppins`, `lato`, `montserrat`, `dm-sans`.
- **Assinatura:** `great-vibes`, `dancing-script`, `pacifico`, `sacramento`, `parisienne`.

A lista está em dois lugares que precisam bater (há teste): `backend/app/Modules/Tenancy/Support/StoreTheme.php` e `frontend/packages/ui/src/theme.ts`. Para incluir uma fonte: adicione o pacote Fontsource em `packages/ui`, a entrada em `FONTS` e a chave no `StoreTheme`.

## Painel do restaurante → Marca → Tema da loja

- **Modelos prontos** (Padrão, Confeitaria, Pizzaria, Hamburgueria, Natural) como ponto de partida.
- **Importar do site:** lê o `tema.json` de um site cadastrado em *Promoções › Vitrine e aviso › Sites autorizados* e preenche o formulário. Nada é salvo até clicar em "Salvar marca".
- Prévia ao vivo com as fontes reais; o botão de salvar fica bloqueado se o contraste não passar.

## Contrato `tema.json` v1 (publicado pelo site)

Arquivo estático na raiz do site: `https://<site>/tema.json`.

```json
{
  "versao": 1,
  "cores": {
    "principal": "#4A2C23",
    "fundo": "#FFF7EE",
    "superficie": "#FFFFFF",
    "texto": "#4A2C23",
    "textoSuave": "#6B4C40",
    "linha": "#E9D9C9",
    "destaque": "#A9CDEE"
  },
  "fontes": { "titulos": "gilda-display", "texto": "jost", "assinatura": "great-vibes" },
  "cantos": "redondo"
}
```

- Todos os campos são opcionais, menos `versao`. `cantos`: `reto`, `suave` ou `redondo`.
- Fonte fora da lista é ignorada com aviso; cor principal clara demais também (fica a atual).
- Sites que a SerTec entrega devem publicar esse arquivo com os mesmos valores do CSS do site.

## Segurança

- O servidor só busca `https://<origem>/tema.json` de origens que o próprio restaurante autorizou; porta 443; sem seguir redirecionamento; 5 s e 64 KB no máximo; limite de 6 importações por minuto por loja.
- O host precisa resolver só para IPs públicos (`PublicHostResolver`), e o IP checado é fixado na conexão (`CURLOPT_RESOLVE`), contra SSRF e DNS rebinding.
- O tema só aceita `#rrggbb`, chaves de fonte da lista e três estilos de canto. O CSS injetado no HTML do cardápio é montado no servidor só com esses valores (`StoreTheme::cssVariables`), e o shell confere o formato de novo antes de injetar.
