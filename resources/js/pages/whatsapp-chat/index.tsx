import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { MessageBubble } from './components/message-bubble';
import { ConversationItem } from './components/conversation-item';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import type { ConversationSummary, Message, WhatsAppChatProps } from './whatsapp-chat-types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'WhatsApp Chat',
        href: '/whatsapp-chat',
    },
];

export default function WhatsAppChat({
    conversations: initialConversations,
    currentConversation: initialCurrentConversation,
    messages: initialMessages,
}: WhatsAppChatProps) {
    const [conversations, setConversations] =
        useState<ConversationSummary[]>(initialConversations);
    const [currentConversation, setCurrentConversation] = useState<
        { id: number; subject?: string | null; contact: { id: number; name: string; email: string; avatar?: string } } | null
    >(initialCurrentConversation);
    const [messages, setMessages] = useState<Message[]>(initialMessages);
    const [inputMessage, setInputMessage] = useState('');
    const [isTyping, setIsTyping] = useState(false);

    const selectedConversationId = currentConversation?.id;

    const currentContact = useMemo(() => {
        if (!currentConversation) return null;
        return currentConversation.contact;
    }, [currentConversation]);

    const handleSelectConversation = async (conversation: ConversationSummary) => {
        if (conversation.id === selectedConversationId) return;

        setCurrentConversation({
            id: conversation.id,
            subject: conversation.subject || undefined,
            contact: conversation.contact!,
        });
        setMessages(
            initialMessages.filter((m) => m.conversation_id === conversation.id),
        );
    };

    const handleSendMessage = async () => {
        const body = inputMessage.trim();
        if (!body || !selectedConversationId) return;

        const newMessage: Message = {
            id: Date.now(),
            conversation_id: selectedConversationId,
            user_id: 1, // This should come from actual user context
            body,
            type: 'text',
            created_at: new Date().toISOString(),
            is_read: false,
            sender: {
                id: 1,
                name: 'Current User',
                email: 'user@example.com',
                avatar: 'https://ui-avatars.com/api/?name=Current+User&background=random',
            },
            isOwnMessage: true,
        };

        setMessages((prev) => [...prev, newMessage]);
        setInputMessage('');
        setIsTyping(true);

        try {
            const response = await fetch('/whatsapp-chat/messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    conversation_id: selectedConversationId,
                    body,
                    type: 'text',
                }),
            });

            if (response.ok) {
                const data = await response.json();
                if (data.ok && data.message) {
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === newMessage.id ? { ...m, ...data.message } : m
                        ),
                    );
                    setConversations((prev) =>
                        prev.map((c) =>
                            c.id === selectedConversationId
                                ? {
                                      ...c,
                                      last_message: body,
                                      last_message_at: new Date().toISOString(),
                                  }
                                : c
                        ),
                    );
                }
            }
        } catch (error) {
            console.error('Error sending message:', error);
        } finally {
            setIsTyping(false);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void handleSendMessage();
        }
    };

    if (conversations.length === 0 && !currentConversation) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="WhatsApp Chat" />
                <div className="h-full flex flex-col items-center justify-center bg-gray-50">
                    <div className="text-center max-w-md px-4">
                        <h2 className="text-3xl font-bold text-gray-900 mb-4">
                            WhatsApp Chat
                        </h2>
                        <p className="text-gray-600 mb-6">
                            Start messaging with your contacts right away. Select a contact from the list to begin a conversation.
                        </p>
                        <div className="text-gray-500 text-sm">
                            No conversations yet. Start by selecting a contact from the sidebar.
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="WhatsApp Chat" />
            <div className="h-full flex flex-col">
                <div className="flex h-full overflow-hidden">
                    {/* Sidebar - Conversations List */}
                    <div className="w-full md:w-80 lg:w-96 border-r border-gray-200 bg-white flex flex-col">
                        {/* Header */}
                        <div className="p-4 border-b border-gray-200 flex items-center justify-between bg-[#f0f2f5]">
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-full overflow-hidden">
                                    <img
                                        src="https://ui-avatars.com/api/?name=Current+User&background=random"
                                        alt="Profile"
                                        className="w-full h-full object-cover"
                                    />
                                </div>
                                <div>
                                    <h2 className="font-semibold text-gray-900">WhatsApp Chat</h2>
                                    <p className="text-xs text-gray-500">All messages</p>
                                </div>
                            </div>
                        </div>

                        {/* Search */}
                        <div className="p-2 border-b border-gray-200">
                            <div className="relative">
                                <input
                                    type="text"
                                    placeholder="Search or start new chat"
                                    className="w-full pl-9 pr-4 py-2 bg-gray-100 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-[#007a5d]"
                                />
                                <div className="absolute left-3 top-2.5 text-gray-500">
                                    <svg
                                        className="w-5 h-5"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                        />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {/* Conversations List */}
                        <div className="flex-1 overflow-y-auto">
                            {conversations.map((conversation) => (
                                <ConversationItem
                                    key={conversation.id}
                                    conversation={conversation}
                                    isActive={conversation.id === selectedConversationId}
                                    onClick={() => handleSelectConversation(conversation)}
                                />
                            ))}
                        </div>
                    </div>

                    {/* Chat Area */}
                    {currentConversation ? (
                        <div className="flex-1 flex flex-col bg-[#efeae2] overflow-hidden">
                            {/* Chat Header */}
                            <div className="p-3 bg-[#f0f2f5] border-b border-gray-200 flex items-center gap-3">
                                <div className="w-10 h-10 rounded-full overflow-hidden cursor-pointer">
                                    {currentContact?.avatar ? (
                                        <img
                                            src={currentContact.avatar}
                                            alt={currentContact.name}
                                            className="w-full h-full object-cover"
                                        />
                                    ) : (
                                        <div className="w-full h-full bg-gray-300 flex items-center justify-center">
                                            <span className="text-gray-600 font-medium">
                                                {currentContact?.name.charAt(0).toUpperCase()}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <div className="flex-1">
                                    <h3 className="font-medium text-gray-900">
                                        {currentContact?.name}
                                    </h3>
                                    <p className="text-xs text-gray-500">
                                        {isTyping ? 'typing...' : 'online'}
                                    </p>
                                </div>
                                <button className="p-2 text-gray-600 hover:bg-gray-200 rounded-full">
                                    <svg
                                        className="w-6 h-6"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"
                                        />
                                    </svg>
                                </button>
                            </div>

                            {/* Messages Area */}
                            <div className="flex-1 overflow-y-auto p-4 space-y-2">
                                <div className="text-center text-xs text-gray-500 my-4">
                                    <span className="bg-gray-300 px-2 py-1 rounded-lg">
                                        Today
                                    </span>
                                </div>
                                {messages.map((message) => (
                                    <MessageBubble
                                        key={message.id}
                                        message={message}
                                    />
                                ))}
                            </div>

                            {/* Input Area */}
                            <div className="p-3 bg-[#f0f2f5] border-t border-gray-200">
                                <div className="flex items-center gap-2">
                                    <button className="p-2 text-gray-600 hover:bg-gray-200 rounded-full">
                                        <svg
                                            className="w-6 h-6"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={2}
                                                d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                            />
                                        </svg>
                                    </button>
                                    <div className="flex-1 relative">
                                        <textarea
                                            value={inputMessage}
                                            onChange={(e) => setInputMessage(e.target.value)}
                                            onKeyDown={handleKeyDown}
                                            placeholder="Type a message"
                                            rows={1}
                                            className="w-full pl-4 pr-12 py-2 bg-white rounded-lg resize-none focus:outline-none focus:ring-1 focus:ring-[#007a5d] shadow-sm max-h-32"
                                            style={{ minHeight: '40px' }}
                                        />
                                        <div className="absolute right-2 bottom-2 flex items-center gap-1">
                                            <button className="p-1 text-gray-500 hover:text-gray-700">
                                                <svg
                                                    className="w-5 h-5"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        strokeWidth={2}
                                                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        onClick={handleSendMessage}
                                        disabled={!inputMessage.trim()}
                                        className={`p-2 rounded-full ${
                                            inputMessage.trim()
                                                ? 'bg-[#007a5d] text-white hover:bg-[#00624d]'
                                                : 'text-gray-500 hover:bg-gray-200'
                                        }`}
                                    >
                                        <svg
                                            className="w-6 h-6"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={2}
                                                d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="flex-1 flex flex-col items-center justify-center bg-[#f0f2f5]">
                            <div className="text-center max-w-md px-4">
                                <svg
                                    className="w-48 h-48 text-gray-300 mx-auto mb-4"
                                    fill="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path d="M12 2C6.48 2 2 6.48 2 12c0 1.74.49 3.35 1.32 4.76L2 22l4.24-1.32C8.35 21.51 9.96 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.66 0-3.17-.51-4.42-1.35l-.58-.32-.05.18-3.17.99.99-3.12.19-.12C5.59 17.13 5 15.66 5 14c0-4.42 3.58-8 8-8s8 3.58 8 8-3.58 8-8 8z"/>
                                    <path d="M12 14c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
                                </svg>
                                <h3 className="text-xl font-medium text-gray-900 mb-2">
                                    Select a conversation
                                </h3>
                                <p className="text-gray-500">
                                    Choose a contact from the sidebar to start messaging
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
