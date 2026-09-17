import {Link, useParams} from "react-router";
import {useGetOrganizer} from "../../../../../queries/useGetOrganizer.ts";
import {useGetOrganizerStats} from "../../../../../queries/useGetOrganizerStats.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import OrganizerReportTable, {RenderContext} from "../../../../common/OrganizerReportTable";
import {t} from "@lingui/macro";
import {Badge} from "@mantine/core";
import {Callout} from "../../../../common/Callout";

interface AccountingReportRow {
    date: string;
    event_id: number;
    event_name: string;
    currency: string;
    payment_provider: string;
    line_type: 'SALE' | 'REFUND';
    transaction_count: number;
    gross_amount: string;
    service_fee_amount: string;
    vat_25_amount: string;
    vat_12_amount: string;
    vat_6_amount: string;
    vat_other_amount: string;
    vat_total_amount: string;
    net_amount: string;
}

const AccountingReport = () => {
    const {organizerId} = useParams();
    const organizerQuery = useGetOrganizer(organizerId);
    const organizer = organizerQuery.data;

    const statsQuery = useGetOrganizerStats(organizerId, organizer?.currency);
    const allCurrencies = statsQuery.data?.all_organizers_currencies || [];

    if (!organizer) {
        return null;
    }

    const money = (value: string, row: AccountingReportRow, context: RenderContext) =>
        formatCurrency(value, row.currency || context.currency);

    const lineTypeLabels: Record<AccountingReportRow['line_type'], string> = {
        SALE: t`Sale`,
        REFUND: t`Refund`,
    };

    const columns = [
        {
            key: 'date' as const,
            label: t`Date`,
            sortable: true,
            render: (value: string) => formatDateWithLocale(value, 'shortDate', organizer.timezone || 'UTC')
        },
        {
            key: 'event_name' as const,
            label: t`Event`,
            sortable: true,
            render: (value: string, row: AccountingReportRow) => (
                <Link to={`/manage/event/${row.event_id}/dashboard`} style={{textDecoration: 'none', color: 'inherit'}}>
                    {value}
                </Link>
            )
        },
        {
            key: 'payment_provider' as const,
            label: t`Payment Method`,
            sortable: true,
        },
        {
            key: 'line_type' as const,
            label: t`Type`,
            sortable: true,
            render: (value: AccountingReportRow['line_type']) => (
                <Badge variant="light" color={value === 'REFUND' ? 'red' : 'green'}>
                    {lineTypeLabels[value] ?? value}
                </Badge>
            )
        },
        {
            key: 'transaction_count' as const,
            label: t`Transactions`,
            sortable: true,
        },
        {key: 'gross_amount' as const, label: t`Gross`, sortable: true, render: money},
        {key: 'service_fee_amount' as const, label: t`Service Fees`, sortable: true, render: money},
        {key: 'vat_25_amount' as const, label: t`VAT 25%`, sortable: true, render: money},
        {key: 'vat_12_amount' as const, label: t`VAT 12%`, sortable: true, render: money},
        {key: 'vat_6_amount' as const, label: t`VAT 6%`, sortable: true, render: money},
        {key: 'vat_other_amount' as const, label: t`VAT Other`, sortable: true, render: money},
        {key: 'vat_total_amount' as const, label: t`VAT Total`, sortable: true, render: money},
        {key: 'net_amount' as const, label: t`Net (excl. VAT)`, sortable: true, render: money},
    ];

    return (
        <>
            <Callout variant="info" title={t`How to read this report`} style={{marginBottom: 24}}>
                {t`One line per day, event and payment method. Sales are positive and refunds are separate negative lines, so the sum of the Gross column equals the amount settled to your bank account before payment provider fees. VAT is split by Swedish rate; service fees are included in Gross.`}
                {' '}
                {t`Service fee VAT is included in the rate columns for orders placed after "VAT follows the ticket's VAT rate" was enabled on the fee; earlier orders carry no fee VAT.`}
            </Callout>

            <OrganizerReportTable<AccountingReportRow>
                title={t`Accounting Report`}
                columns={columns}
                isLoading={organizerQuery.isLoading}
                showDateFilter={true}
                organizer={organizer}
                showCurrencyFilter={true}
                availableCurrencies={allCurrencies}
            />
        </>
    );
};

export default AccountingReport;
