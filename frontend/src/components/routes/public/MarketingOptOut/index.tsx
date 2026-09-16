import {useParams} from "react-router";
import {useState} from "react";
import {useMutation, useQuery} from "@tanstack/react-query";
import {Button, Container} from "@mantine/core";
import {t} from "@lingui/macro";
import {marketingClientPublic} from "../../../../api/marketing.client.ts";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import classes from './MarketingOptOut.module.scss';

export const MarketingOptOut = () => {
    const {token} = useParams();
    const [done, setDone] = useState(false);
    const statusQuery = useQuery({
        queryKey: ['marketingOptOutStatus', token],
        queryFn: async () => (await marketingClientPublic.optOutStatus(String(token))).data,
        retry: false,
    });
    const optOutMutation = useMutation({
        mutationFn: () => marketingClientPublic.optOut(String(token)),
        onSuccess: () => setDone(true),
    });

    if (statusQuery.isError) {
        return (
            <HomepageInfoMessage
                status="not_found"
                message={t`Link not valid`}
                subtitle={t`This unsubscribe link is not valid. It may have been copied incompletely.`}
            />
        );
    }

    if (!statusQuery.data) {
        return null;
    }

    const organizer = statusQuery.data.organizer_name;
    const alreadyOut = !statusQuery.data.opted_in || done;

    return (
        <Container size="xs" className={classes.page}>
            <div className={classes.card}>
                {alreadyOut ? (
                    <>
                        <h1 className={classes.title}>{t`You are unsubscribed`}</h1>
                        <p className={classes.text}>{t`You will no longer receive marketing messages from ${organizer}. Ticket confirmations and practical information about events you have tickets for are still sent.`}</p>
                    </>
                ) : (
                    <>
                        <h1 className={classes.title}>{t`Unsubscribe from marketing`}</h1>
                        <p className={classes.text}>{t`Stop receiving marketing messages from ${organizer} by SMS and email. Ticket confirmations and practical information about events you have tickets for are still sent.`}</p>
                        <Button
                            size="md"
                            fullWidth
                            loading={optOutMutation.isPending}
                            onClick={() => optOutMutation.mutate()}
                            data-testid="marketing-opt-out-button"
                        >
                            {t`Unsubscribe me`}
                        </Button>
                        {optOutMutation.isError && (
                            <p className={classes.error}>{t`Something went wrong. Please try again.`}</p>
                        )}
                    </>
                )}
            </div>
            <PoweredByFooter/>
        </Container>
    );
};

export default MarketingOptOut;
