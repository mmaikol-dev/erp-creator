export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Hospital = {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    status: 'active' | 'inactive';
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};