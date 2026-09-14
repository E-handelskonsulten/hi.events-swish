import {useQuery} from '@tanstack/react-query';
import {swishMassRefundClient} from '../api/swish-mass-refund.client.ts';
import {IdParam} from '../types.ts';

export const GET_SWISH_MASS_REFUND_PREVIEW_QUERY_KEY = 'swishMassRefundPreview';

export const useGetSwishMassRefundPreview = (eventId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_SWISH_MASS_REFUND_PREVIEW_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await swishMassRefundClient.preview(eventId);
            return data;
        },
        enabled: !!eventId && enabled,
        staleTime: 0,
    });
};
