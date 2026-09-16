import {api} from "./client";
import {GenericPaginatedResponse, IdParam, Message, MessageChannel, MessagePurpose, OutgoingMessage, QueryFilters,} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";
import {AxiosResponse} from "axios";

export interface MessagePreviewRequest {
    message_type: string;
    channel: MessageChannel;
    purpose: MessagePurpose;
    sms_body?: string;
    attendee_ids?: IdParam[];
    product_ids?: IdParam[];
    order_id?: IdParam;
    order_statuses?: string[];
    event_occurrence_id?: number | null;
    event_occurrence_ids?: number[] | null;
}

export interface MessagePreview {
    email_recipients: number;
    sms_recipients: number;
    excluded_without_consent: number;
    excluded_without_phone: number;
    sms_available: boolean;
    sms_sender: string;
    sms_characters: number;
    sms_encoding: 'GSM-7' | 'UCS-2';
    sms_parts: number;
    sms_single_part_limit: number;
    sms_opt_out_suffix_length: number;
    sms_cost_per_recipient: number;
    sms_total_cost: number;
    currency: string;
    requires_confirmation: boolean;
    confirmation_word: string;
}

export const messagesClient = {
    preview: async (eventId: IdParam, request: MessagePreviewRequest) => {
        const response = await api.post<{data: MessagePreview}>(`events/${eventId}/messages/preview`, request);
        return response.data;
    },
    send: async (eventId: IdParam, messagesRequest: Message) => {
        return await api.post(`events/${eventId}/messages`, messagesRequest);
    },
    all: async (eventId: IdParam, pagination: QueryFilters) => {
        const response: AxiosResponse<GenericPaginatedResponse<Message>> = await api.get<GenericPaginatedResponse<Message>>(
            `events/${eventId}/messages` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },
    cancel: async (eventId: IdParam, messageId: IdParam) => {
        return await api.post(`events/${eventId}/messages/${messageId}/cancel`);
    },
    recipients: async (eventId: IdParam, messageId: IdParam, pagination: QueryFilters) => {
        const response: AxiosResponse<GenericPaginatedResponse<OutgoingMessage>> = await api.get<GenericPaginatedResponse<OutgoingMessage>>(
            `events/${eventId}/messages/${messageId}/recipients` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },
}
