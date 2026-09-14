export type SwishPaymentStatus =
    | 'CREATED'
    | 'PAID'
    | 'DECLINED'
    | 'ERROR'
    | 'CANCELLED'
    | 'EXPIRED'
    | 'PAID_FLAGGED';

export type SwishCheckoutFlow = 'MCOMMERCE' | 'ECOMMERCE';

export interface SwishPaymentOrderSummary {
    short_id: string;
    status: 'RESERVED' | 'CANCELLED' | 'COMPLETED' | 'AWAITING_OFFLINE_PAYMENT' | 'ABANDONED';
    payment_status?: 'NO_PAYMENT_REQUIRED' | 'AWAITING_PAYMENT' | 'PAYMENT_FAILED' | 'PAYMENT_RECEIVED' | 'AWAITING_OFFLINE_PAYMENT' | null;
    reserved_until?: string | null;
}

export interface SwishPayment {
    instruction_uuid: string;
    status: SwishPaymentStatus;
    flow: SwishCheckoutFlow;
    is_terminal: boolean;
    amount: number;
    currency: string;
    payment_request_token?: string | null;
    payer_alias_masked?: string | null;
    error_code?: string | null;
    created_at: string;
    updated_at: string;
    order?: SwishPaymentOrderSummary;
}

export interface CreateSwishPaymentPayload {
    flow: SwishCheckoutFlow;
    payer_alias?: string;
}
