import {useMutation, useQueryClient} from '@tanstack/react-query';
import {organizerSwishClient} from '../api/organizer-swish.client.ts';
import {IdParam} from '../types.ts';
import {GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY} from '../queries/useGetOrganizerSwishSettings.ts';

export const useTestOrganizerSwishSettings = (organizerId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => {
            return await organizerSwishClient.test(organizerId);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY, organizerId],
            });
        },
    });
};
