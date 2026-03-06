import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

const breadcrumbs = [
    { title: 'Hospital Management', href: '/hospitals' },
    { title: 'Create Hospital', href: '/hospitals/create' },
];

type HospitalCreateProps = {
    errors?: Record<string, string[]>;
};

export default function HospitalCreate({ errors }: HospitalCreateProps) {
    const { data, setData, post, processing, reset } = useForm({
        name: '',
        address: '',
        phone: '',
        email: '',
        status: 'active',
    });

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hospitals', {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/hospitals');
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Hospital" />
            
            <div className="space-y-6">
                <div className="flex items-center gap-2">
                    <Link
                        href="/hospitals"
                        className="inline-flex items-center text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="mr-2 size-4" />
                        Back to Hospitals
                    </Link>
                </div>

                <div className="space-y-1">
                    <h2 className="text-2xl font-bold tracking-tight">Create Hospital</h2>
                    <p className="text-sm text-muted-foreground">
                        Add a new hospital to the system
                    </p>
                </div>

                {errors && Object.keys(errors).length > 0 && (
                    <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                        <ul className="list-inside list-disc">
                            {Object.entries(errors).map(([field, messages]) => (
                                <li key={field}>
                                    {field}: {messages.join(', ')}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Hospital Information</CardTitle>
                        <CardDescription>
                            Fill in the details for the new hospital
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={onSubmit} className="space-y-6">
                            <div className="grid gap-6 md:grid-cols-2">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                                        Hospital Name
                                    </label>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g. General Hospital"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                                        Status
                                    </label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(value) => setData('status', value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="active">Active</SelectItem>
                                            <SelectItem value="inactive">Inactive</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-6 md:grid-cols-2">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                                        Email
                                    </label>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="contact@hospital.com"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                                        Phone
                                    </label>
                                    <Input
                                        type="tel"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        placeholder="+1 (555) 000-0000"
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                                    Address
                                </label>
                                <Textarea
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    placeholder="Hospital address..."
                                    className="min-h-[80px]"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-4">
                                <Link href="/hospitals">
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Hospital'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
