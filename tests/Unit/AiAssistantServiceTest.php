<?php

use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\BoostContextService;
use App\Services\AiAssistant\FilesystemToolService;
use App\Services\AiAssistant\OllamaClient;
use App\Services\AiAssistant\OrderAssistantService;
use App\Services\AiAssistant\ProjectContextRetriever;

uses(Tests\TestCase::class);

afterEach(function (): void {
    \Mockery::close();
});

test('it uses coding model first for coding intent', function () {
    config()->set('ai-assistant.models.planning', 'glm-5:cloud');
    config()->set('ai-assistant.models.coding', 'qwen3-coder-next:cloud');
    config()->set('ai-assistant.quality.typescript_check_on_coding', false);

    $ollama = \Mockery::mock(OllamaClient::class);
    $boost = \Mockery::mock(BoostContextService::class);
    $retriever = \Mockery::mock(ProjectContextRetriever::class);
    $orders = \Mockery::mock(OrderAssistantService::class);

    $orders->shouldReceive('respond')->once()->andReturn(null);

    $boost->shouldReceive('buildContext')
        ->once()
        ->andReturn(['context' => '', 'warnings' => []]);

    $retriever->shouldReceive('retrieve')
        ->once()
        ->andReturn(['chunks' => [], 'warnings' => []]);

    $ollama->shouldReceive('chat')
        ->once()
        ->withArgs(function (string $model, array $messages): bool {
            return $model === 'qwen3-coder-next:cloud'
                && $messages[1]['role'] === 'user';
        })
        ->andReturn([
            'content' => 'Implemented.',
            'model' => 'qwen3-coder-next:cloud',
        ]);

    $service = new AiAssistantService($ollama, $boost, $retriever, new FilesystemToolService(), $orders);

    $result = $service->respond('Create a controller and edit files', mode: 'coding');

    expect($result['intent'])->toBe('coding');
    expect($result['model'])->toBe('qwen3-coder-next:cloud');
    expect($result['fallback_used'])->toBeFalse();
});

test('it falls back to planning model when coding model fails', function () {
    config()->set('ai-assistant.models.planning', 'glm-5:cloud');
    config()->set('ai-assistant.models.coding', 'qwen3-coder-next:cloud');
    config()->set('ai-assistant.quality.typescript_check_on_coding', false);

    $ollama = \Mockery::mock(OllamaClient::class);
    $boost = \Mockery::mock(BoostContextService::class);
    $retriever = \Mockery::mock(ProjectContextRetriever::class);
    $orders = \Mockery::mock(OrderAssistantService::class);

    $orders->shouldReceive('respond')->once()->andReturn(null);

    $boost->shouldReceive('buildContext')
        ->once()
        ->andReturn(['context' => '', 'warnings' => []]);

    $retriever->shouldReceive('retrieve')
        ->once()
        ->andReturn(['chunks' => [], 'warnings' => []]);

    $ollama->shouldReceive('chat')
        ->once()
        ->with('qwen3-coder-next:cloud', \Mockery::type('array'))
        ->andThrow(new \RuntimeException('qwen unavailable'));

    $ollama->shouldReceive('chat')
        ->once()
        ->with('glm-5:cloud', \Mockery::type('array'))
        ->andReturn([
            'content' => 'Fallback response.',
            'model' => 'glm-5:cloud',
        ]);

    $service = new AiAssistantService($ollama, $boost, $retriever, new FilesystemToolService(), $orders);

    $result = $service->respond('Create tests for this endpoint', mode: 'coding');

    expect($result['model'])->toBe('glm-5:cloud');
    expect($result['fallback_used'])->toBeTrue();
});

test('it runs deep mode with planning then coding', function () {
    config()->set('ai-assistant.models.planning', 'glm-5:cloud');
    config()->set('ai-assistant.models.coding', 'qwen3-coder-next:cloud');
    config()->set('ai-assistant.quality.typescript_check_on_coding', false);

    $ollama = \Mockery::mock(OllamaClient::class);
    $boost = \Mockery::mock(BoostContextService::class);
    $retriever = \Mockery::mock(ProjectContextRetriever::class);
    $orders = \Mockery::mock(OrderAssistantService::class);

    $orders->shouldReceive('respond')->once()->andReturn(null);

    $boost->shouldReceive('buildContext')
        ->once()
        ->andReturn(['context' => '', 'warnings' => []]);

    $retriever->shouldReceive('retrieve')
        ->once()
        ->andReturn(['chunks' => [], 'warnings' => []]);

    $ollama->shouldReceive('chat')
        ->once()
        ->with('glm-5:cloud', \Mockery::type('array'))
        ->andReturn([
            'content' => "1. Inspect files\n2. Apply patch\n3. Verify",
            'model' => 'glm-5:cloud',
        ]);

    $ollama->shouldReceive('chat')
        ->once()
        ->with('qwen3-coder-next:cloud', \Mockery::type('array'))
        ->andReturn([
            'content' => 'Done. Updated the code based on the plan.',
            'model' => 'qwen3-coder-next:cloud',
        ]);

    $service = new AiAssistantService($ollama, $boost, $retriever, new FilesystemToolService(), $orders);

    $result = $service->respond('Update controller and tests', mode: 'deep');

    expect($result['intent'])->toBe('deep');
    expect($result['model'])->toBe('qwen3-coder-next:cloud');
    expect($result['fallback_used'])->toBeFalse();
    expect($result['plan'])->toContain('Inspect files');
    expect($result['plan_model'])->toBe('glm-5:cloud');
});
