export interface SupportTicket {
    id: number;
    title: string;
    description: string;
    status: 'open' | 'in_progress' | 'on_hold' | 'resolved' | 'closed';
    priority: 'low' | 'medium' | 'high' | 'urgent';
    created_at: string;
    resolved_at?: string;
    closed_at?: string;
    user: {
        id: number;
        name: string;
        email: string;
    };
}

export interface SupportTicketMessage {
    id: number;
    ticket_id: number;
    user_id: number;
    message: string;
    is_internal: boolean;
    created_at: string;
    user: {
        id: number;
        name: string;
        email: string;
    };
    isOwnMessage: boolean;
}

export interface SupportTicketShowProps {
    ticket: SupportTicket;
    messages: SupportTicketMessage[];
}

export interface SupportTicketEditProps {
    ticket: {
        id: number;
        title: string;
        description: string;
        status: string;
        priority: string;
    };
}
