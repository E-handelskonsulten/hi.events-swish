import {useParams} from "react-router";
import {useEffect, useState, TouchEvent, KeyboardEvent} from "react";
import {Container, UnstyledButton} from "@mantine/core";
import {IconChevronLeft, IconChevronRight} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useGetOrderTicketsPublic} from "../../../../queries/useGetOrderTicketsPublic.ts";
import {AttendeeTicket} from "../../../common/AttendeeTicket";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {Attendee, Product} from "../../../../types.ts";
import classes from './OrderTickets.module.scss';

const SWIPE_THRESHOLD_PX = 40;

export const OrderTickets = () => {
    const {orderShortId} = useParams();
    const {data: order, isError: orderError} = useGetOrderTicketsPublic(String(orderShortId));
    const {data: event, isError: eventError} = useGetEventPublic(order?.event_id, !!order?.event_id);
    const [index, setIndex] = useState(0);
    const [touchStartX, setTouchStartX] = useState<number | null>(null);

    const attendees = order?.attendees ?? [];
    const count = attendees.length;

    useEffect(() => {
        setIndex((current) => Math.min(current, Math.max(count - 1, 0)));
    }, [count]);

    if (orderError || eventError) {
        return (
            <HomepageInfoMessage
                status="not_found"
                message={t`Tickets Not Found`}
                subtitle={t`We couldn't find the tickets you're looking for. The link may have expired or the order details may have changed.`}
            />
        );
    }

    if (!order || !event || count === 0) {
        return null;
    }

    const goTo = (next: number) => setIndex(Math.max(0, Math.min(count - 1, next)));

    const handleTouchEnd = (touch: TouchEvent<HTMLDivElement>) => {
        if (touchStartX === null) {
            return;
        }
        const delta = touch.changedTouches[0].clientX - touchStartX;
        setTouchStartX(null);
        if (Math.abs(delta) < SWIPE_THRESHOLD_PX) {
            return;
        }
        goTo(delta < 0 ? index + 1 : index - 1);
    };

    const handleKeyDown = (key: KeyboardEvent<HTMLDivElement>) => {
        if (key.key === 'ArrowRight') goTo(index + 1);
        if (key.key === 'ArrowLeft') goTo(index - 1);
    };

    const attendee = order.is_valid
        ? attendees[index]
        : {...attendees[index], status: 'CANCELLED' as const};
    const attendeeName = [attendee.first_name, attendee.last_name].filter(Boolean).join(' ');

    return (
        <Container className={classes.page}>
            <h2 className={classes.title}>{t`Your tickets for`} {event.title}</h2>

            {!order.is_valid && (
                <p className={classes.invalidNotice} data-testid="order-tickets-invalid">
                    {order.is_fully_refunded
                        ? t`This order has been refunded. The tickets are no longer valid.`
                        : t`This order has been cancelled. The tickets are no longer valid.`}
                </p>
            )}

            <div className={classes.pager} role="group" aria-label={t`Tickets`}>
                <UnstyledButton
                    className={classes.arrow}
                    onClick={() => goTo(index - 1)}
                    disabled={index === 0}
                    aria-label={t`Previous ticket`}
                    data-testid="order-tickets-previous"
                >
                    <IconChevronLeft size={28}/>
                </UnstyledButton>

                <div className={classes.counter} aria-live="polite" data-testid="order-tickets-counter">
                    <span className={classes.counterPosition}>{t`Ticket ${index + 1} of ${count}`}</span>
                    {attendeeName && <span className={classes.counterName}>{attendeeName}</span>}
                </div>

                <UnstyledButton
                    className={classes.arrow}
                    onClick={() => goTo(index + 1)}
                    disabled={index === count - 1}
                    aria-label={t`Next ticket`}
                    data-testid="order-tickets-next"
                >
                    <IconChevronRight size={28}/>
                </UnstyledButton>
            </div>

            <div
                className={classes.slide}
                tabIndex={0}
                onKeyDown={handleKeyDown}
                onTouchStart={(touch) => setTouchStartX(touch.touches[0].clientX)}
                onTouchEnd={handleTouchEnd}
                data-testid="order-tickets-slide"
            >
                <AttendeeTicket
                    key={attendee.id}
                    attendee={attendee as Attendee}
                    product={attendee.product as Product}
                    event={event}
                    hideButtons
                />
            </div>

            {count > 1 && (
                <div className={classes.dots} aria-hidden="true">
                    {attendees.map((item, dot) => (
                        <button
                            key={item.id}
                            type="button"
                            className={`${classes.dot} ${dot === index ? classes.dotActive : ''}`}
                            onClick={() => goTo(dot)}
                            tabIndex={-1}
                        />
                    ))}
                </div>
            )}

            <p className={classes.hint}>{t`Swipe or use the arrows to show the next ticket at the door.`}</p>

            <PoweredByFooter/>
        </Container>
    );
};

export default OrderTickets;
