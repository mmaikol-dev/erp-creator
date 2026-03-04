<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiAssistantChatRequest;
use App\Models\AiConversation;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\ConversationMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantController extends Controller
{
    /**
     * Display the AI assistant page.
     */
    public function __invoke(Request $request): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        $conversation = null;
        $messages = [];
        $conversations = [];

        if ($user !== null) {
            /** @var ConversationMemoryService $memory */
            $memory = app(ConversationMemoryService::class);
            $conversation = $memory->getOrCreateConversation(
                $user,
                $request->integer('conversation_id') ?: null
            );
            $messages = $memory->recentMessages($conversation);
            $conversations = $memory->listConversations($user, 30);
        }

        return Inertia::render('ai-assistant', [
            'user' => $user,
            'conversationId' => $conversation?->id,
            'messages' => $messages,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Create a new empty conversation for the authenticated user.
     */
    public function newConversation(
        Request $request,
        ConversationMemoryService $memory,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $conversation = $memory->createConversation($user);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'messages' => [],
            'conversation' => $memory->summarizeConversation($conversation),
        ]);
    }

    /**
     * Load a conversation and its recent messages for the authenticated user.
     */
    public function showConversation(
        Request $request,
        AiConversation $conversation,
        ConversationMemoryService $memory,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();

        abort_unless($conversation->user_id === $user->id, 404);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'messages' => $memory->recentMessages($conversation),
            'conversation' => $memory->summarizeConversation($conversation),
        ]);
    }

    /**
     * Handle an AI chat request.
     */
    public function chat(
        AiAssistantChatRequest $request,
        AiAssistantService $assistant,
        ConversationMemoryService $memory,
    ): JsonResponse {
        $startedAt = microtime(true);
        /** @var \App\Models\User $user */
        $user = $request->user();
        $conversation = $memory->getOrCreateConversation(
            $user,
            $request->integer('conversation_id') ?: null
        );
        $history = $memory->chatHistory($conversation, 20);
        $memorySnippets = $memory->relevantMemories(
            $conversation,
            (string) $request->string('message'),
            4
        );

        Log::info('ai-assistant.chat.request', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_length' => mb_strlen((string) $request->input('message', '')),
            'history_count' => count($history),
            'memory_snippets_count' => count($memorySnippets),
            'mode' => (string) $request->input('mode', 'auto'),
        ]);

        try {
            $message = (string) $request->string('message');
            $result = $assistant->respond(
                message: $message,
                history: $history,
                mode: (string) $request->input('mode', 'auto'),
                memorySnippets: $memorySnippets,
            );

            $mode = (string) $request->input('mode', 'auto');
            $memory->storeMessage(
                conversation: $conversation,
                role: 'user',
                content: $message,
                mode: $mode,
            );

            if (isset($result['plan']) && is_string($result['plan']) && trim($result['plan']) !== '') {
                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'assistant',
                    content: $result['plan'],
                    model: is_string($result['plan_model'] ?? null) ? $result['plan_model'] : null,
                    mode: $mode,
                    stage: 'plan',
                );
            }

            $memory->storeMessage(
                conversation: $conversation,
                role: 'assistant',
                content: (string) $result['reply'],
                model: is_string($result['model'] ?? null) ? $result['model'] : null,
                mode: $mode,
                stage: 'execution',
                meta: [
                    'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                ],
            );

            Log::info('ai-assistant.chat.success', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'model' => $result['model'] ?? null,
                'intent' => $result['intent'] ?? null,
                'fallback_used' => $result['fallback_used'] ?? false,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'ok' => true,
                'conversation_id' => $conversation->id,
                ...$result,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('ai-assistant.chat.failed', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $message = 'The assistant is temporarily unavailable. Please try again.';

            if (config('app.debug')) {
                $message = $exception->getMessage();
            }

            return response()->json([
                'ok' => false,
                'error' => $message,
            ], 500);
        }
    }

    /**
     * Stream an AI chat response for fast mode.
     */
    public function chatStream(
        AiAssistantChatRequest $request,
        AiAssistantService $assistant,
        ConversationMemoryService $memory,
    ): StreamedResponse {
        $startedAt = microtime(true);
        /** @var \App\Models\User $user */
        $user = $request->user();
        $conversation = $memory->getOrCreateConversation(
            $user,
            $request->integer('conversation_id') ?: null
        );
        $history = $memory->chatHistory($conversation, 20);
        $message = (string) $request->string('message');
        $mode = (string) $request->input('mode', 'auto');
        $memorySnippets = $memory->relevantMemories($conversation, $message, 4);

        Log::info('ai-assistant.chat.request', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_length' => mb_strlen($message),
            'history_count' => count($history),
            'memory_snippets_count' => count($memorySnippets),
            'mode' => $mode,
            'stream' => true,
        ]);

        return response()->stream(function () use (
            $assistant,
            $conversation,
            $history,
            $memory,
            $memorySnippets,
            $message,
            $mode,
            $startedAt,
            $user
        ): void {
            try {
                @set_time_limit(0);
                echo json_encode([
                    'type' => 'heartbeat',
                    'status' => 'starting',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                @ob_flush();
                flush();

                $result = $assistant->streamRespond(
                    message: $message,
                    history: $history,
                    mode: $mode,
                    memorySnippets: $memorySnippets,
                    onChunk: function (string $delta): void {
                        echo json_encode([
                            'type' => 'chunk',
                            'content' => $delta,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                        @ob_flush();
                        flush();
                    },
                    onStatus: function (string $status): void {
                        echo json_encode([
                            'type' => 'heartbeat',
                            'status' => $status,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                        @ob_flush();
                        flush();
                    },
                    onToolActivity: function (array $activity): void {
                        echo json_encode([
                            'type' => 'tool_activity',
                            ...$activity,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                        @ob_flush();
                        flush();
                    },
                );

                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'user',
                    content: $message,
                    mode: $mode,
                );

                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'assistant',
                    content: (string) $result['reply'],
                    model: is_string($result['model'] ?? null) ? $result['model'] : null,
                    mode: $mode,
                    stage: 'execution',
                    meta: [
                        'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                    ],
                );

                Log::info('ai-assistant.chat.success', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'model' => $result['model'] ?? null,
                    'intent' => $result['intent'] ?? null,
                    'fallback_used' => $result['fallback_used'] ?? false,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'stream' => true,
                ]);

                echo json_encode([
                    'type' => 'done',
                    'conversation_id' => $conversation->id,
                    'model' => $result['model'] ?? null,
                    'fallback_used' => $result['fallback_used'] ?? false,
                    'warnings' => $result['warnings'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                @ob_flush();
                flush();
            } catch (\Throwable $exception) {
                report($exception);
                Log::error('ai-assistant.chat.failed', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'error' => $exception->getMessage(),
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'stream' => true,
                ]);

                $messageText = config('app.debug')
                    ? $exception->getMessage()
                    : 'The assistant is temporarily unavailable. Please try again.';

                echo json_encode([
                    'type' => 'error',
                    'error' => $messageText,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                @ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
