<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Organizer\Swish;

use HiEvents\DomainObjects\Enums\SwishEnvironment;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpsertOrganizerSwishSettingsRequest extends BaseRequest
{
    public function rules(): array
    {
        $requiredWhenEnabled = Rule::requiredIf(fn () => $this->boolean('enabled'));

        return [
            'enabled' => ['required', 'boolean'],
            'environment' => ['required', 'string', Rule::in(SwishEnvironment::valuesArray())],
            'payee_alias' => [$requiredWhenEnabled, 'nullable', 'string', 'regex:/^\d{10,11}$/'],
            'cert_path' => [$requiredWhenEnabled, 'nullable', 'string', 'max:500'],
            'key_path' => [$requiredWhenEnabled, 'nullable', 'string', 'max:500'],
            'ca_path' => [$requiredWhenEnabled, 'nullable', 'string', 'max:500'],
            'key_passphrase' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'payee_alias.regex' => __('The Swish number must be 10 or 11 digits, for example 1234679304.'),
            'payee_alias.required' => __('A Swish number is required when Swish is enabled.'),
            'cert_path.required' => __('The certificate path is required when Swish is enabled.'),
            'key_path.required' => __('The private key path is required when Swish is enabled.'),
            'ca_path.required' => __('The root CA path is required when Swish is enabled.'),
        ];
    }
}
