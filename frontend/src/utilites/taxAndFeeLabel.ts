import {t} from "@lingui/macro";
import {TaxAndFee, TaxAndFeeCalculationType} from "../types.ts";
import {formatCurrency} from "./currency.ts";

export const taxAndFeeRateLabel = (item: TaxAndFee, currency?: string): string => {
    const currencyCode = currency || 'USD';

    switch (item.calculation_type) {
        case TaxAndFeeCalculationType.Percentage:
            return item.rate + '%' + (item.is_inclusive ? ' ' + t`(included)` : '');
        case TaxAndFeeCalculationType.FixedPlusPercentage:
            return formatCurrency(Number(item.fixed_amount || 0), currencyCode) + ' + ' + item.rate + '%';
        default:
            return formatCurrency(Number(item.rate), currencyCode);
    }
};
