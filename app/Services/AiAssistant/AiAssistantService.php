<?php

namespace App\Services\AiAssistant;

use App\Support\AiAssistantWorkflowSkill;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class AiAssistantService
{
    public function __construct(
        private OllamaClient $ollama,
        private BoostContextService $boostContext,
        private ProjectContextRetriever $retriever,
        private FilesystemToolService $filesystemTools,
    ) {
        //
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{
     *     reply: string,
     *     model: string,
     *     intent: string,
     *     fallback_used: bool,
     *     plan?: string,
     *     plan_model?: string,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    public function respond(
        string $message,
        array $history = [],
        string $mode = 'auto',
        array $memorySnippets = [],
    ): array
    {
        if ($mode !== 'deep' && $this->isSimpleGreeting($message)) {
            return $this->greetingResponse();
        }

        if ($mode === 'deep') {
            return $this->respondInDeepMode($message, $history, $memorySnippets);
        }

        $intent = $this->resolveIntent($message, $mode, $history);
        $models = [
            'planning' => (string) config('ai-assistant.models.planning'),
            'coding' => (string) config('ai-assistant.models.coding'),
        ];

        $modelOrder = $intent === 'planning'
            ? [$models['planning'], $models['coding']]
            : [$models['coding'], $models['planning']];

        $warnings = [];
        $boost = $this->boostContext->buildContext();
        $warnings = [...$warnings, ...$boost['warnings']];

        $retrieval = $this->retriever->retrieve($message);
        $warnings = [...$warnings, ...$retrieval['warnings']];

        $messages = $this->buildMessages(
            $message,
            $history,
            $intent,
            $boost['context'],
            $retrieval['chunks'],
            null,
            $memorySnippets,
        );

        $errors = [];

        try {
            $result = $this->chatWithToolRounds(
                $modelOrder,
                $messages,
                'response stage',
                true,
            );

            return [
                'reply' => $this->sanitizeAssistantOutput($result['content']),
                'model' => $result['model'],
                'intent' => $intent,
                'fallback_used' => $result['fallback_used'],
                'warnings' => [...$warnings, ...$result['warnings']],
                'context' => [
                    'boost' => $boost['context'] !== '',
                    'retrieval_chunks' => count($retrieval['chunks']),
                ],
            ];
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        throw new RuntimeException('Both generation models failed. '.implode(' | ', $errors));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<string>  $memorySnippets
     * @param  callable(string): void|null  $onChunk
     * @param  callable(string): void|null  $onStatus
     * @return array{
     *     reply: string,
     *     model: string,
     *     intent: string,
     *     fallback_used: bool,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    public function streamRespond(
        string $message,
        array $history = [],
        string $mode = 'auto',
        array $memorySnippets = [],
        ?callable $onChunk = null,
        ?callable $onStatus = null,
        ?callable $onToolActivity = null,
    ): array {
        if ($mode !== 'deep' && $this->isSimpleGreeting($message)) {
            return $this->greetingResponse($onChunk);
        }

        if ($mode === 'deep') {
            if ($onStatus !== null) {
                $onStatus('planning');
            }

            $result = $this->respondInDeepMode(
                $message,
                $history,
                $memorySnippets,
                $onStatus,
                $onToolActivity,
            );

            if ($onStatus !== null) {
                $onStatus('executing');
            }

            if ($onChunk !== null) {
                $onChunk($this->sanitizeAssistantOutput($result['reply']));
            }

            if ($onStatus !== null) {
                $onStatus('finalizing');
            }

            return $result;
        }

        $intent = $this->resolveIntent($message, $mode, $history);
        $models = [
            'planning' => (string) config('ai-assistant.models.planning'),
            'coding' => (string) config('ai-assistant.models.coding'),
        ];

        $modelOrder = $intent === 'planning'
            ? [$models['planning'], $models['coding']]
            : [$models['coding'], $models['planning']];

        $warnings = [];
        if ($onStatus !== null) {
            $onStatus('building_context');
        }
        $boost = $this->boostContext->buildContext();
        $warnings = [...$warnings, ...$boost['warnings']];

        if ($onStatus !== null) {
            $onStatus('retrieving_context');
        }
        $retrieval = $this->retriever->retrieve($message);
        $warnings = [...$warnings, ...$retrieval['warnings']];

        $messages = $this->buildMessages(
            $message,
            $history,
            $intent,
            $boost['context'],
            $retrieval['chunks'],
            null,
            $memorySnippets,
        );

        $errors = [];

        try {
            $result = $this->chatWithToolRounds(
                $modelOrder,
                $messages,
                'stream response stage',
                true,
                null,
                $onStatus,
                $onToolActivity,
            );

            if ($onChunk !== null) {
                $onChunk($this->sanitizeAssistantOutput($result['content']));
            }

            return [
                'reply' => $this->sanitizeAssistantOutput($result['content']),
                'model' => $result['model'],
                'intent' => $intent,
                'fallback_used' => $result['fallback_used'],
                'warnings' => [...$warnings, ...$result['warnings']],
                'context' => [
                    'boost' => $boost['context'] !== '',
                    'retrieval_chunks' => count($retrieval['chunks']),
                ],
            ];
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        throw new RuntimeException('Both generation models failed. '.implode(' | ', $errors));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<string>  $retrievalChunks
     * @param  list<string>  $memorySnippets
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(
        string $message,
        array $history,
        string $intent,
        string $boostContext,
        array $retrievalChunks,
        ?string $executionPlan = null,
        array $memorySnippets = [],
    ): array {
        $safeHistory = array_values(array_filter($history, function (array $item): bool {
            return in_array($item['role'] ?? '', ['user', 'assistant'], true)
                && is_string($item['content'] ?? null)
                && trim((string) $item['content']) !== '';
        }));

        $safeHistory = array_slice($safeHistory, -12);
        $systemPrompt = [
            'You are an AI assistant embedded in a Laravel application.',
            "Task mode: {$intent}.",
            'Routing policy:',
            '- Planning and reasoning primary model: glm-5:cloud',
            '- Coding and execution primary model: qwen3-coder-next:cloud',
            '- Embeddings model for retrieval: qwen3-embedding:0.6b',
            'Use the provided project context directly and avoid guessing framework structure.',
            'Follow this skill rigorously:',
            AiAssistantWorkflowSkill::text(),
        ];

        if ($this->shouldEnableFilesystemTools($message, $intent, $executionPlan)) {
            $systemPrompt[] = $this->filesystemTools->toolInstructions();
        }

        if ($boostContext !== '') {
            $systemPrompt[] = 'Laravel Boost context:';
            $systemPrompt[] = $boostContext;
        }

        if ($retrievalChunks !== []) {
            $systemPrompt[] = 'Retrieved project chunks:';
            $systemPrompt[] = implode("\n\n---\n\n", $retrievalChunks);
        }

        if ($executionPlan !== null && trim($executionPlan) !== '') {
            $systemPrompt[] = 'Execution plan from planning stage:';
            $systemPrompt[] = $executionPlan;
        }

        if ($memorySnippets !== []) {
            $systemPrompt[] = 'Relevant conversation memory snippets:';
            $systemPrompt[] = implode("\n\n", $memorySnippets);
        }

        if ($this->isCrudScaffoldRequest($message)) {
            $systemPrompt[] = $this->crudScaffoldInstructions();
            $templateReference = $this->pageTemplateReferenceText();

            if ($templateReference !== '') {
                $systemPrompt[] = 'Canonical page template reference (must follow this structure for new pages):';
                $systemPrompt[] = $templateReference;
            }
        }

        return [
            [
                'role' => 'system',
                'content' => implode("\n", $systemPrompt),
            ],
            ...$safeHistory,
            [
                'role' => 'user',
                'content' => $message,
            ],
        ];
    }

    private function resolveIntent(string $message, string $mode, array $history = []): string
    {
        if (in_array($mode, ['planning', 'coding'], true)) {
            return $mode;
        }

        if ($this->isContinuationPrompt($message)) {
            $historyIntent = $this->inferIntentFromHistory($history);

            if ($historyIntent !== null) {
                return $historyIntent;
            }
        }

        $planningKeywords = [
            'plan',
            'architecture',
            'design',
            'strategy',
            'reason',
            'analyze',
            'debug approach',
            'roadmap',
        ];

        $normalized = mb_strtolower($message);

        foreach ($planningKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'planning';
            }
        }

        $codingKeywords = [
            'code',
            'coding',
            'implement',
            'fix',
            'bug',
            'debug',
            'refactor',
            'controller',
            'route',
            'model',
            'migration',
            'seed',
            'test',
            'endpoint',
            'api',
            'database',
            'sql',
            'query',
            'php',
            'typescript',
            'react',
            'laravel',
            'crud',
            'create page',
            'build page',
            'resource page',
            'management page',
            'write',
            'edit file',
            'update file',
        ];

        foreach ($codingKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'coding';
            }
        }

        return 'planning';
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function inferIntentFromHistory(array $history): ?string
    {
        if ($history === []) {
            return null;
        }

        $combined = mb_strtolower(implode("\n", array_map(
            fn (array $item): string => (string) ($item['content'] ?? ''),
            array_slice($history, -12)
        )));

        if ($combined === '') {
            return null;
        }

        $codingSignals = [
            'crud',
            'create page',
            'management page',
            'controller',
            'model',
            'migration',
            'route',
            'resource',
            'write_file',
            'append_file',
            'tool_calls',
            'implement',
            'fix',
            'refactor',
            'products',
        ];

        foreach ($codingSignals as $signal) {
            if (str_contains($combined, $signal)) {
                return 'coding';
            }
        }

        return 'planning';
    }

    private function isContinuationPrompt(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        return in_array($normalized, [
            'continue',
            'go on',
            'proceed',
            'carry on',
            'keep going',
            'continue please',
            'continue.',
        ], true);
    }

    private function isCrudScaffoldRequest(string $message): bool
    {
        $normalized = mb_strtolower($message);
        $needles = [
            'crud',
            'create a page',
            'build a page',
            'generate a page',
            'create page',
            'resource page',
            'admin page',
            'management page',
            'full stack page',
            'create module',
            'build module',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function crudScaffoldInstructions(): string
    {
        return implode("\n", [
            'Full CRUD scaffold mode is active for this request.',
            'You must implement all required artifacts unless they already exist and can be safely reused:',
            '1) Routes in routes/web.php for index/create/store/show/edit/update/destroy.',
            '2) Backend controller methods and any request validation classes needed.',
            '3) Model + migration when the entity does not exist yet.',
            '4) Inertia React pages/components under resources/js/pages for list + create/edit forms (and show if requested).',
            '5) UI actions wired to backend endpoints for create, read, update, delete.',
            '6) Sidebar navigation entry added for the new page.',
            'Sidebar edit constraints:',
            '- Do NOT rewrite or replace sidebar component structure.',
            '- Only append a single new NavMain item inside existing mainNavItems.',
            '- Keep existing imports, AppLogo block, footer links, and layout wrappers unchanged.',
            '- If resources/js/components/app-sidebar.tsx exists, treat it as canonical and perform minimal diff edits only.',
            'Before final response, verify files and imports with tools and ensure all CRUD paths are wired.',
            'Final response must include: completed CRUD checklist and changed file paths.',
        ]);
    }

    private function pageTemplateReferenceText(): string
    {
        $path = base_path('tasks/page-template-reference.md');

        if (! File::exists($path) || File::isDirectory($path)) {
            return '';
        }

        $content = trim((string) File::get($path));

        if ($content === '') {
            return '';
        }

        return mb_substr($content, 0, 12000);
    }

    private function shouldEnableFilesystemTools(
        string $message,
        string $intent,
        ?string $executionPlan = null,
    ): bool {
        if ($intent === 'coding') {
            return true;
        }

        if ($executionPlan !== null && trim($executionPlan) !== '') {
            return true;
        }

        return $this->isCrudScaffoldRequest($message);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{
     *     reply: string,
     *     model: string,
     *     intent: string,
     *     fallback_used: bool,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    private function respondInDeepMode(
        string $message,
        array $history,
        array $memorySnippets = [],
        ?callable $onStatus = null,
        ?callable $onToolActivity = null,
    ): array
    {
        $models = [
            'planning' => (string) config('ai-assistant.models.planning'),
            'coding' => (string) config('ai-assistant.models.coding'),
        ];

        $warnings = [];
        $boost = $this->boostContext->buildContext();
        $warnings = [...$warnings, ...$boost['warnings']];

        $retrieval = $this->retriever->retrieve($message);
        $warnings = [...$warnings, ...$retrieval['warnings']];

        $planningPrompt = "Create a concise execution plan for this request before coding:\n{$message}";
        $planMessages = $this->buildMessages(
            $planningPrompt,
            $history,
            'planning',
            $boost['context'],
            $retrieval['chunks'],
            null,
            $memorySnippets,
        );

        $planResult = $this->chatWithFallback(
            [$models['planning'], $models['coding']],
            $planMessages,
            'planning stage'
        );

        $executionMessages = $this->buildMessages(
            $message,
            $history,
            'coding',
            $boost['context'],
            $retrieval['chunks'],
            $planResult['content'],
            $memorySnippets,
        );

        $executionResult = $this->chatWithToolRounds(
            [$models['coding'], $models['planning']],
            $executionMessages,
            'execution stage',
            true,
            null,
            $onStatus,
            $onToolActivity,
        );

        if ($planResult['fallback_used']) {
            $warnings[] = 'Deep mode planning used fallback model.';
        }

        if ($executionResult['fallback_used']) {
            $warnings[] = 'Deep mode execution used fallback model.';
        }

        return [
            'reply' => $this->sanitizeAssistantOutput($executionResult['content']),
            'model' => $executionResult['model'],
            'intent' => 'deep',
            'fallback_used' => $planResult['fallback_used'] || $executionResult['fallback_used'],
            'plan' => $this->sanitizeAssistantOutput($planResult['content']),
            'plan_model' => $planResult['model'],
            'warnings' => $warnings,
            'context' => [
                'boost' => $boost['context'] !== '',
                'retrieval_chunks' => count($retrieval['chunks']),
            ],
        ];
    }

    /**
     * @param  list<string>  $modelOrder
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, fallback_used: bool, warnings: list<string>}
     */
    private function chatWithToolRounds(
        array $modelOrder,
        array $messages,
        string $stage,
        bool $allowTools,
        ?callable $onChunk = null,
        ?callable $onStatus = null,
        ?callable $onToolActivity = null,
    ): array {
        $toolWarnings = [];
        $fallbackUsed = false;
        $configuredMaxToolRounds = (int) config('ai-assistant.tools.filesystem.max_tool_rounds', 8);
        $unboundedToolRounds = $configuredMaxToolRounds <= 0;
        $maxToolRounds = $unboundedToolRounds ? 0 : max(1, $configuredMaxToolRounds);
        $currentMessages = $messages;
        $crudRequest = $this->isCrudRequestFromMessages($messages);
        $toolCallSignatures = [];
        $lastToolNames = [];

        if ($crudRequest) {
            if (! $unboundedToolRounds) {
                $maxToolRounds = max($maxToolRounds, 8);
            }

            if ($onStatus !== null) {
                $onStatus('bootstrap_tools');
            }

            $bootstrapResults = $this->bootstrapScaffoldToolResults();
            $serializedBootstrap = json_encode(
                $bootstrapResults,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if (is_string($serializedBootstrap) && $serializedBootstrap !== '') {
                if ($onToolActivity !== null) {
                    $onToolActivity([
                        'phase' => 'bootstrap',
                        'status' => 'ok',
                        'round' => 0,
                        'results_count' => count($bootstrapResults),
                    ]);
                }

                $currentMessages[] = [
                    'role' => 'user',
                    'content' => "Project scaffold snapshot (tool results):\n{$serializedBootstrap}\n".
                        'Use this snapshot and continue with filesystem tool_calls to implement the requested CRUD task. '.
                        'Do not stop at planning text.',
                ];
            }
        }

        $round = 0;
        while ($unboundedToolRounds || $round < $maxToolRounds) {
            $round++;
            if ($onStatus !== null) {
                $onStatus("model_round_{$round}");
            }
            $result = $this->chatWithFallback($modelOrder, $currentMessages, $stage, null);

            $fallbackUsed = $fallbackUsed || $result['fallback_used'];

            if (! $allowTools) {
                return [
                    ...$result,
                    'fallback_used' => $fallbackUsed,
                    'warnings' => $toolWarnings,
                ];
            }

            $calls = $this->filesystemTools->extractToolCalls($result['content']);

            if ($calls === []) {
                if ($this->looksLikeMalformedToolCallOutput($result['content'])) {
                    $toolWarnings[] = 'Model output resembled tool_calls JSON but could not be parsed; requesting strict JSON retry.';

                    $currentMessages[] = [
                        'role' => 'assistant',
                        'content' => $result['content'],
                    ];
                    $currentMessages[] = [
                        'role' => 'user',
                        'content' => 'Your previous output looked like tool_calls but was invalid or wrapped in extra text. '.
                            'Output ONLY strict JSON with key "tool_calls" and valid arguments, no prose.',
                    ];

                    continue;
                }

                $shouldForceCrudNudge = $crudRequest && $round <= 2;

                if ($shouldForceCrudNudge || $this->shouldNudgeToolExecution($currentMessages, $result['content'])) {
                    $bootstrapResults = $this->bootstrapScaffoldToolResults();
                    $serializedBootstrap = json_encode(
                        $bootstrapResults,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );

                    $currentMessages[] = [
                        'role' => 'assistant',
                        'content' => $result['content'],
                    ];
                    $currentMessages[] = [
                        'role' => 'user',
                        'content' => is_string($serializedBootstrap) && $serializedBootstrap !== ''
                            ? "Tool results:\n{$serializedBootstrap}\nUse these concrete project paths and continue implementation with filesystem tools now. ".
                                'Do not stop at planning text.'
                            : 'Do not stop at planning. Execute the requested implementation now using filesystem tools. '.
                                'Output strict tool_calls JSON and continue until the implementation is complete.',
                    ];

                    continue;
                }

                if ($onStatus !== null) {
                    $onStatus('finalizing');
                }

                return [
                    ...$result,
                    'fallback_used' => $fallbackUsed,
                    'warnings' => $toolWarnings,
                ];
            }

            if ($onStatus !== null) {
                $onStatus('running_tools');
            }

            $lastToolNames = array_values(array_unique(array_map(
                fn (array $call): string => (string) ($call['tool'] ?? 'unknown'),
                $calls
            )));

            if ($onToolActivity !== null) {
                $onToolActivity([
                    'phase' => 'tool_calls',
                    'status' => 'requested',
                    'round' => $round,
                    'tools' => $lastToolNames,
                    'calls' => $this->summarizeToolCalls($calls),
                ]);
            }

            $callSignature = $this->toolCallSignature($calls);

            if ($callSignature !== '' && isset($toolCallSignatures[$callSignature])) {
                $toolWarnings[] = 'Detected repeated identical tool call payload; requesting alternative steps.';

                $currentMessages[] = [
                    'role' => 'assistant',
                    'content' => $result['content'],
                ];
                $currentMessages[] = [
                    'role' => 'user',
                    'content' => 'You repeated the same tool_calls as a previous round. Do not repeat identical calls. '.
                        'Request only missing files/edits needed to complete the task, or provide final answer if complete.',
                ];

                continue;
            }

            if ($callSignature !== '') {
                $toolCallSignatures[$callSignature] = true;
            }

            $toolResults = [];

            foreach ($calls as $index => $call) {
                try {
                    $toolResults[] = [
                        'index' => $index,
                        'ok' => true,
                        'call' => $call,
                        'result' => $this->filesystemTools->execute($call),
                    ];
                } catch (Throwable $exception) {
                    $toolWarnings[] = "Filesystem tool failed: {$exception->getMessage()}";
                    $toolResults[] = [
                        'index' => $index,
                        'ok' => false,
                        'call' => $call,
                        'error' => $exception->getMessage(),
                    ];
                }
            }

            if ($onToolActivity !== null) {
                $onToolActivity([
                    'phase' => 'tool_results',
                    'status' => 'completed',
                    'round' => $round,
                    'results' => $this->summarizeToolResults($toolResults),
                ]);
            }

            $serialized = json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (! is_string($serialized) || $serialized === '') {
                throw new RuntimeException('Unable to serialize filesystem tool results.');
            }

            $currentMessages[] = [
                'role' => 'assistant',
                'content' => $result['content'],
            ];
            $currentMessages[] = [
                'role' => 'user',
                'content' => "Tool results:\n{$serialized}\nUse these results to continue. ".
                    'If no more tool calls are needed, provide the final user-facing answer.',
            ];
        }

        $toolWarnings[] = "Tool loop exceeded {$maxToolRounds} rounds during {$stage}.";
        $lastToolsText = $lastToolNames === []
            ? 'none'
            : implode(', ', $lastToolNames);

        return [
            'content' => "I could not complete the request automatically because the tool loop exceeded the safety limit after {$maxToolRounds} rounds. ".
                "Last requested tools: {$lastToolsText}. ".
                'Retry with a narrower step (for example: "create Product model + migration only"), then continue step by step.',
            'model' => 'system:tool-loop-guard',
            'fallback_used' => $fallbackUsed,
            'warnings' => $toolWarnings,
        ];
    }

    /**
     * @param  list<array{tool: string, arguments: array<string, mixed>}>  $calls
     * @return list<array<string, mixed>>
     */
    private function summarizeToolCalls(array $calls): array
    {
        return array_values(array_map(function (array $call): array {
            $tool = (string) ($call['tool'] ?? 'unknown');
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $path = isset($arguments['path']) && is_string($arguments['path'])
                ? $arguments['path']
                : null;
            $query = isset($arguments['query']) && is_string($arguments['query'])
                ? $arguments['query']
                : null;

            return [
                'tool' => $tool,
                'path' => $path,
                'query' => $query,
            ];
        }, $calls));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function summarizeToolResults(array $results): array
    {
        return array_values(array_map(function (array $row): array {
            $call = is_array($row['call'] ?? null) ? $row['call'] : [];
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            $tool = isset($call['tool']) && is_string($call['tool'])
                ? $call['tool']
                : 'unknown';

            return [
                'tool' => $tool,
                'ok' => (bool) ($row['ok'] ?? false),
                'path' => is_string($result['path'] ?? null) ? $result['path'] : null,
                'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
            ];
        }, $results));
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function isCrudRequestFromMessages(array $messages): bool
    {
        return $this->isCrudScaffoldRequest($this->lastUserMessage($messages));
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function lastUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $item = $messages[$index];

            if (($item['role'] ?? '') === 'user') {
                return (string) ($item['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  list<array{tool: string, arguments: array<string, mixed>}>  $calls
     */
    private function toolCallSignature(array $calls): string
    {
        $encoded = json_encode($calls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            return '';
        }

        return sha1($encoded);
    }

    /**
     * @param  list<string>  $modelOrder
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, fallback_used: bool}
     */
    private function chatWithFallback(
        array $modelOrder,
        array $messages,
        string $stage,
        ?callable $onChunk = null,
    ): array
    {
        $errors = [];
        $order = $modelOrder;

        foreach ($order as $index => $model) {
            if (! is_string($model) || trim($model) === '') {
                continue;
            }

            try {
                if ($onChunk === null) {
                    $result = $this->ollama->chat($model, $messages);
                } else {
                    $content = '';

                    foreach ($this->ollama->streamChat($model, $messages) as $delta) {
                        $content .= $delta;
                        $onChunk($delta);
                    }

                    if (trim($content) === '') {
                        throw new RuntimeException('Streaming response completed without content.');
                    }

                    $result = [
                        'content' => $content,
                        'model' => $model,
                    ];
                }

                return [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'fallback_used' => $index > 0,
                ];
            } catch (Throwable $exception) {
                $errors[] = "{$model}: {$exception->getMessage()}";
            }
        }

        throw new RuntimeException("All models failed during {$stage}. ".implode(' | ', $errors));
    }

    private function isToolCallPayloadPrefix(string $content): bool
    {
        return preg_match('/^\{\s*"tool_calls"\s*:/', $content) === 1;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function shouldNudgeToolExecution(array $messages, string $modelOutput): bool
    {
        $lastUserMessage = $this->lastUserMessage($messages);

        if (! $this->isCrudScaffoldRequest($lastUserMessage)) {
            return false;
        }

        $normalized = mb_strtolower(trim($modelOutput));
        if ($normalized === '') {
            return false;
        }

        if ($this->isToolCallPayloadPrefix($normalized)) {
            return false;
        }

        $planSignals = [
            "i'll create",
            'i will create',
            'let me first',
            'first, let me',
            'i will first',
            'i can help you create',
            'execution plan',
        ];

        foreach ($planSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeAssistantOutput(string $content): string
    {
        $clean = trim($content);

        if ($clean === '') {
            return $clean;
        }

        $clean = preg_replace('/```json\s*\{\s*"tool_calls"[\s\S]*?```/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\{\s*"tool_calls"\s*:\s*\[[\s\S]*?\]\s*\}/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        if (preg_match('/<\/think>/i', $clean) === 1) {
            $clean = preg_replace('/^.*<\/think>/is', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $clean = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        $length = mb_strlen($clean);
        if ($length >= 20 && $length % 2 === 0) {
            $half = (int) ($length / 2);
            $left = trim(mb_substr($clean, 0, $half));
            $right = trim(mb_substr($clean, $half));

            if ($left !== '' && $left === $right) {
                return $left;
            }
        }

        return $clean !== ''
            ? $clean
            : 'I encountered an internal formatting issue while continuing. Please type "continue the previous implementation".';
    }

    private function looksLikeMalformedToolCallOutput(string $content): bool
    {
        $normalized = mb_strtolower(trim($content));

        if ($normalized === '') {
            return false;
        }

        if ($this->isToolCallPayloadPrefix($normalized)) {
            return false;
        }

        return str_contains($normalized, 'tool_calls')
            || str_contains($normalized, '"tool":')
            || str_contains($normalized, 'read_file')
            || str_contains($normalized, 'write_file');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bootstrapScaffoldToolResults(): array
    {
        $seedCalls = [
            ['tool' => 'list_directory', 'arguments' => ['path' => 'app/Models']],
            ['tool' => 'list_directory', 'arguments' => ['path' => 'app/Http/Controllers']],
            ['tool' => 'list_directory', 'arguments' => ['path' => 'app/Http/Requests']],
            ['tool' => 'list_directory', 'arguments' => ['path' => 'database/migrations']],
            ['tool' => 'list_directory', 'arguments' => ['path' => 'resources/js/pages']],
            ['tool' => 'read_file', 'arguments' => ['path' => 'routes/web.php']],
            ['tool' => 'read_file', 'arguments' => ['path' => 'resources/js/components/app-sidebar.tsx']],
        ];

        $results = [];

        foreach ($seedCalls as $index => $call) {
            try {
                $results[] = [
                    'index' => $index,
                    'ok' => true,
                    'call' => $call,
                    'result' => $this->filesystemTools->execute($call),
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'index' => $index,
                    'ok' => false,
                    'call' => $call,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function isSimpleGreeting(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(hi+|hello+|hey+|heyy+|yo+|sup+|hola+|good (morning|afternoon|evening))([!.?,\s]*)$/', $normalized) === 1;
    }

    /**
     * @return array{
     *     reply: string,
     *     model: string,
     *     intent: string,
     *     fallback_used: bool,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    private function greetingResponse(?callable $onChunk = null): array
    {
        $reply = 'Hey! I am here. Tell me what you want to build, fix, or debug in this project.';

        if ($onChunk !== null) {
            $onChunk($reply);
        }

        return [
            'reply' => $reply,
            'model' => 'system:greeting-fastpath',
            'intent' => 'planning',
            'fallback_used' => false,
            'warnings' => [],
            'context' => [
                'boost' => false,
                'retrieval_chunks' => 0,
            ],
        ];
    }
}
