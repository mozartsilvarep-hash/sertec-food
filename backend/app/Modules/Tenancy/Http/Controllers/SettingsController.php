<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Support\BrandColor;
use App\Modules\Tenancy\Support\BrandImageStore;
use App\Modules\Tenancy\Support\Cnpj;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Configurações visíveis do estabelecimento. Nome e slug são alterados
 * apenas pela SerTec (painel de plataforma).
 */
class SettingsController
{
    public function show(TenantContext $context): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($context)]);
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'brand_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && ! BrandColor::isAccessible($value)) {
                    $fail('Cor clara demais: o texto branco ficaria ilegível. Escolha um tom mais escuro.');
                }
            }],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && ! Cnpj::isValid($value)) {
                    $fail('CNPJ inválido. Confira os números.');
                }
            }],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^@?[A-Za-z0-9._]{1,30}$/'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                $digits = preg_replace('/\D/', '', (string) $value);
                if (is_string($value) && ! preg_match('/^(55)?[1-9]{2}9?\d{8}$/', (string) $digits)) {
                    $fail('Informe o WhatsApp com DDD.');
                }
            }],
        ], [
            'instagram.regex' => 'Use só o @ do perfil (letras, números, ponto e sublinhado).',
        ], [
            'legal_name' => 'razão social',
            'instagram' => 'Instagram',
        ]);

        if (isset($data['brand_color'])) {
            $data['brand_color'] = strtolower($data['brand_color']);
        }
        if (array_key_exists('cnpj', $data)) {
            $data['cnpj'] = $data['cnpj'] === null ? null : Cnpj::normalize($data['cnpj']);
        }
        if (array_key_exists('instagram', $data)) {
            $data['instagram'] = $data['instagram'] === null ? null : ltrim($data['instagram'], '@');
        }
        if (array_key_exists('whatsapp', $data) && $data['whatsapp'] !== null) {
            $digits = (string) preg_replace('/\D/', '', $data['whatsapp']);
            $data['whatsapp'] = str_starts_with($digits, '55') && strlen($digits) > 11 ? $digits : '55'.$digits;
        }
        if (array_key_exists('legal_name', $data) && is_string($data['legal_name'])) {
            $data['legal_name'] = trim($data['legal_name']) ?: null;
        }

        $tenant = $context->get();
        $tenant->fill($data)->save();
        Cache::forget("storefront-meta:{$tenant->id}");
        $audit->record('tenant.settings_updated', $tenant, ['fields' => array_keys($data)]);

        return new JsonResponse(['data' => $this->present($context)]);
    }

    /** POST merchant/settings/{logo|cover} — envia a imagem (multipart "image"). */
    public function uploadImage(Request $request, string $kind, TenantContext $context, BrandImageStore $images, AuditLogger $audit): JsonResponse
    {
        abort_unless(isset(BrandImageStore::KINDS[$kind]), 404);
        $request->validate(['image' => ['required', 'file']], ['image.required' => 'Escolha uma imagem.']);
        /** @var UploadedFile $file */
        $file = $request->file('image');
        $tenant = $context->get();
        $images->store($tenant, $kind, $file);
        Cache::forget("storefront-meta:{$tenant->id}");
        $audit->record('tenant.brand_image_updated', $tenant, ['kind' => $kind]);

        return new JsonResponse(['data' => $this->present($context)]);
    }

    public function deleteImage(string $kind, TenantContext $context, BrandImageStore $images, AuditLogger $audit): JsonResponse
    {
        abort_unless(isset(BrandImageStore::KINDS[$kind]), 404);
        $tenant = $context->get();
        $images->remove($tenant, $kind);
        Cache::forget("storefront-meta:{$tenant->id}");
        $audit->record('tenant.brand_image_removed', $tenant, ['kind' => $kind]);

        return new JsonResponse(['data' => $this->present($context)]);
    }

    /** @return array<string, mixed> */
    private function present(TenantContext $context): array
    {
        $tenant = $context->get();

        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'description' => $tenant->description,
            'brand_color' => $tenant->brand_color,
            'logo_url' => BrandImageStore::url($tenant->logo_key),
            'cover_url' => BrandImageStore::url($tenant->cover_key),
            'legal_name' => $tenant->legal_name,
            'cnpj' => $tenant->cnpj === null ? null : Cnpj::format($tenant->cnpj),
            'instagram' => $tenant->instagram,
            'whatsapp' => $tenant->whatsapp,
        ];
    }
}
