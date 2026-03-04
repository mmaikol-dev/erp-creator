import { Head } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="relative min-h-[70vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    <div className="relative z-10 flex h-full items-center justify-center p-6 text-center">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Dashboard Coming Soon
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                This space is reserved for future dashboard
                                features.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
