import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Home',
        href: '/dashboard',
    },
    {
        title: 'Orders',
        href: '/orders',
    },
];

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

export default function Index({ orders, filters }: any) {
    const { data, setData, post, processing, resetsForm } = useForm({
        search: filters.search,
        status: filters.status,
    });

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        post('/orders');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Orders" />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-3xl font-bold tracking-tight">Orders</h1>
                    <Link href="/orders/create">
                        <Button>Create Order</Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Order List</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSearch} className="mb-4 flex gap-4">
                            <Input
                                placeholder="Search by customer name or email"
                                value={data.search}
                                onChange={(e) =>
                                    setData('search', e.target.value)
                                }
                                className="max-w-sm"
                            />
                            <select
                                value={data.status}
                                onChange={(e) =>
                                    setData('status', e.target.value)
                                }
                                className="rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                            >
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <Button type="submit" disabled={processing}>
                                Filter
                            </Button>
                        </form>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Customer</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Total</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {orders.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center">
                                            No orders found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    orders.data.map((order: any) => (
                                        <TableRow key={order.id}>
                                            <TableCell className="font-mono text-sm">
                                                #{order.id}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {order.customer_name}
                                            </TableCell>
                                            <TableCell>
                                                {order.customer_email}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        statusColors[order.status]
                                                    }
                                                >
                                                    {order.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                ${order.total_amount.toFixed(2)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link
                                                        href={`/orders/${order.id}`}
                                                        className="text-sm text-primary hover:underline"
                                                    >
                                                        View
                                                    </Link>
                                                    <Link
                                                        href={`/orders/${order.id}/edit`}
                                                        className="text-sm text-primary hover:underline"
                                                    >
                                                        Edit
                                                    </Link>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>

                        {orders.meta && (
                            <div className="mt-4">
                                Showing {orders.from} to {orders.to} of{' '}
                                {orders.total} orders
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
