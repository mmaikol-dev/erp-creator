<?php

namespace App\Services\AiAssistant;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class ConversationMemoryService
{
    public function __construct(private OllamaClient $ollama)
    {
        //
    }

    public function getOrCreateConversation(User $user, ?int $conversationId = null): AiConversation
    {
        if ($conversationId !== null) {
            $conversation = AiConversation::query()
                ->where('user_id', $user->id)
                ->find($conversationId);

            if ($conversation !== null) {
                return $conversation;
            }
        }

        $latest = AiConversation::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($latest !== null) {
            return $latest;
        }

        return $this->createConversation($user);
    }

    public function createConversation(User $user, ?string $title = null): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'title' => $title ?? 'RealDeal Assistant',
        ]);
    }

    /**
     * @return list<array{id: int, title: ?string, preview: string, updated_at: string, message_count: int}>
     */
    public function listConversations(User $user, int $limit = 30): array
    {
        $conversations = AiConversation::query()
            ->where('user_id', $user->id)
            ->with('latestMessage')
            ->withCount('messages')
            ->latest('updated_at')
            ->latest('id')
            ->limit(max(1, $limit))
            ->get();

        return $conversations
            ->map(fn (AiConversation $conversation): array => $this->summarizeConversation($conversation))
            ->all();
    }

    /**
     * @return array{id: int, title: ?string, preview: string, updated_at: string, message_count: int}
     */
    public function summarizeConversation(AiConversation $conversation): array
    {
        $latestMessage = $conversation->relationLoaded('latestMessage')
            ? $conversation->latestMessage
            : $conversation->latestMessage()->first();

        $preview = $latestMessage?->content ?? 'No messages yet';

        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'preview' => Str::limit(trim($preview), 80),
            'updated_at' => $conversation->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            'message_count' => (int) ($conversation->messages_count ?? $conversation->messages()->count()),
        ];
    }

    /**
     * @return list<array{id: int, role: string, content: string, model: ?string, stage: ?string, fallbackUsed: bool, meta?: array<string, mixed>}>
     */
    public function recentMessages(AiConversation $conversation, int $limit = 30): array
    {
        $messages = $conversation->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $messages->map(function (AiMessage $message): array {
            return [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'model' => $message->model,
                'stage' => $message->stage,
                'fallbackUsed' => (bool) data_get($message->meta, 'fallback_used', false),
                'meta' => is_array($message->meta) ? $message->meta : [],
            ];
        })->all();
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function chatHistory(AiConversation $conversation, int $limit = 20): array
    {
        $messages = $conversation->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $messages->map(fn (AiMessage $message): array => [
            'role' => $message->role,
            'content' => $message->content,
        ])->all();
    }

    /**
     * @return list<string>
     */
    public function relevantMemories(AiConversation $conversation, string $query, int $limit = 4): array
    {
        $embeddingModel = (string) config('ai-assistant.models.embedding');

        try {
            $queryVector = $this->ollama->embedding($embeddingModel, $query);
        } catch (Throwable) {
            return [];
        }

        $candidates = $conversation->messages()
            ->whereNotNull('embedding')
            ->latest('id')
            ->limit(120)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $scored = [];

        foreach ($candidates as $message) {
            $vector = $this->decodeEmbedding($message->embedding);

            if ($vector === []) {
                continue;
            }

            $scored[] = [
                'score' => $this->cosineSimilarity($queryVector, $vector),
                'text' => "[{$message->role}] {$message->content}",
            ];
        }

        usort($scored, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_values(array_map(
            fn (array $item): string => $item['text'],
            array_slice($scored, 0, max(1, $limit))
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function storeMessage(
        AiConversation $conversation,
        string $role,
        string $content,
        ?string $model = null,
        ?string $mode = null,
        ?string $stage = null,
        array $meta = [],
    ): AiMessage {
        $embedding = $this->encodeEmbedding($content);

        return $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
            'model' => $model,
            'mode' => $mode,
            'stage' => $stage,
            'embedding' => $embedding,
            'meta' => $meta,
        ]);
    }

    /**
     * @return list<float>
     */
    private function decodeEmbedding(?string $embedding): array
    {
        if (! is_string($embedding) || trim($embedding) === '') {
            return [];
        }

        $decoded = json_decode($embedding, true);

        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        /** @var list<float> */
        return array_map('floatval', $decoded);
    }

    private function encodeEmbedding(string $content): ?string
    {
        $embeddingModel = (string) config('ai-assistant.models.embedding');

        try {
            $vector = $this->ollama->embedding($embeddingModel, $content);

            if ($vector === []) {
                return null;
            }

            return json_encode($vector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $length = min(count($left), count($right));

        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        for ($index = 0; $index < $length; $index++) {
            $leftValue = (float) $left[$index];
            $rightValue = (float) $right[$index];
            $dot += $leftValue * $rightValue;
            $leftNorm += $leftValue ** 2;
            $rightNorm += $rightValue ** 2;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }
}
