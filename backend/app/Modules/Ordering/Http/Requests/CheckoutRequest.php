<?php

namespace App\Modules\Ordering\Http\Requests;

use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Não existe nenhum campo de preço aqui de propósito: valores enviados pelo
 * navegador são descartados pela validação e recalculados no servidor.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $delivery = fn () => $this->input('fulfillment.type') === FulfillmentType::Delivery->value;

        return [
            'cart_token' => ['required', 'string', 'size:48'],
            'customer.name' => ['required', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:25', function (string $attr, mixed $value, \Closure $fail) {
                if (! is_string($value) || Phone::normalize($value) === null) {
                    $fail('Informe um celular com DDD, ex.: (82) 99999-0000.');
                }
            }],
            'customer.email' => ['nullable', 'email', 'max:191'],
            'fulfillment.type' => ['required', Rule::enum(FulfillmentType::class)],
            'fulfillment.zone_id' => [Rule::requiredIf($delivery), 'nullable', 'string', 'size:26'],
            'fulfillment.address.street' => [Rule::requiredIf($delivery), 'nullable', 'string', 'max:150'],
            'fulfillment.address.number' => [Rule::requiredIf($delivery), 'nullable', 'string', 'max:20'],
            'fulfillment.address.complement' => ['nullable', 'string', 'max:80'],
            'fulfillment.address.neighborhood' => [Rule::requiredIf($delivery), 'nullable', 'string', 'max:80'],
            'fulfillment.address.reference' => ['nullable', 'string', 'max:150'],
            'payment.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment.change_for' => ['nullable', 'string', ProductRequest::MONEY],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer.name' => 'nome',
            'customer.phone' => 'celular',
            'fulfillment.address.street' => 'rua',
            'fulfillment.address.number' => 'número',
            'fulfillment.address.neighborhood' => 'bairro',
            'fulfillment.zone_id' => 'bairro',
        ];
    }
}
