<?php

namespace App\Modules\Tenancy\Support;

/**
 * Tema da loja: a "pele" do cardápio (cores de fundo e texto, fontes e cantos)
 * guardada em tenants.theme_json. Só valores fechados: cores hexadecimais,
 * fontes de uma lista curada (auto-hospedadas no app, OFL) e um estilo de
 * canto. Nada de CSS livre por cliente (DESIGN.md). A cor principal continua
 * em tenants.brand_color.
 *
 * Contraste AA (4,5:1) é exigido para todo texto: texto e texto suave sobre o
 * fundo e sobre os cartões, e o texto principal também sobre branco (avisos).
 *
 * As chaves de fonte precisam bater com frontend/packages/ui/src/theme.ts.
 */
final class StoreTheme
{
    public const MIN_CONTRAST = 4.5;

    /** Valores padrão (iguais a frontend/packages/ui/src/tokens.css). */
    public const DEFAULT_COLORS = [
        'canvas' => '#fafaf9',
        'surface' => '#ffffff',
        'ink' => '#1c1917',
        'muted' => '#57534e',
        'line' => '#e7e5e4',
        'accent' => null,
    ];

    /** @var list<string> */
    public const DISPLAY_FONTS = [
        'bricolage-grotesque', 'gilda-display', 'playfair-display', 'fraunces', 'dm-serif-display',
        'cormorant-garamond', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'baloo-2', 'lobster',
    ];

    /** @var list<string> */
    public const BODY_FONTS = ['system', 'jost', 'inter', 'nunito', 'poppins', 'lato', 'montserrat', 'dm-sans'];

    /** @var list<string> */
    public const SCRIPT_FONTS = ['great-vibes', 'dancing-script', 'pacifico', 'sacramento', 'parisienne'];

    /** @var list<string> */
    public const RADII = ['square', 'soft', 'round'];

    /** Nomes das cores para as mensagens de erro. */
    private const LABELS = [
        'canvas' => 'fundo',
        'surface' => 'cartões',
        'ink' => 'texto',
        'muted' => 'texto suave',
        'line' => 'linhas',
        'accent' => 'destaque',
    ];

    /**
     * Normaliza e valida o tema enviado pelo painel.
     *
     * @param  array<string, mixed>  $input
     * @return array{theme: array{colors: array<string, string>, fonts: array<string, string>, radius?: string}, errors: array<string, string>}
     */
    public static function parse(array $input): array
    {
        $errors = [];
        $theme = ['colors' => [], 'fonts' => []];

        $colors = is_array($input['colors'] ?? null) ? $input['colors'] : [];
        foreach (array_keys(self::DEFAULT_COLORS) as $key) {
            $value = $colors[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $errors["theme.colors.{$key}"] = 'Cor inválida: use o formato #RRGGBB.';

                continue;
            }
            $theme['colors'][$key] = strtolower($value);
        }

        $fonts = is_array($input['fonts'] ?? null) ? $input['fonts'] : [];
        foreach (['display' => self::DISPLAY_FONTS, 'body' => self::BODY_FONTS, 'script' => self::SCRIPT_FONTS] as $key => $allowed) {
            $value = $fonts[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $errors["theme.fonts.{$key}"] = 'Fonte não disponível.';

                continue;
            }
            $theme['fonts'][$key] = $value;
        }

        $radius = $input['radius'] ?? null;
        if ($radius !== null && $radius !== '') {
            if (! is_string($radius) || ! in_array($radius, self::RADII, true)) {
                $errors['theme.radius'] = 'Estilo de canto inválido.';
            } else {
                $theme['radius'] = $radius;
            }
        }

        if ($errors === []) {
            $errors = self::contrastErrors($theme['colors']);
        }

        return ['theme' => $theme, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    public static function contrastErrors(array $colors): array
    {
        $c = array_merge(self::DEFAULT_COLORS, $colors);
        $pairs = [
            ['ink', 'canvas'], ['ink', 'surface'], ['muted', 'canvas'], ['muted', 'surface'],
        ];
        $errors = [];
        foreach ($pairs as [$text, $background]) {
            $ratio = BrandColor::contrast((string) $c[$text], (string) $c[$background]);
            if ($ratio < self::MIN_CONTRAST && ! isset($errors["theme.colors.{$text}"])) {
                $errors["theme.colors.{$text}"] = sprintf(
                    'A cor de %s fica pouco legível sobre a cor de %s (%s:1; mínimo 4,5:1).',
                    self::LABELS[$text], self::LABELS[$background], number_format($ratio, 1, ',', ''),
                );
            }
        }
        // Avisos rápidos usam texto branco sobre a cor do texto.
        if (! isset($errors['theme.colors.ink']) && BrandColor::contrastWithWhite((string) $c['ink']) < self::MIN_CONTRAST) {
            $errors['theme.colors.ink'] = 'A cor do texto precisa ser escura (texto branco por cima dela nos avisos).';
        }

        return $errors;
    }

    /**
     * Tema para o cardápio, já com os valores padrão nos campos vazios.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{colors: array<string, string|null>, fonts: array{display: string, body: string, script: string|null}, radius: string}
     */
    public static function resolved(?array $stored): array
    {
        $stored ??= [];
        $colors = is_array($stored['colors'] ?? null) ? $stored['colors'] : [];
        $fonts = is_array($stored['fonts'] ?? null) ? $stored['fonts'] : [];

        return [
            'colors' => array_merge(self::DEFAULT_COLORS, array_intersect_key($colors, self::DEFAULT_COLORS)),
            'fonts' => [
                'display' => (string) ($fonts['display'] ?? 'bricolage-grotesque'),
                'body' => (string) ($fonts['body'] ?? 'system'),
                'script' => isset($fonts['script']) ? (string) $fonts['script'] : null,
            ],
            'radius' => (string) ($stored['radius'] ?? 'soft'),
        ];
    }

    /**
     * Variáveis CSS do tema, para o HTML inicial do cardápio (sem "piscar"
     * com as cores padrão). Só valores já validados entram aqui.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function cssVariables(?array $stored, ?string $brandColor): string
    {
        $vars = [];
        $colors = is_array($stored['colors'] ?? null) ? $stored['colors'] : [];
        foreach (array_keys(self::DEFAULT_COLORS) as $key) {
            $value = $colors[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-f]{6}$/', $value)) {
                $vars[] = "--theme-{$key}:{$value}";
            }
        }
        if ($brandColor !== null && preg_match('/^#[0-9a-f]{6}$/', $brandColor)) {
            $vars[] = "--brand-600:{$brandColor}";
        }

        return $vars === [] ? '' : ':root{'.implode(';', $vars).'}';
    }

    /**
     * Converte o tema publicado por um site (contrato "tema.json" v1, chaves em
     * português) para o formato do painel. Campos desconhecidos são ignorados.
     *
     * @param  array<string, mixed>  $site
     * @return array{brand_color: string|null, theme: array<string, mixed>}
     */
    public static function fromSiteContract(array $site): array
    {
        $cores = is_array($site['cores'] ?? null) ? $site['cores'] : [];
        $fontes = is_array($site['fontes'] ?? null) ? $site['fontes'] : [];
        $map = ['fundo' => 'canvas', 'superficie' => 'surface', 'texto' => 'ink', 'textoSuave' => 'muted', 'linha' => 'line', 'destaque' => 'accent'];
        $colors = [];
        foreach ($map as $from => $to) {
            if (isset($cores[$from]) && is_string($cores[$from])) {
                $colors[$to] = $cores[$from];
            }
        }
        $radius = ['reto' => 'square', 'suave' => 'soft', 'redondo' => 'round'][$site['cantos'] ?? ''] ?? null;

        return [
            'brand_color' => isset($cores['principal']) && is_string($cores['principal']) ? $cores['principal'] : null,
            'theme' => array_filter([
                'colors' => $colors,
                'fonts' => array_filter([
                    'display' => $fontes['titulos'] ?? null,
                    'body' => $fontes['texto'] ?? null,
                    'script' => $fontes['assinatura'] ?? null,
                ], fn ($v) => is_string($v) && $v !== ''),
                'radius' => $radius,
            ], fn ($v) => $v !== null),
        ];
    }
}
