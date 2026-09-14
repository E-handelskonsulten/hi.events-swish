import {useQuery} from '@tanstack/react-query';
import {swishMassRefundClient} from '../api/swish-mass-refund.client.ts';
import {IdParam} from '../types.ts';

export const GET_SWISH_MASS_REFUND_RUN_QUERY_KEY = 'swishMassRefundRun';

const ACTIVE_POLL_INTERVAL_MS = 3000;

export const useGetSwishMassRefundRun = (eventId: IdParam, runId: IdParam | null | undefined) => {
    return useQuery({
        queryKey: [GET_SWISH_MASS_REFUND_RUN_QUERY_KEY, eventId, runId],
        queryFn: async () => {
            const {data} = await swishMassRefundClient.run(eventId, runId as IdParam);
            return data;
        },
        enabled: !!eventId && !!runId,
        refetchInterval: (query) => query.state.data && query.state.data.status !== 'COMPLETED'
            ? ACTIVE_POLL_INTERVAL_MS
            : false,
    });
};
