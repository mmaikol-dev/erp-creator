<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiAssistantChatRequest;
use App\Models\AiConversation;
use App\Models\AiTaskRun;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\ConversationMemoryService;
use App\Services\AiAssistant\TaskRunService;
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

        $taskRunPayload = null;
        if ($conversation !== null && $user !== null) {
            /** @var TaskRunService $taskRuns */
            $taskRuns = app(TaskRunService::class);
            $taskRun = AiTaskRun::query()
                ->where('ai_conversation_id', $conversation->id)
                ->latest('id')
                ->first();

            if ($taskRun !== null) {
                $taskRunPayload = $taskRuns->serializeRun($taskRun);
            }
        }

        return Inertia::render('ai-assistant', [
            'user' => $user,
            'conversationId' => $conversation?->id,
            'messages' => $messages,
            'conversations' => $conversations,
            'taskRun' => $taskRunPayload,
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
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();

        abort_unless($conversation->user_id === $user->id, 404);

        $taskRun = AiTaskRun::query()
            ->where('ai_conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'messages' => $memory->recentMessages($conversation),
            'conversation' => $memory->summarizeConversation($conversation),
            'task_run' => $taskRun ? $taskRuns->serializeRun($taskRun) : null,
        ]);
    }

    public function createTaskRun(
        Request $request,
        ConversationMemoryService $memory,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $validated = $request->validate([
            'goal' => ['required', 'string', 'max:1200'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = $memory->getOrCreateConversation(
            $user,
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null
        );

        $taskRun = $taskRuns->createRun(
            user: $user,
            conversation: $conversation,
            goal: (string) $validated['goal'],
        );

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'task_run' => $taskRuns->serializeRun($taskRun),
        ]);
    }

    public function showTaskRun(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        return response()->json([
            'ok' => true,
            'task_run' => $taskRuns->serializeRun($taskRun),
        ]);
    }

    public function runNextTaskStep(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $result = $taskRuns->runNextStep($user, $taskRun);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    public function approveTaskRunStep(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $result = $taskRuns->approveCurrentStep($user, $taskRun);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    public function retryTaskRunStep(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $result = $taskRuns->retryCurrentStep($user, $taskRun);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    public function skipTaskRunStep(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $result = $taskRuns->skipCurrentStep($user, $taskRun);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    public function pauseTaskRun(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $updated = $taskRuns->pause($user, $taskRun);

        return response()->json([
            'ok' => true,
            'task_run' => $taskRuns->serializeRun($updated),
        ]);
    }

    public function resumeTaskRun(
        Request $request,
        AiTaskRun $taskRun,
        TaskRunService $taskRuns,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $taskRun->loadMissing('conversation');

        abort_unless($taskRun->conversation?->user_id === $user->id, 404);

        $updated = $taskRuns->resume($user, $taskRun);

        return response()->json([
            'ok' => true,
            'task_run' => $taskRuns->serializeRun($updated),
        ]);
    }

    /**
     * Handle an AI chat request.
     */
    public function chat(
        AiAssistantChatRequest $request,
        AiAssistantService $assistant,
        ConversationMemoryService $memory,
        TaskRunService $taskRuns,
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
            'mode' => (string) $request->input('mode', 'deep'),
        ]);

        try {
            $message = (string) $request->string('message');
            $mode = (string) $request->input('mode', 'deep');
            $activeRun = AiTaskRun::query()
                ->where('ai_conversation_id', $conversation->id)
                ->latest('id')
                ->first();

            if ($activeRun !== null && $taskRuns->shouldTreatAsContinue($message)) {
                $continued = $taskRuns->continueRun($user, $activeRun);
                $reply = $this->formatTaskRunContinueReply(
                    (string) ($continued['action'] ?? 'next'),
                    is_array($continued['result'] ?? null) ? $continued['result'] : []
                );
                $runPayload = is_array(($continued['result'] ?? null))
                    ? ($continued['result']['run'] ?? null)
                    : null;

                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'user',
                    content: $message,
                    mode: $mode,
                    stage: 'task_run_continue',
                );
                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'assistant',
                    content: $reply,
                    model: 'system:task-run-router',
                    mode: $mode,
                    stage: 'task_run_continue',
                );

                Log::info('ai-assistant.chat.success', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'model' => 'system:task-run-router',
                    'intent' => 'task-run-continue',
                    'fallback_used' => false,
                    'task_run_action' => $continued['action'] ?? null,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                return response()->json([
                    'ok' => true,
                    'conversation_id' => $conversation->id,
                    'reply' => $reply,
                    'model' => 'system:task-run-router',
                    'intent' => 'task_run_continue',
                    'fallback_used' => false,
                    'warnings' => [],
                    'task_run' => $runPayload,
                ]);
            }

            if ($taskRuns->shouldAutoOrchestrate($mode, $message)) {
                $auto = $taskRuns->runAutonomously($user, $conversation, $message);

                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'user',
                    content: $message,
                    mode: $mode,
                    stage: 'task_run_goal',
                );
                $memory->storeMessage(
                    conversation: $conversation,
                    role: 'assistant',
                    content: (string) $auto['reply'],
                    model: (string) ($auto['model'] ?? 'system:autonomous-task-runner'),
                    mode: $mode,
                    stage: 'task_run_summary',
                );

                Log::info('ai-assistant.chat.success', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'model' => $auto['model'] ?? null,
                    'intent' => 'deep-autonomous',
                    'fallback_used' => false,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                return response()->json([
                    'ok' => true,
                    'conversation_id' => $conversation->id,
                    'reply' => (string) $auto['reply'],
                    'model' => (string) ($auto['model'] ?? 'system:autonomous-task-runner'),
                    'intent' => 'deep',
                    'fallback_used' => false,
                    'warnings' => is_array($auto['warnings'] ?? null) ? $auto['warnings'] : [],
                    'task_run' => $auto['run'] ?? null,
                ]);
            }

            $result = $assistant->respond(
                message: $message,
                history: $history,
                mode: $mode,
                memorySnippets: $memorySnippets,
            );

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
                meta: array_filter([
                    'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                    ...((is_array($result['meta'] ?? null) ? $result['meta'] : [])),
                ], static fn ($value): bool => $value !== null),
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
     * Stream an AI chat response.
     */
    public function chatStream(
        AiAssistantChatRequest $request,
        AiAssistantService $assistant,
        ConversationMemoryService $memory,
        TaskRunService $taskRuns,
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
        $mode = (string) $request->input('mode', 'deep');
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
            $user,
            $taskRuns
        ): void {
            try {
                @set_time_limit(0);
                echo json_encode([
                    'type' => 'heartbeat',
                    'status' => 'starting',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                @ob_flush();
                flush();

                $activeRun = AiTaskRun::query()
                    ->where('ai_conversation_id', $conversation->id)
                    ->latest('id')
                    ->first();

                if ($activeRun !== null && $taskRuns->shouldTreatAsContinue($message)) {
                    $continued = $taskRuns->continueRun($user, $activeRun);
                    $reply = $this->formatTaskRunContinueReply(
                        (string) ($continued['action'] ?? 'next'),
                        is_array($continued['result'] ?? null) ? $continued['result'] : []
                    );
                    $runPayload = is_array(($continued['result'] ?? null))
                        ? ($continued['result']['run'] ?? null)
                        : null;

                    $memory->storeMessage(
                        conversation: $conversation,
                        role: 'user',
                        content: $message,
                        mode: $mode,
                        stage: 'task_run_continue',
                    );
                    $memory->storeMessage(
                        conversation: $conversation,
                        role: 'assistant',
                        content: $reply,
                        model: 'system:task-run-router',
                        mode: $mode,
                        stage: 'task_run_continue',
                    );

                    echo json_encode([
                        'type' => 'chunk',
                        'content' => $reply,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @ob_flush();
                    flush();

                    Log::info('ai-assistant.chat.success', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversation->id,
                        'model' => 'system:task-run-router',
                        'intent' => 'task-run-continue',
                        'fallback_used' => false,
                        'task_run_action' => $continued['action'] ?? null,
                        'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                        'stream' => true,
                    ]);

                    echo json_encode([
                        'type' => 'done',
                        'conversation_id' => $conversation->id,
                        'model' => 'system:task-run-router',
                        'fallback_used' => false,
                        'warnings' => [],
                        'task_run' => $runPayload,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @ob_flush();
                    flush();

                    return;
                }

                if ($taskRuns->shouldAutoOrchestrate($mode, $message)) {
                    echo json_encode([
                        'type' => 'heartbeat',
                        'status' => 'autonomous_planning',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @ob_flush();
                    flush();

                    $auto = $taskRuns->runAutonomously(
                        user: $user,
                        conversation: $conversation,
                        goal: $message,
                        onProgress: function (array $progress): void {
                            $phase = (string) ($progress['phase'] ?? 'autonomous_progress');
                            $status = match ($phase) {
                                'run_created' => 'autonomous_run_created',
                                'step_started' => 'autonomous_step_started',
                                'step_reviewed' => 'autonomous_step_reviewed',
                                'run_completed' => 'autonomous_run_completed',
                                default => 'autonomous_progress',
                            };

                            echo json_encode([
                                'type' => 'heartbeat',
                                'status' => $status,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

                            if ($phase === 'step_started') {
                                echo json_encode([
                                    'type' => 'tool_activity',
                                    'phase' => 'autonomous',
                                    'status' => 'running',
                                    'round' => (int) (($progress['step_number'] ?? 1)),
                                    'attempt' => (int) (($progress['attempt'] ?? 1)),
                                    'agent' => (string) ($progress['agent'] ?? 'executor'),
                                    'message' => 'Executing autonomous step',
                                    'calls' => [[
                                        'tool' => 'step',
                                        'path' => null,
                                        'query' => is_string($progress['step_title'] ?? null)
                                            ? $progress['step_title']
                                            : null,
                                    ]],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                            }

                            if ($phase === 'step_reviewed') {
                                $summary = is_string($progress['review_summary'] ?? null)
                                    ? trim((string) $progress['review_summary'])
                                    : '';
                                $result = is_string($progress['review_result'] ?? null)
                                    ? trim((string) $progress['review_result'])
                                    : 'partial';

                                echo json_encode([
                                    'type' => 'tool_activity',
                                    'phase' => 'autonomous_review',
                                    'status' => $result,
                                    'round' => (int) (($progress['step_number'] ?? 1)),
                                    'attempt' => (int) (($progress['attempt'] ?? 1)),
                                    'agent' => (string) ($progress['agent'] ?? 'reviewer'),
                                    'message' => 'Review gate completed',
                                    'results' => [[
                                        'tool' => 'review',
                                        'ok' => $result === 'pass',
                                        'path' => null,
                                        'error' => $summary === '' ? null : $summary,
                                    ]],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                            }

                            if ($phase === 'run_created') {
                                echo json_encode([
                                    'type' => 'tool_activity',
                                    'phase' => 'autonomous_run',
                                    'status' => 'created',
                                    'round' => 0,
                                    'agent' => (string) ($progress['agent'] ?? 'planner'),
                                    'message' => 'Autonomous task run created',
                                    'calls' => [[
                                        'tool' => 'task_run',
                                        'path' => null,
                                        'query' => 'Run #'.((string) ($progress['run_id'] ?? '?')),
                                    ]],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                            }

                            if ($phase === 'run_completed') {
                                echo json_encode([
                                    'type' => 'tool_activity',
                                    'phase' => 'autonomous_run',
                                    'status' => (string) ($progress['status'] ?? 'completed'),
                                    'round' => (int) (($progress['steps_executed'] ?? 0)),
                                    'agent' => (string) ($progress['agent'] ?? 'orchestrator'),
                                    'message' => 'Autonomous run finished',
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                            }

                            @ob_flush();
                            flush();
                        },
                    );

                    $memory->storeMessage(
                        conversation: $conversation,
                        role: 'user',
                        content: $message,
                        mode: $mode,
                        stage: 'task_run_goal',
                    );
                    $memory->storeMessage(
                        conversation: $conversation,
                        role: 'assistant',
                        content: (string) $auto['reply'],
                        model: (string) ($auto['model'] ?? 'system:autonomous-task-runner'),
                        mode: $mode,
                        stage: 'task_run_summary',
                    );

                    echo json_encode([
                        'type' => 'chunk',
                        'content' => (string) $auto['reply'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @ob_flush();
                    flush();

                    Log::info('ai-assistant.chat.success', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversation->id,
                        'model' => $auto['model'] ?? null,
                        'intent' => 'deep-autonomous',
                        'fallback_used' => false,
                        'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                        'stream' => true,
                    ]);

                    echo json_encode([
                        'type' => 'done',
                        'conversation_id' => $conversation->id,
                        'model' => $auto['model'] ?? null,
                        'fallback_used' => false,
                        'warnings' => is_array($auto['warnings'] ?? null) ? $auto['warnings'] : [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    @ob_flush();
                    flush();

                    return;
                }

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
                    onPlanChunk: function (string $delta): void {
                        echo json_encode([
                            'type' => 'plan_chunk',
                            'content' => $delta,
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
                    meta: array_filter([
                        'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                        ...((is_array($result['meta'] ?? null) ? $result['meta'] : [])),
                    ], static fn ($value): bool => $value !== null),
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
                    'plan' => is_string($result['plan'] ?? null) ? $result['plan'] : null,
                    'plan_model' => is_string($result['plan_model'] ?? null) ? $result['plan_model'] : null,
                    'thinking' => is_string($result['thinking'] ?? null) ? $result['thinking'] : null,
                    'plan_thinking' => is_string($result['plan_thinking'] ?? null) ? $result['plan_thinking'] : null,
                    'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : null,
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatTaskRunContinueReply(string $action, array $result): string
    {
        $normalizedAction = trim(mb_strtolower($action));
        $run = is_array($result['run'] ?? null) ? $result['run'] : [];
        $status = is_string($run['status'] ?? null) ? (string) $run['status'] : 'unknown';
        $currentStep = is_numeric($run['current_step_index'] ?? null)
            ? ((int) $run['current_step_index']) + 1
            : null;

        if (isset($result['preview']) && is_array($result['preview'])) {
            $preview = $result['preview'];
            $title = is_string($preview['step_title'] ?? null) ? (string) $preview['step_title'] : 'Current step';
            $changes = is_array($preview['changes'] ?? null) ? $preview['changes'] : [];

            return "{$title} preview is ready (".count($changes)." file changes). ".
                'Review and approve to apply this step.';
        }

        if (isset($result['applied'])) {
            $applied = (bool) ($result['applied'] ?? false);
            $rolledBack = (bool) ($result['rolled_back'] ?? false);

            if ($applied) {
                return 'Approved and applied the current step successfully. '.
                    ($currentStep !== null ? "Current step pointer: {$currentStep}. " : '').
                    "Run status: {$status}.";
            }

            if ($rolledBack) {
                return 'Step apply failed validation and was rolled back automatically. '.
                    "Run status: {$status}.";
            }
        }

        if (isset($result['execution']) && is_array($result['execution'])) {
            $reviewResult = is_array($result['review'] ?? null)
                ? (string) (($result['review']['result'] ?? 'partial'))
                : 'partial';

            return "Continued task run with action '{$normalizedAction}'. ".
                "Review result: {$reviewResult}. Run status: {$status}.";
        }

        if (is_string($result['message'] ?? null)) {
            return (string) $result['message'];
        }

        return "Continued task run with action '{$normalizedAction}'. Run status: {$status}.";
    }
}
