import {t} from "@lingui/macro";
import {Button, Stack, Switch, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {Callout} from "../../../../../common/Callout";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useGetOrganizerBillingSettings} from "../../../../../../queries/useGetOrganizerBillingSettings.ts";
import {useUpdateOrganizerBillingSettings} from "../../../../../../mutations/useUpdateOrganizerBillingSettings.ts";
import {formatCurrency} from "../../../../../../utilites/currency.ts";

interface SmsSettingsFormValues {
    sms_enabled: boolean;
    sms_sender_name: string;
}

export const SmsSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetOrganizerBillingSettings(organizerId);
    const updateMutation = useUpdateOrganizerBillingSettings(organizerId);
    const formErrorHandle = useFormErrorResponseHandler();

    const settings = settingsQuery.data?.data ?? null;
    const meta = settingsQuery.data?.meta;
    const defaultSender = meta?.default_sms_sender_name ?? 'Biljettera';
    const feePerMessage = settings?.sms_fee_per_message ?? meta?.default_sms_fee_per_message ?? 0;

    const form = useForm<SmsSettingsFormValues>({
        initialValues: {
            sms_enabled: false,
            sms_sender_name: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                sms_enabled: settings.sms_enabled,
                sms_sender_name: settings.sms_sender_name ?? '',
            });
        }
    }, [settingsQuery.isFetched, settings?.updated_at]);

    const handleSubmit = (values: SmsSettingsFormValues) => {
        updateMutation.mutate({
            sms_enabled: values.sms_enabled,
            sms_sender_name: values.sms_sender_name.trim() || null,
        }, {
            onSuccess: () => {
                showSuccess(t`SMS delivery settings saved.`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            },
        });
    };

    const feeLabel = formatCurrency(feePerMessage, 'SEK');

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS delivery`}
                description={t`Send buyers a text message with a link to their tickets when an order is completed, and a notice if the order is fully refunded.`}
            />

            {meta && !meta.sms_configured && (
                <Callout variant="warning" title={t`SMS delivery is not available yet`} style={{marginBottom: 20}}>
                    {t`SMS sending has not been activated on this platform. Your settings are saved but no messages will be sent until it is.`}
                </Callout>
            )}

            {meta && (
                <Callout variant="info" title={t`Paid add-on`} style={{marginBottom: 20}}>
                    {t`SMS delivery is billed at ${feeLabel} per sent message and is added to your monthly invoice. Messages are only sent to buyers who pay with Swish or enter a Swedish mobile number at checkout.`}
                </Callout>
            )}

            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending}>
                    <Stack gap="md">
                        <Switch
                            label={t`Enable SMS delivery`}
                            description={t`Ticket links and refund notices are sent by SMS in addition to email.`}
                            checked={form.values.sms_enabled}
                            onChange={(event) => form.setFieldValue('sms_enabled', event.currentTarget.checked)}
                            data-testid="sms-settings-enabled-switch"
                        />

                        <TextInput
                            label={t`Sender name`}
                            description={t`Shown as the sender on the buyer's phone. 3-11 letters or digits, no spaces or Swedish characters. Leave blank to use ${defaultSender}.`}
                            placeholder={defaultSender}
                            maxLength={11}
                            {...form.getInputProps('sms_sender_name')}
                        />

                        <div>
                            <Button type="submit" loading={updateMutation.isPending} data-testid="sms-settings-save-button">
                                {t`Save`}
                            </Button>
                        </div>
                    </Stack>
                </fieldset>
            </form>
        </Card>
    );
};
