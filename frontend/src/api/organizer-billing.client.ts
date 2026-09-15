import {api} from "./client.ts";
import {IdParam} from "../types.ts";

export interface OrganizerBillingSettings {
    id: number;
    organizer_id: number;
    sms_enabled: boolean;
    sms_sender_name: string | null;
    sms_lead_hours: number;
    sms_fee_per_message: number;
    updated_at: string;
}

export interface OrganizerBillingSettingsResponse {
    data: OrganizerBillingSettings | null;
    meta: {
        sms_configured: boolean;
        default_sms_sender_name: string;
        default_sms_fee_per_message: number;
    };
}

export interface UpsertOrganizerBillingSettingsRequest {
    sms_enabled: boolean;
    sms_sender_name: string | null;
    sms_lead_hours: number;
}

export const organizerBillingClient = {
    get: async (organizerId: IdParam) => {
        const response = await api.get<OrganizerBillingSettingsResponse>(
            `organizers/${organizerId}/billing-settings`,
        );
        return response.data;
    },

    upsert: async (organizerId: IdParam, data: UpsertOrganizerBillingSettingsRequest) => {
        const response = await api.put<{data: OrganizerBillingSettings}>(
            `organizers/${organizerId}/billing-settings`,
            data,
        );
        return response.data;
    },
};
