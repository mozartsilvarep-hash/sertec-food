<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\FractionPricingRule;
use App\Modules\Catalog\Enums\OptionGroupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // POST e PUT recebem o recurso completo (o formulário sempre envia tudo).
        $required = 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::enum(OptionGroupType::class)],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'max_select' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'free_quantity' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'pricing_rule' => ['nullable', Rule::enum(FractionPricingRule::class), Rule::requiredIf(fn () => $this->input('type') === OptionGroupType::Fraction->value)],
            'active' => ['sometimes', 'boolean'],
            'options' => [$required, 'array', 'min:1', 'max:100'],
            'options.*.id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'options.*.name' => ['required', 'string', 'max:120'],
            'options.*.description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'options.*.price_delta' => ['sometimes', 'string', ProductRequest::MONEY],
            'options.*.max_quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'options.*.active' => ['sometimes', 'boolean'],
            'options.*.variant_prices' => ['sometimes', 'array', 'max:20'],
            'options.*.variant_prices.*.variant' => ['required', 'string', 'size:26'],
            'options.*.variant_prices.*.price' => ['required', 'string', ProductRequest::MONEY],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $min = (int) $this->input('min_select', 0);
            $max = (int) $this->input('max_select', 1);
            if ($min > $max) {
                $v->errors()->add('min_select', 'O mínimo não pode ser maior que o máximo.');
            }
            if ($this->input('type') === OptionGroupType::Fraction->value && $max > 4) {
                $v->errors()->add('max_select', 'Pizza aceita no máximo 4 sabores.');
            }
        });
    }
}
