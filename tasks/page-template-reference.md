# Canonical Inertia Page Template (Laravel Starter Kit + React + shadcn)

Use this structure as the default pattern when creating new pages.

## Rules
- Use `AppLayout` with breadcrumbs.
- Use `<Head title="..."/>`.
- Keep the content wrapper structure consistent with the dashboard page.
- Prefer shadcn-style utility classes and existing project components.
- Replace labels/title/href with the target page context.

## Template
```tsx
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Page Title',
        href: '/page-slug',
    },
];

export default function PageName() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Page Title" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="relative min-h-[70vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    {/* Page content goes here */}
                </div>
            </div>
        </AppLayout>
    );
}
```

## Source
Derived from: `resources/js/pages/dashboard.tsx`
