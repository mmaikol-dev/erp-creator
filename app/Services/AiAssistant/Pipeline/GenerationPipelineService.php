<?php

namespace App\Services\AiAssistant\Pipeline;

use App\Models\AiConversation;
use App\Models\AiTaskRun;
use App\Models\User;
use App\Services\AiAssistant\Pipeline\IO\FileWriter;
use App\Services\AiAssistant\Pipeline\IO\RouteInserter;
use RuntimeException;
use Throwable;

class GenerationPipelineService
{
    public function __construct(
        private SpecCompiler $specCompiler,
        private CrudBlueprintGenerator $generator,
        private PipelineValidator $validator,
        private FileWriter $fileWriter,
        private RouteInserter $routeInserter,
    ) {
        //
    }

    public function shouldUsePipeline(string $goal): bool
    {
        if (! (bool) config('ai-assistant.pipeline.enabled', true)) {
            return false;
        }

        $normalized = mb_strtolower(trim($goal));

        if ($normalized === '') {
            return false;
        }

        $signals = [
            'crud',
            'module',
            'resource',
            'scaffold',
            'create page',
            'inertia',
            'laravel',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return mb_strlen($normalized) >= 120;
    }

    public function createPipelineRun(User $user, AiConversation $conversation, string $goal): AiTaskRun
    {
        if ($conversation->user_id !== $user->id) {
            throw new RuntimeException('Conversation does not belong to the authenticated user.');
        }

        $spec = $this->specCompiler->compileCrud($goal);

        $steps = [
            $this->makeStep('model_migration', 'Generate model and migration'),
            $this->makeStep('http_layer', 'Generate policy, form requests, controller, and routes'),
            $this->makeStep('inertia_pages', 'Generate Inertia CRUD pages'),
            $this->makeStep('factory_tests', 'Generate factory and feature tests'),
        ];

        return AiTaskRun::create([
            'ai_conversation_id' => $conversation->id,
            'goal' => trim($goal),
            'status' => PipelineStatus::Ready->value,
            'current_step_index' => 0,
            'plan' => $steps,
            'meta' => [
                'pipeline' => [
                    'enabled' => true,
                    'blueprint' => 'crud_resource',
                    'version' => 'v1',
                    'spec' => $spec->toLegacyArray(),
                    'resource' => $spec->modelClass,
                    'confident' => $spec->confident,
                    'warnings' => $spec->warnings,
                ],
            ],
        ]);
    }

    public function isPipelineRun(AiTaskRun $run): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $pipeline = is_array($meta['pipeline'] ?? null) ? $meta['pipeline'] : [];

        return (bool) ($pipeline['enabled'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewNextStep(User $user, AiTaskRun $run): array
    {
        $this->assertOwned($user, $run);
        $this->assertStatusAllowsPreview($run);

        $plan = $this->plan($run);
        $stepIndex = $this->nextPendingStepIndex($plan, (int) $run->current_step_index);

        if ($stepIndex === null) {
            $this->transitionRunStatus($run, PipelineStatus::Completed);
            $run->completed_at = now();
            $run->save();

            return [
                'run' => $this->serializeRun($run->fresh()),
                'preview' => null,
                'message' => 'All steps were already completed.',
            ];
        }

        $step = $plan[$stepIndex] ?? [];
        $stepKey = (string) ($step['key'] ?? '');
        if ($stepKey === '') {
            throw new RuntimeException('Pipeline step key is missing.');
        }

        $spec = $this->pipelineSpec($run);
        $artifacts = $this->generator->buildStepArtifacts($spec->toLegacyArray(), $stepKey);
        $changes = $this->buildChanges($artifacts, $spec, $stepKey);

        $lint = $this->validator->lintGeneratedChanges($changes);
        $nowIso = now()->toIso8601String();
        $attempt = max(0, (int) ($step['attempt_count'] ?? 0)) + 1;

        $plan[$stepIndex] = [
            ...$step,
            'status' => 'preview_ready',
            'attempt_count' => $attempt,
            'generated_at' => $nowIso,
            'changes' => $changes,
            'validation' => [
                'preview' => $lint,
            ],
        ];

        $run->plan = $plan;
        $this->transitionRunStatus($run, PipelineStatus::NeedsApproval);
        $run->current_step_index = $stepIndex;
        $run->save();

        return [
            'run' => $this->serializeRun($run->fresh()),
            'preview' => [
                'step_index' => $stepIndex,
                'step_key' => $stepKey,
                'step_title' => (string) ($step['title'] ?? "Step ".($stepIndex + 1)),
                'changes' => $changes,
                'validation' => $lint,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approveCurrentStep(User $user, AiTaskRun $run): array
    {
        $this->assertOwned($user, $run);
        $this->assertCurrentStatus($run, PipelineStatus::NeedsApproval);

        $plan = $this->plan($run);
        $stepIndex = (int) $run->current_step_index;
        $step = $plan[$stepIndex] ?? null;

        if (! is_array($step) || (string) ($step['status'] ?? '') !== 'preview_ready') {
            throw new RuntimeException('No preview-ready step available to approve.');
        }

        /** @var list<array<string, mixed>> $changes */
        $changes = is_array($step['changes'] ?? null) ? $step['changes'] : [];
        $snapshots = $this->captureSnapshots($changes);
        $writeResult = $this->applyChanges($changes);

        if (! (bool) ($writeResult['success'] ?? false)) {
            $plan[$stepIndex] = [
                ...$step,
                'status' => 'failed',
                'validation' => [
                    ...((array) ($step['validation'] ?? [])),
                    'post_apply' => [
                        'ok' => false,
                        'commands' => [],
                        'changed_paths' => array_values(array_map(
                            static fn (array $change): string => (string) ($change['path'] ?? ''),
                            $changes
                        )),
                        'error' => (string) ($writeResult['error'] ?? 'Unknown write failure.'),
                    ],
                ],
                'applied' => false,
            ];

            $run->plan = $plan;
            $this->transitionRunStatus($run, PipelineStatus::Paused);
            $run->paused_at = now();
            $run->save();

            return [
                'run' => $this->serializeRun($run->fresh()),
                'applied' => false,
                'rolled_back' => true,
                'validation' => $plan[$stepIndex]['validation']['post_apply'],
                'write' => $writeResult,
            ];
        }

        $postValidation = $this->validator->validateAppliedStep($changes);

        if (! (bool) ($postValidation['ok'] ?? false)) {
            $this->restoreSnapshots($snapshots);

            $plan[$stepIndex] = [
                ...$step,
                'status' => 'failed',
                'validation' => [
                    ...((array) ($step['validation'] ?? [])),
                    'post_apply' => $postValidation,
                ],
                'applied' => false,
            ];

            $run->plan = $plan;
            $this->transitionRunStatus($run, PipelineStatus::Paused);
            $run->paused_at = now();
            $run->save();

            return [
                'run' => $this->serializeRun($run->fresh()),
                'applied' => false,
                'rolled_back' => true,
                'validation' => $postValidation,
                'write' => $writeResult,
            ];
        }

        $plan[$stepIndex] = [
            ...$step,
            'status' => 'completed',
            'applied' => true,
            'applied_at' => now()->toIso8601String(),
            'snapshots' => $snapshots,
            'validation' => [
                ...((array) ($step['validation'] ?? [])),
                'post_apply' => $postValidation,
            ],
        ];

        $next = $this->nextPendingStepIndex($plan, $stepIndex + 1);
        $run->plan = $plan;

        if ($next === null) {
            $this->transitionRunStatus($run, PipelineStatus::Completed);
            $run->completed_at = now();
        } else {
            $this->transitionRunStatus($run, PipelineStatus::Ready);
            $run->current_step_index = $next;
        }

        $run->save();

        return [
            'run' => $this->serializeRun($run->fresh()),
            'applied' => true,
            'rolled_back' => false,
            'validation' => $postValidation,
            'write' => $writeResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryCurrentStep(User $user, AiTaskRun $run): array
    {
        $this->assertOwned($user, $run);
        $this->assertStatusCanTransitionToReady($run);

        $plan = $this->plan($run);
        $index = (int) $run->current_step_index;

        if (! isset($plan[$index]) || ! is_array($plan[$index])) {
            throw new RuntimeException('No current step available to retry.');
        }

        $plan[$index] = [
            ...$plan[$index],
            'status' => 'pending',
            'changes' => [],
            'validation' => [],
        ];

        $run->plan = $plan;
        // Keep retry explicit: this step is now the active pending step.
        $run->current_step_index = $index;
        $this->transitionRunStatus($run, PipelineStatus::Ready);
        $run->save();

        return [
            'run' => $this->serializeRun($run->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function skipCurrentStep(User $user, AiTaskRun $run): array
    {
        $this->assertOwned($user, $run);
        $this->assertStatusCanTransitionToReady($run);

        $plan = $this->plan($run);
        $index = (int) $run->current_step_index;

        if (! isset($plan[$index]) || ! is_array($plan[$index])) {
            throw new RuntimeException('No current step available to skip.');
        }

        $plan[$index] = [
            ...$plan[$index],
            'status' => 'skipped',
            'skipped_at' => now()->toIso8601String(),
        ];

        $next = $this->nextPendingStepIndex($plan, $index + 1);

        $run->plan = $plan;
        if ($next === null) {
            $this->transitionRunStatus($run, PipelineStatus::Completed);
            $run->completed_at = now();
        } else {
            $this->transitionRunStatus($run, PipelineStatus::Ready);
            $run->current_step_index = $next;
        }

        $run->save();

        return [
            'run' => $this->serializeRun($run->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(AiTaskRun $run): array
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = is_array($run->plan) ? $run->plan : [];
        $meta = is_array($run->meta) ? $run->meta : [];
        $pipeline = is_array($meta['pipeline'] ?? null) ? $meta['pipeline'] : [];

        return [
            'id' => $run->id,
            'conversation_id' => $run->ai_conversation_id,
            'goal' => $run->goal,
            'status' => $run->status,
            'current_step_index' => (int) $run->current_step_index,
            'plan' => $plan,
            'pipeline' => [
                'enabled' => (bool) ($pipeline['enabled'] ?? false),
                'blueprint' => $pipeline['blueprint'] ?? null,
                'version' => $pipeline['version'] ?? null,
                'resource' => $pipeline['resource'] ?? null,
                'spec' => $pipeline['spec'] ?? null,
            ],
            'paused_at' => $run->paused_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeStep(string $key, string $title): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'status' => 'pending',
            'attempt_count' => 0,
            'changes' => [],
            'validation' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function nextPendingStepIndex(array $plan, int $startAt): ?int
    {
        for ($index = max(0, $startAt); $index < count($plan); $index++) {
            $status = (string) ($plan[$index]['status'] ?? 'pending');
            if (! in_array($status, ['completed', 'skipped'], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return list<array<string, mixed>>
     */
    private function buildChanges(array $artifacts, CompiledSpec $spec, string $stepKey): array
    {
        $changes = [];

        foreach ($artifacts as $artifact) {
            $path = trim((string) ($artifact['path'] ?? ''));
            if ($path === '') {
                throw new RuntimeException(
                    'Generator returned an artifact with an empty path for step "'.$stepKey.'".'
                );
            }

            $kind = (string) ($artifact['kind'] ?? 'file');
            $content = (string) ($artifact['content'] ?? '');
            $absolutePath = base_path($path);
            $exists = $this->fileWriter->exists($absolutePath);
            $before = $exists ? $this->fileWriter->read($absolutePath) : '';

            if ($kind === 'route_resource') {
                $content = $this->routeInserter->insert($before, $spec);
            }

            $changes[] = [
                'path' => $path,
                'absolute_path' => $absolutePath,
                'exists' => $exists,
                'before' => $before,
                'after' => $content,
            ];
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function captureSnapshots(array $changes): array
    {
        return array_map(static function (array $change): array {
            return [
                'path' => $change['path'] ?? '',
                'exists' => (bool) ($change['exists'] ?? false),
                'before' => $change['before'] ?? '',
            ];
        }, $changes);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    private function applyChanges(array $changes): array
    {
        $written = [];
        $snapshots = $this->captureSnapshots($changes);

        try {
            foreach ($changes as $change) {
                $path = (string) ($change['absolute_path'] ?? '');
                $after = (string) ($change['after'] ?? '');

                if ($path === '') {
                    throw new RuntimeException('Change entry has an empty absolute_path.');
                }

                $this->fileWriter->write($path, $after);
                $written[] = $change['path'] ?? '';
            }

            return [
                'success' => true,
                'error' => null,
                'written_paths' => $written,
                'count' => count($written),
            ];
        } catch (Throwable $e) {
            $this->restoreSnapshots($snapshots);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'written_paths' => $written,
                'count' => count($written),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    private function restoreSnapshots(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $path = trim((string) ($snapshot['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $absolute = base_path($path);
            $exists = (bool) ($snapshot['exists'] ?? false);

            if (! $exists) {
                if ($this->fileWriter->exists($absolute)) {
                    $this->fileWriter->delete($absolute);
                }
                continue;
            }

            $this->fileWriter->write($absolute, (string) ($snapshot['before'] ?? ''));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plan(AiTaskRun $run): array
    {
        /** @var list<array<string, mixed>> $plan */
        $plan = is_array($run->plan) ? $run->plan : [];

        return $plan;
    }

    /**
     * @return CompiledSpec
     */
    private function pipelineSpec(AiTaskRun $run): CompiledSpec
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $pipeline = is_array($meta['pipeline'] ?? null) ? $meta['pipeline'] : [];

        /** @var array<string, mixed> $spec */
        $spec = is_array($pipeline['spec'] ?? null) ? $pipeline['spec'] : [];

        return CompiledSpec::fromLegacyArray($spec);
    }

    private function assertOwned(User $user, AiTaskRun $run): void
    {
        $run->loadMissing('conversation');
        $conversation = $run->conversation;

        if ($conversation === null) {
            throw new RuntimeException('Task run has no associated conversation.');
        }

        if ($conversation->user_id !== $user->id) {
            throw new RuntimeException('Task run is not accessible.');
        }
    }

    private function statusFrom(AiTaskRun $run): PipelineStatus
    {
        return PipelineStatus::tryFrom((string) $run->status)
            ?? throw new RuntimeException("Unsupported pipeline status '{$run->status}'.");
    }

    private function transitionRunStatus(AiTaskRun $run, PipelineStatus $target): void
    {
        $current = $this->statusFrom($run);

        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                "Cannot transition from '{$current->value}' to '{$target->value}'."
            );
        }

        $run->status = $target->value;
    }

    private function assertCurrentStatus(AiTaskRun $run, PipelineStatus $expected): void
    {
        $current = $this->statusFrom($run);

        if ($current !== $expected) {
            throw new RuntimeException(
                "Action not allowed while run status is '{$current->value}'. Expected '{$expected->value}'."
            );
        }
    }

    private function assertStatusAllowsPreview(AiTaskRun $run): void
    {
        $current = $this->statusFrom($run);

        if ($current === PipelineStatus::Paused) {
            throw new RuntimeException('Task run is paused. Resume it before generating the next step.');
        }

        if ($current === PipelineStatus::Completed) {
            throw new RuntimeException('Task run is already completed.');
        }

        if (! in_array($current, [PipelineStatus::Ready, PipelineStatus::NeedsApproval], true)) {
            throw new RuntimeException("Cannot preview next step while status is '{$current->value}'.");
        }
    }

    private function assertStatusCanTransitionToReady(AiTaskRun $run): void
    {
        $current = $this->statusFrom($run);

        if (! $current->canTransitionTo(PipelineStatus::Ready) && $current !== PipelineStatus::Ready) {
            throw new RuntimeException("Cannot transition from '{$current->value}' to 'ready'.");
        }
    }
}
