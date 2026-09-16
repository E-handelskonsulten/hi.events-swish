import {Link, useParams} from "react-router";
import {PageBody} from "../../../../common/PageBody";
import {Button} from "@mantine/core";
import {IconChevronLeft} from "@tabler/icons-react";
import {OrganizerReportTypes} from "../../../../../types.ts";
import RevenueSummaryReport from "../RevenueSummaryReport";
import EventsPerformanceReport from "../EventsPerformanceReport";
import TaxSummaryReport from "../TaxSummaryReport";
import CheckInSummaryReport from "../CheckInSummaryReport";
import PlatformFeesReport from "../PlatformFeesReport";
import AccountingReport from "../AccountingReport";
import {t} from "@lingui/macro";
import {useGetAccount} from "../../../../../queries/useGetAccount.ts";

const renderReport = (reportType: string, isSaasMode: boolean) => {
    switch (reportType) {
        case OrganizerReportTypes.RevenueSummary:
            return <RevenueSummaryReport/>;
        case OrganizerReportTypes.EventsPerformance:
            return <EventsPerformanceReport/>;
        case OrganizerReportTypes.TaxSummary:
            return <TaxSummaryReport/>;
        case OrganizerReportTypes.CheckInSummary:
            return <CheckInSummaryReport/>;
        case OrganizerReportTypes.PlatformFees:
            return isSaasMode ? <PlatformFeesReport/> : <div>{t`Report not found`}</div>;
        case OrganizerReportTypes.Accounting:
            return <AccountingReport/>;
        default:
            return <div>{t`Report not found`}</div>;
    }
};

const OrganizerReportLayout = () => {
    const {organizerId, reportType} = useParams();
    const {data: account} = useGetAccount();

    return (
        <PageBody>
            <Button mb={20}
                    leftSection={<IconChevronLeft/>}
                    variant={'transparent'}
                    component={Link}
                    to={`/manage/organizer/${organizerId}/reports`}
                    pl={0}
            >
                {t`Back to Reports`}
            </Button>
            <div>
                {renderReport(reportType as string, !!account?.is_saas_mode_enabled)}
            </div>
        </PageBody>
    );
}

export default OrganizerReportLayout;
