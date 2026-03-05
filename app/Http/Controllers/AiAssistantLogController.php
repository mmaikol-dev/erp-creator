<?php

namespace App\Http\Controllers;

use App\Models\AiMessage;
use App\Models\AiTaskRun;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class AiAssistantLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $entries = $this->loadEntries();
        $runs = $this->buildRuns($entries);
        $runs = $this->enrichRuns($runs);

        return Inertia::render('ai-assistant-logs', [
            'runs' => array_slice(array_reverse($runs), 0, 80),
            'entries' => array_slice(array_reverse($entries), 0, 250),
            'logPath' => storage_path('logs/laravel.log'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEntries(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            return [];
        }

        $content = (string) File::get($path);
        $maxTailBytes = 2_000_000;
        if (strlen($content) > $maxTailBytes) {
            $content = substr($content, -$maxTailBytes);
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '' || ! str_contains($trimmed, 'ai-assistant.chat.')) {
                continue;
            }

            if (preg_match(
                '/^\[(?<time>[^\]]+)\]\s+(?<channel>[^:]+):\s+(?<event>ai-assistant\.chat\.(?:request|success|failed))\s*(?<json>\{.*\})?\s*$/',
                $trimmed,
                $matches
            ) !== 1) {
                continue;
            }

            $payload = [];
            $rawJson = isset($matches['json']) ? trim((string) $matches['json']) : '';
            if ($rawJson !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $entries[] = [
                'time' => (string) ($matches['time'] ?? ''),
                'channel' => (string) ($matches['channel'] ?? ''),
                'event' => (string) ($matches['event'] ?? ''),
                'payload' => $payload,
                'raw' => $trimmed,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function buildRuns(array $entries): array
    {
        $runs = [];
        /** @var array<string, list<int>> $openByConversation */
        $openByConversation = [];

        foreach ($entries as $entry) {
            $event = (string) ($entry['event'] ?? '');
            /** @var array<string, mixed> $payload */
            $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            $conversationId = isset($payload['conversation_id']) ? (string) $payload['conversation_id'] : 'unknown';
            $mode = isset($payload['mode']) ? (string) $payload['mode'] : null;
            $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

            if ($event === 'ai-assistant.chat.request') {
                $runs[] = [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'mode' => $mode,
                    'status' => 'running',
                    'started_at' => (string) ($entry['time'] ?? ''),
                    'ended_at' => null,
                    'duration_ms' => null,
                    'model' => null,
                    'intent' => null,
                    'fallback_used' => null,
                    'stream' => isset($payload['stream']) ? (bool) $payload['stream'] : null,
                    'events' => [$entry],
                ];
                $index = count($runs) - 1;
                $openByConversation[$conversationId] ??= [];
                $openByConversation[$conversationId][] = $index;

                continue;
            }

            if ($event !== 'ai-assistant.chat.success' && $event !== 'ai-assistant.chat.failed') {
                continue;
            }

            $index = null;
            if (isset($openByConversation[$conversationId]) && $openByConversation[$conversationId] !== []) {
                $index = array_pop($openByConversation[$conversationId]);
            }

            if (! is_int($index)) {
                $runs[] = [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'mode' => $mode,
                    'status' => $event === 'ai-assistant.chat.success' ? 'success' : 'failed',
                    'started_at' => (string) ($entry['time'] ?? ''),
                    'ended_at' => (string) ($entry['time'] ?? ''),
                    'duration_ms' => isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null,
                    'model' => isset($payload['model']) ? (string) $payload['model'] : null,
                    'intent' => isset($payload['intent']) ? (string) $payload['intent'] : null,
                    'fallback_used' => isset($payload['fallback_used']) ? (bool) $payload['fallback_used'] : null,
                    'stream' => isset($payload['stream']) ? (bool) $payload['stream'] : null,
                    'events' => [$entry],
                ];

                continue;
            }

            $runs[$index]['status'] = $event === 'ai-assistant.chat.success' ? 'success' : 'failed';
            $runs[$index]['ended_at'] = (string) ($entry['time'] ?? '');
            $runs[$index]['duration_ms'] = isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null;
            $runs[$index]['model'] = isset($payload['model']) ? (string) $payload['model'] : null;
            $runs[$index]['intent'] = isset($payload['intent']) ? (string) $payload['intent'] : null;
            $runs[$index]['fallback_used'] = isset($payload['fallback_used']) ? (bool) $payload['fallback_used'] : null;
            $runs[$index]['stream'] = isset($payload['stream']) ? (bool) $payload['stream'] : ($runs[$index]['stream'] ?? null);
            $runs[$index]['events'][] = $entry;
        }

        return $runs;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function enrichRuns(array $runs): array
    {
        foreach ($runs as $index => $run) {
            $conversationId = isset($run['conversation_id'])
                ? (int) $run['conversation_id']
                : 0;
            if ($conversationId <= 0) {
                continue;
            }

            $startedAt = $this->parseLogTime(is_string($run['started_at'] ?? null) ? $run['started_at'] : null);
            $endedAt = $this->parseLogTime(is_string($run['ended_at'] ?? null) ? $run['ended_at'] : null);
            if ($endedAt === null && $startedAt !== null) {
                $endedAt = (clone $startedAt)->addMinutes(15);
            }

            $assistantMessage = null;
            if ($startedAt !== null && $endedAt !== null) {
                $assistantMessage = AiMessage::query()
                    ->where('ai_conversation_id', $conversationId)
                    ->where('role', 'assistant')
                    ->whereBetween('created_at', [
                        (clone $startedAt)->subSeconds(5),
                        (clone $endedAt)->addSeconds(45),
                    ])
                    ->orderByDesc('id')
                    ->first();
            }

            if ($assistantMessage === null) {
                $assistantMessage = AiMessage::query()
                    ->where('ai_conversation_id', $conversationId)
                    ->where('role', 'assistant')
                    ->orderByDesc('id')
                    ->first();
            }

            $runs[$index]['final_response'] = $assistantMessage?->content;
            $runs[$index]['final_response_preview'] = $assistantMessage !== null
                ? mb_substr(trim((string) $assistantMessage->content), 0, 260)
                : null;

            $runs[$index]['failure_reason'] = $this->extractFailureReason($run);

            $taskRun = null;
            if ($startedAt !== null && $endedAt !== null) {
                $taskRun = AiTaskRun::query()
                    ->where('ai_conversation_id', $conversationId)
                    ->whereBetween('created_at', [
                        (clone $startedAt)->subMinutes(1),
                        (clone $endedAt)->addMinutes(2),
                    ])
                    ->orderByDesc('id')
                    ->first();
            }

            if ($taskRun === null) {
                $taskRun = AiTaskRun::query()
                    ->where('ai_conversation_id', $conversationId)
                    ->orderByDesc('id')
                    ->first();
            }

            $runs[$index]['task_run'] = $taskRun === null
                ? null
                : [
                    'id' => $taskRun->id,
                    'status' => $taskRun->status,
                    'goal' => $taskRun->goal,
                    'current_step_index' => $taskRun->current_step_index,
                    'plan' => is_array($taskRun->plan) ? $taskRun->plan : [],
                ];
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function extractFailureReason(array $run): ?string
    {
        if (($run['status'] ?? null) !== 'failed') {
            return null;
        }

        $events = is_array($run['events'] ?? null) ? $run['events'] : [];
        foreach (array_reverse($events) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $reason = isset($payload['error']) ? trim((string) $payload['error']) : '';
            if ($reason !== '') {
                return $reason;
            }
        }

        return 'Unknown failure reason.';
    }

    private function parseLogTime(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
