<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variants.*.price.regex' => 'Preço inválido: use o formato 12.50.',
            'variants.min' => 'Informe ao menos um tamanho/preço.',
        ];
    }
}
