<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWhatsAppConversationRequest;
use App\Http\Requests\StoreWhatsAppMessageRequest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppChatController extends Controller
{
    /**
     * Display the WhatsApp chat page with conversations list.
     */
    public function __invoke(Request $request): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        $conversations = [];
        $currentConversation = null;
        $messages = [];

        if ($user !== null) {
            $conversations = WhatsAppConversation::where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('contact_id', $user->id);
            })
            ->with(['contact', 'user'])
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(function ($conversation) use ($user) {
                $lastMessageAt = $conversation->last_message_at;
                return [
                    'id' => $conversation->id,
                    'subject' => $conversation->subject,
                    'last_message' => $conversation->last_message,
                    'last_message_at' => $lastMessageAt ? $lastMessageAt->format('Y-m-d H:i:s') : null,
                    'is_read' => true,
                    'contact' => $conversation->user_id === $user->id
                        ? [
                            'id' => $conversation->contact->id,
                            'name' => $conversation->contact->name,
                            'email' => $conversation->contact->email,
                            'avatar' => $conversation->contact->email
                                ? 'https://ui-avatars.com/api/?name='.urlencode($conversation->contact->name).'&background=random'
                                : null,
                        ]
                        : [
                            'id' => $conversation->user->id,
                            'name' => $conversation->user->name,
                            'email' => $conversation->user->email,
                            'avatar' => $conversation->user->email
                                ? 'https://ui-avatars.com/api/?name='.urlencode($conversation->user->name).'&background=random'
                                : null,
                        ],
                ];
            })
            ->toArray();

            $conversationId = $request->input('conversation_id');
            if ($conversationId) {
                $currentConversation = WhatsAppConversation::with(['contact', 'user'])
                    ->where('id', $conversationId)
                    ->first();

                if ($currentConversation && ($currentConversation->user_id === $user->id || $currentConversation->contact_id === $user->id)) {
                    $messages = WhatsAppMessage::where('conversation_id', $conversationId)
                        ->with('user')
                        ->orderBy('id')
                        ->get()
                        ->map(function ($message) use ($user) {
                            return [
                                'id' => $message->id,
                                'conversation_id' => $message->conversation_id,
                                'user_id' => $message->user_id,
                                'body' => $message->body,
                                'type' => $message->type,
                                'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                                'is_read' => $message->is_read,
                                'sender' => [
                                    'id' => $message->user->id,
                                    'name' => $message->user->name,
                                    'email' => $message->user->email,
                                    'avatar' => $message->user->email
                                        ? 'https://ui-avatars.com/api/?name='.urlencode($message->user->name).'&background=random'
                                        : null,
                                ],
                                'isOwnMessage' => $message->user_id === $user->id,
                            ];
                        })
                        ->toArray();

                    // Mark messages as read
                    WhatsAppMessage::where('conversation_id', $conversationId)
                        ->where('user_id', '!=', $user->id)
                        ->where('is_read', false)
                        ->update(['is_read' => true]);
                }
            }
        }

        $currentConversationData = null;
        if ($currentConversation && $user) {
            $currentConversationData = [
                'id' => $currentConversation->id,
                'subject' => $currentConversation->subject,
                'contact' => $currentConversation->user_id === $user->id
                    ? [
                        'id' => $currentConversation->contact->id,
                        'name' => $currentConversation->contact->name,
                        'email' => $currentConversation->contact->email,
                        'avatar' => $currentConversation->contact->email
                            ? 'https://ui-avatars.com/api/?name='.urlencode($currentConversation->contact->name).'&background=random'
                            : null,
                    ]
                    : [
                        'id' => $currentConversation->user->id,
                        'name' => $currentConversation->user->name,
                        'email' => $currentConversation->user->email,
                        'avatar' => $currentConversation->user->email
                            ? 'https://ui-avatars.com/api/?name='.urlencode($currentConversation->user->name).'&background=random'
                            : null,
                    ],
            ];
        }

        return Inertia::render('whatsapp-chat/index', [
            'conversations' => $conversations,
            'currentConversation' => $currentConversationData,
            'messages' => $messages,
        ]);
    }

    /**
     * Store a newly created conversation.
     */
    public function store(StoreWhatsAppConversationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $conversation = WhatsAppConversation::firstOrCreate(
            [
                'user_id' => $user->id,
                'contact_id' => $request->input('contact_id'),
            ],
            [
                'subject' => $request->input('subject'),
            ]
        );

        return response()->json([
            'ok' => true,
            'conversation' => [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
            ],
        ]);
    }

    /**
     * Store a message in a conversation.
     */
    public function storeMessage(StoreWhatsAppMessageRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $conversation = WhatsAppConversation::find($request->input('conversation_id'));

        if (!$conversation || ($conversation->user_id !== $user->id && $conversation->contact_id !== $user->id)) {
            return response()->json([
                'ok' => false,
                'error' => 'Conversation not found or unauthorized',
            ], 404);
        }

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => $request->input('body'),
            'type' => $request->input('type', 'text'),
            'meta' => $request->input('meta'),
            'is_read' => false,
        ]);

        // Update conversation's last message
        $conversation->update([
            'last_message' => $message->body,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'user_id' => $message->user_id,
                'body' => $message->body,
                'type' => $message->type,
                'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                'sender' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->email
                        ? 'https://ui-avatars.com/api/?name='.urlencode($user->name).'&background=random'
                        : null,
                ],
                'isOwnMessage' => true,
            ],
        ]);
    }

    /**
     * Get messages for a specific conversation.
     */
    public function showMessages(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($conversation->user_id !== $user->id && $conversation->contact_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Conversation not found or unauthorized',
            ], 404);
        }

        $messages = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(function ($message) use ($user) {
                return [
                    'id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'user_id' => $message->user_id,
                    'body' => $message->body,
                    'type' => $message->type,
                    'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                    'is_read' => $message->is_read,
                    'sender' => [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'email' => $message->user->email,
                        'avatar' => $message->user->email
                            ? 'https://ui-avatars.com/api/?name='.urlencode($message->user->name).'&background=random'
                            : null,
                    ],
                    'isOwnMessage' => $message->user_id === $user->id,
                ];
            })
            ->toArray();

        // Mark messages as read
        WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'messages' => $messages,
        ]);
    }
}
