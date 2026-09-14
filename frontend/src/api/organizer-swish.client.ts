import {api} from "./client.ts";
import {IdParam} from "../types.ts";

export type SwishEnvironment = 'mss' | 'production';

export interface OrganizerSwishSettings {
    id: number;
    organizer_id: number;
    enabled: boolean;
    environment: SwishEnvironment;
    payee_alias: string | null;
    cert_path: string | null;
    key_path: string | null;
    ca_path: string | null;
    has_key_passphrase: boolean;
    last_verified_at: string | null;
    last_verification_error: string | null;
    updated_at: string;
}

export interface OrganizerSwishSettingsResponse {
    data: OrganizerSwishSettings | null;
    meta: {
        environment_fallback_configured: boolean;
        environment_fallback_payee_alias: string | null;
        environment_fallback_source: string;
    };
}

export interface UpsertOrganizerSwishSettingsRequest {
    enabled: boolean;
    environment: SwishEnvironment;
    payee_alias: string | null;
    cert_path: string | null;
    key_path: string | null;
    ca_path: string | null;
    key_passphrase?: string | null;
}

export interface SwishConnectionTestResult {
    success: boolean;
    message: string;
    environment: SwishEnvironment | null;
    payee_alias: string | null;
    source: 'organizer' | 'environment' | null;
}

export const organizerSwishClient = {
    get: async (organizerId: IdParam) => {
        const response = await api.get<OrganizerSwishSettingsResponse>(
            `organizers/${organizerId}/swish-settings`,
        );
        return response.data;
    },

    upsert: async (organizerId: IdParam, data: UpsertOrganizerSwishSettingsRequest) => {
        const response = await api.put<{data: OrganizerSwishSettings}>(
            `organizers/${organizerId}/swish-settings`,
            data,
        );
        return response.data;
    },

    test: async (organizerId: IdParam) => {
        const response = await api.post<{data: SwishConnectionTestResult}>(
            `organizers/${organizerId}/swish-settings/test`,
        );
        return response.data;
    },
};
