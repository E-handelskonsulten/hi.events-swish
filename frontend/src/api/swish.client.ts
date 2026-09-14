import {publicApi} from "./public-client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";
import {CreateSwishPaymentPayload, SwishPayment} from "../types/swish.ts";

const paymentPath = (eventId: IdParam, orderShortId: IdParam) =>
    `events/${eventId}/order/${orderShortId}/swish/payment`;

export const swishClientPublic = {
    create: async (eventId: IdParam, orderShortId: IdParam, payload: CreateSwishPaymentPayload) => {
        const response = await publicApi.post<GenericDataResponse<SwishPayment>>(paymentPath(eventId, orderShortId), payload);
        return response.data;
    },

    get: async (eventId: IdParam, orderShortId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<SwishPayment>>(paymentPath(eventId, orderShortId));
        return response.data;
    },

    cancel: async (eventId: IdParam, orderShortId: IdParam) => {
        const response = await publicApi.delete<GenericDataResponse<SwishPayment>>(paymentPath(eventId, orderShortId));
        return response.data;
    },
};
