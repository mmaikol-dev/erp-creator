<?php

use App\Models\AiConversation;
use App\Models\User;

test('authenticated user can start a new chat conversation', function () {
    $user = User::factory()->create();
    $existing = AiConversation::create([
        'user_id' => $user->id,
        'title' => 'Existing',
    ]);
    $existing->messages()->create([
        'role' => 'user',
        'content' => 'Old message',
    ]);

    $response = $this->actingAs($user)->postJson(route('ai-assistant.conversations.store'));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure([
            'ok',
            'conversation_id',
            'messages',
        ])
        ->assertJsonPath('messages', []);

    $newConversationId = $response->json('conversation_id');

    expect($newConversationId)->toBeInt()
        ->not->toBe($existing->id);

    $newConversation = AiConversation::query()->findOrFail($newConversationId);

    expect($newConversation->user_id)->toBe($user->id)
        ->and($newConversation->messages()->count())->toBe(0);
});
