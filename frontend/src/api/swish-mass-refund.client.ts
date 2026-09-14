import {api} from "./client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";

export type SwishMassRefundRunStatus = 'PENDING' | 'RUNNING' | 'COMPLETED';

export type SwishMassRefundItemStatus = 'PENDING' | 'PROCESSING' | 'REQUESTED' | 'SUCCEEDED' | 'FAILED' | 'SKIPPED';

export interface SwishMassRefundOrder {
    order_id: number;
    public_id: string;
    buyer_name: string | null;
    buyer_email: string | null;
    amount: number;
    reason: string | null;
    reason_label: string | null;
}

export interface SwishMassRefundTicketType {
    name: string;
    quantity: number;
    order_count: number;
    amount: number;
}

export interface SwishMassRefundPreview {
    event_id: number;
    event_title: string;
    currency: string;
    refundable_count: number;
    total_amount: number;
    manual_count: number;
    manual_amount: number;
    ticket_types: SwishMassRefundTicketType[];
    refundable_orders: SwishMassRefundOrder[];
    manual_orders: SwishMassRefundOrder[];
    active_run_id: number | null;
}

export interface SwishMassRefundItem {
    id: number;
    order_id: number;
    order_public_id: string;
    buyer_name: string | null;
    buyer_email: string | null;
    amount: number;
    status: SwishMassRefundItemStatus;
    attempts: number;
    error_code: string | null;
    error_message: string | null;
    processed_at: string | null;
}

export interface SwishMassRefundRun {
    id: number;
    event_id: number;
    status: SwishMassRefundRunStatus;
    currency: string;
    initiated_by_user_id: number | null;
    initiated_by_name: string | null;
    total_orders: number;
    total_amount: number;
    manual_count: number;
    pending_count: number;
    requested_count: number;
    succeeded_count: number;
    failed_count: number;
    skipped_count: number;
    succeeded_amount: number;
    notify_buyers: boolean;
    cancel_orders: boolean;
    summary: {
        ticket_types?: SwishMassRefundTicketType[];
        manual_orders?: SwishMassRefundOrder[];
        manual_amount?: number;
    } | null;
    started_at: string | null;
    completed_at: string | null;
    last_activity_at: string | null;
    created_at: string;
    items?: SwishMassRefundItem[];
}

export interface StartSwishMassRefundPayload {
    confirmation: string;
    notify_buyers: boolean;
    cancel_orders: boolean;
}

export const swishMassRefundClient = {
    preview: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<SwishMassRefundPreview>>(
            `events/${eventId}/swish-mass-refunds/preview`,
        );
        return response.data;
    },

    runs: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<SwishMassRefundRun[]>>(
            `events/${eventId}/swish-mass-refunds`,
        );
        return response.data;
    },

    run: async (eventId: IdParam, runId: IdParam) => {
        const response = await api.get<GenericDataResponse<SwishMassRefundRun>>(
            `events/${eventId}/swish-mass-refunds/${runId}`,
        );
        return response.data;
    },

    start: async (eventId: IdParam, payload: StartSwishMassRefundPayload) => {
        const response = await api.post<GenericDataResponse<SwishMassRefundRun>>(
            `events/${eventId}/swish-mass-refunds`,
            payload,
        );
        return response.data;
    },

    retry: async (eventId: IdParam, runId: IdParam) => {
        const response = await api.post<GenericDataResponse<SwishMassRefundRun>>(
            `events/${eventId}/swish-mass-refunds/${runId}/retry`,
        );
        return response.data;
    },
};
