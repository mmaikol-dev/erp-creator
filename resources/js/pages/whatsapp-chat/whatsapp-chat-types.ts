export type Message = {
    id: number;
    conversation_id: number;
    user_id: number;
    body: string;
    type: 'text' | 'image' | 'audio' | 'video' | 'document';
    created_at: string;
    is_read: boolean;
    sender: {
        id: number;
        name: string;
        email: string;
        avatar?: string;
    };
    isOwnMessage: boolean;
};

export type Contact = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
};

export type ConversationSummary = {
    id: number;
    subject?: string | null;
    last_message?: string | null;
    last_message_at?: string | null;
    is_read: boolean;
    contact?: Contact;
};

export type WhatsAppChatProps = {
    conversations: ConversationSummary[];
    currentConversation?: {
        id: number;
        subject?: string | null;
        contact: Contact;
    } | null;
    messages: Message[];
};
