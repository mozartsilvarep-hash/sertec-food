<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Converte ULIDs públicos vindos do cliente em models DO TENANT ATUAL.
 *
 * Não use a regra "exists" do validador para IDs de tenant: ela consulta a
 * tabela sem o TenantScope e confirmaria IDs de outros estabelecimentos.
 * Aqui a busca passa pelo escopo; ID de outro tenant = "não encontrado".
 */
final class PublicIds
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  list<string>  $ids
     * @return Collection<string, TModel> indexada por public_id, na ordem recebida
     */
    public static function resolve(string $model, array $ids, string $field): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<string, TModel> $found */
        $found = $model::query()->whereIn('public_id', $ids)->get()->keyBy('public_id');

        $missing = array_diff($ids, $found->keys()->all());
        if ($missing !== []) {
            throw ValidationException::withMessages([$field => 'Item não encontrado: '.implode(', ', $missing)]);
        }

        /** @var Collection<string, TModel> $ordered */
        $ordered = new Collection;
        foreach ($ids as $id) {
            $ordered->put($id, $found[$id]);
        }

        return $ordered;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function one(string $model, string $id, string $field): Model
    {
        /** @var TModel */
        return self::resolve($model, [$id], $field)->first();
    }
}
