import { cn } from '@/lib/utils';
import { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <>
            <img
                {...props}
                src="/images/brand/bordeguna-logo-dark-bg.png"
                alt="Bor De Guna"
                className={cn('hidden object-contain dark:block', className)}
            />
            <img
                {...props}
                src="/images/brand/bordeguna-logo-light-bg.png"
                alt="Bor De Guna"
                className={cn('block object-contain dark:hidden', className)}
            />
        </>
    );
}
