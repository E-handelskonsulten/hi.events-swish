import {i18n} from "@lingui/core";
import dayjs from "dayjs";
import {getConfig} from "./utilites/config.ts";

export type SupportedLocales =
    "en"
    | "de"
    | "fr"
    | "it"
    | "nl"
    | "pt"
    | "es"
    | "zh-cn"
    | "pt-br"
    | "vi"
    | "zh-hk"
    | "tr"
    | "hu"
    | "pl"
    | "se"
    | "sk"
    | "el";

export const availableLocales = ["en", "de", "fr", "it", "nl", "pt", "es", "zh-cn", "zh-hk", "pt-br", "vi", "tr", "hu", "pl", "se", "sk", "el"];

/* eslint-disable lingui/no-unlocalized-strings */
const localeAliases: Record<string, SupportedLocales> = {
    sv: "se",
    "sv-se": "se",
    "sv-fi": "se",
};

export const getDefaultLocale = (): SupportedLocales => {
    const configured = getConfig("VITE_DEFAULT_LOCALE")?.toLowerCase();
    if (configured && availableLocales.includes(configured)) {
        return configured as SupportedLocales;
    }

    return "en";
};

export const getIntlLocale = (locale?: string): string => {
    const appLocale = getSupportedLocale(locale || i18n.locale || getDefaultLocale());

    if (appLocale === "se") {
        return "sv-SE";
    }

    return typeof navigator !== "undefined" ? navigator.language : "en-US";
};
/* eslint-enable lingui/no-unlocalized-strings */

export const localeToFlagEmojiMap: Record<SupportedLocales, string> = {
    en: '🇬🇧',
    de: '🇩🇪',
    fr: '🇫🇷',
    it: '🇮🇹',
    nl: '🇳🇱',
    pt: '🇵🇹',
    es: '🇪🇸',
    "zh-cn": '🇨🇳',
    "zh-hk": '🇭🇰',
    "pt-br": '🇧🇷',
    vi: '🇻🇳',
    tr: '🇹🇷',
    hu: '🇭🇺',
    pl: '🇵🇱',
    se: '🇸🇪',
    sk: '🇸🇰',
    el: '🇬🇷',
};

export const localeToNameMap: Record<SupportedLocales, string> = {
    en: `English`,
    de: `German`,
    fr: `French`,
    it: `Italian`,
    nl: `Dutch`,
    pt: `Portuguese`,
    es: `Spanish`,
    "zh-cn": `Chinese`,
    "zh-hk": `Cantonese`,
    "pt-br": `Portuguese (Brazil)`,
    vi: `Vietnamese`,
    tr: `Turkish`,
    hu: `Hungarian`,
    pl: `Polish`,
    se: `Swedish`,
    sk: `Slovak`,
    el: `Greek`,
};

export const getLocaleName = (locale: SupportedLocales) => {
    return localeToNameMap[locale];
}

export const getClientLocale = () => {
    if (typeof window !== "undefined") {
        const storedLocale = document
            .cookie
            .split(";")
            .find((c) => c.includes("locale="))
            ?.split("=")[1];

        if (storedLocale) {
            return getSupportedLocale(storedLocale);
        }

        return getSupportedLocale(window.navigator.language);
    }

    return getDefaultLocale();
};

const dayjsLocaleLoaders: Partial<Record<SupportedLocales, () => Promise<unknown>>> = {
    de: () => import("dayjs/locale/de"),
    fr: () => import("dayjs/locale/fr"),
    it: () => import("dayjs/locale/it"),
    nl: () => import("dayjs/locale/nl"),
    pt: () => import("dayjs/locale/pt"),
    es: () => import("dayjs/locale/es"),
    "zh-cn": () => import("dayjs/locale/zh-cn"),
    "pt-br": () => import("dayjs/locale/pt-br"),
    vi: () => import("dayjs/locale/vi"),
    "zh-hk": () => import("dayjs/locale/zh-hk"),
    tr: () => import("dayjs/locale/tr"),
    hu: () => import("dayjs/locale/hu"),
    se: () => import("dayjs/locale/sv").then((module) => {
        dayjs.locale({...module.default, name: "se"}, undefined, true);
    }),
    sk: () => import("dayjs/locale/sk"),
    el: () => import("dayjs/locale/el"),
};

export async function dynamicActivateLocale(locale: string) {
    try {
        locale = availableLocales.includes(locale) ? locale : getDefaultLocale();
        const [module] = await Promise.all([
            import(`./locales/${locale}.po`),
            dayjsLocaleLoaders[locale as SupportedLocales]?.().catch((error) => console.error("Error loading dayjs locale:", error)),
        ]);
        i18n.load(locale, module.messages);
        i18n.activate(locale);
    } catch (error) {
        console.error("Error loading locale:", error);
        // i18n.activate("en");
    }
}

export const getSupportedLocale = (userLocale: string) => {
    const normalizedLocale = userLocale.toLowerCase().replace("_", "-");

    if (!normalizedLocale) {
        return getDefaultLocale();
    }

    if (localeAliases[normalizedLocale]) {
        return localeAliases[normalizedLocale];
    }

    if (availableLocales.includes(normalizedLocale)) {
        return normalizedLocale;
    }

    const mainLanguage = normalizedLocale.split('-')[0];

    if (localeAliases[mainLanguage]) {
        return localeAliases[mainLanguage];
    }

    const mainLocale = availableLocales.find(locale => locale.startsWith(mainLanguage));
    if (mainLocale) {
        return mainLocale;
    }

    return getDefaultLocale();
};
