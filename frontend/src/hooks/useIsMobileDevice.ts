import {useEffect, useState} from "react";

const MOBILE_USER_AGENT = /Android|iPhone|iPad|iPod|Windows Phone|Mobile/i;

export const detectMobileDevice = (): boolean => {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return false;
    }

    if (MOBILE_USER_AGENT.test(navigator.userAgent)) {
        return true;
    }

    const coarsePointer = typeof window.matchMedia === 'function'
        && window.matchMedia('(pointer: coarse)').matches;

    return coarsePointer && (navigator.maxTouchPoints ?? 0) > 0 && window.innerWidth < 900;
};

export const useIsMobileDevice = (): boolean => {
    const [isMobile, setIsMobile] = useState(false);

    useEffect(() => {
        setIsMobile(detectMobileDevice());
    }, []);

    return isMobile;
};
