import { Check, CheckCheck, Clock } from 'lucide-react';
import type { Message } from './whatsapp-chat-types';

interface MessageBubbleProps {
    message: Message;
    showTimestamp?: boolean;
}

export function MessageBubble({ message, showTimestamp = true }: MessageBubbleProps) {
    const isOwnMessage = message.isOwnMessage;

    return (
        <div
            className={`flex w-full ${
                isOwnMessage
                    ? 'justify-end'
                    : 'justify-start'
            } mb-2`}
        >
            <div
                className={`max-w-[75%] rounded-lg px-3 py-2 text-sm shadow-sm ${
                    isOwnMessage
                        ? 'bg-[#007a5d] text-white rounded-tr-none'
                        : 'bg-white text-black rounded-tl-none border border-gray-200'
                }`}
            >
                <p className="whitespace-pre-wrap leading-relaxed">
                    {message.body}
                </p>
                <div
                    className={`flex items-center justify-end gap-1 mt-1 text-[10px] ${
                        isOwnMessage ? 'text-white/70' : 'text-gray-500'
                    }`}
                >
                    <span>
                        {new Date(message.created_at).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </span>
                    {isOwnMessage && (
                        <span>
                            {message.is_read ? (
                                <CheckCheck className="w-3 h-3" />
                            ) : (
                                <Check className="w-3 h-3" />
                            )}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}
