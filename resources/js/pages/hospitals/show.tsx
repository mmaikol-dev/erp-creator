import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Calendar, Mail, MapPin, Phone } from 'lucide-react';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface Hospital {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    status: 'active' | 'inactive';
    created_at: string;
    updated_at: string;
}

interface HospitalShowProps {
    hospital: Hospital;
}

export default function HospitalShow({ hospital }: HospitalShowProps) {
    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Hospital Management', href: '/hospitals' },
            { title: hospital.name, href: `/hospitals/${hospital.id}` },
        ]}>
            <Head title={hospital.name} />
            
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Link
                            href="/hospitals"
                            className="inline-flex items-center text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="mr-2 size-4" />
                            Back to Hospitals
                        </Link>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={`/hospitals/${hospital.id}/edit`}>
                            <Button variant="outline">
                                Edit Hospital
                            </Button>
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader className="border-b bg-muted/30">
                        <div className="flex items-start justify-between">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-2xl font-bold tracking-tight">{hospital.name}</h2>
                                    <Badge
                                        variant={hospital.status === 'active' ? 'default' : 'secondary'}
                                    >
                                        {hospital.status}
                                    </Badge>
                                </div>
                                <CardDescription>
                                    Hospital ID: #{hospital.id}
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-6">
                        <div className="grid gap-8 md:grid-cols-2">
                            <div className="space-y-6">
                                <section>
                                    <h3 className="mb-4 text-lg font-semibold">Basic Information</h3>
                                    <div className="space-y-4">
                                        <div className="flex items-start gap-3">
                                            <MapPin className="mt-1 size-5 text-muted-foreground" />
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium">Address</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {hospital.address || 'No address available'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-start gap-3">
                                            <Phone className="mt-1 size-5 text-muted-foreground" />
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium">Phone</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {hospital.phone || 'No phone available'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-start gap-3">
                                            <Mail className="mt-1 size-5 text-muted-foreground" />
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium">Email</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {hospital.email || 'No email available'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <div className="space-y-6">
                                <section>
                                    <h3 className="mb-4 text-lg font-semibold">System Information</h3>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="space-y-3">
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Created</span>
                                                <span className="font-medium">{formatDate(hospital.created_at)}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Last Updated</span>
                                                <span className="font-medium">{formatDate(hospital.updated_at)}</span>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
