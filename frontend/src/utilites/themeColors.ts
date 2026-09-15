import {generateColors} from "@mantine/colors-generator";
import {MantineColorsTuple} from "@mantine/core";
import {getConfig} from "./config.ts";

export type ThemeColors = Record<"primary" | "secondary", MantineColorsTuple>;

const biljetteraPrimary: MantineColorsTuple = [
    "#EFF6FF", "#DBEAFE", "#BFDBFE", "#93C5FD", "#60A5FA",
    "#3B82F6", "#3273F0", "#2B6BED", "#2563EB", "#1D4ED8",
];

const biljetteraSecondary: MantineColorsTuple = [
    "#EFF6FF", "#DBEAFE", "#BFDBFE", "#93C5FD", "#60A5FA",
    "#2563EB", "#1D4ED8", "#1E40AF", "#1E3A8A", "#172554",
];

const colorsFor = (key: "VITE_APP_PRIMARY_COLOR" | "VITE_APP_SECONDARY_COLOR", fallback: MantineColorsTuple): MantineColorsTuple => {
    const configured = getConfig(key);

    return configured ? generateColors(configured) : fallback;
};

export const generateThemeColors = (): ThemeColors => ({
    primary: colorsFor("VITE_APP_PRIMARY_COLOR", biljetteraPrimary),
    secondary: colorsFor("VITE_APP_SECONDARY_COLOR", biljetteraSecondary),
});
