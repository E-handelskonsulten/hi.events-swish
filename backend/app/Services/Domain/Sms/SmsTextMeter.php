<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\Services\Domain\Sms\DTO\SmsTextMeasurementDTO;

class SmsTextMeter
{
    public const GSM_SINGLE_PART = 160;

    public const GSM_MULTI_PART = 153;

    public const UCS2_SINGLE_PART = 70;

    public const UCS2_MULTI_PART = 67;

    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = '^{}\\[~]|€';

    public function measure(string $text): SmsTextMeasurementDTO
    {
        $characters = mb_strlen($text);
        $isGsm = $this->isGsm($text);
        $units = $isGsm ? $this->gsmUnits($text) : $characters;

        [$single, $multi] = $isGsm
            ? [self::GSM_SINGLE_PART, self::GSM_MULTI_PART]
            : [self::UCS2_SINGLE_PART, self::UCS2_MULTI_PART];

        $parts = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));

        return new SmsTextMeasurementDTO(
            characters: $characters,
            encoding: $isGsm ? 'GSM-7' : 'UCS-2',
            parts: $parts,
            singlePartLimit: $single,
        );
    }

    private function isGsm(string $text): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (! str_contains(self::GSM_BASIC, $character) && ! str_contains(self::GSM_EXTENDED, $character)) {
                return false;
            }
        }

        return true;
    }

    private function gsmUnits(string $text): int
    {
        $units = 0;

        foreach (mb_str_split($text) as $character) {
            $units += str_contains(self::GSM_EXTENDED, $character) ? 2 : 1;
        }

        return $units;
    }
}
