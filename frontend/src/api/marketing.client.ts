import {publicApi} from "./public-client.ts";
import {GenericDataResponse} from "../types.ts";

export interface MarketingOptOutStatus {
    organizer_name: string;
    opted_in: boolean;
}

export const marketingClientPublic = {
    optOutStatus: async (token: string) => {
        const response = await publicApi.get<GenericDataResponse<MarketingOptOutStatus>>(`marketing/opt-out/${token}`);
        return response.data;
    },

    optOut: async (token: string) => {
        const response = await publicApi.post<GenericDataResponse<MarketingOptOutStatus>>(`marketing/opt-out/${token}`);
        return response.data;
    },
};
