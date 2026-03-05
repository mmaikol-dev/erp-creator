<?php

namespace App\Services\AiAssistant;

use App\Models\AiConversation;
use App\Models\AiTaskRun;
use App\Models\User;
use RuntimeException;
use Throwable;

class TaskRunService
{
    public function __construct(
        private AiAssistantService $assistant,
        private ConversationMemoryService $memory,
    ) {
        //
    }

    public function createRun(User $user, AiConversation $conversation, string $goal): AiTaskRun
    {
        if ($conversation->user_id !== $user->id) {
            throw new RuntimeException('Conversation does not belong to the authenticated user.');
        }

        $trimmedGoal = trim($goal);
        if ($trimmedGoal === '') {
            throw new RuntimeException('Task goal cannot be empty.');
        }

        $planningPrompt = implode("\n", [
            'Create an execution plan for this task.',
            'Return concise numbered steps with short acceptance criteria per step.',
            'Task goal:',
            $trimmedGoal,
        ]);

        $history = $this->memory->chatHistory($conversation, 20);
        $result = $this->assistant->respond(
            message: $planningPrompt,
            history: $history,
            mode: 'planning',
            memorySnippets: [],
        );

        $planText = trim((string) ($result['reply'] ?? ''));
        $steps = $this->extractSteps($planText);

        if ($steps === []) {
            $steps = $this->fallbackSteps($trimmedGoal);
        }

        return AiTaskRun::create([
            'ai_conversation_id' => $conversation->id,
            'goal' => $trimmedGoal,
            'status' => 'ready',
            'current_step_index' => 0,
            'plan' => $steps,
            'meta' => [
                'planner_model' => $result['model'] ?? null,
                'planner_reply' => $planText,
                'review_enabled' => true,
            ],
        ]);
    }

    public function shouldAutoOrchestrate(string $mode, string $message): bool
    {
        if ($mode !== 'deep') {
            return false;
        }

        if (! (bool) config('ai-assistant.task_runs.autonomous_enabled', true)) {
            return false;
        }

        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        $signals = [
            'dashboard',
            'multiple pages',
            'multi-page',
            'full module',
            'end to end',
            'step by step',
            'full crud',
            'scaffold',
            'implement complete',
            'build complete',
            'routes controller pages',
            'backend and frontend',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return mb_strlen($normalized) >= 220;
    }

    /**
     * @param  callable(array<string, mixed>): void|null  $onProgress
     * @return array{
     *   run: array<string, mixed>,
     *   reply: string,
     *   model: string,
     *   warnings: list<string>
     * }
     */
    public function runAutonomously(
        User $user,
        AiConversation $conversation,
        string $goal,
        ?callable $onProgress = null,
    ): array {
        $run = $this->createRun($user, $conversation, $goal);
        $warnings = [];
        $lastExecutionReply = '';
        $maxSteps = max(1, (int) config('ai-assistant.task_runs.max_autonomous_steps', 10));
        $stepsExecuted = 0;

        if ($onProgress !== null) {
            $onProgress([
                'phase' => 'run_created',
                'agent' => 'planner',
                'run_id' => $run->id,
                'status' => $run->status,
                'current_step_index' => $run->current_step_index,
            ]);
        }

        while ($stepsExecuted < $maxSteps) {
            $run->refresh();

            if (in_array($run->status, ['completed', 'paused'], true)) {
                break;
            }

            if (! in_array($run->status, ['ready', 'needs_review_fix', 'running'], true)) {
                break;
            }

            $stepIndex = (int) $run->current_step_index;
            $stepTitle = is_array($run->plan) && isset($run->plan[$stepIndex]['title'])
                ? (string) $run->plan[$stepIndex]['title']
                : 'Untitled step';
            $stepAttempt = is_array($run->plan) && isset($run->plan[$stepIndex]['attempt_count'])
                ? max(0, (int) $run->plan[$stepIndex]['attempt_count']) + 1
                : 1;

            if ($onProgress !== null) {
                $onProgress([
                    'phase' => 'step_started',
                    'agent' => 'executor',
                    'run_id' => $run->id,
                    'step_index' => $stepIndex,
                    'step_number' => $stepIndex + 1,
                    'step_title' => $stepTitle,
                    'attempt' => $stepAttempt,
                ]);
            }

            $result = $this->runNextStep($user, $run);
            $stepsExecuted++;

            $executionReply = trim((string) data_get($result, 'execution.reply', ''));
            if ($executionReply !== '') {
                $lastExecutionReply = $executionReply;
            }

            $reviewResult = (string) data_get($result, 'review.result', 'partial');
            $reviewSummary = (string) data_get($result, 'review.summary', '');
            $reviewAttempt = max(1, (int) data_get(
                $result,
                "run.plan.{$stepIndex}.attempt_count",
                $stepAttempt
            ));

            if ($onProgress !== null) {
                $onProgress([
                    'phase' => 'step_reviewed',
                    'agent' => 'reviewer',
                    'run_id' => $run->id,
                    'step_index' => $stepIndex,
                    'step_number' => $stepIndex + 1,
                    'attempt' => $reviewAttempt,
                    'review_result' => $reviewResult,
                    'review_summary' => $reviewSummary,
                ]);
            }

            if ($reviewResult !== 'pass') {
                $warnings[] = "Step ".($stepIndex + 1)." review: {$reviewResult}.";
            }
        }

        $run->refresh();

        if (! in_array($run->status, ['completed', 'paused'], true) && $stepsExecuted >= $maxSteps) {
            $warnings[] = "Autonomous step budget reached ({$maxSteps}).";
            $run->status = 'paused';
            $run->paused_at = now();
            $run->save();
            $run->refresh();
        }

        if ($onProgress !== null) {
            $onProgress([
                'phase' => 'run_completed',
                'agent' => 'orchestrator',
                'run_id' => $run->id,
                'status' => $run->status,
                'steps_executed' => $stepsExecuted,
            ]);
        }

        $summary = $this->buildAutonomousSummary($run, $lastExecutionReply);

        return [
            'run' => $this->serializeRun($run),
            'reply' => $summary,
            'model' => 'system:autonomous-task-runner',
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{run: array<string, mixed>, execution: array<string, mixed>, review: array<string, mixed>}
     */
    public function runNextStep(User $user, AiTaskRun $run): array
    {
        $run->loadMissing('conversation');
        $conversation = $run->conversation;

        if (! $conversation instanceof AiConversation || $conversation->user_id !== $user->id) {
            throw new RuntimeException('Task run is not accessible.');
        }

        if ($run->status === 'paused') {
            throw new RuntimeException('Task run is paused. Resume it before running the next step.');
        }

        if ($run->status === 'completed') {
            return [
                'run' => $this->serializeRun($run),
                'execution' => ['skipped' => true, 'reason' => 'already_completed'],
                'review' => ['result' => 'pass', 'summary' => 'Task run is already complete.', 'missing' => []],
            ];
        }

        /** @var list<array<string, mixed>> $plan */
        $plan = is_array($run->plan) ? $run->plan : [];
        $stepIndex = $this->nextStepIndex($plan, (int) $run->current_step_index);

        if ($stepIndex === null) {
            $run->status = 'completed';
            $run->completed_at = now();
            $run->save();

            return [
                'run' => $this->serializeRun($run->fresh()),
                'execution' => ['skipped' => true, 'reason' => 'no_remaining_steps'],
                'review' => ['result' => 'pass', 'summary' => 'All steps were already completed.', 'missing' => []],
            ];
        }

        $step = $plan[$stepIndex] ?? [];
        $stepTitle = trim((string) ($step['title'] ?? "Step ".($stepIndex + 1)));
        $criteria = $this->normalizeCriteria($step['criteria'] ?? []);
        $attemptCount = max(0, (int) ($step['attempt_count'] ?? 0)) + 1;
        $stepMode = $this->resolveStepExecutionMode($stepTitle);
        $maxAttemptsPerStep = max(1, (int) config('ai-assistant.task_runs.max_attempts_per_step', 3));

        $run->status = 'running';
        $run->current_step_index = $stepIndex;
        $run->save();

        $executePrompt = $this->buildExecutionPrompt(
            goal: (string) $run->goal,
            stepNumber: $stepIndex + 1,
            stepTitle: $stepTitle,
            criteria: $criteria,
        );

        $history = $this->memory->chatHistory($conversation, 20);
        $execution = $this->assistant->respond(
            message: $executePrompt,
            history: $history,
            mode: $stepMode,
            memorySnippets: [],
        );

        $executionReply = trim((string) ($execution['reply'] ?? ''));

        $this->memory->storeMessage(
            conversation: $conversation,
            role: 'user',
            content: "[TaskRun #{$run->id}] {$executePrompt}",
            mode: $stepMode,
            stage: 'task_step',
        );
        $this->memory->storeMessage(
            conversation: $conversation,
            role: 'assistant',
            content: $executionReply,
            model: is_string($execution['model'] ?? null) ? $execution['model'] : null,
            mode: $stepMode,
            stage: 'task_step',
        );

        $review = $this->reviewStep(
            goal: (string) $run->goal,
            stepTitle: $stepTitle,
            criteria: $criteria,
            executionReply: $executionReply,
            conversation: $conversation,
        );

        $nowIso = now()->toIso8601String();
        $plan[$stepIndex] = [
            ...$step,
            'title' => $stepTitle,
            'criteria' => $criteria,
            'attempt_count' => $attemptCount,
            'status' => $review['result'] === 'pass'
                ? 'completed'
                : ($attemptCount >= $maxAttemptsPerStep ? 'failed' : 'needs_fix'),
            'review' => $review,
            'last_execution' => [
                'model' => $execution['model'] ?? null,
                'fallback_used' => (bool) ($execution['fallback_used'] ?? false),
                'mode' => $stepMode,
                'at' => $nowIso,
            ],
        ];

        $run->plan = $plan;

        if ($review['result'] === 'pass') {
            $next = $this->nextStepIndex($plan, $stepIndex + 1);
            if ($next === null) {
                $run->status = 'completed';
                $run->completed_at = now();
            } else {
                $run->status = 'ready';
                $run->current_step_index = $next;
            }
        } elseif ($attemptCount >= $maxAttemptsPerStep) {
            $run->status = 'paused';
            $run->paused_at = now();

            $meta = is_array($run->meta) ? $run->meta : [];
            $meta['last_failure'] = [
                'step_index' => $stepIndex,
                'step_title' => $stepTitle,
                'attempt_count' => $attemptCount,
                'max_attempts_per_step' => $maxAttemptsPerStep,
                'review_result' => $review['result'],
                'review_summary' => $review['summary'] ?? null,
                'at' => $nowIso,
            ];
            $run->meta = $meta;
        } else {
            $run->status = 'needs_review_fix';
            $run->current_step_index = $stepIndex;
        }

        $run->save();

        return [
            'run' => $this->serializeRun($run->fresh()),
            'execution' => [
                'model' => $execution['model'] ?? null,
                'fallback_used' => (bool) ($execution['fallback_used'] ?? false),
                'warnings' => $execution['warnings'] ?? [],
                'reply' => $executionReply,
                'mode' => $stepMode,
                'attempt' => $attemptCount,
            ],
            'review' => $review,
        ];
    }

    public function pause(User $user, AiTaskRun $run): AiTaskRun
    {
        $this->assertOwned($user, $run);

        $run->status = 'paused';
        $run->paused_at = now();
        $run->save();

        return $run;
    }

    public function resume(User $user, AiTaskRun $run): AiTaskRun
    {
        $this->assertOwned($user, $run);

        if ($run->status === 'completed') {
            return $run;
        }

        $run->status = 'ready';
        $run->paused_at = null;
        $run->save();

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(AiTaskRun $run): array
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = is_array($run->plan) ? $run->plan : [];

        return [
            'id' => $run->id,
            'conversation_id' => $run->ai_conversation_id,
            'goal' => $run->goal,
            'status' => $run->status,
            'current_step_index' => (int) $run->current_step_index,
            'plan' => $plan,
            'paused_at' => $run->paused_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function nextStepIndex(array $plan, int $startAt = 0): ?int
    {
        for ($index = max(0, $startAt); $index < count($plan); $index++) {
            $status = (string) ($plan[$index]['status'] ?? 'pending');
            if (! in_array($status, ['completed'], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractSteps(string $planText): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $planText) ?: [];
        $steps = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^(\d+)[\.\)]\s+(.+)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $title = trim((string) ($matches[2] ?? ''));
            if ($title === '') {
                continue;
            }

            $steps[] = [
                'title' => $title,
                'criteria' => [
                    'Step output aligns with requested goal segment.',
                    'No unresolved blockers remain for this step.',
                ],
                'status' => 'pending',
            ];
        }

        return array_slice($steps, 0, 12);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fallbackSteps(string $goal): array
    {
        return [
            [
                'title' => "Scope and structure work for: {$goal}",
                'criteria' => ['Scope is concrete and implementable.'],
                'status' => 'pending',
            ],
            [
                'title' => 'Implement core backend and routing changes',
                'criteria' => ['Routes/controllers and backend logic are wired correctly.'],
                'status' => 'pending',
            ],
            [
                'title' => 'Implement frontend updates and validate',
                'criteria' => ['UI wired and TypeScript check passes.'],
                'status' => 'pending',
            ],
        ];
    }

    /**
     * @param  list<string>  $criteria
     */
    private function buildExecutionPrompt(
        string $goal,
        int $stepNumber,
        string $stepTitle,
        array $criteria,
    ): string {
        $criteriaText = $criteria === []
            ? '- Finish this step with concrete code changes.'
            : implode("\n", array_map(static fn (string $item): string => "- {$item}", $criteria));

        return implode("\n", [
            "Task goal: {$goal}",
            "Current step {$stepNumber}: {$stepTitle}",
            'Acceptance criteria for this step:',
            $criteriaText,
            'Execute only this step now. Use tools as needed.',
            'When done, provide a concise summary and changed files for this step only.',
        ]);
    }

    /**
     * @param  list<string>  $criteria
     * @return array{result: string, summary: string, missing: list<string>}
     */
    private function reviewStep(
        string $goal,
        string $stepTitle,
        array $criteria,
        string $executionReply,
        AiConversation $conversation,
    ): array {
        $criteriaText = $criteria === []
            ? '- No explicit criteria provided.'
            : implode("\n", array_map(static fn (string $item): string => "- {$item}", $criteria));

        $reviewPrompt = implode("\n", [
            'Review the following completed step and assess if it is on track.',
            "Goal: {$goal}",
            "Step: {$stepTitle}",
            'Criteria:',
            $criteriaText,
            'Execution output:',
            $executionReply,
            'Return ONLY strict JSON in this shape:',
            '{"result":"pass|partial|fail","summary":"...","missing":["..."]}',
        ]);

        try {
            $reviewResult = $this->assistant->respond(
                message: $reviewPrompt,
                history: $this->memory->chatHistory($conversation, 12),
                mode: 'planning',
                memorySnippets: [],
            );

            $reviewReply = trim((string) ($reviewResult['reply'] ?? ''));
            $decoded = json_decode($reviewReply, true);

            if (! is_array($decoded)) {
                return $this->heuristicReview($executionReply, 'Review parser fallback was used.');
            }

            $result = strtolower(trim((string) ($decoded['result'] ?? 'partial')));
            if (! in_array($result, ['pass', 'partial', 'fail'], true)) {
                $result = 'partial';
            }

            $summary = trim((string) ($decoded['summary'] ?? 'No review summary provided.'));
            /** @var list<string> $missing */
            $missing = array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                is_array($decoded['missing'] ?? null) ? $decoded['missing'] : []
            ), static fn (string $item): bool => $item !== ''));

            return [
                'result' => $result,
                'summary' => $summary === '' ? 'No review summary provided.' : $summary,
                'missing' => $missing,
            ];
        } catch (Throwable) {
            return $this->heuristicReview($executionReply, 'Review model call failed; heuristic review used.');
        }
    }

    /**
     * @return array{result: string, summary: string, missing: list<string>}
     */
    private function heuristicReview(string $executionReply, string $summary): array
    {
        $normalized = mb_strtolower(trim($executionReply));
        $failedSignals = [
            'could not complete',
            'exceeded the safety limit',
            'internal formatting issue',
            'temporarily unavailable',
            'timed out',
        ];

        foreach ($failedSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return [
                    'result' => 'fail',
                    'summary' => $summary,
                    'missing' => ['Execution output indicates an incomplete/failed step.'],
                ];
            }
        }

        return [
            'result' => 'pass',
            'summary' => $summary,
            'missing' => [],
        ];
    }

    private function resolveStepExecutionMode(string $stepTitle): string
    {
        $normalized = mb_strtolower(trim($stepTitle));
        $planningSignals = [
            'scope',
            'plan',
            'structure',
            'analy',
            'discover',
        ];

        foreach ($planningSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return 'planning';
            }
        }

        return 'coding';
    }

    /**
     * @param  mixed  $criteria
     * @return list<string>
     */
    private function normalizeCriteria(mixed $criteria): array
    {
        if (! is_array($criteria)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $criteria
        ), static fn (string $item): bool => $item !== ''));
    }

    private function assertOwned(User $user, AiTaskRun $run): void
    {
        $run->loadMissing('conversation');
        $conversation = $run->conversation;

        if (! $conversation instanceof AiConversation || $conversation->user_id !== $user->id) {
            throw new RuntimeException('Task run is not accessible.');
        }
    }

    private function buildAutonomousSummary(AiTaskRun $run, string $lastExecutionReply): string
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = is_array($run->plan) ? $run->plan : [];
        $lines = [];

        $lines[] = "Autonomous run #{$run->id} status: {$run->status}.";
        $lines[] = 'Step review summary:';

        foreach ($plan as $index => $step) {
            $title = trim((string) ($step['title'] ?? "Step ".($index + 1)));
            $status = trim((string) ($step['status'] ?? 'pending'));
            $review = trim((string) data_get($step, 'review.result', 'n/a'));
            $lines[] = ($index + 1).". {$title} [{$status}] review={$review}";
        }

        if (trim($lastExecutionReply) !== '') {
            $lines[] = '';
            $lines[] = 'Latest execution output:';
            $lines[] = $lastExecutionReply;
        }

        return implode("\n", $lines);
    }
}
