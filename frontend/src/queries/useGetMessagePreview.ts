import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {MessagePreview, MessagePreviewRequest, messagesClient} from "../api/messages.client.ts";

export const GET_MESSAGE_PREVIEW_QUERY_KEY = 'getMessagePreview';

export const useGetMessagePreview = (eventId: IdParam, request: MessagePreviewRequest, enabled: boolean) => {
    return useQuery<MessagePreview>({
        queryKey: [GET_MESSAGE_PREVIEW_QUERY_KEY, eventId, request],
        queryFn: async () => {
            const {data} = await messagesClient.preview(eventId, request);
            return data;
        },
        enabled: enabled && !!eventId,
        placeholderData: (previous) => previous,
        staleTime: 10_000,
        retry: false,
    });
};
