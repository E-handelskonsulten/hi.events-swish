<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Order;

use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Payment\Swish\SwishPayerAliasNormalizer;
use Illuminate\Validation\Rule;

class CreateSwishPaymentRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('payer_alias'))) {
            $this->merge([
                'payer_alias' => preg_replace('/[\s\-().]/', '', trim($this->input('payer_alias'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'flow' => ['required', 'string', Rule::in(SwishCheckoutFlow::valuesArray())],
            'payer_alias' => [
                Rule::requiredIf(fn () => $this->input('flow') === SwishCheckoutFlow::ECOMMERCE->value),
                'nullable',
                'string',
                'regex:'.SwishPayerAliasNormalizer::PATTERN,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'flow.in' => __('Invalid Swish checkout flow.'),
            'payer_alias.required' => __('Please enter the mobile number connected to your Swish account.'),
            'payer_alias.regex' => __('Please enter a valid Swedish mobile number, for example 070-123 45 67.'),
        ];
    }
}
