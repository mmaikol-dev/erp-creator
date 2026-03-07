import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Suppliers', href: '/suppliers' },
];

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    status: 'active' | 'inactive';
    notes: string | null;
};

type PageProps = {
    suppliers: Supplier[];
    success?: string;
    error?: string;
};

export default function SuppliersPage({ suppliers = [] as Supplier[], success, error }: PageProps) {
    const [loadingPage, setLoadingPage] = useState(true);
    const [processingCreate, setProcessingCreate] = useState(false);
    const [processingUpdate, setProcessingUpdate] = useState(false);
    const [processingDelete, setProcessingDelete] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [editSupplier, setEditSupplier] = useState<Supplier | null>(null);
    const [deleteSupplier, setDeleteSupplier] = useState<Supplier | null>(null);

    const [form, setForm] = useState({
        name: '',
        contact_person: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        country: '',
        status: 'active' as 'active' | 'inactive',
        notes: '',
    });

    const hasData = suppliers.length > 0;

    useEffect(() => {
        if (success) toast.success(success);
        if (error) toast.error(error);
    }, [success, error]);

    useEffect(() => {
        const timer = setTimeout(() => setLoadingPage(false), 300);
        return () => clearTimeout(timer);
    }, []);

    function resetForm() {
        setForm({
            name: '',
            contact_person: '',
            email: '',
            phone: '',
            address: '',
            city: '',
            country: '',
            status: 'active',
            notes: '',
        });
    }

    function openCreate() {
        resetForm();
        setCreateOpen(true);
    }

    function openEdit(supplier: Supplier) {
        setForm({
            name: supplier.name,
            contact_person: supplier.contact_person || '',
            email: supplier.email || '',
            phone: supplier.phone || '',
            address: supplier.address || '',
            city: supplier.city || '',
            country: supplier.country || '',
            status: supplier.status,
            notes: supplier.notes || '',
        });
        setEditSupplier(supplier);
    }

    function createSupplier() {
        if (!form.name.trim()) {
            toast.error('Name is required.');
            return;
        }
        setProcessingCreate(true);
        router.post('/suppliers', form, {
            onSuccess: () => {
                toast.success('Supplier created successfully.');
                setCreateOpen(false);
                resetForm();
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                toast.error(firstError || 'Failed to create supplier.');
            },
            onFinish: () => setProcessingCreate(false),
        });
    }

    function updateSupplier() {
        if (!editSupplier) return;
        if (!form.name.trim()) {
            toast.error('Name is required.');
            return;
        }
        setProcessingUpdate(true);
        router.put(`/suppliers/${editSupplier.id}`, form, {
            onSuccess: () => {
                toast.success('Supplier updated successfully.');
                setEditSupplier(null);
                resetForm();
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                toast.error(firstError || 'Failed to update supplier.');
            },
            onFinish: () => setProcessingUpdate(false),
        });
    }

    function deleteConfirmed() {
        if (!deleteSupplier) return;
        setProcessingDelete(true);
        router.delete(`/suppliers/${deleteSupplier.id}`, {
            onSuccess: () => {
                toast.success('Supplier deleted successfully.');
                setDeleteSupplier(null);
            },
            onError: () => toast.error('Failed to delete supplier.'),
            onFinish: () => setProcessingDelete(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suppliers" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {loadingPage && (
                    <Card>
                        <CardHeader className="space-y-2">
                            <Skeleton className="h-6 w-32" />
                            <Skeleton className="h-4 w-48" />
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
                            <CardTitle>Suppliers</CardTitle>
                            <Button onClick={openCreate}>Add Supplier</Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {!hasData && (
                                <div className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                                    No suppliers found. Click "Add Supplier" to create one.
                                </div>
                            )}

                            {hasData && (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full min-w-[800px] text-sm">
                                        <thead className="bg-muted/50 text-left">
                                            <tr>
                                                <th className="p-3">Name</th>
                                                <th className="p-3">Contact Person</th>
                                                <th className="p-3">Email</th>
                                                <th className="p-3">Phone</th>
                                                <th className="p-3">City</th>
                                                <th className="p-3">Status</th>
                                                <th className="p-3 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {suppliers.map((supplier) => (
                                                <tr key={supplier.id} className="border-t">
                                                    <td className="p-3 font-medium">{supplier.name}</td>
                                                    <td className="p-3">{supplier.contact_person || '—'}</td>
                                                    <td className="p-3">{supplier.email || '—'}</td>
                                                    <td className="p-3">{supplier.phone || '—'}</td>
                                                    <td className="p-3">{supplier.city || '—'}</td>
                                                    <td className="p-3">
                                                        <Badge variant={supplier.status === 'active' ? 'default' : 'secondary'}>
                                                            {supplier.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button variant="outline" size="sm" onClick={() => openEdit(supplier)}>
                                                                Edit
                                                            </Button>
                                                            <Button
                                                                variant="destructive"
                                                                size="sm"
                                                                onClick={() => setDeleteSupplier(supplier)}
                                                            >
                                                                Delete
                                                            </Button>
                                                        </div>
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

                {/* Create Dialog */}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Supplier</DialogTitle>
                            <DialogDescription>Create a new supplier record.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="create-name">Name *</Label>
                                <Input
                                    id="create-name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    placeholder="Supplier name"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="create-contact">Contact Person</Label>
                                <Input
                                    id="create-contact"
                                    value={form.contact_person}
                                    onChange={(e) => setForm({ ...form, contact_person: e.target.value })}
                                    placeholder="Contact name"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="create-email">Email</Label>
                                    <Input
                                        id="create-email"
                                        type="email"
                                        value={form.email}
                                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                                        placeholder="email@example.com"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="create-phone">Phone</Label>
                                    <Input
                                        id="create-phone"
                                        value={form.phone}
                                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                        placeholder="Phone number"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="create-address">Address</Label>
                                <Input
                                    id="create-address"
                                    value={form.address}
                                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                                    placeholder="Street address"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="create-city">City</Label>
                                    <Input
                                        id="create-city"
                                        value={form.city}
                                        onChange={(e) => setForm({ ...form, city: e.target.value })}
                                        placeholder="City"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="create-country">Country</Label>
                                    <Input
                                        id="create-country"
                                        value={form.country}
                                        onChange={(e) => setForm({ ...form, country: e.target.value })}
                                        placeholder="Country"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="create-status">Status</Label>
                                <select
                                    id="create-status"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    value={form.status}
                                    onChange={(e) => setForm({ ...form, status: e.target.value as 'active' | 'inactive' })}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="create-notes">Notes</Label>
                                <textarea
                                    id="create-notes"
                                    className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                    placeholder="Additional notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setCreateOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={processingCreate} onClick={createSupplier}>
                                {processingCreate && <Spinner />}
                                Create
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Edit Dialog */}
                <Dialog open={editSupplier !== null} onOpenChange={(open) => !open && setEditSupplier(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit Supplier</DialogTitle>
                            <DialogDescription>Update supplier information.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit-name">Name *</Label>
                                <Input
                                    id="edit-name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    placeholder="Supplier name"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-contact">Contact Person</Label>
                                <Input
                                    id="edit-contact"
                                    value={form.contact_person}
                                    onChange={(e) => setForm({ ...form, contact_person: e.target.value })}
                                    placeholder="Contact name"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-email">Email</Label>
                                    <Input
                                        id="edit-email"
                                        type="email"
                                        value={form.email}
                                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                                        placeholder="email@example.com"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-phone">Phone</Label>
                                    <Input
                                        id="edit-phone"
                                        value={form.phone}
                                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                        placeholder="Phone number"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-address">Address</Label>
                                <Input
                                    id="edit-address"
                                    value={form.address}
                                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                                    placeholder="Street address"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-city">City</Label>
                                    <Input
                                        id="edit-city"
                                        value={form.city}
                                        onChange={(e) => setForm({ ...form, city: e.target.value })}
                                        placeholder="City"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-country">Country</Label>
                                    <Input
                                        id="edit-country"
                                        value={form.country}
                                        onChange={(e) => setForm({ ...form, country: e.target.value })}
                                        placeholder="Country"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-status">Status</Label>
                                <select
                                    id="edit-status"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    value={form.status}
                                    onChange={(e) => setForm({ ...form, status: e.target.value as 'active' | 'inactive' })}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-notes">Notes</Label>
                                <textarea
                                    id="edit-notes"
                                    className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                    placeholder="Additional notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setEditSupplier(null)}>
                                Cancel
                            </Button>
                            <Button disabled={processingUpdate} onClick={updateSupplier}>
                                {processingUpdate && <Spinner />}
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Delete Confirmation Dialog */}
                <Dialog open={deleteSupplier !== null} onOpenChange={(open) => !open && setDeleteSupplier(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete Supplier</DialogTitle>
                            <DialogDescription>
                                Are you sure you want to delete "{deleteSupplier?.name}"? This action cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setDeleteSupplier(null)}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={processingDelete}
                                onClick={deleteConfirmed}
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
