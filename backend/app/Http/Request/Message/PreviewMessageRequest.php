<?php

namespace HiEvents\Http\Request\Message;

class PreviewMessageRequest extends SendMessageRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'subject' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:8000',
            'sms_body' => 'nullable|string|max:1000',
            'confirmation' => 'nullable|string',
        ]);
    }
}
