import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const paginationContentVariants = cva(
    'flex flex-row items-center gap-1',
    {
        variants: {
            variant: {
                default: 'justify-center',
                start: 'justify-start',
                end: 'justify-end',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    }
);

const paginationItemVariants = cva(
    'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground hover:bg-muted hover:text-foreground',
                outline:
                    'border border-input bg-background hover:bg-muted hover:text-foreground',
            },
            size: {
                default: 'h-9 px-4 py-2',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-5',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    }
);

const paginationLinkVariants = cva(
    'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground hover:bg-muted hover:text-foreground',
                outline:
                    'border border-input bg-background hover:bg-muted hover:text-foreground',
            },
            size: {
                default: 'h-9 px-4 py-2',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-5',
            },
            isActive: {
                true: 'bg-primary text-primary-foreground hover:bg-primary focus-visible:ring-primary',
                false: '',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
            isActive: false,
        },
    }
);

const paginationEllipsisVariants = cva(
    'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-background text-foreground hover:bg-muted hover:text-foreground',
                outline:
                    'border border-input bg-background hover:bg-muted hover:text-foreground',
            },
            size: {
                default: 'h-9 px-4 py-2',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-5',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    }
);

export interface PaginationProps
    extends React.HtmlHTMLAttributes<HTMLDivElement>,
        VariantProps<typeof paginationContentVariants> {}

export interface PaginationItemProps
    extends React.HtmlHTMLAttributes<HTMLLIElement>,
        VariantProps<typeof paginationItemVariants> {}

export interface PaginationLinkProps
    extends React.HtmlHTMLAttributes<HTMLAnchorElement>,
        VariantProps<typeof paginationLinkVariants> {
    isActive?: boolean;
}

export interface PaginationEllipsisProps
    extends React.HtmlHTMLAttributes<HTMLSpanElement>,
        VariantProps<typeof paginationEllipsisVariants> {}

export function Pagination({ className, variant, ...props }: PaginationProps) {
    return (
        <div
            className={cn(
                paginationContentVariants({ variant }),
                className
            )}
            {...props}
        />
    );
}

export function PaginationItem({
    className,
    variant,
    size,
    ...props
}: PaginationItemProps) {
    return (
        <li
            className={cn(
                paginationItemVariants({ variant, size }),
                className
            )}
            {...props}
        />
    );
}

export function PaginationLink({
    className,
    variant,
    size,
    isActive,
    ...props
}: PaginationLinkProps) {
    return (
        <a
            className={cn(
                paginationLinkVariants({ variant, size, isActive }),
                className
            )}
            {...props}
        />
    );
}

export function PaginationEllipsis({
    className,
    variant,
    size,
    ...props
}: PaginationEllipsisProps) {
    return (
        <span
            className={cn(
                paginationEllipsisVariants({ variant, size }),
                className
            )}
            {...props}
        >
            <span className="hidden-sm sm:inline-flex">...</span>
        </span>
    );
}

PaginationItem.displayName = 'PaginationItem';
PaginationLink.displayName = 'PaginationLink';
PaginationEllipsis.displayName = 'PaginationEllipsis';
