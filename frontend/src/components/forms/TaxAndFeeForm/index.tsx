import {UseFormReturnType} from "@mantine/form";
import {TaxAndFee, TaxAndFeeCalculationType, TaxAndFeeType} from "../../../types.ts";
import {NumberInput, Switch, TextInput} from "@mantine/core";
import {CustomSelect, ItemProps} from "../../common/CustomSelect";
import {IconCash, IconPercentage, IconPlus, IconReceiptTax} from "@tabler/icons-react";
import {t} from "@lingui/macro";

export const TaxAndFeeForm = ({form}: { form: UseFormReturnType<TaxAndFee> }) => {
    const typeOptions: ItemProps[] = [
        {
            icon: <IconReceiptTax/>,
            label: t`Tax`,
            value: 'TAX',
            description: t`A standard tax, like VAT or GST`,
        },
        {
            icon: <IconCash/>,
            label: t`Fee`,
            value: 'FEE',
            description: t`A fee, like a booking fee or a service fee`,
        },
    ];

    const calcTypeOptions: ItemProps[] = [
        {
            icon: <IconPercentage/>,
            label: t`Percentage`,
            value: 'PERCENTAGE',
            description: t`A percentage of the product price. E.g., 3.5% of the product price`,
        },
        {
            icon: <IconCash/>,
            label: t`Fixed`,
            value: 'FIXED',
            description: t`A fixed amount per product. E.g, $0.50 per product`,
        },
        {
            icon: <IconPlus/>,
            label: t`Fixed + percentage`,
            value: 'FIXED_PLUS_PERCENTAGE',
            description: t`A fixed amount plus a percentage of the product price, shown to the buyer as one amount. E.g. $0.50 + 1%`,
        },
    ];

    const isPercentage = form.values.calculation_type === TaxAndFeeCalculationType.Percentage;
    const isCombined = form.values.calculation_type === TaxAndFeeCalculationType.FixedPlusPercentage;

    const type = (form.values.type === TaxAndFeeType.Tax ? t`Tax` : t`Fee`).toLowerCase();

    return (
        <div>
            <CustomSelect
                label={t`Type`}
                required
                form={form}
                name={'type'}
                optionList={typeOptions}
            />

            <CustomSelect
                label={t`Calculation Type`}
                required
                form={form}
                name={'calculation_type'}
                optionList={calcTypeOptions}
            />

            <TextInput
                {...form.getInputProps('name')}
                label={t`Name`}
                placeholder={form.values.type === TaxAndFeeType.Tax ? t`VAT` : t`Service Fee`}
                required
            />

            {isCombined && (
                <NumberInput
                    decimalScale={2}
                    fixedDecimalScale
                    step={0.50}
                    min={0}
                    {...form.getInputProps('fixed_amount')}
                    label={t`Fixed Amount`}
                    placeholder="4.00"
                    description={t`Charged once per product, before the percentage is added`}
                    required
                />
            )}

            <NumberInput
                decimalScale={2}
                fixedDecimalScale
                step={0.50}
                {...form.getInputProps('rate')}
                label={isPercentage || isCombined ? t`Percentage Amount` : t`Amount`}
                placeholder={isPercentage || isCombined ? '23' : '2.50'}
                leftSection={isPercentage || isCombined ? '%' : ''}
                description={isCombined
                    ? t`Percentage of the product price, added to the fixed amount`
                    : (isPercentage ? t`eg. 23.5 for 23.5%` : t`eg. 2.50 for $2.50`)}
                required
                max={isPercentage || isCombined ? 100 : undefined}
            />

            <TextInput
                {...form.getInputProps('description')}
                label={t`Description`}
            />

            <Switch
                {...form.getInputProps('is_default', {type: 'checkbox'})}
                label={t`Apply this ${type} to all new products`}
                value={1}
                description={t`A default ${type} is automaticaly applied to all new products. You can override this on a per product basis.`}
            />
        </div>
    )
}
