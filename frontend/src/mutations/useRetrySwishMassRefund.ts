import {useMutation, useQueryClient} from '@tanstack/react-query';
import {swishMassRefundClient} from '../api/swish-mass-refund.client.ts';
import {IdParam} from '../types.ts';
import {GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY} from '../queries/useGetSwishMassRefundRuns.ts';
import {GET_SWISH_MASS_REFUND_RUN_QUERY_KEY} from '../queries/useGetSwishMassRefundRun.ts';

export const useRetrySwishMassRefund = (eventId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (runId: IdParam) => {
            return await swishMassRefundClient.retry(eventId, runId);
        },
        onSuccess: (_data, runId) => {
            queryClient.invalidateQueries({queryKey: [GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY, eventId]});
            queryClient.invalidateQueries({queryKey: [GET_SWISH_MASS_REFUND_RUN_QUERY_KEY, eventId, runId]});
        },
    });
};
