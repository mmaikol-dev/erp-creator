<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AiAssistantLogController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('ai-assistant', AiAssistantController::class)->name('ai-assistant');
    Route::post('ai-assistant/conversations', [AiAssistantController::class, 'newConversation'])
        ->name('ai-assistant.conversations.store');
    Route::get('ai-assistant/conversations/{conversation}', [AiAssistantController::class, 'showConversation'])
        ->name('ai-assistant.conversations.show');
    Route::post('ai-assistant/chat', [AiAssistantController::class, 'chat'])
        ->name('ai-assistant.chat');
    Route::post('ai-assistant/chat/stream', [AiAssistantController::class, 'chatStream'])
        ->name('ai-assistant.chat.stream');
    Route::get('ai-assistant/logs', AiAssistantLogController::class)
        ->name('ai-assistant.logs');
    Route::post('ai-assistant/task-runs', [AiAssistantController::class, 'createTaskRun'])
        ->name('ai-assistant.task-runs.store');
    Route::get('ai-assistant/task-runs/{taskRun}', [AiAssistantController::class, 'showTaskRun'])
        ->name('ai-assistant.task-runs.show');
    Route::post('ai-assistant/task-runs/{taskRun}/next', [AiAssistantController::class, 'runNextTaskStep'])
        ->name('ai-assistant.task-runs.next');
    Route::post('ai-assistant/task-runs/{taskRun}/approve', [AiAssistantController::class, 'approveTaskRunStep'])
        ->name('ai-assistant.task-runs.approve');
    Route::post('ai-assistant/task-runs/{taskRun}/retry', [AiAssistantController::class, 'retryTaskRunStep'])
        ->name('ai-assistant.task-runs.retry');
    Route::post('ai-assistant/task-runs/{taskRun}/skip', [AiAssistantController::class, 'skipTaskRunStep'])
        ->name('ai-assistant.task-runs.skip');
    Route::post('ai-assistant/task-runs/{taskRun}/pause', [AiAssistantController::class, 'pauseTaskRun'])
        ->name('ai-assistant.task-runs.pause');
    Route::post('ai-assistant/task-runs/{taskRun}/resume', [AiAssistantController::class, 'resumeTaskRun'])
        ->name('ai-assistant.task-runs.resume');
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

});

require __DIR__.'/settings.php';
