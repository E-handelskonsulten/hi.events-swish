import {useEffect, useState} from "react";
import {t} from "@lingui/macro";
import {
    Alert,
    Badge,
    Button,
    Checkbox,
    Group,
    Loader,
    Paper,
    Progress,
    ScrollArea,
    SimpleGrid,
    Stack,
    Table as MantineTable,
    Text,
    TextInput,
} from "@mantine/core";
import {IconAlertTriangle, IconCheck, IconRefresh} from "@tabler/icons-react";
import {GenericModalProps, IdParam} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Callout} from "../../common/Callout";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetSwishMassRefundPreview} from "../../../queries/useGetSwishMassRefundPreview.ts";
import {useGetSwishMassRefundRun} from "../../../queries/useGetSwishMassRefundRun.ts";
import {useGetSwishMassRefundRuns} from "../../../queries/useGetSwishMassRefundRuns.ts";
import {useStartSwishMassRefund} from "../../../mutations/useStartSwishMassRefund.ts";
import {useRetrySwishMassRefund} from "../../../mutations/useRetrySwishMassRefund.ts";
import {
    SwishMassRefundItem,
    SwishMassRefundItemStatus,
    SwishMassRefundOrder,
    SwishMassRefundRun,
    SwishMassRefundRunStatus,
} from "../../../api/swish-mass-refund.client.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../utilites/dates.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import classes from "./SwishMassRefundModal.module.scss";

interface SwishMassRefundModalProps extends GenericModalProps {
    eventId: IdParam;
    initialRunId?: number | null;
}

const itemStatusColor: Record<SwishMassRefundItemStatus, string> = {
    PENDING: 'gray',
    PROCESSING: 'blue',
    REQUESTED: 'blue',
    SUCCEEDED: 'green',
    FAILED: 'red',
    SKIPPED: 'yellow',
};

const runStatusColor: Record<SwishMassRefundRunStatus, string> = {
    PENDING: 'gray',
    RUNNING: 'blue',
    COMPLETED: 'green',
};

const ManualOrdersTable = ({orders, currency}: { orders: SwishMassRefundOrder[]; currency: string }) => (
    <ScrollArea.Autosize mah={260}>
        <MantineTable striped highlightOnHover withTableBorder>
            <MantineTable.Thead>
                <MantineTable.Tr>
                    <MantineTable.Th>{t`Order`}</MantineTable.Th>
                    <MantineTable.Th>{t`Buyer`}</MantineTable.Th>
                    <MantineTable.Th ta="right">{t`Amount`}</MantineTable.Th>
                    <MantineTable.Th>{t`Reason`}</MantineTable.Th>
                </MantineTable.Tr>
            </MantineTable.Thead>
            <MantineTable.Tbody>
                {orders.map((order) => (
                    <MantineTable.Tr key={order.order_id}>
                        <MantineTable.Td>{order.public_id}</MantineTable.Td>
                        <MantineTable.Td>{order.buyer_email ?? order.buyer_name ?? '-'}</MantineTable.Td>
                        <MantineTable.Td ta="right">{formatCurrency(order.amount, currency)}</MantineTable.Td>
                        <MantineTable.Td>{order.reason_label ?? order.reason ?? '-'}</MantineTable.Td>
                    </MantineTable.Tr>
                ))}
            </MantineTable.Tbody>
        </MantineTable>
    </ScrollArea.Autosize>
);

const itemStatusLabel = (status: SwishMassRefundItemStatus): string => {
    switch (status) {
        case 'PENDING':
            return t`Pending`;
        case 'PROCESSING':
            return t`Processing`;
        case 'REQUESTED':
            return t`Awaiting Swish`;
        case 'SUCCEEDED':
            return t`Refunded`;
        case 'FAILED':
            return t`Failed`;
        case 'SKIPPED':
            return t`Skipped`;
    }
};

const runStatusLabel = (status: SwishMassRefundRunStatus): string => {
    switch (status) {
        case 'PENDING':
            return t`Queued`;
        case 'RUNNING':
            return t`Running`;
        case 'COMPLETED':
            return t`Completed`;
    }
};

const ItemsTable = ({items, currency}: { items: SwishMassRefundItem[]; currency: string }) => (
    <ScrollArea.Autosize mah={320}>
        <MantineTable striped highlightOnHover withTableBorder>
            <MantineTable.Thead>
                <MantineTable.Tr>
                    <MantineTable.Th>{t`Order`}</MantineTable.Th>
                    <MantineTable.Th>{t`Buyer`}</MantineTable.Th>
                    <MantineTable.Th ta="right">{t`Amount`}</MantineTable.Th>
                    <MantineTable.Th>{t`Status`}</MantineTable.Th>
                    <MantineTable.Th>{t`Details`}</MantineTable.Th>
                </MantineTable.Tr>
            </MantineTable.Thead>
            <MantineTable.Tbody>
                {items.map((item) => (
                    <MantineTable.Tr key={item.id}>
                        <MantineTable.Td>{item.order_public_id}</MantineTable.Td>
                        <MantineTable.Td>{item.buyer_email ?? item.buyer_name ?? '-'}</MantineTable.Td>
                        <MantineTable.Td ta="right">{formatCurrency(item.amount, currency)}</MantineTable.Td>
                        <MantineTable.Td>
                            <Badge color={itemStatusColor[item.status]} variant="light">{itemStatusLabel(item.status)}</Badge>
                        </MantineTable.Td>
                        <MantineTable.Td>
                            {item.error_message
                                ? `${item.error_code ? item.error_code + ' ' : ''}${item.error_message}`
                                : '-'}
                        </MantineTable.Td>
                    </MantineTable.Tr>
                ))}
            </MantineTable.Tbody>
        </MantineTable>
    </ScrollArea.Autosize>
);

const RunProgress = ({eventId, runId, timezone, onStartAnother}: {
    eventId: IdParam;
    runId: number;
    timezone: string;
    onStartAnother: () => void;
}) => {
    const runQuery = useGetSwishMassRefundRun(eventId, runId);
    const retryMutation = useRetrySwishMassRefund(eventId);
    const run = runQuery.data;

    if (!run) {
        return <Group justify="center" p="xl"><Loader/></Group>;
    }

    const total = Math.max(run.total_orders, 1);
    const pct = (count: number) => (count / total) * 100;
    const isCompleted = run.status === 'COMPLETED';
    const failedItems = (run.items ?? []).filter((item) => item.status === 'FAILED');
    const otherItems = (run.items ?? []).filter((item) => item.status !== 'FAILED');
    const manualOrders = run.summary?.manual_orders ?? [];

    const handleRetry = () => retryMutation.mutate(run.id, {
        onSuccess: () => showSuccess(t`Failed refunds have been queued again.`),
        onError: (error: any) => showError(error?.response?.data?.message ?? t`Could not retry the failed refunds.`),
    });

    return (
        <Stack gap="md">
            <Group justify="space-between" align="center">
                <div>
                    <Text fw={600}>{t`Mass refund #${run.id}`}</Text>
                    <Text size="sm" c="dimmed">
                        {t`Started by ${run.initiated_by_name ?? '-'} on ${formatDateWithLocale(run.created_at, 'shortDateTime', timezone)}`}
                    </Text>
                </div>
                <Badge color={runStatusColor[run.status]} size="lg" variant="light">{runStatusLabel(run.status)}</Badge>
            </Group>

            <Progress.Root size={22}>
                <Progress.Section value={pct(run.succeeded_count)} color="green"/>
                <Progress.Section value={pct(run.failed_count)} color="red"/>
                <Progress.Section value={pct(run.skipped_count)} color="yellow"/>
                <Progress.Section value={pct(run.requested_count)} color="blue" striped animated={!isCompleted}/>
            </Progress.Root>

            <SimpleGrid cols={{base: 2, sm: 5}} spacing="sm">
                <Paper withBorder p="sm" className={classes.stat}>
                    <Text size="xs" c="dimmed">{t`Pending`}</Text>
                    <Text fw={700}>{run.pending_count}</Text>
                </Paper>
                <Paper withBorder p="sm" className={classes.stat}>
                    <Text size="xs" c="dimmed">{t`Awaiting Swish`}</Text>
                    <Text fw={700}>{run.requested_count}</Text>
                </Paper>
                <Paper withBorder p="sm" className={classes.stat}>
                    <Text size="xs" c="dimmed">{t`Refunded`}</Text>
                    <Text fw={700} c="green">{run.succeeded_count}</Text>
                    <Text size="xs" c="dimmed">{formatCurrency(run.succeeded_amount, run.currency)}</Text>
                </Paper>
                <Paper withBorder p="sm" className={classes.stat}>
                    <Text size="xs" c="dimmed">{t`Failed`}</Text>
                    <Text fw={700} c={run.failed_count > 0 ? 'red' : undefined}>{run.failed_count}</Text>
                </Paper>
                <Paper withBorder p="sm" className={classes.stat}>
                    <Text size="xs" c="dimmed">{t`Skipped`}</Text>
                    <Text fw={700}>{run.skipped_count}</Text>
                </Paper>
            </SimpleGrid>

            {isCompleted && (
                <Alert
                    color={run.failed_count > 0 ? 'orange' : 'green'}
                    icon={run.failed_count > 0 ? <IconAlertTriangle size={16}/> : <IconCheck size={16}/>}
                    title={t`Run completed`}
                >
                    {t`${run.succeeded_count} of ${run.total_orders} orders refunded, ${formatCurrency(run.succeeded_amount, run.currency)} of ${formatCurrency(run.total_amount, run.currency)}. A summary has been emailed to the organizer.`}
                </Alert>
            )}

            {!isCompleted && (
                <Text size="sm" c="dimmed">
                    {t`Refunds are sent to Swish a few at a time. You can close this window; the run continues in the background and can be reopened from the orders page.`}
                </Text>
            )}

            {failedItems.length > 0 && (
                <Stack gap="xs">
                    <Group justify="space-between">
                        <Text fw={600}>{t`Failed refunds`}</Text>
                        {isCompleted && (
                            <Button
                                size="xs"
                                variant="light"
                                leftSection={<IconRefresh size={14}/>}
                                loading={retryMutation.isPending}
                                onClick={handleRetry}
                                data-testid="swish-mass-refund-retry-button"
                            >
                                {t`Retry failed refunds`}
                            </Button>
                        )}
                    </Group>
                    <ItemsTable items={failedItems} currency={run.currency}/>
                </Stack>
            )}

            {manualOrders.length > 0 && (
                <Stack gap="xs">
                    <Text fw={600}>{t`Requires manual handling`} ({manualOrders.length})</Text>
                    <ManualOrdersTable orders={manualOrders} currency={run.currency}/>
                </Stack>
            )}

            {otherItems.length > 0 && (
                <Stack gap="xs">
                    <Text fw={600}>{t`All orders in this run`} ({run.total_orders})</Text>
                    <ItemsTable items={otherItems} currency={run.currency}/>
                </Stack>
            )}

            {isCompleted && (
                <Group justify="flex-end">
                    <Button variant="default" onClick={onStartAnother}>{t`Check for remaining orders`}</Button>
                </Group>
            )}
        </Stack>
    );
};

export const SwishMassRefundModal = ({eventId, onClose, initialRunId = null}: SwishMassRefundModalProps) => {
    const {data: event} = useGetEvent(eventId);
    const [viewRunId, setViewRunId] = useState<number | null>(initialRunId);
    const previewQuery = useGetSwishMassRefundPreview(eventId, viewRunId === null);
    const runsQuery = useGetSwishMassRefundRuns(eventId, viewRunId === null);
    const startMutation = useStartSwishMassRefund(eventId);
    const [confirmation, setConfirmation] = useState('');
    const [notifyBuyers, setNotifyBuyers] = useState(true);
    const [cancelOrders, setCancelOrders] = useState(true);

    const preview = previewQuery.data;
    const timezone = event?.timezone || 'UTC';

    useEffect(() => {
        if (preview?.active_run_id) {
            setViewRunId(preview.active_run_id);
        }
    }, [preview?.active_run_id]);

    const eventTitle = preview?.event_title ?? event?.title ?? '';
    const isConfirmed = confirmation.trim() === eventTitle.trim() && eventTitle !== '';
    const totalLabel = preview ? formatCurrency(preview.total_amount, preview.currency) : '';

    const handleStart = () => startMutation.mutate({
        confirmation: confirmation.trim(),
        notify_buyers: notifyBuyers,
        cancel_orders: cancelOrders,
    }, {
        onSuccess: ({data}) => {
            showSuccess(t`Mass refund started. Refunds are now being sent to Swish.`);
            setConfirmation('');
            setViewRunId(data.id);
        },
        onError: (error: any) => {
            const message = error?.response?.data?.errors?.confirmation?.[0]
                ?? error?.response?.data?.message
                ?? t`Could not start the mass refund.`;
            showError(message);
        },
    });

    const renderPreview = () => {
        if (!preview) {
            return <Group justify="center" p="xl"><Loader/></Group>;
        }

        const previousRuns: SwishMassRefundRun[] = runsQuery.data ?? [];

        return (
            <Stack gap="md">
                <Callout variant="warning" title={t`This moves real money`}>
                    {t`Every refundable Swish order for this event will be refunded in full to the buyer's Swish number. Refunds cannot be undone.`}
                </Callout>

                <SimpleGrid cols={{base: 1, sm: 3}} spacing="sm">
                    <Paper withBorder p="md" className={classes.stat}>
                        <Text size="xs" c="dimmed">{t`Refundable orders`}</Text>
                        <Text fw={700} size="xl">{preview.refundable_count}</Text>
                    </Paper>
                    <Paper withBorder p="md" className={classes.stat}>
                        <Text size="xs" c="dimmed">{t`Total to refund`}</Text>
                        <Text fw={700} size="xl">{totalLabel}</Text>
                    </Paper>
                    <Paper withBorder p="md" className={classes.stat}>
                        <Text size="xs" c="dimmed">{t`Requires manual handling`}</Text>
                        <Text fw={700} size="xl" c={preview.manual_count > 0 ? 'orange' : undefined}>{preview.manual_count}</Text>
                        {preview.manual_count > 0 && (
                            <Text size="xs" c="dimmed">{formatCurrency(preview.manual_amount, preview.currency)}</Text>
                        )}
                    </Paper>
                </SimpleGrid>

                {preview.ticket_types.length > 0 && (
                    <Stack gap="xs">
                        <Text fw={600}>{t`Per ticket type`}</Text>
                        <MantineTable withTableBorder>
                            <MantineTable.Thead>
                                <MantineTable.Tr>
                                    <MantineTable.Th>{t`Ticket type`}</MantineTable.Th>
                                    <MantineTable.Th ta="right">{t`Orders`}</MantineTable.Th>
                                    <MantineTable.Th ta="right">{t`Tickets`}</MantineTable.Th>
                                    <MantineTable.Th ta="right">{t`Amount`}</MantineTable.Th>
                                </MantineTable.Tr>
                            </MantineTable.Thead>
                            <MantineTable.Tbody>
                                {preview.ticket_types.map((ticketType) => (
                                    <MantineTable.Tr key={ticketType.name}>
                                        <MantineTable.Td>{ticketType.name}</MantineTable.Td>
                                        <MantineTable.Td ta="right">{ticketType.order_count}</MantineTable.Td>
                                        <MantineTable.Td ta="right">{ticketType.quantity}</MantineTable.Td>
                                        <MantineTable.Td ta="right">{formatCurrency(ticketType.amount, preview.currency)}</MantineTable.Td>
                                    </MantineTable.Tr>
                                ))}
                            </MantineTable.Tbody>
                        </MantineTable>
                    </Stack>
                )}

                {preview.manual_orders.length > 0 && (
                    <Stack gap="xs">
                        <Text fw={600}>{t`Cannot be refunded automatically`}</Text>
                        <Text size="sm" c="dimmed">
                            {t`These orders are excluded from the run and must be handled manually, for example by bank transfer.`}
                        </Text>
                        <ManualOrdersTable orders={preview.manual_orders} currency={preview.currency}/>
                    </Stack>
                )}

                {preview.refundable_count > 0 && (
                    <Stack gap="sm">
                        <Checkbox
                            label={t`Email each buyer a refund confirmation`}
                            checked={notifyBuyers}
                            onChange={(event) => setNotifyBuyers(event.currentTarget.checked)}
                        />
                        <Checkbox
                            label={t`Cancel the orders and release the tickets`}
                            checked={cancelOrders}
                            onChange={(event) => setCancelOrders(event.currentTarget.checked)}
                        />
                        <TextInput
                            label={t`Type the event name to confirm`}
                            description={t`Type "${eventTitle}" exactly to enable the refund button.`}
                            value={confirmation}
                            onChange={(event) => setConfirmation(event.currentTarget.value)}
                            autoComplete="off"
                            data-testid="swish-mass-refund-confirmation-input"
                        />
                        <Group justify="flex-end">
                            <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                            <Button
                                color="red"
                                disabled={!isConfirmed}
                                loading={startMutation.isPending}
                                onClick={handleStart}
                                data-testid="swish-mass-refund-confirm-button"
                            >
                                {t`Refund ${totalLabel}`}
                            </Button>
                        </Group>
                    </Stack>
                )}

                {preview.refundable_count === 0 && (
                    <Alert color="gray" icon={<IconCheck size={16}/>}>
                        {t`There are no Swish orders left to refund automatically for this event.`}
                    </Alert>
                )}

                {previousRuns.length > 0 && (
                    <Stack gap="xs">
                        <Text fw={600}>{t`Previous mass refunds`}</Text>
                        <MantineTable withTableBorder>
                            <MantineTable.Tbody>
                                {previousRuns.map((run) => (
                                    <MantineTable.Tr key={run.id}>
                                        <MantineTable.Td>#{run.id}</MantineTable.Td>
                                        <MantineTable.Td>{formatDateWithLocale(run.created_at, 'shortDateTime', timezone)}</MantineTable.Td>
                                        <MantineTable.Td>{run.initiated_by_name ?? '-'}</MantineTable.Td>
                                        <MantineTable.Td>
                                            <Badge color={runStatusColor[run.status]} variant="light">{runStatusLabel(run.status)}</Badge>
                                        </MantineTable.Td>
                                        <MantineTable.Td ta="right">
                                            {run.succeeded_count}/{run.total_orders} · {formatCurrency(run.succeeded_amount, run.currency)}
                                        </MantineTable.Td>
                                        <MantineTable.Td ta="right">
                                            <Button size="xs" variant="subtle" onClick={() => setViewRunId(run.id)}>{t`View`}</Button>
                                        </MantineTable.Td>
                                    </MantineTable.Tr>
                                ))}
                            </MantineTable.Tbody>
                        </MantineTable>
                    </Stack>
                )}
            </Stack>
        );
    };

    return (
        <Modal opened onClose={onClose} heading={t`Refund all Swish orders`}>
            {viewRunId !== null
                ? (
                    <RunProgress
                        eventId={eventId}
                        runId={viewRunId}
                        timezone={timezone}
                        onStartAnother={() => {
                            setViewRunId(null);
                            previewQuery.refetch();
                        }}
                    />
                )
                : renderPreview()}
        </Modal>
    );
};
