import {useQuery} from "@tanstack/react-query";
import {AxiosError} from "axios";
import {swishClientPublic} from "../api/swish.client.ts";
import {IdParam} from "../types.ts";
import {SwishPayment} from "../types/swish.ts";

export const GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY = 'getSwishPaymentPublic';

export const SWISH_POLL_INTERVAL_MS = 2500;

export const useGetSwishPaymentPublic = (eventId: IdParam, orderShortId: IdParam, enabled: boolean) => {
    return useQuery<SwishPayment, AxiosError>({
        queryKey: [GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY, eventId, orderShortId],

        queryFn: async () => {
            const {data} = await swishClientPublic.get(eventId, orderShortId);
            return data;
        },

        enabled,
        retry: (failureCount, error) => error.response?.status !== 404 && failureCount < 3,
        refetchOnWindowFocus: true,
        refetchInterval: (query) => query.state.data?.is_terminal ? false : SWISH_POLL_INTERVAL_MS,
    });
};
