import {useQuery} from '@tanstack/react-query';
import {organizerSwishClient} from '../api/organizer-swish.client.ts';
import {IdParam} from '../types.ts';

export const GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY = 'organizerSwishSettings';

export const useGetOrganizerSwishSettings = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_ORGANIZER_SWISH_SETTINGS_QUERY_KEY, organizerId],
        queryFn: async () => organizerSwishClient.get(organizerId),
        enabled: !!organizerId,
    });
};
