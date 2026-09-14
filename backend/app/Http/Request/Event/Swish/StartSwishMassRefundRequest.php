<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Event\Swish;

use HiEvents\Http\Request\BaseRequest;

class StartSwishMassRefundRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:255'],
            'notify_buyers' => ['required', 'boolean'],
            'cancel_orders' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => __('Type the event name exactly as shown to confirm the mass refund.'),
        ];
    }
}
