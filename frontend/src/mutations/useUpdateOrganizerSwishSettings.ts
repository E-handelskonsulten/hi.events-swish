import {useMutation, useQueryClient} from '@tanstack/react-query';
import {organizerSwishClient, UpsertOrganizerSwishSettingsRequest} from '../api/organizer-swish.client.ts';
import {IdParam} from '../types.ts';
import {GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY} from '../queries/useGetOrganizerSwishSettings.ts';

export const useUpdateOrganizerSwishSettings = (organizerId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: UpsertOrganizerSwishSettingsRequest) => {
            return await organizerSwishClient.upsert(organizerId, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY, organizerId],
            });
        },
    });
};
