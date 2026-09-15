import {useQuery} from "@tanstack/react-query";
import {AxiosError} from "axios";
import {orderClientPublic, OrderTickets} from "../api/order.client.ts";

export const GET_ORDER_TICKETS_PUBLIC_QUERY_KEY = 'getOrderTicketsPublic';

export const useGetOrderTicketsPublic = (orderShortId: string) => {
    return useQuery<OrderTickets, AxiosError>({
        queryKey: [GET_ORDER_TICKETS_PUBLIC_QUERY_KEY, orderShortId],

        queryFn: async () => {
            const {data} = await orderClientPublic.findTicketsByShortId(orderShortId);
            return data;
        },

        refetchOnWindowFocus: false,
        retryOnMount: false,
        staleTime: 0,
        retry: (failureCount, error) => {
            if (error?.response?.status === 404) {
                return false;
            }
            return failureCount < 3;
        },
    });
};
