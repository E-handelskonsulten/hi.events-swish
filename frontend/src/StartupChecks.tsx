import {useGetMe} from "./queries/useGetMe.ts";
import {useEffect} from "react";
import {i18n} from "@lingui/core";
import {availableLocales, dynamicActivateLocale} from "./locales.ts";

export const StartupChecks = () => {
    const meQuery = useGetMe();

    const setLocaleForLoggedInUser = () => {
        const hasLocaleCookie = typeof document !== 'undefined' && document.cookie.split(';').some((c) => c.trim().startsWith('locale='));

        if (hasLocaleCookie) {
            // If the user has a locale set in their cookies, we don't want to override it
            return;
        }

        const userLocale = meQuery.data?.locale;

        if (userLocale && availableLocales.includes(userLocale) && userLocale !== i18n.locale) {
            dynamicActivateLocale(userLocale);
        }
    };

    useEffect(() => {
        if (!meQuery.isSuccess) {
            return;
        }

        setLocaleForLoggedInUser();
    }, [meQuery.isSuccess]);

    return <></>;
}