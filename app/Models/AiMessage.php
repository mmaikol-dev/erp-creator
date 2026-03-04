<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $touches = ['conversation'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'model',
        'mode',
        'stage',
        'embedding',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
