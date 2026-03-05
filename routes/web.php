<?php

use App\Http\Controllers\AiAssistantController;
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
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

});

require __DIR__.'/settings.php';
