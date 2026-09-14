import {useQuery} from '@tanstack/react-query';
import {swishMassRefundClient} from '../api/swish-mass-refund.client.ts';
import {IdParam} from '../types.ts';

export const GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY = 'swishMassRefundRuns';

const ACTIVE_POLL_INTERVAL_MS = 5000;

export const useGetSwishMassRefundRuns = (eventId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_SWISH_MASS_REFUND_RUNS_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await swishMassRefundClient.runs(eventId);
            return data;
        },
        enabled: !!eventId && enabled,
        refetchInterval: (query) => query.state.data?.some((run) => run.status !== 'COMPLETED')
            ? ACTIVE_POLL_INTERVAL_MS
            : false,
    });
};
