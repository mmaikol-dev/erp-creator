import { Head, Link, router } from '@inertiajs/react';
import { PenSquare, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Hospital } from '@/types';

const breadcrumbs = [
    { title: 'Hospital Management', href: '/hospitals' },
];

type HospitalPageProps = {
    hospitals: Hospital[];
    errors?: Record<string, string[]>;
    message?: string;
};

export default function HospitalIndex({ hospitals, errors, message }: HospitalPageProps) {
    const [searchTerm, setSearchTerm] = useState('');

    const handleDelete = (id: number) => {
        if (confirm('Are you sure you want to delete this hospital?')) {
            router.delete(`/hospitals/${id}`);
        }
    };

    const filteredHospitals = hospitals.filter(hospital =>
        hospital.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (hospital.email && hospital.email.toLowerCase().includes(searchTerm.toLowerCase())) ||
        (hospital.phone && hospital.phone.toLowerCase().includes(searchTerm.toLowerCase()))
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hospital Management" />
            
            <div className="space-y-6">
                {message && (
                    <div className="rounded-md bg-green-50 p-4 text-sm text-green-800 dark:bg-green-950/30 dark:text-green-300">
                        {message}
                    </div>
                )}

                {errors && Object.keys(errors).length > 0 && (
                    <AlertError
                        errors={Object.values(errors).flat()}
                        title="Error creating hospital"
                    />
                )}

                <div className="flex items-center justify-between">
                    <div className="space-y-1">
                        <h2 className="text-2xl font-bold tracking-tight">Hospitals</h2>
                        <p className="text-sm text-muted-foreground">
                            Manage hospitals in the system
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href="/hospitals/create">
                            <Button>
                                <Plus className="mr-2 size-4" />
                                Add Hospital
                            </Button>
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Hospital List</CardTitle>
                        <CardDescription>
                            View and manage all registered hospitals
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="mb-4">
                            <Input
                                type="search"
                                placeholder="Search hospitals..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="max-w-sm"
                            />
                        </div>
                        
                        {filteredHospitals.length === 0 ? (
                            <div className="text-center py-12">
                                <p className="text-sm text-muted-foreground">
                                    No hospitals found.{' '}
                                    <Link
                                        href="/hospitals/create"
                                        className="font-medium text-primary underline underline-offset-4"
                                    >
                                        Add a new hospital
                                    </Link>
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Email</TableHead>
                                            <TableHead>Phone</TableHead>
                                            <TableHead>Address</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredHospitals.map((hospital) => (
                                            <TableRow key={hospital.id}>
                                                <TableCell className="font-medium">
                                                    {hospital.name}
                                                </TableCell>
                                                <TableCell>
                                                    {hospital.email || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {hospital.phone || '-'}
                                                </TableCell>
                                                <TableCell className="max-w-[200px] truncate">
                                                    {hospital.address || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            hospital.status === 'active'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {hospital.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Link href={`/hospitals/${hospital.id}`}>
                                                            <Button variant="ghost" size="icon" className="h-8 w-8">
                                                                <PenSquare className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8 text-destructive"
                                                            onClick={() => handleDelete(hospital.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
