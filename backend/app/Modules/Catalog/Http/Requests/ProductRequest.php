<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public const MONEY = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // POST e PUT recebem o recurso completo (o formulário sempre envia tudo).
        $required = 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // PDV: código curto (único no restaurante) e botão de favorito.
            'code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('products', 'code')
                ->where('tenant_id', app(TenantContext::class)->id())->ignore($this->route('product')?->id)],
            'pos_favorite' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'variants' => [$required, 'array', 'min:1', 'max:20'],
            'variants.*.id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'variants.*.name' => ['required', 'string', 'max:80'],
            'variants.*.price' => ['required', 'string', self::MONEY],
            'variants.*.active' => ['sometimes', 'boolean'],
            'option_group_ids' => ['sometimes', 'array', 'max:30'],
            'option_group_ids.*' => ['string', 'size:26', 'distinct'],
            'category_ids' => ['sometimes', 'array', 'max:20'],
            'category_ids.*' => ['string', 'size:26', 'distinct'],
            // Disponibilidade: dias (0 = domingo … 6 = sábado) e horário opcionais.
            'available_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'available_days.*' => ['integer', 'between:0,6', 'distinct'],
            'available_from' => ['nullable', 'date_format:H:i', 'required_with:available_until'],
            'available_until' => ['nullable', 'date_format:H:i', 'required_with:available_from', 'different:available_from'],
            // Combo: espaços preenchidos por produtos do cardápio (ex.: "2 bolos de pote").
            'kind' => ['sometimes', 'in:item,combo'],
            'combo_slots' => ['nullable', 'array', 'max:10', 'required_if:kind,combo'],
            'combo_slots.*.id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'combo_slots.*.name' => ['required', 'string', 'max:80'],
            'combo_slots.*.quantity' => ['required', 'integer', 'between:1,20'],
            'combo_slots.*.product_ids' => ['required', 'array', 'min:1', 'max:60'],
            'combo_slots.*.product_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'código',
            'available_from' => 'início do horário',
            'available_until' => 'fim do horário',
            'combo_slots.*.name' => 'nome do item do combo',
            'combo_slots.*.quantity' => 'quantidade',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variants.*.price.regex' => 'Preço inválido: use o formato 12.50.',
            'code.regex' => 'Use só letras, números e hífen no código.',
            'code.unique' => 'Outro produto já usa este código.',
            'variants.min' => 'Informe ao menos um tamanho/preço.',
            'combo_slots.required_if' => 'Um combo precisa de ao menos um item (ex.: "2 bolos de pote").',
            'combo_slots.*.product_ids.required' => 'Escolha os produtos que podem entrar neste item do combo.',
            'available_until.different' => 'O horário final precisa ser diferente do inicial.',
        ];
    }
}
