import {Navigate, Outlet} from "react-router";
import classes from "./Auth.module.scss";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {LanguageSwitcher} from "../../common/LanguageSwitcher";
import {useCallback, useRef} from "react";
import {getConfig} from "../../../utilites/config.ts";
import {isHiEvents} from "../../../utilites/helpers.ts";
import {showInfo} from "../../../utilites/notifications.tsx";

const BrandPanel = () => (
    <div className={classes.rightPanel}>
        <div className={classes.brandPanel}>
            <img
                src={getConfig("VITE_APP_LOGO_LIGHT", "/images/biljettera/logo-light.png")}
                alt={t`${getConfig("VITE_APP_NAME", "Biljettera")} logo`}
                className={classes.brandLogo}
            />
            <p className={classes.tagline}>{t`Upplevelser närmare dig`}</p>
        </div>
    </div>
);

const AuthLayout = () => {
    const me = useGetMe();
    const clickCountRef = useRef(0);
    const clickTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const handleLogoClick = useCallback(() => {
        clickCountRef.current += 1;
        clearTimeout(clickTimerRef.current);
        clickTimerRef.current = setTimeout(() => { clickCountRef.current = 0; }, 2000);

        if (clickCountRef.current >= 5) {
            clickCountRef.current = 0;
            showInfo(`HiEvents v${__APP_VERSION__}`);
        }
    }, []);

    if (me.isSuccess) {
        return <Navigate to={'/manage/events'} />
    }

    return (
        <div className={classes.authLayout}>
            <div className={classes.splitLayout}>
                <div className={classes.leftPanel}>
                    <main className={classes.container}>
                        <div className={classes.logo} onClick={handleLogoClick} style={{cursor: 'pointer'}}>
                            <img
                                src={getConfig("VITE_APP_LOGO_DARK", "/images/biljettera/logo-dark.png")}
                                alt={t`${getConfig("VITE_APP_NAME", "Hi.Events")} logo`}
                            />
                        </div>
                        <div className={classes.formArea}>
                            <div className={classes.wrapper}>
                                <Outlet />
                            </div>
                        </div>
                        <div className={classes.panelFooter}>
                            {/*
                             * (c) Hi.Events Ltd 2025
                             *
                             * PLEASE NOTE:
                             *
                             * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
                             *
                             * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
                             *
                             * In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice.
                             *
                             * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
                             */}
                            {!isHiEvents() && <PoweredByFooter />}
                            <div className={classes.languageSwitcher}>
                                <LanguageSwitcher />
                            </div>
                        </div>
                    </main>
                </div>

                <BrandPanel />
            </div>
        </div>
    );
};

export default AuthLayout;
