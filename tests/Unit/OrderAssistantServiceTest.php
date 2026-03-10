<?php

use App\Services\AiAssistant\OrderAssistantService;

uses(Tests\TestCase::class);

test('it normalizes natural language merchant count queries', function () {
    $service = new OrderAssistantService();

    $result = $service->respond('how many delivered orders does merchant trovela have?');

    expect($result)->not->toBeNull();
    expect($result['intent'])->toBe('orders');
    expect($result['reply'])->toContain('## Order Count');
});
