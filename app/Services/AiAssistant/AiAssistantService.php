<?php

namespace App\Services\AiAssistant;

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
        private OrderAssistantService $orderAssistant,
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
     *     thinking?: string,
     *     plan_thinking?: string,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    public function respond(
        string $message,
        array $history = [],
        string $mode = 'deep',
        array $memorySnippets = [],
    ): array
    {
        if ($this->isSimpleGreeting($message)) {
            return $this->greetingResponse();
        }

        $orderResponse = $this->orderAssistant->respond($message, $history);
        if ($orderResponse !== null) {
            return $orderResponse;
        }

        $directFileReview = $this->maybeDirectFileReviewResponse($message);
        if ($directFileReview !== null) {
            return $directFileReview;
        }

        if ($mode === 'deep' && ! $this->isReadOnlyFileRequest($message)) {
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
                'thinking' => $this->thinkingForUi($result['content']),
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
     * @param  callable(string): void|null  $onPlanChunk
     * @return array{
     *     reply: string,
     *     model: string,
     *     intent: string,
     *     fallback_used: bool,
     *     thinking?: string,
     *     plan_thinking?: string,
     *     warnings: list<string>,
     *     context: array{boost: bool, retrieval_chunks: int}
     * }
     */
    public function streamRespond(
        string $message,
        array $history = [],
        string $mode = 'deep',
        array $memorySnippets = [],
        ?callable $onChunk = null,
        ?callable $onStatus = null,
        ?callable $onToolActivity = null,
        ?callable $onPlanChunk = null,
    ): array {
        if ($this->isSimpleGreeting($message)) {
            return $this->greetingResponse($onChunk);
        }

        $orderResponse = $this->orderAssistant->respond($message, $history);
        if ($orderResponse !== null) {
            if ($onChunk !== null) {
                $onChunk($orderResponse['reply']);
            }
            if ($onStatus !== null) {
                $onStatus('finalizing');
            }

            return $orderResponse;
        }

        $directFileReview = $this->maybeDirectFileReviewResponse($message);
        if ($directFileReview !== null) {
            if ($onChunk !== null) {
                $onChunk($directFileReview['reply']);
            }
            if ($onStatus !== null) {
                $onStatus('finalizing');
            }

            return $directFileReview;
        }

        if ($mode === 'deep' && ! $this->isReadOnlyFileRequest($message)) {
            $result = $this->respondInDeepMode(
                $message,
                $history,
                $memorySnippets,
                $onStatus,
                $onToolActivity,
                $onPlanChunk,
            );

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
                'thinking' => $this->thinkingForUi($result['content']),
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
            'You are the RealDeal Logistics office assistant.',
            "Task mode: {$intent}.",
            'Primary responsibilities:',
            '- Help employees with orders, merchants, deliveries, statuses, customer details, and office operations.',
            '- Prefer concise operational answers over technical explanations.',
            '- If a query is ambiguous, ask one short clarifying question.',
            'Response style contract:',
            '- Keep final responses concise, clean, and practical.',
            '- Avoid decorative emojis, hype language, and marketing-style closers.',
            '- Prefer short bullets over long narrative paragraphs.',
            '- When web_search is used, include a final "Sources" section with plain URL citations.',
        ];

        if ((bool) config('ai-assistant.tools.web_search.enabled', false)
            && $this->shouldEnableWebSearchInstructions($message, $intent)
        ) {
            $systemPrompt[] = $this->filesystemTools->webSearchInstructions();
        }

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

        if ($intent === 'coding' || $this->isCrudScaffoldRequest($message)) {
            $importReference = $this->importPatternReferenceText();
            $modelTableReference = $this->modelTableReferenceText();

            if ($importReference !== '') {
                $systemPrompt[] = 'Strict import rules (must follow):';
                $systemPrompt[] = $importReference;
            }

            if ($modelTableReference !== '') {
                $systemPrompt[] = 'Strict Laravel model/table rules (must follow):';
                $systemPrompt[] = $modelTableReference;
            }
        }

        if ($memorySnippets !== []) {
            $systemPrompt[] = 'Relevant conversation memory snippets:';
            $systemPrompt[] = implode("\n\n", $memorySnippets);
        }

        if ($this->isCrudScaffoldRequest($message)) {
            $systemPrompt[] = $this->crudScaffoldInstructions();
        }

        if ($this->shouldIncludePageTemplateReference($message, $intent, $executionPlan)) {
            $templateReference = $this->pageTemplateReferenceText();

            if ($templateReference !== '') {
                $systemPrompt[] = 'Canonical page template reference (strict): when creating or updating Inertia pages, follow this structure and component patterns unless the user explicitly asks for a different layout.';
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

        if ($this->isReadOnlyFileRequest($message)) {
            return 'coding';
        }

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
            'add, view, edit, and delete',
            'add, view, edit, delete',
            'add view edit delete',
            'add and edit and delete',
            'create, read, update, delete',
            'create read update delete',
            'add/edit/delete',
            'add edit delete',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function shouldIncludePageTemplateReference(
        string $message,
        string $intent,
        ?string $executionPlan = null,
    ): bool {
        if ($this->isCrudScaffoldRequest($message)) {
            return true;
        }

        if ($intent !== 'coding') {
            return false;
        }

        $normalized = mb_strtolower($message);
        $pageNeedles = [
            'page',
            'inertia',
            'frontend',
            'front-end',
            'ui',
            'screen',
            'dashboard',
            'form',
            'table',
            'modal',
            'sidebar',
        ];

        foreach ($pageNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        if ($executionPlan !== null) {
            $plan = mb_strtolower($executionPlan);
            foreach ($pageNeedles as $needle) {
                if (str_contains($plan, $needle)) {
                    return true;
                }
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
            'Import strictness constraints:',
            '- Before adding imports, read target module files and match export style exactly (default vs named).',
            '- Do not guess export style from names.',
            "- Use `import AlertError from '@/components/alert-error';` (default import), never named import for that component.",
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

    private function importPatternReferenceText(): string
    {
        $path = base_path('tasks/import-pattern-reference.md');

        if (! File::exists($path) || File::isDirectory($path)) {
            return '';
        }

        $content = trim((string) File::get($path));

        if ($content === '') {
            return '';
        }

        return mb_substr($content, 0, 8000);
    }

    private function modelTableReferenceText(): string
    {
        $path = base_path('tasks/laravel-model-table-reference.md');

        if (! File::exists($path) || File::isDirectory($path)) {
            return '';
        }

        $content = trim((string) File::get($path));

        if ($content === '') {
            return '';
        }

        return mb_substr($content, 0, 8000);
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
        ?callable $onPlanChunk = null,
    ): array
    {
        $models = [
            'planning' => (string) config('ai-assistant.models.planning'),
            'coding' => (string) config('ai-assistant.models.coding'),
        ];

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

        if ($onStatus !== null) {
            $onStatus('planning');
        }

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

        try {
            $planningWasStreamed = false;
            $planningChunkHandler = null;

            if ($onPlanChunk !== null) {
                $planningChunkHandler = function (string $delta) use ($onPlanChunk, &$planningWasStreamed): void {
                    if (trim($delta) === '') {
                        return;
                    }

                    $planningWasStreamed = true;
                    $onPlanChunk($delta);
                };
            }

            $planResult = $this->chatWithFallback(
                [$models['planning']],
                $planMessages,
                'planning stage',
                $planningChunkHandler,
            );
        } catch (Throwable $planningException) {
            $warnings[] = 'Deep mode planning failed; continuing execution with a minimal fallback plan.';
            $planResult = [
                'content' => 'Planning stage failed. Continue directly with implementation and verify changes with tools.',
                'model' => 'system:planning-fallback',
                'fallback_used' => false,
            ];
            $planningWasStreamed = false;
        }

        if ($onPlanChunk !== null) {
            $planPreview = $this->sanitizeAssistantOutput($planResult['content']);

            if (! $planningWasStreamed && trim($planPreview) !== '') {
                $onPlanChunk($planPreview);
            }
        }

        if ($onStatus !== null) {
            $onStatus('executing');
        }

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
            'thinking' => $this->thinkingForUi($executionResult['content']),
            'plan_thinking' => $this->thinkingForUi($planResult['content']),
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
        $hardToolRoundCap = max(4, (int) config('ai-assistant.tools.filesystem.max_tool_rounds_hard_cap', 24));
        $unboundedToolRounds = $configuredMaxToolRounds <= 0;
        $maxToolRounds = $unboundedToolRounds
            ? $hardToolRoundCap
            : max(1, min($configuredMaxToolRounds, $hardToolRoundCap));
        $currentMessages = $messages;
        $crudRequest = $this->isCrudRequestFromMessages($messages);
        $requireTypecheck = $this->shouldRunTypeScriptCheck($messages, $crudRequest);
        $typecheckAttempted = false;
        $citationRetryAttempted = false;
        $webSearchUsed = false;
        $webSearchCallsExecuted = 0;
        $maxWebSearchCallsPerRequest = max(1, (int) config('ai-assistant.tools.web_search.max_calls_per_request', 2));
        $toolCallSignatures = [];
        $lastToolNames = [];
        $consecutiveReadOnlyRounds = 0;
        $rawToolPayloadRetryAttempted = false;

        if ($requireTypecheck && ! $unboundedToolRounds) {
            $maxToolRounds += 4;
        }

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

        if ($unboundedToolRounds) {
            $toolWarnings[] = "Configured tool rounds were unbounded; applying hard safety cap of {$hardToolRoundCap} rounds.";
        } elseif ($configuredMaxToolRounds > $hardToolRoundCap) {
            $toolWarnings[] = "Configured tool rounds ({$configuredMaxToolRounds}) exceed hard cap; using {$hardToolRoundCap}.";
        }

        $round = 0;
        while ($round < $maxToolRounds) {
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
                if (! $rawToolPayloadRetryAttempted && $this->isRawToolPayloadOutput($result['content'])) {
                    $rawToolPayloadRetryAttempted = true;
                    $toolWarnings[] = 'Detected raw tool payload in assistant output; forcing one extra execution/finalization round.';

                    $currentMessages[] = [
                        'role' => 'assistant',
                        'content' => $result['content'],
                    ];
                    $currentMessages[] = [
                        'role' => 'user',
                        'content' => 'Your previous response exposed raw tool payload JSON. '.
                            'Do not show tool_calls/calls/tool JSON to the user. '.
                            'Execute any pending tool work first, then return only a normal user-facing summary and changed files.',
                    ];

                    continue;
                }

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

                if ($requireTypecheck && ! $typecheckAttempted) {
                    $typecheckAttempted = true;

                    if ($onStatus !== null) {
                        $onStatus('verifying_typescript');
                    }

                    $typecheck = $this->runTypeScriptCheck();

                    if ($onToolActivity !== null) {
                        $onToolActivity([
                            'phase' => 'verification',
                            'status' => $typecheck['ok'] ? 'passed' : 'failed',
                            'round' => $round,
                            'tools' => ['run_shell'],
                            'results' => [[
                                'tool' => 'run_shell',
                                'ok' => (bool) $typecheck['ok'],
                                'path' => is_string($typecheck['cwd'] ?? null) ? $typecheck['cwd'] : null,
                                'error' => $typecheck['ok'] ? null : 'TypeScript check failed',
                            ]],
                        ]);
                    }

                    if (! $typecheck['ok']) {
                        $toolWarnings[] = 'TypeScript check failed after implementation; requesting fixes.';
                        $currentMessages[] = [
                            'role' => 'assistant',
                            'content' => $result['content'],
                        ];
                        $currentMessages[] = [
                            'role' => 'user',
                            'content' => "TypeScript check failed. Command: {$typecheck['command']}\n".
                                "Exit code: {$typecheck['exit_code']}\n".
                                "stdout:\n{$typecheck['stdout']}\n\nstderr:\n{$typecheck['stderr']}\n\n".
                                'Use filesystem tools to fix all TypeScript errors. Output strict tool_calls JSON only until errors are resolved; avoid planning prose.',
                        ];

                        continue;
                    }
                }

                if ($webSearchUsed
                    && (bool) config('ai-assistant.tools.web_search.require_citations', true)
                    && ! $citationRetryAttempted
                    && ! $this->responseIncludesCitationLinks($result['content'])
                ) {
                    $citationRetryAttempted = true;
                    $toolWarnings[] = 'Web search was used but final answer had no source links; requesting citation-complete rewrite.';
                    $currentMessages[] = [
                        'role' => 'assistant',
                        'content' => $result['content'],
                    ];
                    $currentMessages[] = [
                        'role' => 'user',
                        'content' => 'You used web_search results. Rewrite your final answer in a clean concise format, '.
                            'without decorative emojis or promotional text, and include a final "Sources" section with clickable URLs for key claims.',
                    ];

                    continue;
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
            $webSearchUsed = $webSearchUsed || in_array('web_search', $lastToolNames, true);
            $readOnlyTools = ['read_file', 'list_directory', 'search_code'];
            $allReadOnlyRound = $lastToolNames !== []
                && array_reduce($lastToolNames, static function (bool $carry, string $tool) use ($readOnlyTools): bool {
                    return $carry && in_array($tool, $readOnlyTools, true);
                }, true);
            $consecutiveReadOnlyRounds = $allReadOnlyRound
                ? $consecutiveReadOnlyRounds + 1
                : 0;

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
                $callTool = (string) ($call['tool'] ?? '');

                if ($callTool === 'web_search' && $webSearchCallsExecuted >= $maxWebSearchCallsPerRequest) {
                    $toolWarnings[] = "web_search call limit reached ({$maxWebSearchCallsPerRequest} per request).";
                    $toolResults[] = [
                        'index' => $index,
                        'ok' => false,
                        'call' => $call,
                        'error' => "web_search call limit reached ({$maxWebSearchCallsPerRequest} per request). Continue with existing results.",
                    ];

                    continue;
                }

                try {
                    if ($callTool === 'web_search') {
                        $webSearchCallsExecuted++;
                    }

                    $shellEventForwarder = null;
                    if ($callTool === 'run_shell' && $onToolActivity !== null) {
                        $shellEventForwarder = function (array $shellEvent) use ($onToolActivity, $round): void {
                            $onToolActivity([
                                'phase' => 'shell_stream',
                                'status' => isset($shellEvent['event']) && is_string($shellEvent['event'])
                                    ? $shellEvent['event']
                                    : 'update',
                                'round' => $round,
                                'tool' => 'run_shell',
                                ...$shellEvent,
                            ]);
                        };
                    }

                    $toolResults[] = [
                        'index' => $index,
                        'ok' => true,
                        'call' => $call,
                        'result' => $this->filesystemTools->execute($call, $shellEventForwarder),
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

            if ($crudRequest && $consecutiveReadOnlyRounds >= 3) {
                $toolWarnings[] = 'Detected repeated discovery-only rounds; forcing direct implementation edits.';
                $currentMessages[] = [
                    'role' => 'user',
                    'content' => 'Stop discovery now. In the next response, output ONLY strict tool_calls JSON using write_file/edit/append_file '.
                        'to implement concrete code changes for this CRUD step. Do not request read_file/list_directory/search_code unless absolutely required.',
                ];
            }
        }

        $toolWarnings[] = "Tool loop exceeded {$maxToolRounds} rounds during {$stage}.";
        $lastToolsText = $lastToolNames === []
            ? 'none'
            : implode(', ', $lastToolNames);

        return [
            'content' => "I could not complete the request automatically because the tool loop exceeded the safety limit after {$maxToolRounds} rounds. ".
                "Last requested tools: {$lastToolsText}. ".
                'Retry with a narrower step (for example: "create model + migration only"), then continue step by step.',
            'model' => 'system:tool-loop-guard',
            'fallback_used' => $fallbackUsed,
            'warnings' => $toolWarnings,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function shouldRunTypeScriptCheck(array $messages, bool $crudRequest): bool
    {
        if (! (bool) config('ai-assistant.quality.typescript_check_on_coding', true)) {
            return false;
        }

        if ($crudRequest) {
            return true;
        }

        if ($this->isReadOnlyFileRequest($this->lastUserMessage($messages))) {
            return false;
        }

        return $this->resolveIntent($this->lastUserMessage($messages), 'deep', $messages) === 'coding';
    }

    /**
     * @return array{
     *   ok: bool,
     *   command: string,
     *   cwd: string|null,
     *   exit_code: int|null,
     *   stdout: string,
     *   stderr: string
     * }
     */
    private function runTypeScriptCheck(): array
    {
        $command = trim((string) config('ai-assistant.quality.typescript_check_command', 'npm run types -- --pretty false'));
        $timeout = max(10, (int) config('ai-assistant.quality.typescript_check_timeout_seconds', 120));

        if ($command === '') {
            return [
                'ok' => true,
                'command' => '',
                'cwd' => null,
                'exit_code' => 0,
                'stdout' => '',
                'stderr' => '',
            ];
        }

        try {
            $result = $this->filesystemTools->execute([
                'tool' => 'run_shell',
                'arguments' => [
                    'command' => $command,
                    'cwd' => '.',
                    'timeout_seconds' => $timeout,
                ],
            ]);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'command' => $command,
                'cwd' => null,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
            ];
        }

        $maxOutput = max(2000, (int) config('ai-assistant.quality.typescript_check_max_output_chars', 24000));

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'command' => is_string($result['command'] ?? null) ? $result['command'] : $command,
            'cwd' => is_string($result['cwd'] ?? null) ? $result['cwd'] : null,
            'exit_code' => is_int($result['exit_code'] ?? null) ? $result['exit_code'] : null,
            'stdout' => mb_substr((string) ($result['stdout'] ?? ''), 0, $maxOutput),
            'stderr' => mb_substr((string) ($result['stderr'] ?? ''), 0, $maxOutput),
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
                'domains' => isset($arguments['domains']) ? $arguments['domains'] : null,
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
                $message = $exception->getMessage();
                $errors[] = "{$model}: {$message}";

                if ($this->isNonRecoverableModelError($message)) {
                    break;
                }
            }
        }

        throw new RuntimeException("All models failed during {$stage}. ".implode(' | ', $errors));
    }

    private function isNonRecoverableModelError(string $message): bool
    {
        $normalized = mb_strtolower($message);

        if ($normalized === '') {
            return false;
        }

        $needles = [
            '429 too many requests',
            'reached your session usage limit',
            'server misbehaving',
            'lookup ollama.com',
            'dial tcp',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isToolCallPayloadPrefix(string $content): bool
    {
        return preg_match('/^\{\s*"tool_calls"\s*:/', $content) === 1;
    }

    private function responseIncludesCitationLinks(string $content): bool
    {
        return preg_match('/https?:\/\/[^\s\])}<>"]+/i', $content) === 1;
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
        $clean = preg_replace('/```json\s*\{\s*"calls"[\s\S]*?```/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\{\s*"calls"\s*:\s*\[[\s\S]*?\]\s*\}/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^\s*\{\s*"tool"\s*:\s*"[^"]+"[\s\S]*\}\s*$/i', '', $clean) ?? $clean;
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

    private function thinkingForUi(string $content): ?string
    {
        if (! (bool) config('ai-assistant.ui.expose_thinking', false)) {
            return null;
        }

        return $this->extractThinking($content);
    }

    private function extractThinking(string $content): ?string
    {
        $normalized = trim($content);

        if ($normalized === '') {
            return null;
        }

        if (preg_match_all('/<think>([\s\S]*?)<\/think>/i', $normalized, $matches) === false) {
            return null;
        }

        if (! isset($matches[1]) || ! is_array($matches[1]) || $matches[1] === []) {
            return null;
        }

        $segments = array_values(array_filter(array_map(
            static fn ($segment): string => trim((string) $segment),
            $matches[1]
        ), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        return implode("\n\n---\n\n", $segments);
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

    private function isRawToolPayloadOutput(string $content): bool
    {
        $trimmed = trim($content);

        if ($trimmed === '') {
            return false;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)
            && preg_match('/```json\s*([\s\S]*?)\s*```/i', $trimmed, $matches) === 1
        ) {
            /** @var mixed $fencedDecoded */
            $fencedDecoded = json_decode(trim((string) ($matches[1] ?? '')), true);
            $decoded = $fencedDecoded;
        }

        if (! is_array($decoded)) {
            return false;
        }

        if (array_key_exists('tool_calls', $decoded) || array_key_exists('calls', $decoded)) {
            return true;
        }

        return isset($decoded['tool'])
            && is_string($decoded['tool'])
            && trim($decoded['tool']) !== '';
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
     * }|null
     */
    private function maybeDirectFileReviewResponse(string $message): ?array
    {
        if (! $this->isReadOnlyFileRequest($message)) {
            return null;
        }

        $requestedPath = $this->extractRequestedFilePath($message);
        if ($requestedPath === null) {
            return null;
        }

        $resolvedPath = $this->resolveRequestedFilePath($requestedPath);
        if ($resolvedPath === null) {
            return [
                'reply' => "I could not find `{$requestedPath}` in common project paths. ".
                    'Try a full relative path like `routes/ai.php`.',
                'model' => 'system:file-review-shortcut',
                'intent' => 'coding',
                'fallback_used' => false,
                'warnings' => [],
                'context' => [
                    'boost' => false,
                    'retrieval_chunks' => 0,
                ],
            ];
        }

        $content = (string) File::get($resolvedPath);
        $displayContent = mb_substr($content, 0, 12000);
        $truncated = mb_strlen($content) > 12000;
        $relativePath = ltrim(str_replace(base_path(), '', $resolvedPath), '/');
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $language = $extension !== '' ? $extension : 'text';
        $recommendations = $this->recommendationsForFile($relativePath, $content);

        $reply = implode("\n", [
            "Here is `{$relativePath}`:",
            "```{$language}",
            $displayContent,
            '```',
            $truncated ? '_Output truncated at 12000 chars._' : '',
            '',
            'Recommended updates:',
            ...array_map(
                fn (int $index, string $item): string => ($index + 1).'. '.$item,
                array_keys($recommendations),
                $recommendations
            ),
        ]);

        return [
            'reply' => trim($reply),
            'model' => 'system:file-review-shortcut',
            'intent' => 'coding',
            'fallback_used' => false,
            'warnings' => [],
            'context' => [
                'boost' => false,
                'retrieval_chunks' => 0,
            ],
        ];
    }

    private function extractRequestedFilePath(string $message): ?string
    {
        if (preg_match(
            '/\b([\w.\-\/]+?\.(php|ts|tsx|js|jsx|json|md|yml|yaml|env|txt|blade\.php))\b/i',
            $message,
            $matches
        ) !== 1) {
            return null;
        }

        $path = trim((string) ($matches[1] ?? ''));

        if ($path === '') {
            return null;
        }

        return ltrim($path, './');
    }

    private function resolveRequestedFilePath(string $path): ?string
    {
        $candidates = [];

        if (str_contains($path, '/')) {
            $candidates[] = base_path($path);
        } else {
            $candidates[] = base_path($path);
            $candidates[] = base_path("routes/{$path}");
            $candidates[] = base_path("app/{$path}");
            $candidates[] = base_path("config/{$path}");
            $candidates[] = base_path("resources/{$path}");
            $candidates[] = base_path("database/{$path}");
            $candidates[] = base_path("tests/{$path}");
        }

        foreach ($candidates as $candidate) {
            if (File::exists($candidate) && ! File::isDirectory($candidate)) {
                $real = realpath($candidate);

                if (is_string($real) && $real !== '') {
                    return $real;
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function recommendationsForFile(string $relativePath, string $content): array
    {
        $recommendations = [];
        $trimmed = trim($content);

        if ($trimmed === '') {
            $recommendations[] = 'Add the expected implementation or remove the file if it is intentionally unused.';
            $recommendations[] = 'Document why this file exists to avoid confusion for future prompts.';

            return $recommendations;
        }

        if ($relativePath === 'routes/ai.php' || str_ends_with($relativePath, '/ai.php')) {
            if (preg_match('/^\s*\/\/\s*Mcp::web\(/m', $content) === 1
                && preg_match('/^\s*Mcp::web\(/m', $content) !== 1
            ) {
                $recommendations[] = 'Either uncomment and wire `Mcp::web(...)` with proper middleware, or remove this placeholder route file.';
            }

            $recommendations[] = 'If this route file is active, ensure it is loaded in bootstrap routing and protected with auth/authorization middleware.';
            $recommendations[] = 'Add a feature test that confirms the MCP route is reachable only for authorized users.';

            return $recommendations;
        }

        $recommendations[] = 'Add or update tests covering the behavior defined in this file.';
        $recommendations[] = 'Confirm imports/usages are minimal and remove dead code/comments to reduce prompt ambiguity.';
        $recommendations[] = 'If this file is part of runtime paths, add brief doc comments for non-obvious logic.';

        return $recommendations;
    }

    private function isReadOnlyFileRequest(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        $hasPathLikeTarget = preg_match(
            '/(?:^|[\s`"\'])[\w.\-\/]+?\.(php|ts|tsx|js|jsx|json|md|yml|yaml|env|txt|blade\.php)(?:$|[\s`"\'])/i',
            $message
        ) === 1;

        $hasStrongReadIntent = preg_match(
            '/\b(fetch|show|display|open|read|print|cat)\b[\s\S]{0,80}\b(file|code|contents?)\b/i',
            $normalized
        ) === 1;

        if (! $hasStrongReadIntent && ! $hasPathLikeTarget) {
            return false;
        }

        $directEditSignals = [
            'edit the file',
            'update the file',
            'modify the file',
            'change the file',
            'rewrite the file',
            'replace in the file',
            'apply changes',
            'make changes',
            'implement in',
            'fix in',
            'delete from',
            'remove from',
            'rename in',
        ];

        foreach ($directEditSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return false;
            }
        }

        return true;
    }

    private function shouldEnableWebSearchInstructions(string $message, string $intent): bool
    {
        if (! (bool) config('ai-assistant.tools.web_search.enabled', false)) {
            return false;
        }

        // Keep coding flows focused on local tools/files unless explicitly requested.
        if ($intent === 'coding') {
            return false;
        }

        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return false;
        }

        $webSignals = [
            'latest',
            'current',
            'today',
            'news',
            'price',
            'pricing',
            'compare',
            'official docs',
            'documentation',
            'search web',
            'look it up',
            'web search',
        ];

        foreach ($webSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
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
        $reply = 'Tell me which orders, merchants, deliveries, or office details you want to check.';

        if ($onChunk !== null) {
            $onChunk($reply);
        }

        return [
            'reply' => $reply,
            'model' => 'system:greeting-shortcut',
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
