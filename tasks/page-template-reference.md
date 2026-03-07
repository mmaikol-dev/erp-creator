# Canonical Inertia CRUD Page Template (Laravel Starter Kit + React + shadcn)

Use this as the default structure for generated pages. Stay on the default shadcn look and project tokens.

## Hard rules
- Use `AppLayout` + breadcrumbs.
- Use `<Head title="..."/>`.
- Keep wrapper structure aligned with existing pages.
- Prefer project `ui/*` components before creating custom primitives.
- Use toast notifications for success/error feedback.
- Use `Dialog`/`AlertDialog` for destructive confirmations.
- Use `Badge` for status indicators.
- Always show loading icons (`Spinner`) during CRUD processing.
- Disable action buttons while processing.

## Canonical skeleton
```tsx
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Records', href: '/records' },
];

type RecordItem = {
    id: number;
    name: string;
    status: 'active' | 'inactive';
};

export default function RecordsPage({ records = [] as RecordItem[] }) {
    const [loadingPage, setLoadingPage] = useState(false);
    const [processingCreate, setProcessingCreate] = useState(false);
    const [processingDelete, setProcessingDelete] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [deleteId, setDeleteId] = useState<number | null>(null);

    const hasData = records.length > 0;

    function createRecord(payload: { name: string }) {
        setProcessingCreate(true);
        router.post('/records', payload, {
            onSuccess: () => {
                toast.success('Record created successfully.');
                setCreateOpen(false);
            },
            onError: () => toast.error('Failed to create record.'),
            onFinish: () => setProcessingCreate(false),
        });
    }

    function deleteRecord(id: number) {
        setProcessingDelete(true);
        router.delete(`/records/${id}`, {
            onSuccess: () => {
                toast.success('Record deleted.');
                setDeleteId(null);
            },
            onError: () => toast.error('Failed to delete record.'),
            onFinish: () => setProcessingDelete(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Records" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {loadingPage && (
                    <Card>
                        <CardHeader className="space-y-2">
                            <Skeleton className="h-6 w-40" />
                            <Skeleton className="h-4 w-64" />
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                        </CardContent>
                    </Card>
                )}

                {!loadingPage && (
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                        <CardTitle>Records</CardTitle>
                        <Button onClick={() => setCreateOpen(true)}>Create record</Button>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!hasData && (
                            <div className="rounded-lg border p-6 text-sm text-muted-foreground">
                                No records yet.
                            </div>
                        )}

                        {hasData && (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-[640px] text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3">Name</th>
                                            <th className="p-3">Status</th>
                                            <th className="p-3 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {records.map((row) => (
                                            <tr key={row.id} className="border-t">
                                                <td className="p-3">{row.name}</td>
                                                <td className="p-3">
                                                    <Badge variant={row.status === 'active' ? 'default' : 'secondary'}>
                                                        {row.status}
                                                    </Badge>
                                                </td>
                                                <td className="p-3 text-right">
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        onClick={() => setDeleteId(row.id)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
                )}

                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create record</DialogTitle>
                            <DialogDescription>Add a new record.</DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            <Label htmlFor="record-name">Name</Label>
                            <Input id="record-name" placeholder="Type name..." />
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setCreateOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={processingCreate} onClick={() => createRecord({ name: 'Example' })}>
                                {processingCreate && <Spinner />}
                                Save
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={deleteId !== null} onOpenChange={(open) => !open && setDeleteId(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete record</DialogTitle>
                            <DialogDescription>This action cannot be undone.</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setDeleteId(null)}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={processingDelete || deleteId === null}
                                onClick={() => deleteId !== null && deleteRecord(deleteId)}
                            >
                                {processingDelete && <Spinner />}
                                Delete
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
```

## Required states checklist
- Loading: show `Spinner` inside action buttons and disable them.
- Page loading: use `Skeleton` blocks (`@/components/ui/skeleton`) for initial/fetch loading placeholders.
- Empty: show a clear empty-state container with action hint.
- Error: show `toast.error(...)` and keep form state safe.
- Success: show `toast.success(...)` and close/reset modal when appropriate.
