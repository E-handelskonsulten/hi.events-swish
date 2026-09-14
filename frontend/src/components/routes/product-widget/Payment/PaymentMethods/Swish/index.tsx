import {useEffect, useMemo, useState} from "react";
import {useNavigate, useParams, useSearchParams} from "react-router";
import {Alert, Button, Loader, SegmentedControl, Stack, Text, TextInput} from "@mantine/core";
import {IconDeviceMobile, IconExternalLink, IconPhone, IconRefresh} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetSwishPaymentPublic} from "../../../../../../queries/useGetSwishPaymentPublic.ts";
import {useCreateSwishPaymentPublic} from "../../../../../../mutations/useCreateSwishPaymentPublic.ts";
import {useCancelSwishPaymentPublic} from "../../../../../../mutations/useCancelSwishPaymentPublic.ts";
import {useIsMobileDevice} from "../../../../../../hooks/useIsMobileDevice.ts";
import {
    buildSwishDeeplink,
    buildSwishReturnUrl,
    formatSwedishMobileNumber,
    normalizeSwedishMobileNumber,
} from "../../../../../../utilites/swishPayment.ts";
import {getCheckoutSessionIdentifier} from "../../../../../../utilites/checkoutSession.ts";
import {eventCheckoutPath} from "../../../../../../utilites/urlHelper.ts";
import {formatCurrency} from "../../../../../../utilites/currency.ts";
import {SwishLogo} from "../../../../../common/SwishLogo";
import {CreateSwishPaymentPayload, SwishCheckoutFlow, SwishPayment} from "../../../../../../types/swish.ts";
import classes from './Swish.module.scss';

type Phase = 'form' | 'waiting' | 'failed';

interface SwishPaymentMethodProps {
    active: boolean;
    amount: number;
    currency: string;
}

const failureMessage = (payment: SwishPayment): string => {
    switch (payment.status) {
        case 'DECLINED':
            return t`The payment was declined in Swish.`;
        case 'CANCELLED':
            return t`The Swish payment was cancelled.`;
        case 'EXPIRED':
            return t`The Swish payment request expired before it was approved.`;
        case 'PAID_FLAGGED':
            return t`Your payment was received, but the order could not be completed automatically. Please contact the event organizer.`;
        case 'ERROR':
            switch (payment.error_code) {
                case 'TM01':
                case 'DS24':
                    return t`Swish timed out waiting for your approval.`;
                case 'BANKIDCL':
                    return t`The BankID signing was cancelled.`;
                case 'RF07':
                    return t`The payment was declined in Swish.`;
                default:
                    return t`Swish could not complete the payment.`;
            }
        default:
            return t`Swish could not complete the payment.`;
    }
};

export const SwishPaymentMethod = ({active, amount, currency}: SwishPaymentMethodProps) => {
    const {eventId, orderShortId} = useParams();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const isMobile = useIsMobileDevice();

    const [flow, setFlow] = useState<SwishCheckoutFlow>('ECOMMERCE');
    const [flowTouched, setFlowTouched] = useState(false);
    const [phone, setPhone] = useState('');
    const [phoneError, setPhoneError] = useState<string | null>(null);
    const [createError, setCreateError] = useState<string | null>(null);
    const [phase, setPhase] = useState<Phase>(searchParams.get('swish_failed') ? 'failed' : 'form');
    const [failedPayment, setFailedPayment] = useState<SwishPayment | null>(null);

    const paymentQuery = useGetSwishPaymentPublic(eventId, orderShortId, active);
    const createMutation = useCreateSwishPaymentPublic();
    const cancelMutation = useCancelSwishPaymentPublic();
    const payment = paymentQuery.data;

    useEffect(() => {
        if (!flowTouched && isMobile) {
            setFlow('MCOMMERCE');
        }
    }, [isMobile, flowTouched]);

    useEffect(() => {
        if (!payment) {
            return;
        }

        if (payment.status === 'PAID' || payment.order?.status === 'COMPLETED') {
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
            return;
        }

        if (!payment.is_terminal) {
            setPhase('waiting');
            setFlow(payment.flow);
            return;
        }

        if (phase === 'waiting' || payment.status === 'PAID_FLAGGED' || searchParams.get('swish_failed')) {
            setFailedPayment(payment);
            setPhase('failed');
        }
    }, [payment?.status, payment?.instruction_uuid, payment?.order?.status]);

    const sessionId = useMemo(() => getCheckoutSessionIdentifier(String(orderShortId)), [orderShortId]);

    const deeplink = useMemo(() => {
        if (!payment?.payment_request_token) {
            return null;
        }

        return buildSwishDeeplink(
            payment.payment_request_token,
            buildSwishReturnUrl(String(eventId), String(orderShortId), sessionId),
        );
    }, [payment?.payment_request_token, eventId, orderShortId, sessionId]);

    const handleFlowChange = (value: string) => {
        setFlowTouched(true);
        setFlow(value as SwishCheckoutFlow);
        setPhoneError(null);
        setCreateError(null);
    };

    const handleSubmit = async () => {
        setCreateError(null);
        setPhoneError(null);

        const payload: CreateSwishPaymentPayload = {flow};

        if (flow === 'ECOMMERCE') {
            const normalized = normalizeSwedishMobileNumber(phone);

            if (!normalized) {
                setPhoneError(t`Enter a valid Swedish mobile number, for example 070-123 45 67.`);
                return;
            }

            payload.payer_alias = normalized;
        }

        try {
            await createMutation.mutateAsync({eventId, orderShortId, payload});
            setPhase('waiting');
        } catch (error: any) {
            const status = error?.response?.status;
            const message = error?.response?.data?.message;

            if (status === 503) {
                setCreateError(t`Swish is temporarily unavailable. Please try again in a moment.`);
            } else if (status === 409) {
                setCreateError(message || t`This order can no longer be paid. Please start a new order.`);
            } else {
                setCreateError(message || t`Swish could not start the payment. Please try again.`);
            }
        }
    };

    const handleCancel = async () => {
        try {
            const response = await cancelMutation.mutateAsync({eventId, orderShortId});

            if (response.data.status !== 'PAID' && response.data.order?.status !== 'COMPLETED') {
                setPhase('form');
            }
        } catch {
            setCreateError(t`The Swish request could not be cancelled. Please try again.`);
        }
    };

    const handleRetry = () => {
        setFailedPayment(null);
        setCreateError(null);
        setPhase('form');
    };

    const handleSwitchToPhone = async () => {
        setFlowTouched(true);
        setFlow('ECOMMERCE');
        await handleCancel();
    };

    const amountLabel = formatCurrency(amount, currency);

    if (!active) {
        return null;
    }

    if (phase === 'waiting' && payment && !payment.is_terminal) {
        const waitingForToken = payment.flow === 'MCOMMERCE' && !deeplink;
        const phoneDisplay = payment.payer_alias_masked ?? '';

        return (
            <div className={classes.container}>
                <div className={classes.header}>
                    <SwishLogo height={28}/>
                </div>

                <Stack gap="md" align="center" className={classes.waiting}>
                    {payment.flow === 'MCOMMERCE' ? (
                        <>
                            {waitingForToken ? (
                                <Loader size="sm"/>
                            ) : (
                                <Button
                                    component="a"
                                    href={deeplink ?? undefined}
                                    target="_top"
                                    rel="noopener"
                                    size="lg"
                                    fullWidth
                                    className={classes.openSwishButton}
                                    leftSection={<IconExternalLink size={18}/>}
                                    data-testid="swish-open-app-button"
                                >
                                    {t`Open Swish`}
                                </Button>
                            )}
                            <Text size="sm" ta="center" className={classes.hint}>
                                {t`Approve the payment of ${amountLabel} in the Swish app, then return here. This page updates automatically.`}
                            </Text>
                            <Button variant="subtle" size="xs" onClick={handleSwitchToPhone} loading={cancelMutation.isPending}>
                                {t`Don't have Swish on this device? Enter your mobile number instead`}
                            </Button>
                        </>
                    ) : (
                        <>
                            <Loader size="sm"/>
                            <Text size="sm" ta="center" className={classes.hint}>
                                {t`Open the Swish app on the phone with number ${phoneDisplay} and approve the payment of ${amountLabel}. This page updates automatically.`}
                            </Text>
                        </>
                    )}

                    <div className={classes.pollingIndicator}>
                        <Loader size="xs" type="dots"/>
                        <Text size="xs" c="dimmed">{t`Waiting for confirmation from Swish…`}</Text>
                    </div>

                    {createError && <Alert color="red" w="100%">{createError}</Alert>}

                    <Button variant="light" color="gray" onClick={handleCancel} loading={cancelMutation.isPending} data-testid="swish-cancel-button">
                        {t`Cancel and choose another option`}
                    </Button>
                </Stack>
            </div>
        );
    }

    if (phase === 'failed' && failedPayment) {
        return (
            <div className={classes.container}>
                <div className={classes.header}>
                    <SwishLogo height={28}/>
                </div>
                <Alert color="red" title={t`Payment not completed`} mb="md">
                    {failureMessage(failedPayment)}
                </Alert>
                {failedPayment.status !== 'PAID_FLAGGED' && (
                    <Button fullWidth onClick={handleRetry} leftSection={<IconRefresh size={18}/>} data-testid="swish-retry-button">
                        {t`Try again`}
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className={classes.container}>
            <div className={classes.header}>
                <SwishLogo height={28}/>
                <Text size="sm" c="dimmed">{t`Pay securely with the Swish app`}</Text>
            </div>

            <SegmentedControl
                fullWidth
                value={flow}
                onChange={handleFlowChange}
                className={classes.flowSwitch}
                data={[
                    {
                        value: 'MCOMMERCE',
                        label: (
                            <span className={classes.flowOption}>
                                <IconDeviceMobile size={16}/>
                                <span>{t`Swish on this device`}</span>
                            </span>
                        ),
                    },
                    {
                        value: 'ECOMMERCE',
                        label: (
                            <span className={classes.flowOption}>
                                <IconPhone size={16}/>
                                <span>{t`Enter mobile number`}</span>
                            </span>
                        ),
                    },
                ]}
            />

            {flow === 'ECOMMERCE' ? (
                <TextInput
                    mt="md"
                    label={t`Mobile number connected to Swish`}
                    description={t`Formats accepted: 070-123 45 67, 0701234567 or +46701234567`}
                    placeholder="070-123 45 67"
                    inputMode="tel"
                    autoComplete="tel"
                    value={phone}
                    error={phoneError}
                    onChange={(event) => {
                        setPhone(event.currentTarget.value);
                        setPhoneError(null);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void handleSubmit();
                        }
                    }}
                    data-testid="swish-phone-input"
                />
            ) : (
                <Text size="sm" mt="md" className={classes.hint}>
                    {t`Tap the button, approve the payment in the Swish app on this device, and you will be brought back here.`}
                </Text>
            )}

            {createError && <Alert color="red" mt="md">{createError}</Alert>}

            <Button
                fullWidth
                size="lg"
                mt="md"
                className={classes.payButton}
                onClick={handleSubmit}
                loading={createMutation.isPending}
                data-testid="swish-pay-button"
            >
                {flow === 'MCOMMERCE'
                    ? t`Pay ${amountLabel} with Swish`
                    : t`Send Swish request for ${amountLabel}`}
            </Button>

            {phone && flow === 'ECOMMERCE' && normalizeSwedishMobileNumber(phone) && (
                <Text size="xs" c="dimmed" mt="xs" ta="center">
                    {t`The request will be sent to ${formatSwedishMobileNumber(normalizeSwedishMobileNumber(phone) as string)}`}
                </Text>
            )}
        </div>
    );
};
