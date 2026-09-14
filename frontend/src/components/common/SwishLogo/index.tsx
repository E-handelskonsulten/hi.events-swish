import {useState} from "react";
import classes from './SwishLogo.module.scss';

export const SWISH_LOGO_PATH = '/images/swish/swish-logo.svg';

interface SwishLogoProps {
    height?: number;
    className?: string;
}

export const SwishLogo = ({height = 20, className = ''}: SwishLogoProps) => {
    const [imageFailed, setImageFailed] = useState(false);

    if (imageFailed) {
        return <span className={`${classes.wordmark} ${className}`} style={{fontSize: height * 0.85}}>Swish</span>;
    }

    return (
        <img
            src={SWISH_LOGO_PATH}
            alt="Swish"
            height={height}
            className={`${classes.logo} ${className}`}
            onError={() => setImageFailed(true)}
        />
    );
};
