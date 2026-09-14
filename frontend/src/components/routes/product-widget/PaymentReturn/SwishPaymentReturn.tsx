import {useEffect, useState} from "react";
import {useNavigate, useParams} from "react-router";
import {t} from "@lingui/macro";
import {useGetSwishPaymentPublic} from "../../../../queries/useGetSwishPaymentPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {isSsr} from "../../../../utilites/helpers.ts";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";
import classes from './PaymentReturn.module.scss';

const CONFIRMATION_TIMEOUT_MS = 90000;

export const SwishPaymentReturn = () => {
    const {eventId, orderShortId} = useParams();
    const navigate = useNavigate();
    const [timedOut, setTimedOut] = useState(false);
    const paymentQuery = useGetSwishPaymentPublic(eventId, orderShortId, true);
    const payment = paymentQuery.data;

    useEffect(() => {
        const timeout = setTimeout(() => setTimedOut(true), CONFIRMATION_TIMEOUT_MS);

        return () => clearTimeout(timeout);
    }, []);

    useEffect(() => {
        if (isSsr() || !payment) {
            return;
        }

        if (payment.status === 'PAID' || payment.order?.status === 'COMPLETED') {
            trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_PAID, {value: Math.round((payment.amount || 0) * 100)});
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
            return;
        }

        if (payment.is_terminal) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + `?swish_failed=${payment.status}`);
        }
    }, [payment?.status, payment?.order?.status]);

    useEffect(() => {
        if (paymentQuery.error?.response?.status === 404) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'payment'));
        }
    }, [paymentQuery.error]);

    return (
        <CheckoutContent>
            <div className={classes.container}>
                {timedOut ? (
                    <HomepageInfoMessage
                        status="warning"
                        message={t`We have not received a confirmation from Swish yet.`}
                        subtitle={t`If you approved the payment, your tickets will be emailed as soon as Swish confirms it. You can also go back and check the payment status.`}
                        link={eventCheckoutPath(eventId, orderShortId, 'payment')}
                        linkText={t`Back to payment`}
                    />
                ) : (
                    <HomepageInfoMessage
                        status="processing"
                        message={t`Waiting for Swish to confirm your payment…`}
                        subtitle={t`This usually takes a few seconds. Keep this page open.`}
                    />
                )}
            </div>
        </CheckoutContent>
    );
};

export default SwishPaymentReturn;
