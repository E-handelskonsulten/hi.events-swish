<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\Services\Domain\Sms\SmsTextMeter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsTextMeterTest extends TestCase
{
    #[DataProvider('texts')]
    public function test_it_counts_characters_encoding_and_parts(string $text, int $characters, string $encoding, int $parts): void
    {
        $measured = (new SmsTextMeter)->measure($text);

        $this->assertSame($characters, $measured->characters);
        $this->assertSame($encoding, $measured->encoding);
        $this->assertSame($parts, $measured->parts);
    }

    public static function texts(): array
    {
        return [
            'empty' => ['', 0, 'GSM-7', 0],
            'short gsm' => ['Hej! Dorrarna oppnar 19:00.', 27, 'GSM-7', 1],
            'gsm at the single part limit' => [str_repeat('a', 160), 160, 'GSM-7', 1],
            'gsm just over the limit' => [str_repeat('a', 161), 161, 'GSM-7', 2],
            'gsm two full parts' => [str_repeat('a', 306), 306, 'GSM-7', 2],
            'gsm three parts' => [str_repeat('a', 307), 307, 'GSM-7', 3],
            'extended gsm characters count double' => [str_repeat('a', 158).'€', 159, 'GSM-7', 1],
            'extended gsm pushing over the limit' => [str_repeat('a', 159).'€', 160, 'GSM-7', 2],
            'swedish letters are gsm' => ['Hej Örjan! Välkommen på lördag.', 31, 'GSM-7', 1],
            'en dash forces ucs2' => ['Lördagsklubben – demo', 21, 'UCS-2', 1],
            'ucs2 at the single part limit' => [str_repeat('–', 70), 70, 'UCS-2', 1],
            'ucs2 just over the limit' => [str_repeat('–', 71), 71, 'UCS-2', 2],
            'ucs2 three parts' => [str_repeat('–', 135), 135, 'UCS-2', 3],
        ];
    }
}
