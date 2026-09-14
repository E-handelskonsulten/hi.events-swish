import {IdParam} from "../types.ts";
import {getEmbedParentUrl} from "./iframeResize.ts";
import {eventCheckoutPath} from "./urlHelper.ts";
import {isSsr} from "./helpers.ts";

export const SWISH_PROVIDER_PARAM = 'swish';

const SWEDISH_MOBILE_PATTERN = /^(?:\+?46|0)7\d{8}$/;

export const normalizeSwedishMobileNumber = (input: string): string | null => {
    const digits = input.replace(/[\s\-().]/g, '').trim();

    if (!SWEDISH_MOBILE_PATTERN.test(digits)) {
        return null;
    }

    if (digits.startsWith('+46')) {
        return digits.slice(1);
    }

    if (digits.startsWith('0')) {
        return `46${digits.slice(1)}`;
    }

    return digits;
};

export const formatSwedishMobileNumber = (alias: string): string => {
    const national = alias.startsWith('46') ? `0${alias.slice(2)}` : alias;

    return national.replace(/^(\d{3})(\d{3})(\d{2})(\d{2})$/, '$1-$2 $3 $4');
};

export const buildSwishReturnUrl = (eventId: IdParam, orderShortId: IdParam, sessionId: string | null): string => {
    if (isSsr()) {
        return '';
    }

    const parentUrl = getEmbedParentUrl();

    if (parentUrl) {
        try {
            const url = new URL(parentUrl);
            if (url.protocol === 'https:' || url.protocol === 'http:') {
                url.searchParams.set('hievents_event', String(eventId));
                url.searchParams.set('hievents_order', String(orderShortId));
                url.searchParams.set('hievents_provider', SWISH_PROVIDER_PARAM);
                if (sessionId) {
                    url.searchParams.set('hievents_session', sessionId);
                }
                return url.toString();
            }
        } catch {
            return buildStandaloneReturnUrl(eventId, orderShortId, sessionId);
        }
    }

    return buildStandaloneReturnUrl(eventId, orderShortId, sessionId);
};

const buildStandaloneReturnUrl = (eventId: IdParam, orderShortId: IdParam, sessionId: string | null): string => {
    const url = new URL(eventCheckoutPath(eventId, orderShortId, 'payment_return'), window.location.origin);
    url.searchParams.set('provider', SWISH_PROVIDER_PARAM);
    if (sessionId) {
        url.searchParams.set('session_identifier', sessionId);
    }

    return url.toString();
};

export const buildSwishDeeplink = (paymentRequestToken: string, returnUrl: string): string => {
    return `swish://paymentrequest?token=${encodeURIComponent(paymentRequestToken)}&callbackurl=${encodeURIComponent(returnUrl)}`;
};
