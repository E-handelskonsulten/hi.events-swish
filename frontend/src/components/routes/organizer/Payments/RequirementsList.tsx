import {List, ThemeIcon} from "@mantine/core";
import {IconAlertCircle, IconClock} from "@tabler/icons-react";
import {t} from "@lingui/macro";

interface RequirementsListProps {
    items: string[];
    severity?: "due" | "eventually";
}

const getRequirementLabels = (): Record<string, string> => ({
    external_account: t`Bank account`,
    "individual.verification.document": t`Government-issued photo ID`,
    "individual.verification.additional_document": t`Additional ID verification`,
    "individual.address.line1": t`Personal address`,
    "individual.address.city": t`Personal city`,
    "individual.address.postal_code": t`Personal postal code`,
    "individual.dob.day": t`Date of birth`,
    "individual.email": t`Email address`,
    "individual.first_name": t`First name`,
    "individual.last_name": t`Last name`,
    "individual.phone": t`Phone number`,
    "individual.ssn_last_4": t`Last 4 of SSN`,
    "individual.id_number": t`Government ID number`,
    "business_profile.url": t`Business website`,
    "business_profile.mcc": t`Business category (MCC)`,
    "business_profile.product_description": t`Business description`,
    "business_profile.support_phone": t`Customer support phone`,
    "company.tax_id": t`Business tax ID`,
    "company.name": t`Legal business name`,
    "company.address.line1": t`Business address`,
    "company.address.city": t`Business city`,
    "company.address.postal_code": t`Business postal code`,
    "company.verification.document": t`Business verification document`,
    "tos_acceptance.date": t`Stripe terms acceptance`,
    "tos_acceptance.ip": t`Stripe terms acceptance`,
});

const labelFor = (key: string): string => {
    const labels = getRequirementLabels();
    if (labels[key]) return labels[key];
    return key
        .replace(/_/g, " ")
        .replace(/\./g, " ‣ ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
};

export const RequirementsList = ({items, severity = "due"}: RequirementsListProps) => {
    if (!items?.length) return null;
    const color = severity === "due" ? "orange" : "blue";
    const Icon = severity === "due" ? IconAlertCircle : IconClock;

    return (
        <List
            spacing={4}
            size="sm"
            icon={
                <ThemeIcon color={color} variant="light" size={20} radius="xl">
                    <Icon size={14}/>
                </ThemeIcon>
            }
        >
            {items.map((key) => (
                <List.Item key={key}>{labelFor(key)}</List.Item>
            ))}
        </List>
    );
};
