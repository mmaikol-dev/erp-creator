import type { ConversationSummary } from '../whatsapp-chat-types';

interface ConversationItemProps {
    conversation: ConversationSummary;
    isActive: boolean;
    onClick: () => void;
}

export function ConversationItem({ conversation, isActive, onClick }: ConversationItemProps) {
    return (
        <div
            onClick={onClick}
            className={`flex items-center gap-3 p-3 cursor-pointer transition-colors ${
                isActive
                    ? 'bg-[#f0f2f5]'
                    : 'hover:bg-[#f5f6f6]'
            }`}
        >
            <div className="relative">
                <div className="w-12 h-12 rounded-full overflow-hidden">
                    {conversation.contact?.avatar ? (
                        <img
                            src={conversation.contact.avatar}
                            alt={conversation.contact?.name}
                            className="w-full h-full object-cover"
                        />
                    ) : (
                        <div className="w-full h-full bg-gray-300 flex items-center justify-center">
                            <span className="text-gray-600 font-medium">
                                {conversation.contact?.name?.charAt(0).toUpperCase() || 'U'}
                            </span>
                        </div>
                    )}
                </div>
                {conversation.last_message_at && (
                    <div className="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between mb-1">
                    <h3 className="font-medium text-gray-900 truncate">
                        {conversation.contact?.name || 'Unknown User'}
                    </h3>
                    {conversation.last_message_at && (
                        <span className="text-xs text-gray-500">
                            {new Date(conversation.last_message_at).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </span>
                    )}
                </div>
                <div className="flex items-center justify-between">
                    <p className="text-sm text-gray-600 truncate pr-2">
                        {conversation.last_message || 'No messages yet'}
                    </p>
                    {!conversation.is_read && (
                        <div className="w-2 h-2 bg-[#007a5d] rounded-full"></div>
                    )}
                </div>
            </div>
        </div>
    );
}
