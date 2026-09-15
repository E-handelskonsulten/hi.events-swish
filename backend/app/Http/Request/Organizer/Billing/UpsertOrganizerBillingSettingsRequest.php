<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Organizer\Billing;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Sms\SmsSenderName;

class UpsertOrganizerBillingSettingsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'sms_enabled' => ['required', 'boolean'],
            'sms_sender_name' => ['nullable', 'string', 'regex:'.SmsSenderName::PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'sms_sender_name.regex' => __('The SMS sender must be 3-11 letters or digits, start with a letter and contain no spaces.'),
        ];
    }
}
