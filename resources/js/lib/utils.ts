import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    if (typeof url === 'string') {
        return url;
    }

    if (url && typeof url === 'object' && 'url' in url && typeof url.url === 'string') {
        return url.url;
    }

    return '';
}
