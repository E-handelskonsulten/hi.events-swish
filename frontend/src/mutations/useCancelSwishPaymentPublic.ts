import {useMutation, useQueryClient} from "@tanstack/react-query";
import {swishClientPublic} from "../api/swish.client.ts";
import {IdParam} from "../types.ts";
import {GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY} from "../queries/useGetSwishPaymentPublic.ts";

export const useCancelSwishPaymentPublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, orderShortId}: {eventId: IdParam, orderShortId: IdParam}) =>
            swishClientPublic.cancel(eventId, orderShortId),

        onSuccess: (response, {eventId, orderShortId}) => {
            queryClient.setQueryData([GET_SWISH_PAYMENT_PUBLIC_QUERY_KEY, eventId, orderShortId], response.data);
        },
    });
};
