import {useQuery} from '@tanstack/react-query';
import {organizerBillingClient} from '../api/organizer-billing.client.ts';
import {IdParam} from '../types.ts';

export const GET_ORGANIZER_BILLING_SETTINGS_QUERY_KEY = 'organizerBillingSettings';

export const useGetOrganizerBillingSettings = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_ORGANIZER_BILLING_SETTINGS_QUERY_KEY, organizerId],
        queryFn: async () => organizerBillingClient.get(organizerId),
        enabled: !!organizerId,
    });
};
