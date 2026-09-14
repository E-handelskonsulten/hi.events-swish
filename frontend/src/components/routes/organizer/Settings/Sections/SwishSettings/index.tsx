import {t} from "@lingui/macro";
import {Alert, Button, Group, PasswordInput, Select, Stack, Switch, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {IconCheck, IconX} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {Callout} from "../../../../../common/Callout";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useGetOrganizerSwishSettings} from "../../../../../../queries/useGetOrganizerSwishSettings.ts";
import {useUpdateOrganizerSwishSettings} from "../../../../../../mutations/useUpdateOrganizerSwishSettings.ts";
import {useTestOrganizerSwishSettings} from "../../../../../../mutations/useTestOrganizerSwishSettings.ts";
import {SwishEnvironment} from "../../../../../../api/organizer-swish.client.ts";
import {formatDateWithLocale} from "../../../../../../utilites/dates.ts";
import {useGetOrganizer} from "../../../../../../queries/useGetOrganizer.ts";

interface SwishSettingsFormValues {
    enabled: boolean;
    environment: SwishEnvironment;
    payee_alias: string;
    cert_path: string;
    key_path: string;
    ca_path: string;
    key_passphrase: string;
}

export const SwishSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetOrganizerSwishSettings(organizerId);
    const organizerQuery = useGetOrganizer(organizerId);
    const updateMutation = useUpdateOrganizerSwishSettings(organizerId);
    const testMutation = useTestOrganizerSwishSettings(organizerId);
    const formErrorHandle = useFormErrorResponseHandler();

    const settings = settingsQuery.data?.data ?? null;
    const meta = settingsQuery.data?.meta;

    const form = useForm<SwishSettingsFormValues>({
        initialValues: {
            enabled: false,
            environment: 'mss',
            payee_alias: '',
            cert_path: '',
            key_path: '',
            ca_path: '',
            key_passphrase: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                enabled: settings.enabled,
                environment: settings.environment,
                payee_alias: settings.payee_alias ?? '',
                cert_path: settings.cert_path ?? '',
                key_path: settings.key_path ?? '',
                ca_path: settings.ca_path ?? '',
                key_passphrase: '',
            });
        }
    }, [settingsQuery.isFetched, settings?.updated_at]);

    const handleSubmit = (values: SwishSettingsFormValues) => {
        updateMutation.mutate({
            enabled: values.enabled,
            environment: values.environment,
            payee_alias: values.payee_alias.replace(/\s+/g, '') || null,
            cert_path: values.cert_path.trim() || null,
            key_path: values.key_path.trim() || null,
            ca_path: values.ca_path.trim() || null,
            ...(values.key_passphrase !== '' ? {key_passphrase: values.key_passphrase} : {}),
        }, {
            onSuccess: () => {
                showSuccess(values.enabled
                    ? t`Swish settings saved and the connection to Swish was verified.`
                    : t`Swish settings saved.`);
                form.setFieldValue('key_passphrase', '');
            },
            onError: (error) => {
                formErrorHandle(form, error);
            },
        });
    };

    const handleTest = () => {
        testMutation.mutate(undefined, {
            onSuccess: ({data}) => {
                if (data.success) {
                    showSuccess(data.message);
                } else {
                    showError(data.message);
                }
            },
            onError: () => {
                showError(t`Could not run the Swish connection test. Please try again.`);
            },
        });
    };

    const environmentOptions = [
        {value: 'mss', label: t`Test (Merchant Swish Simulator)`},
        {value: 'production', label: t`Production`},
    ];

    const timezone = organizerQuery.data?.timezone || 'UTC';

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Swish`}
                description={t`Accept Swish payments for this organizer's events. Certificate files must be placed on the server; only their paths are stored here.`}
            />

            {!settings && meta?.environment_fallback_configured && (
                <Callout variant="info" title={t`Configured by the server`} style={{marginBottom: 20}}>
                    {t`Swish is currently configured through the server environment for Swish number ${meta.environment_fallback_payee_alias ?? ''}. Saving settings here overrides that configuration for this organizer.`}
                </Callout>
            )}

            {settings?.last_verification_error && (
                <Alert color="red" icon={<IconX size={16}/>} mb="md" title={t`Last connection test failed`}>
                    {settings.last_verification_error}
                </Alert>
            )}

            {settings?.last_verified_at && !settings.last_verification_error && (
                <Alert color="green" icon={<IconCheck size={16}/>} mb="md" title={t`Connection verified`}>
                    {t`Swish accepted this configuration on ${formatDateWithLocale(settings.last_verified_at, 'shortDateTime', timezone)}.`}
                </Alert>
            )}

            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending}>
                    <Stack gap="md">
                        <Switch
                            label={t`Enable Swish`}
                            description={t`When enabled, Swish can be selected as a payment method in each event's payment settings.`}
                            checked={form.values.enabled}
                            onChange={(event) => form.setFieldValue('enabled', event.currentTarget.checked)}
                        />

                        <Select
                            label={t`Environment`}
                            data={environmentOptions}
                            allowDeselect={false}
                            {...form.getInputProps('environment')}
                        />

                        <TextInput
                            label={t`Swish number`}
                            description={t`The merchant Swish number (payee alias), 10 or 11 digits, for example 1234679304.`}
                            placeholder="1234679304"
                            {...form.getInputProps('payee_alias')}
                        />

                        <TextInput
                            label={t`Certificate path`}
                            description={t`Absolute path on the server to the merchant certificate in PEM format.`}
                            placeholder="/var/www/html/storage/app/swish-certs/merchant.pem"
                            {...form.getInputProps('cert_path')}
                        />

                        <TextInput
                            label={t`Private key path`}
                            description={t`Absolute path on the server to the private key in PEM format.`}
                            placeholder="/var/www/html/storage/app/swish-certs/merchant.key"
                            {...form.getInputProps('key_path')}
                        />

                        <TextInput
                            label={t`Root CA path`}
                            description={t`Absolute path on the server to the Swish root CA bundle in PEM format.`}
                            placeholder="/var/www/html/storage/app/swish-certs/Swish_TLS_RootCA.pem"
                            {...form.getInputProps('ca_path')}
                        />

                        <PasswordInput
                            label={t`Private key passphrase`}
                            description={settings?.has_key_passphrase
                                ? t`A passphrase is stored. Leave blank to keep it.`
                                : t`Leave blank if the private key is not encrypted.`}
                            {...form.getInputProps('key_passphrase')}
                        />

                        <Group>
                            <Button type="submit" loading={updateMutation.isPending} data-testid="swish-settings-save-button">
                                {t`Save`}
                            </Button>
                            <Button
                                variant="light"
                                loading={testMutation.isPending}
                                onClick={handleTest}
                                data-testid="swish-settings-test-button"
                            >
                                {t`Test connection`}
                            </Button>
                        </Group>

                        {form.values.enabled && (
                            <Text size="sm" c="dimmed">
                                {t`Saving with Swish enabled performs a live connection test against Swish before the settings are stored.`}
                            </Text>
                        )}
                    </Stack>
                </fieldset>
            </form>
        </Card>
    );
};
