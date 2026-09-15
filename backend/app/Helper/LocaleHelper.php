<?php

namespace HiEvents\Helper;

use Carbon\CarbonInterface;
use HiEvents\Locale;
use Illuminate\Support\Facades\App;

class LocaleHelper
{
    private const ALIASES = [
        'sv' => 'se',
        'sv-se' => 'se',
        'sv-fi' => 'se',
    ];

    private const ICU_LOCALES = [
        'en' => 'en_US',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'it' => 'it_IT',
        'nl' => 'nl_NL',
        'hu' => 'hu_HU',
        'es' => 'es_ES',
        'pt' => 'pt_PT',
        'pt-br' => 'pt_BR',
        'zh-cn' => 'zh_CN',
        'se' => 'sv_SE',
        'zh-hk' => 'zh_HK',
        'vi' => 'vi_VN',
        'tr' => 'tr_TR',
        'pl' => 'pl_PL',
        'sk' => 'sk_SK',
        'el' => 'el_GR',
    ];

    private const DATE_FORMATS = [
        'en' => [
            'date' => 'MMMM D, YYYY',
            'time' => 'h:mm A',
            'dateTimeShort' => 'ddd, MMM D, YYYY · h:mm A',
            'dayAndTime' => 'dddd, MMMM D · h:mm A',
            'dateTimeWithZone' => 'MMMM D, YYYY [at] h:mm A (z)',
            'numericDate' => 'DD/MM/YYYY',
        ],
        'se' => [
            'date' => 'D MMMM YYYY',
            'time' => 'HH:mm',
            'dateTimeShort' => 'ddd D MMM YYYY · HH:mm',
            'dayAndTime' => 'dddd D MMMM · HH:mm',
            'dateTimeWithZone' => 'D MMMM YYYY [kl.] HH:mm (z)',
            'numericDate' => 'YYYY-MM-DD',
        ],
        'default' => [
            'date' => 'D MMMM YYYY',
            'time' => 'HH:mm',
            'dateTimeShort' => 'ddd D MMM YYYY · HH:mm',
            'dayAndTime' => 'dddd D MMMM · HH:mm',
            'dateTimeWithZone' => 'D MMMM YYYY, HH:mm (z)',
            'numericDate' => 'DD/MM/YYYY',
        ],
    ];

    public static function resolveAlias(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', $locale));

        if (isset(self::ALIASES[$normalized])) {
            return self::ALIASES[$normalized];
        }

        $base = explode('-', $normalized)[0];

        return self::ALIASES[$base] ?? $normalized;
    }

    public static function toIcuLocale(?string $appLocale = null): string
    {
        $appLocale = $appLocale ?? App::getLocale();

        return self::ICU_LOCALES[$appLocale] ?? self::ICU_LOCALES[Locale::EN->value];
    }

    public static function toCarbonLocale(?string $appLocale = null): string
    {
        return strtolower(explode('_', self::toIcuLocale($appLocale))[0]);
    }

    public static function formatDate(CarbonInterface $date, ?string $appLocale = null): string
    {
        return self::isoFormat($date, 'date', $appLocale);
    }

    public static function formatTime(CarbonInterface $date, ?string $appLocale = null): string
    {
        return self::isoFormat($date, 'time', $appLocale);
    }

    public static function formatDateTimeShort(CarbonInterface $date, ?string $appLocale = null): string
    {
        return self::isoFormat($date, 'dateTimeShort', $appLocale);
    }

    public static function formatDateTimeWithZone(CarbonInterface $date, ?string $appLocale = null): string
    {
        return self::isoFormat($date, 'dateTimeWithZone', $appLocale);
    }

    public static function formatNumericDate(CarbonInterface $date, ?string $appLocale = null): string
    {
        return self::isoFormat($date, 'numericDate', $appLocale);
    }

    public static function pattern(string $format, ?string $appLocale = null): string
    {
        $appLocale = $appLocale ?? App::getLocale();
        $formats = self::DATE_FORMATS[$appLocale] ?? self::DATE_FORMATS['default'];

        return $formats[$format];
    }

    private static function isoFormat(CarbonInterface $date, string $format, ?string $appLocale): string
    {
        return $date
            ->locale(self::toCarbonLocale($appLocale))
            ->isoFormat(self::pattern($format, $appLocale));
    }
}
