<?php

namespace HiEvents\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;

trait SendsAsOrganizer
{
    protected function organizerFrom(): Address
    {
        return new Address(
            config('mail.from.address'),
            $this->organizer->getName() ?: config('mail.from.name'),
        );
    }
}
