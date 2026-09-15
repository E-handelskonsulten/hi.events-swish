import {useMutation, useQueryClient} from '@tanstack/react-query';
import {organizerBillingClient, UpsertOrganizerBillingSettingsRequest} from '../api/organizer-billing.client.ts';
import {IdParam} from '../types.ts';
import {GET_ORGANIZER_BILLING_SETTINGS_QUERY_KEY} from '../queries/useGetOrganizerBillingSettings.ts';

export const useUpdateOrganizerBillingSettings = (organizerId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: UpsertOrganizerBillingSettingsRequest) => {
            return await organizerBillingClient.upsert(organizerId, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [GET_ORGANIZER_BILLING_SETTINGS_QUERY_KEY, organizerId],
            });
        },
    });
};
