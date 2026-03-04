import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Home',
        href: '/dashboard',
    },
    {
        title: 'Orders',
        href: '/orders',
    },
    {
        title: `Order #${order.id}`,
        href: `/orders/${order.id}`,
    },
];

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

export default function Show({ order }: any) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Order #${order.id}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Order #{order.id}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Created on {new Date(order.created_at).toLocaleString()}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={`/orders/${order.id}/edit`}>
                            <Button variant="outline">Edit</Button>
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Order Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">
                                        Customer Name
                                    </Label>
                                    <p className="font-medium">{order.customer_name}</p>
                                </div>
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">
                                        Customer Email
                                    </Label>
                                    <p className="font-medium">{order.customer_email}</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">
                                        Status
                                    </Label>
                                    <Badge className={statusColors[order.status]}>
                                        {order.status}
                                    </Badge>
                                </div>
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">
                                        Total Amount
                                    </Label>
                                    <p className="font-medium">${order.total_amount.toFixed(2)}</p>
                                </div>
                            </div>

                            {order.notes && (
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">
                                        Notes
                                    </Label>
                                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                        {order.notes}
                                    </p>
                                </div>
                            )}
                        </div>
                    </CardContent>
                    <CardFooter className="border-t px-6 py-4">
                        <Link href="/orders">
                            <Button variant="ghost">Back to Orders</Button>
                        </Link>
                    </CardFooter>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Order History</CardTitle>
                        <CardDescription>
                            Track the order's status and timeline
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="relative pl-6">
                            <div className="absolute left-0 top-0 h-full w-0.5 bg-border" />
                            
                            <div className="relative mb-4">
                                <div className="absolute -left-[21px] top-1 h-4 w-4 rounded-full border-2 bg-background ring-2 ring-primary" />
                                <div className="border-b pb-4">
                                    <h4 className="font-medium">Order Created</h4>
                                    <p className="text-sm text-muted-foreground">
                                        {new Date(order.created_at).toLocaleString()}
                                    </p>
                                </div>
                            </div>

                            {order.updated_at !== order.created_at && (
                                <div className="relative">
                                    <div className="absolute -left-[21px] top-1 h-4 w-4 rounded-full border-2 bg-background ring-2 ring-primary" />
                                    <div>
                                        <h4 className="font-medium">Order Updated</h4>
                                        <p className="text-sm text-muted-foreground">
                                            {new Date(order.updated_at).toLocaleString()}
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
