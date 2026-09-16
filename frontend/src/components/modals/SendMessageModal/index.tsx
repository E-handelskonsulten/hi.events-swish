import {Event, EventOccurrence, EventType, GenericModalProps, IdParam, MessageType, ProductType, QueryFilters} from "../../../types.ts";
import {NavLink, useParams} from "react-router";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetOrder} from "../../../queries/useGetOrder.ts";
import {Modal} from "../../common/Modal";
import {
    Alert,
    Button,
    Checkbox,
    ComboboxItem,
    ComboboxItemGroup,
    Group,
    LoadingOverlay,
    Menu,
    MultiSelect,
    SegmentedControl,
    Select,
    Text,
    Textarea,
    TextInput
} from "@mantine/core";
import {
    IconAlertCircle,
    IconBrandStripe,
    IconCheck,
    IconChevronDown,
    IconClock,
    IconCopy,
    IconSend,
    IconTestPipe
} from "@tabler/icons-react";
import {Callout} from "../../common/Callout";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useForm, UseFormReturnType} from "@mantine/form";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {Editor} from "../../common/Editor";
import {useSendEventMessage} from "../../../mutations/useSendEventMessage.ts";
import {ProductSelector} from "../../common/ProductSelector";
import {useEffect, useMemo, useRef, useState} from "react";
import {useGetAccount} from "../../../queries/useGetAccount.ts";
import {getConfig} from "../../../utilites/config";
import {utcToTz, prettyDate} from "../../../utilites/dates.ts";
import {useGetEventOccurrences} from "../../../queries/useGetEventOccurrences.ts";
import dayjs from "dayjs";
import classes from "./SendMessageModal.module.scss";
import {useDebouncedValue} from "@mantine/hooks";
import {useGetMessagePreview} from "../../../queries/useGetMessagePreview.ts";
import {useGetOrganizerBillingSettings} from "../../../queries/useGetOrganizerBillingSettings.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {MessagePreviewRequest} from "../../../api/messages.client.ts";

interface EventMessageModalProps extends GenericModalProps {
    orderId?: IdParam,
    productId?: IdParam,
    messageType: MessageType,
    attendeeId?: IdParam,
    eventOccurrenceId?: IdParam,
    eventOccurrenceIds?: IdParam[],
    initialSubject?: string,
    initialMessage?: string,
}

const OrderField = ({orderId, eventId}: { orderId: IdParam, eventId: IdParam }) => {
    const {data: order} = useGetOrder(eventId, orderId);

    if (!order) {
        return null;
    }

    return (
        <TextInput
            label={t`Recipient`}
            disabled
            placeholder={`${order.first_name} ${order.last_name} <${order.email}>`}
        />
    )
}

const AttendeeField = ({orderId, eventId, attendeeId, form}: {
    orderId: IdParam,
    eventId: IdParam,
    attendeeId: IdParam,
    form: UseFormReturnType<any>
}) => {
    const {data: order} = useGetOrder(eventId, orderId);
    const {data: {products} = {}} = useGetEvent(eventId);

    if (!order || !products || !attendeeId) {
        return null;
    }

    const groups: ComboboxItemGroup<ComboboxItem>[] = products.map(product => {
        return {
            group: product.title,
            items: order.attendees?.filter(a => a.product_id === product.id).map(attendee => {
                return {
                    value: String(attendee.id),
                    label: attendee.first_name + ' ' + attendee.last_name,
                };
            }) || []
        }
    });

    return (
        <MultiSelect
            label={t`Message individual attendees`}
            searchable
            data={groups}
            {...form.getInputProps('attendee_ids')}
        />
    )
}

const CUSTOM_PRESET = 'custom';

const getSchedulePresets = (event: Event, occurrence?: EventOccurrence) => {
    const now = dayjs.utc();
    const startDate = occurrence ? dayjs.utc(occurrence.start_date) : dayjs.utc(event.start_date);
    const endDate = occurrence?.end_date
        ? dayjs.utc(occurrence.end_date)
        : event.end_date ? dayjs.utc(event.end_date) : null;

    const presets: { value: string; label: string; utcDate: dayjs.Dayjs }[] = [
        {value: '1_week_before', label: t`1 week before event`, utcDate: startDate.subtract(1, 'week')},
        {value: '1_day_before', label: t`1 day before event`, utcDate: startDate.subtract(1, 'day')},
        {value: '1_hour_before', label: t`1 hour before event`, utcDate: startDate.subtract(1, 'hour')},
        {value: '1_day_after_start', label: t`1 day after start date`, utcDate: startDate.add(1, 'day')},
    ];

    if (endDate) {
        presets.push({
            value: '1_day_after_end',
            label: t`1 day after end date`,
            utcDate: endDate.add(1, 'day'),
        });
    }

    return presets.filter(p => p.utcDate.isAfter(now));
};

export const SendMessageModal = (props: EventMessageModalProps) => {
    const {
        onClose, orderId, productId, messageType, attendeeId,
        eventOccurrenceId: rawEventOccurrenceId, eventOccurrenceIds,
        initialSubject, initialMessage,
    } = props;
    const isMultiOccurrence = !!eventOccurrenceIds && eventOccurrenceIds.length > 1;
    const eventOccurrenceId = rawEventOccurrenceId
        ?? (eventOccurrenceIds?.length === 1 ? eventOccurrenceIds[0] : undefined);
    const {eventId} = useParams();
    const {data: event, data: {product_categories} = {}} = useGetEvent(eventId);
    const isRecurring = event?.type === EventType.RECURRING;
    const {data: occurrencesData} = useGetEventOccurrences(
        eventId,
        {pageNumber: 1, perPage: 100} as QueryFilters,
    );
    const occurrenceOptions = useMemo(() => {
        if (!isRecurring || !occurrencesData?.data) return [];
        return occurrencesData.data
            .filter(occ => occ.status !== 'CANCELLED')
            .map(occ => ({
                label: prettyDate(occ.start_date, event?.timezone || 'UTC')
                    + (occ.label ? ` (${occ.label})` : ''),
                value: String(occ.id),
            }));
    }, [isRecurring, occurrencesData, event?.timezone]);

    const targetedOccurrences = useMemo(() => {
        if (!isMultiOccurrence || !occurrencesData?.data || !eventOccurrenceIds) return [];
        const ids = new Set(eventOccurrenceIds.map(id => Number(id)));
        return occurrencesData.data
            .filter(occ => ids.has(Number(occ.id)))
            .sort((a, b) => a.start_date.localeCompare(b.start_date));
    }, [isMultiOccurrence, eventOccurrenceIds, occurrencesData]);
    const {data: me} = useGetMe();
    const errorHandler = useFormErrorResponseHandler();
    const isPreselectedRecipient = !!(orderId || attendeeId || productId);
    const {data: account, isFetched: isAccountFetched} = useGetAccount();
    const isAccountVerified = isAccountFetched && account?.is_account_email_confirmed;
    const accountRequiresManualVerification = isAccountFetched && account?.requires_manual_verification;
    const formIsDisabled = !isAccountVerified || accountRequiresManualVerification;
    const supportEmail = getConfig('VITE_PLATFORM_SUPPORT_EMAIL');
    const [tierLimitError, setTierLimitError] = useState<string | null>(null);
    const [isScheduled, setIsScheduled] = useState(false);
    const [selectedPreset, setSelectedPreset] = useState<string | null>(null);

    const sendMessageMutation = useSendEventMessage();
    const [reviewing, setReviewing] = useState(false);
    const reviewRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (reviewing) {
            reviewRef.current?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }, [reviewing]);
    const billingSettingsQuery = useGetOrganizerBillingSettings(event?.organizer_id);
    const smsAvailable = !!billingSettingsQuery.data?.data?.sms_enabled && !!billingSettingsQuery.data?.meta?.sms_configured;

    const form = useForm({
        initialValues: {
            subject: initialSubject ?? '',
            message: initialMessage ?? '',
            message_type: messageType,
            attendee_ids: attendeeId ? [String(attendeeId)] : [],
            product_ids: productId ? [String(productId)] : [],
            order_id: orderId,
            is_test: false,
            send_copy_to_current_user: false,
            type: 'EVENT',
            acknowledgement: false,
            channel: 'EMAIL' as 'EMAIL' | 'SMS' | 'BOTH',
            purpose: '' as '' | 'SERVICE' | 'MARKETING',
            sms_body: '',
            confirmation: '',
            order_statuses: ['COMPLETED'],
            scheduled_at: '',
            event_occurrence_id: eventOccurrenceId ? Number(eventOccurrenceId) : null as number | null,
            event_occurrence_ids: isMultiOccurrence
                ? eventOccurrenceIds!.map(id => Number(id))
                : null as number[] | null,
        },
        validate: {
            acknowledgement: (value) => value === true ? null : t`You must confirm the message type before sending`,
            purpose: (value) => value ? null : t`Choose whether this is service information or marketing`,
            sms_body: (value, values) => values.channel !== 'EMAIL' && !String(value).trim() ? t`Write the SMS text` : null,
            subject: (value, values) => values.channel !== 'SMS' && !String(value).trim() ? t`The subject is required` : null,
            scheduled_at: (value) => {
                if (!isScheduled) return null;
                if (selectedPreset && selectedPreset !== CUSTOM_PRESET) return null;
                if (!value) return t`The scheduled time is required`;
                if (event && dayjs.tz(value, event.timezone).isBefore(dayjs())) return t`The scheduled time must be in the future`;
                return null;
            },
        }
    });

    const selectedOccurrence = useMemo<EventOccurrence | undefined>(() => {
        if (!form.values.event_occurrence_id || !occurrencesData?.data) return undefined;
        return occurrencesData.data.find(occ => occ.id === form.values.event_occurrence_id);
    }, [form.values.event_occurrence_id, occurrencesData]);

    const presets = useMemo(() => event ? getSchedulePresets(event, selectedOccurrence) : [], [event, selectedOccurrence]);

    const resolvedPreset = useMemo(() => {
        if (!selectedPreset || selectedPreset === CUSTOM_PRESET) return null;
        return presets.find(p => p.value === selectedPreset) ?? null;
    }, [selectedPreset, presets]);

    const handleSend = (values: any) => {
        setTierLimitError(null);
        if (!reviewing) {
            setReviewing(true);
            return;
        }
        const submitData = {...values};
        if (submitData.channel === 'SMS') {
            delete submitData.subject;
            delete submitData.message;
        }
        if (submitData.channel === 'EMAIL') {
            delete submitData.sms_body;
        }
        if (isScheduled) {
            if (selectedPreset && selectedPreset !== CUSTOM_PRESET && resolvedPreset && event) {
                submitData.scheduled_at = resolvedPreset.utcDate.tz(event.timezone).format('YYYY-MM-DDTHH:mm');
            }
        } else {
            delete submitData.scheduled_at;
        }
        sendMessageMutation.mutate({
            eventId: eventId,
            messageData: submitData,
        }, {
            onSuccess: () => {
                showSuccess(isScheduled ? t`Message Scheduled` : t`Message Sent`);
                form.reset();
                onClose();
            },
            onError: (error: any) => {
                setReviewing(false);
                if (error?.response?.status === 429) {
                    const message = error?.response?.data?.message || t`You have reached your messaging limit.`;
                    setTierLimitError(message);
                } else {
                    errorHandler(form, error);
                }
            }
        });
    }

    useEffect(() => {
        form.setFieldValue('product_ids', []);
    }, [form.values.message_type]);

    const previewRequest: MessagePreviewRequest = {
        message_type: form.values.message_type,
        channel: form.values.channel,
        purpose: form.values.purpose || 'SERVICE',
        sms_body: form.values.sms_body,
        attendee_ids: form.values.attendee_ids,
        product_ids: form.values.product_ids,
        order_id: form.values.order_id,
        order_statuses: form.values.order_statuses,
        event_occurrence_id: form.values.event_occurrence_id,
        event_occurrence_ids: form.values.event_occurrence_ids,
    };
    const [debouncedPreviewRequest] = useDebouncedValue(previewRequest, 400);
    const {data: preview} = useGetMessagePreview(eventId, debouncedPreviewRequest, !formIsDisabled && !!event);
    const currency = preview?.currency || 'SEK';
    const includesSms = form.values.channel !== 'EMAIL';
    const includesEmail = form.values.channel !== 'SMS';
    const smsCharacters = form.values.sms_body.length + (form.values.purpose === 'MARKETING' ? (preview?.sms_opt_out_suffix_length ?? 0) : 0);
    const isConfirmed = !preview?.requires_confirmation || form.values.confirmation.trim() === (preview?.confirmation_word ?? '').trim();

    if (!event || !me || !product_categories) {
        return <LoadingOverlay visible/>;
    }

    return (
        <Modal
            withCloseButton
            opened
            onClose={onClose}
            heading={t`Send a message`}
        >
            {!isAccountFetched && (
                <div className={classes.loadingContainer}>
                    <LoadingOverlay visible/>
                </div>
            )}

            <form onSubmit={form.onSubmit(handleSend)}>
                {(!isAccountVerified && isAccountFetched) && (
                    <Callout variant="info" className={classes.verificationAlert}>
                        {t`You need to verify your account email before you can send messages.`}
                    </Callout>
                )}

                {accountRequiresManualVerification && (
                    <Callout variant="info" className={classes.verificationAlert}
                             title={t`Connect Stripe to enable messaging`}>
                        {t`Due to the high risk of spam, you must connect a Stripe account before you can send messages to attendees.
                         This is to ensure that all event organizers are verified and accountable.`}
                        {event?.organizer_id && (
                            <div className={classes.stripeConnectButton}>
                                <Button
                                    component={NavLink}
                                    to={`/manage/organizer/${event.organizer_id}/settings#payouts`}
                                    leftSection={<IconBrandStripe size={16}/>}
                                    variant="light"
                                >
                                    {t`Connect Stripe`}
                                </Button>
                            </div>
                        )}
                    </Callout>
                )}

                {tierLimitError && (
                    <Alert
                        variant="light"
                        color="red"
                        icon={<IconAlertCircle size="1rem"/>}
                        mb="md"
                    >
                        {tierLimitError}
                        {supportEmail && (
                            <>
                                {' '}{t`To increase your limits, contact us at`}{' '}
                                <a href={`mailto:${supportEmail}`}>{supportEmail}</a>
                            </>
                        )}
                    </Alert>
                )}

                {!formIsDisabled && !tierLimitError && supportEmail && (
                    <Callout variant="info">
                        {t`Your account has messaging limits. To increase your limits, contact us at`}{' '}
                        <a href={`mailto:${supportEmail}`}>{supportEmail}</a>
                    </Callout>
                )}

                {!formIsDisabled && (
                    <fieldset disabled={formIsDisabled} style={{border: 'none', padding: 0, margin: 0}}>
                        <div className={classes.formSection}>
                            {isMultiOccurrence && (
                                <Callout variant="info">
                                    <Text size="sm" fw={500} mb={targetedOccurrences.length ? 'xs' : 0}>
                                        {t`Targeting attendees across ${eventOccurrenceIds!.length} selected sessions.`}
                                    </Text>
                                    {targetedOccurrences.length > 0 && (
                                        <div className={classes.occurrenceList}>
                                            {targetedOccurrences.map(occ => (
                                                <div key={occ.id} className={classes.occurrenceChip}>
                                                    {prettyDate(occ.start_date, event?.timezone || 'UTC')}
                                                    {occ.label && <span className={classes.occurrenceChipLabel}> · {occ.label}</span>}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    {eventOccurrenceIds!.length > targetedOccurrences.length && (
                                        <Text size="xs" c="dimmed" mt="xs">
                                            {t`Showing the first ${targetedOccurrences.length} — the remaining ${eventOccurrenceIds!.length - targetedOccurrences.length} session(s) will still be targeted when the message is sent.`}
                                        </Text>
                                    )}
                                </Callout>
                            )}

                            {isRecurring && !isPreselectedRecipient && !isMultiOccurrence && occurrenceOptions.length > 0 && (
                                <Select
                                    label={t`Occurrence`}
                                    description={t`Send to all occurrences, or choose a specific one`}
                                    placeholder={t`All occurrences`}
                                    data={occurrenceOptions}
                                    value={form.values.event_occurrence_id ? String(form.values.event_occurrence_id) : null}
                                    onChange={(val) => form.setFieldValue('event_occurrence_id', val ? Number(val) : null)}
                                    clearable={!eventOccurrenceId}
                                    disabled={!!eventOccurrenceId}
                                />
                            )}

                            {!isPreselectedRecipient && (
                                <Select
                                    data={[
                                        {
                                            value: 'TICKET_HOLDERS',
                                            label: t`Attendees with a specific ticket`,
                                        },
                                        {
                                            value: 'ALL_ATTENDEES',
                                            label: isMultiOccurrence
                                                ? t`All attendees of the selected sessions`
                                                : form.values.event_occurrence_id
                                                    ? t`All attendees of this occurrence`
                                                    : t`All attendees of this event`,
                                        },
                                        {
                                            value: 'ORDER_OWNERS_WITH_PRODUCT',
                                            label: t`Order owners with a specific product`,
                                        },
                                    ]}
                                    label={t`Recipients`}
                                    description={t`Select which attendees should receive this message`}
                                    placeholder={t`Select attendee group`}
                                    {...form.getInputProps('message_type')}
                                />
                            )}

                            {((form.values.message_type === MessageType.IndividualAttendees) && attendeeId && orderId) && (
                                <AttendeeField eventId={eventId} orderId={orderId} attendeeId={attendeeId} form={form}/>
                            )}

                            {((form.values.message_type === MessageType.TicketHolders && event.product_categories)) && (
                                <ProductSelector
                                    label={t`Message attendees with specific tickets`}
                                    placeholder={t`Select tickets`}
                                    productCategories={event.product_categories}
                                    form={form}
                                    productFieldName={'product_ids'}
                                    includedProductTypes={[ProductType.Ticket]}
                                />
                            )}

                            {((form.values.message_type === MessageType.OrderOwnersWithProduct && event.product_categories)) && (
                                <>
                                    <ProductSelector
                                        label={t`Message order owners with specific products`}
                                        placeholder={t`Select products`}
                                        productCategories={event.product_categories}
                                        form={form}
                                        productFieldName={'product_ids'}
                                        includedProductTypes={[ProductType.Ticket, ProductType.General]}
                                    />
                                    <MultiSelect
                                        description={t`Only send to orders with these statuses`}
                                        label={t`Order statuses`}
                                        data={[
                                            {value: 'COMPLETED', label: t`Completed`},
                                            {value: 'AWAITING_OFFLINE_PAYMENT', label: t`Awaiting offline payment`},
                                        ]}
                                        {...form.getInputProps('order_statuses')}
                                    />
                                </>
                            )}

                            {(form.values.message_type === MessageType.OrderOwner && orderId) && (
                                <OrderField orderId={orderId} eventId={eventId}/>
                            )}

                            <div>
                                <Text size="sm" fw={500} mb={4}>{t`Message type`}</Text>
                                <Button.Group data-testid="message-purpose" className={classes.purposeGroup}>
                                    {([
                                        ['SERVICE', t`Service information`],
                                        ['MARKETING', t`Marketing`],
                                    ] as const).map(([value, label]) => (
                                        <Button
                                            key={value}
                                            type="button"
                                            variant={form.values.purpose === value ? 'filled' : 'default'}
                                            className={classes.purposeButton}
                                            aria-pressed={form.values.purpose === value}
                                            onClick={() => form.setFieldValue('purpose', value)}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </Button.Group>
                                <Text size="xs" c={form.errors.purpose ? 'red' : 'dimmed'} mt={4}>
                                    {form.errors.purpose
                                        ? form.errors.purpose
                                        : form.values.purpose === 'MARKETING'
                                            ? t`Marketing: offers, news and other events. Only buyers who ticked the marketing box at checkout receive it, and every SMS ends with an unsubscribe link.`
                                            : form.values.purpose === 'SERVICE'
                                                ? t`Service information: practical details about the event the recipients hold tickets for, such as times, entrance and changes. Goes to everyone with a ticket.`
                                                : t`Choose a type. Service information is practical details for ticket holders and reaches everyone. Marketing is offers and news and only reaches buyers who have consented.`}
                                </Text>
                            </div>

                            <div>
                                <Text size="sm" fw={500} mb={4}>{t`Channel`}</Text>
                                <SegmentedControl
                                    fullWidth
                                    data={[
                                        {value: 'EMAIL', label: t`Email`},
                                        {value: 'SMS', label: t`SMS`, disabled: !smsAvailable},
                                        {value: 'BOTH', label: t`Both`, disabled: !smsAvailable},
                                    ]}
                                    value={form.values.channel}
                                    onChange={(value) => form.setFieldValue('channel', value as 'EMAIL' | 'SMS' | 'BOTH')}
                                    data-testid="message-channel"
                                />
                                {!smsAvailable && (
                                    <Text size="xs" c="dimmed" mt={4}>{t`SMS is a paid add-on. Enable SMS delivery in the organizer settings to send text messages.`}</Text>
                                )}
                                {preview && (
                                    <Text size="xs" c="dimmed" mt={4} data-testid="message-recipient-summary">
                                        {includesEmail && t`Email: ${preview.email_recipients} recipients`}
                                        {includesEmail && includesSms && ' · '}
                                        {includesSms && t`SMS: ${preview.sms_recipients} recipients`}
                                        {includesSms && preview.excluded_without_phone > 0 && ` (${t`${preview.excluded_without_phone} without a mobile number`})`}
                                        {form.values.purpose === 'MARKETING' && preview.excluded_without_consent > 0 && ` · ${t`${preview.excluded_without_consent} excluded without marketing consent`}`}
                                    </Text>
                                )}
                            </div>

                            {includesEmail && (
                                <>
                                    <TextInput
                                        required
                                        label={t`Subject`}
                                        placeholder={t`e.g., Important update about your tickets`}
                                        {...form.getInputProps('subject')}
                                    />

                                    <Editor
                                        label={t`Message`}
                                        value={form.values.message || ''}
                                        onChange={(value) => form.setFieldValue('message', value)}
                                        error={form.errors.message as string}
                                    />
                                </>
                            )}

                            {includesSms && (
                                <Textarea
                                    required
                                    autosize
                                    minRows={3}
                                    label={t`SMS text`}
                                    description={preview
                                        ? `${t`Sender`}: ${preview.sms_sender} · ${smsCharacters}/${preview.sms_single_part_limit} ${t`characters`} · ${t`${preview.sms_parts} part(s)`} · ${t`approx. ${formatCurrency(preview.sms_cost_per_recipient, currency)} per recipient`}${form.values.purpose === 'MARKETING' ? ` · ${t`unsubscribe link is added automatically`}` : ''}${preview.sms_encoding === 'UCS-2' ? ` · ${t`contains characters outside the SMS alphabet (e.g. – or emoji), so each part holds only 70`}` : ''}`
                                        : t`Keep it short. Links are optional.`}
                                    placeholder={t`Hi! Doors open at 19:00. Bring your ticket QR code.`}
                                    {...form.getInputProps('sms_body')}
                                    data-testid="message-sms-body"
                                />
                            )}
                        </div>

                        <div className={classes.footerSection}>
                            <div className={classes.scheduleSection}>
                                <div className={classes.sendToggle}>
                                    <button
                                        type="button"
                                        className={`${classes.toggleOption} ${!isScheduled ? classes.toggleActive : ''}`}
                                        onClick={() => {
                                            setIsScheduled(false);
                                            form.setFieldValue('scheduled_at', '');
                                            setSelectedPreset(null);
                                        }}
                                    >
                                        <IconSend size={15}/>
                                        {t`Send now`}
                                    </button>
                                    <button
                                        type="button"
                                        className={`${classes.toggleOption} ${isScheduled ? classes.toggleActive : ''}`}
                                        onClick={() => setIsScheduled(true)}
                                    >
                                        <IconClock size={15}/>
                                        {t`Schedule for later`}
                                    </button>
                                </div>
                                {isScheduled && (
                                    <div className={classes.scheduleBody}>
                                        <div className={classes.presetChips}>
                                            {presets.map(p => (
                                                <button
                                                    key={p.value}
                                                    type="button"
                                                    className={`${classes.presetChip} ${selectedPreset === p.value ? classes.presetChipActive : ''}`}
                                                    onClick={() => {
                                                        setSelectedPreset(p.value);
                                                        form.setFieldValue('scheduled_at', '');
                                                    }}
                                                >
                                                    {p.label}
                                                </button>
                                            ))}
                                            <button
                                                type="button"
                                                className={`${classes.presetChip} ${selectedPreset === CUSTOM_PRESET ? classes.presetChipActive : ''}`}
                                                onClick={() => setSelectedPreset(CUSTOM_PRESET)}
                                            >
                                                {t`Custom date and time`}
                                            </button>
                                        </div>
                                        {selectedPreset === CUSTOM_PRESET && (
                                            <TextInput
                                                type="datetime-local"
                                                label={t`Scheduled time`}
                                                description={event.timezone}
                                                min={utcToTz(dayjs.utc().toISOString(), event.timezone)}
                                                {...form.getInputProps('scheduled_at')}
                                            />
                                        )}
                                        {resolvedPreset && event && (
                                            <div className={classes.scheduledConfirmation}>
                                                <div className={classes.scheduledConfirmationIcon}>
                                                    <IconClock size={20}/>
                                                </div>
                                                <div className={classes.scheduledConfirmationText}>
                                                    <span className={classes.scheduledConfirmationDate}>
                                                        {resolvedPreset.utcDate.tz(event.timezone).format('dddd, MMMM D, YYYY')}
                                                    </span>
                                                    <span className={classes.scheduledConfirmationTime}>
                                                        {resolvedPreset.utcDate.tz(event.timezone).format('h:mm A')}
                                                        {' '}<span className={classes.scheduledConfirmationTz}>{event.timezone}</span>
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            <Checkbox
                                {...form.getInputProps('acknowledgement', {type: 'checkbox'})}
                                label={form.values.purpose === 'MARKETING'
                                    ? t`I confirm this is marketing and will only reach buyers who have consented`
                                    : t`I confirm this is a transactional message related to this event`}
                            />

                            {reviewing && preview && (
                                <div ref={reviewRef}>
                                <Callout variant="warning" title={t`Review before sending`}>
                                    <Text size="sm" data-testid="message-review-summary">
                                        {form.values.purpose === 'MARKETING' ? t`Marketing` : t`Service information`} · {form.values.channel === 'BOTH' ? t`Email and SMS` : form.values.channel === 'SMS' ? t`SMS` : t`Email`}
                                        <br/>
                                        {includesEmail && t`Email: ${preview.email_recipients} recipients`}
                                        {includesEmail && includesSms && ' · '}
                                        {includesSms && t`SMS: ${preview.sms_recipients} recipients, ${preview.sms_parts} part(s), approx. ${formatCurrency(preview.sms_cost_per_recipient, currency)} each ≈ ${formatCurrency(preview.sms_total_cost, currency)}`}
                                    </Text>
                                    {preview.requires_confirmation && !form.values.is_test && (
                                        <TextInput
                                            mt="sm"
                                            label={t`Type the event name to confirm`}
                                            description={t`Type "${preview.confirmation_word}" exactly to enable sending to more than 100 recipients.`}
                                            autoComplete="off"
                                            {...form.getInputProps('confirmation')}
                                            data-testid="message-confirmation-input"
                                        />
                                    )}
                                </Callout>
                                </div>
                            )}

                            <Group gap={0}>
                                <Button
                                    className={classes.sendButton}
                                    loading={sendMessageMutation.isPending}
                                    type={'submit'}
                                    leftSection={isScheduled ? <IconClock size={16}/> : <IconSend size={16}/>}
                                    disabled={!form.values.acknowledgement || !isAccountVerified || accountRequiresManualVerification || (reviewing && !isConfirmed)}
                                    data-testid="message-send-button"
                                >
                                    {!reviewing ? t`Review and send` : isScheduled ? t`Schedule Message` : (form.values.is_test ? t`Send Test` : t`Send Message`)}
                                </Button>
                                {reviewing && (
                                    <Button type="button" variant="default" ml="xs" onClick={() => setReviewing(false)} data-testid="message-review-back">
                                        {t`Back`}
                                    </Button>
                                )}
                                <Menu shadow="md" width={220} position="bottom-end">
                                    <Menu.Target>
                                        <Button
                                            type="button"
                                            className={classes.menuButton}
                                            disabled={!form.values.acknowledgement || !isAccountVerified || accountRequiresManualVerification}
                                        >
                                            <IconChevronDown size={16}/>
                                        </Button>
                                    </Menu.Target>
                                    <Menu.Dropdown>
                                        <Menu.Item
                                            leftSection={<IconTestPipe size={16}/>}
                                            rightSection={form.values.is_test ? <IconCheck size={14}/> : null}
                                            onClick={() => form.setFieldValue('is_test', !form.values.is_test)}
                                        >
                                            {t`Send as test`}
                                        </Menu.Item>
                                        <Menu.Item
                                            leftSection={<IconCopy size={16}/>}
                                            rightSection={form.values.send_copy_to_current_user ?
                                                <IconCheck size={14}/> : null}
                                            onClick={() => form.setFieldValue('send_copy_to_current_user', !form.values.send_copy_to_current_user)}
                                        >
                                            {t`Send me a copy`}
                                        </Menu.Item>
                                    </Menu.Dropdown>
                                </Menu>
                            </Group>

                            {form.values.purpose !== 'MARKETING' && (
                                <p className={classes.warningText}>
                                    {t`Promotional emails may result in account suspension`}
                                </p>
                            )}
                        </div>
                    </fieldset>
                )}
            </form>
        </Modal>
    )
};
