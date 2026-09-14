import {useMutation, useQueryClient} from '@tanstack/react-query';
import {StartSwishMassRefundPayload, swishMassRefundClient} from '../api/swish-mass-refund.client.ts';
import {IdParam} from '../types.ts';
import {GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY} from '../queries/useGetSwishMassRefundRuns.ts';
import {GET_SWISH_MASS_REFUND_PREVIEW_QUERY_KEY} from '../queries/useGetSwishMassRefundPreview.ts';
import {GET_EVENT_ORDERS_QUERY_KEY} from '../queries/useGetEventOrders.ts';

export const useStartSwishMassRefund = (eventId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: StartSwishMassRefundPayload) => {
            return await swishMassRefundClient.start(eventId, payload);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY, eventId]});
            queryClient.invalidateQueries({queryKey: [GET_SWISH_MASS_REFUND_PREVIEW_QUERY_KEY, eventId]});
            queryClient.invalidateQueries({queryKey: [GET_EVENT_ORDERS_QUERY_KEY]});
        },
    });
};
