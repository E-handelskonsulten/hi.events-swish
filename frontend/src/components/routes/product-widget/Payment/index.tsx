import React, {useState} from "react";
import {useNavigate, useParams, useSearchParams} from "react-router";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {CheckoutStepTitle} from "../../../layouts/Checkout/CheckoutStepTitle";
import {StripePaymentMethod} from "./PaymentMethods/Stripe";
import {OfflinePaymentMethod} from "./PaymentMethods/Offline";
import {SwishPaymentMethod} from "./PaymentMethods/Swish";
import {SwishLogo} from "../../../common/SwishLogo";
import {useIsMobileDevice} from "../../../../hooks/useIsMobileDevice.ts";
import {Event} from "../../../../types.ts";
import {Button, Group, Text} from "@mantine/core";
import {IconBuildingBank, IconLock, IconWallet} from "@tabler/icons-react";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {t, Trans} from "@lingui/macro";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {
    useTransitionOrderToOfflinePaymentPublic
} from "../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts";
import {Card} from "../../../common/Card";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {showError} from "../../../../utilites/notifications.tsx";
import {getConfig} from "../../../../utilites/config.ts";
import classes from "./Payment.module.scss";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";

const Payment = () => {
    const navigate = useNavigate();
    const {eventId, orderShortId} = useParams();
    const {data: event, isFetched: isEventFetched} = useGetEventPublic(eventId);
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const isLoading = !isOrderFetched;
    const checkoutEvent = order?.event || event;
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);
    const [activePaymentMethod, setActivePaymentMethod] = useState<'STRIPE' | 'OFFLINE' | 'SWISH' | null>(null);
    const [submitHandler, setSubmitHandler] = useState<(() => Promise<void>) | null>(null);
    const transitionOrderToOfflinePaymentMutation = useTransitionOrderToOfflinePaymentPublic();
    const [searchParams] = useSearchParams();
    const isMobileDevice = useIsMobileDevice();

    const isStripeEnabled = event?.settings?.payment_providers?.includes('STRIPE');
    const isOfflineEnabled = event?.settings?.payment_providers?.includes('OFFLINE');
    const isSwishEnabled = event?.settings?.payment_providers?.includes('SWISH');
    const swishPreferred = isSwishEnabled && (isMobileDevice || !!searchParams.get('swish_failed') || !isStripeEnabled);

    React.useEffect(() => {
        // Automatically set the first available payment method
        if (swishPreferred) {
            setActivePaymentMethod('SWISH');
        } else if (isStripeEnabled) {
            setActivePaymentMethod('STRIPE');
        } else if (isOfflineEnabled) {
            setActivePaymentMethod('OFFLINE');
        } else {
            setActivePaymentMethod(null); // No methods available
        }
    }, [isStripeEnabled, isOfflineEnabled, swishPreferred]);

    React.useEffect(() => {
        // Scroll to top when payment page loads
        window?.scrollTo(0, 0);
    }, []);

    const handleParentSubmit = () => {
        if (submitHandler) {
            setIsPaymentLoading(true);
            submitHandler().finally(() => setIsPaymentLoading(false));
        }
    };

    const handleSubmit = async () => {
        if (activePaymentMethod === 'STRIPE') {
            handleParentSubmit();
        } else if (activePaymentMethod === 'OFFLINE') {
            setIsPaymentLoading(true);

            await transitionOrderToOfflinePaymentMutation.mutateAsync({
                eventId,
                orderShortId
            }, {
                onSuccess: () => {
                    const totalCents = Math.round((order?.total_gross || 0) * 100);
                    trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_OFFLINE, { value: totalCents });
                    navigate(`/checkout/${eventId}/${orderShortId}/summary`);
                },
                onError: (error: any) => {
                    setIsPaymentLoading(false);
                    showError(error.response?.data?.message || t`Offline payment failed. Please try again or contact the event organizer.`);
                }
            });
        }
    };

    if (!isStripeEnabled && !isOfflineEnabled && !isSwishEnabled && isOrderFetched && isEventFetched) {
        return (
            <CheckoutContent>
                <Card>
                    {t`No payment methods are currently available. Please contact the event organizer for assistance.`}
                </Card>
            </CheckoutContent>
        );
    }

    return (
        <>
            <CheckoutContent>
                <CheckoutStepTitle title={t`Payment`} subtitle={t`Next: review your order`}/>

                {(checkoutEvent && order) && (
                    <InlineOrderSummary event={checkoutEvent} order={order} defaultExpanded={false}/>
                )}
                {isStripeEnabled && (
                    <div style={{display: activePaymentMethod === 'STRIPE' ? 'block' : 'none'}}>
                        <StripePaymentMethod enabled={true} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isOfflineEnabled && (
                    <div style={{display: activePaymentMethod === 'OFFLINE' ? 'block' : 'none'}}>
                        <OfflinePaymentMethod event={checkoutEvent as Event}/>
                    </div>
                )}

                {(isSwishEnabled && order) && (
                    <div style={{display: activePaymentMethod === 'SWISH' ? 'block' : 'none'}}>
                        <SwishPaymentMethod
                            active={activePaymentMethod === 'SWISH'}
                            amount={order.total_gross}
                            currency={order.currency}
                        />
                    </div>
                )}

                {([isStripeEnabled, isOfflineEnabled, isSwishEnabled].filter(Boolean).length > 1) && (
                    <div className={classes.paymentMethodSelector}>
                        <Text size="sm" c="dimmed" className={classes.paymentMethodLabel}>
                            {t`Payment method`}
                        </Text>
                        <div className={classes.paymentMethodTabs}>
                            {isSwishEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'SWISH' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('SWISH')}
                                    data-testid="payment-method-swish"
                                >
                                    <SwishLogo height={18}/>
                                </button>
                            )}
                            {isStripeEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'STRIPE' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('STRIPE')}
                                >
                                    <IconWallet size={18}/>
                                    <span>{t`Online`}</span>
                                </button>
                            )}
                            {isOfflineEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'OFFLINE' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('OFFLINE')}
                                >
                                    <IconBuildingBank size={18}/>
                                    <span>{t`Offline`}</span>
                                </button>
                            )}
                        </div>
                    </div>
                )}

                <div className={classes.checkoutActions}>
                    {activePaymentMethod !== 'SWISH' && (
                        <Button
                            className={classes.continueButton}
                            loading={isLoading || isPaymentLoading}
                            onClick={handleSubmit}
                            data-testid={activePaymentMethod === 'OFFLINE' ? 'offline-payment-button' : undefined}
                        >
                            {order?.is_payment_required ? (
                                <Group gap={8} wrap="nowrap">
                                    <IconLock size={16}/>
                                    <Text fw={600}>{t`Pay`} {formatCurrency(order.total_gross, order.currency)}</Text>
                                </Group>
                            ) : t`Complete Payment`}
                        </Button>
                    )}
                    {getConfig('VITE_TOS_URL') && (
                        <p className={classes.tosNotice}>
                            <Trans>
                                By continuing, you agree to the{' '}
                                <a
                                    href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service') as string}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {getConfig('VITE_APP_NAME', 'Hi.Events')} Terms of Service
                                </a>
                            </Trans>
                        </p>
                    )}
                </div>
            </CheckoutContent>
        </>
    );
}

export default Payment;
