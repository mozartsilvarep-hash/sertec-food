<?php

namespace App\Modules\Tenancy\Support;

use App\Support\Http\PublicHostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Lê o "tema.json" publicado pelo site do restaurante (contrato v1, ver
 * docs/TEMA-DO-SITE.md) para o painel "Importar do site".
 *
 * Proteções (o servidor busca uma URL escolhida pelo cliente):
 * - só sites que o próprio restaurante cadastrou em "Sites autorizados";
 * - só https na porta 443, caminho fixo /tema.json, sem seguir redirecionamento;
 * - o host precisa resolver para IP público, e esse IP é fixado na conexão;
 * - tempo e tamanho limitados; a resposta só é lida como JSON e validada campo a campo.
 */
final class SiteThemeImporter
{
    public const MAX_BYTES = 64 * 1024;

    public function __construct(private readonly PublicHostResolver $resolver) {}

    /**
     * @param  list<string>  $authorizedOrigins
     * @return array<string, mixed> conteúdo do tema.json
     */
    public function fetch(string $origin, array $authorizedOrigins): array
    {
        $origin = rtrim(strtolower(trim($origin)), '/');
        if (! in_array($origin, $authorizedOrigins, true)) {
            throw self::error('Cadastre o site em Promoções › Vitrine e aviso › Sites autorizados antes de importar.');
        }
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        if (parse_url($origin, PHP_URL_SCHEME) !== 'https' || $host === '' || ($port !== null && $port !== 443)) {
            throw self::error('Só é possível importar de um site com https, sem porta especial.');
        }
        $ip = $this->resolver->resolve($host);
        if ($ip === null) {
            throw self::error('Não foi possível encontrar o site (DNS) ou ele aponta para uma rede interna.');
        }
        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"]],
            ])->timeout(5)->connectTimeout(3)->accept('application/json')
                ->withUserAgent('SerTecFood-TemaImport/1')
                ->get($origin.'/tema.json');
        } catch (ConnectionException) {
            throw self::error('O site não respondeu. Tente de novo em instantes.');
        }

        if ($response->status() !== 200) {
            throw self::error("O site respondeu {$response->status()} em /tema.json. Publique o arquivo do tema no site.");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw self::error('O arquivo tema.json é grande demais.');
        }
        $json = json_decode($body, true);
        if (! is_array($json) || ($json['versao'] ?? null) !== 1) {
            throw self::error('O arquivo tema.json não está no formato esperado (versão 1).');
        }

        return $json;
    }

    private static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['origin' => $message]);
    }
}
