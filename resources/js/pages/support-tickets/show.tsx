import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { useState } from 'react';
import type { SupportTicketShowProps } from './support-tickets-types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Support Tickets',
        href: '/support-tickets',
    },
    {
        title: 'Ticket Details',
        href: '/support-tickets/show',
    },
];

const priorityColors: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    medium: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    high: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    urgent: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
};

const statusColors: Record<string, string> = {
    open: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    on_hold: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    resolved: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
    closed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
};

export default function SupportTicketsShow({ ticket, messages: initialMessages }: SupportTicketShowProps) {
    const [messages, setMessages] = useState(initialMessages);
    const [newMessage, setNewMessage] = useState('');
    const [isTyping, setIsTyping] = useState(false);

    const handleSendMessage = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newMessage.trim()) return;

        setIsTyping(true);

        try {
            const response = await fetch(`/support-tickets/${ticket.id}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    message: newMessage,
                    is_internal: false,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                if (data.ok && data.message) {
                    setMessages((prev) => [...prev, data.message]);
                    setNewMessage('');
                }
            }
        } catch (error) {
            console.error('Error sending message:', error);
        } finally {
            setIsTyping(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Ticket #${ticket.id} - ${ticket.title}`} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="relative min-h-[70vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <div className="flex h-full flex-col">
                        {/* Header */}
                        <div className="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-800">
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="flex items-center gap-3">
                                        <h1 className="text-2xl font-bold">{ticket.title}</h1>
                                        <span
                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                statusColors[ticket.status] || 'bg-gray-100 text-gray-800'
                                            }`}
                                        >
                                            {ticket.status.replace('_', ' ')}
                                        </span>
                                        <span
                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                priorityColors[ticket.priority] || 'bg-gray-100 text-gray-800'
                                            }`}
                                        >
                                            {ticket.priority}
                                        </span>
                                    </div>
                                    <div className="mt-2 flex items-center text-sm text-gray-500 dark:text-gray-400">
                                        <span>Ticket #{ticket.id}</span>
                                        <span className="mx-2">•</span>
                                        <span>Created {new Date(ticket.created_at).toLocaleDateString()}</span>
                                        {ticket.resolved_at && (
                                            <>
                                                <span className="mx-2">•</span>
                                                <span className="text-green-600 dark:text-green-400">
                                                    Resolved {new Date(ticket.resolved_at).toLocaleDateString()}
                                                </span>
                                            </>
                                        )}
                                        {ticket.closed_at && (
                                            <>
                                                <span className="mx-2">•</span>
                                                <span className="text-red-600 dark:text-red-400">
                                                    Closed {new Date(ticket.closed_at).toLocaleDateString()}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <div className="flex space-x-2">
                                    <Link
                                        href={`/support-tickets/${ticket.id}/edit`}
                                        className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    >
                                        Edit
                                    </Link>
                                </div>
                            </div>
                        </div>

                        {/* Content */}
                        <div className="flex-1 overflow-y-auto p-6">
                            <div className="mb-6 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-900">
                                <h3 className="mb-3 text-lg font-medium text-gray-900 dark:text-white">
                                    Description
                                </h3>
                                <div className="prose max-w-none text-gray-700 dark:text-gray-300">
                                    {ticket.description.split('\n').map((line, i) => (
                                        <p key={i} className="mb-2 last:mb-0">
                                            {line}
                                        </p>
                                    ))}
                                </div>
                            </div>

                            {/* Messages */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-medium text-gray-900 dark:text-white">
                                    Conversation
                                </h3>
                                {messages.length === 0 ? (
                                    <div className="rounded-lg border border-dashed border-gray-300 p-6 text-center dark:border-gray-700">
                                        <p className="text-gray-500 dark:text-gray-400">No messages yet.</p>
                                        <p className="text-sm text-gray-400">Be the first to respond.</p>
                                    </div>
                                ) : (
                                    messages.map((message) => (
                                        <div
                                            key={message.id}
                                            className={`rounded-lg border p-4 shadow-sm ${
                                                message.isOwnMessage
                                                    ? 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-900/20'
                                                    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2 mb-2">
                                                        <span className="font-medium text-gray-900 dark:text-white">
                                                            {message.user.name}
                                                        </span>
                                                        {message.is_internal && (
                                                            <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                                Internal
                                                            </span>
                                                        )}
                                                        <span className="text-xs text-gray-500 dark:text-gray-400">
                                                            {new Date(message.created_at).toLocaleString()}
                                                        </span>
                                                    </div>
                                                    <p className="text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
                                                        {message.message}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>

                        {/* Message Input */}
                        <div className="border-t border-gray-200 bg-gray-50 p-6 dark:border-gray-700 dark:bg-gray-800">
                            <form onSubmit={handleSendMessage} className="space-y-3">
                                <textarea
                                    value={newMessage}
                                    onChange={(e) => setNewMessage(e.target.value)}
                                    placeholder="Add a comment..."
                                    rows={3}
                                    className="block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                />
                                <div className="flex items-center justify-end">
                                    <button
                                        type="submit"
                                        disabled={!newMessage.trim() || isTyping}
                                        className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {isTyping ? 'Sending...' : 'Add Comment'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
