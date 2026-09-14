import {useMutation, useQueryClient} from "@tanstack/react-query";
import {swishClientPublic} from "../api/swish.client.ts";
import {IdParam} from "../types.ts";
import {CreateSwishPaymentPayload} from "../types/swish.ts";
import {GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY} from "../queries/useGetSwishPaymentPublic.ts";

export const useCreateSwishPaymentPublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, orderShortId, payload}: {
            eventId: IdParam,
            orderShortId: IdParam,
            payload: CreateSwishPaymentPayload,
        }) => swishClientPublic.create(eventId, orderShortId, payload),

        onSuccess: (response, {eventId, orderShortId}) => {
            queryClient.setQueryData([GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY, eventId, orderShortId], response.data);
        },
    });
};
