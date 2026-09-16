<?php

namespace HiEvents\Http\Request\TaxOrFee;

use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\Enums\TaxType;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateTaxOrFeeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'calculation_type' => ['required', Rule::in(TaxCalculationType::valuesArray())],
            'type' => ['required', Rule::in(TaxType::valuesArray())],
            // @todo - add a sane max value for rate.
            'rate' => 'required|numeric:gt:0',
            'fixed_amount' => [
                'exclude_unless:calculation_type,'.TaxCalculationType::FIXED_PLUS_PERCENTAGE->name,
                'required_if:calculation_type,'.TaxCalculationType::FIXED_PLUS_PERCENTAGE->name,
                'numeric',
                'min:0',
            ],
            'is_active' => 'required|boolean',
            'is_default' => 'required|boolean',
            'description' => 'nullable|string',
        ];
    }
}
