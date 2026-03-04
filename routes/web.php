<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\WhatsAppChatController;
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
    
    // WhatsApp Chat Routes
    Route::get('whatsapp-chat', WhatsAppChatController::class)->name('whatsapp-chat');
    Route::post('whatsapp-chat/conversations', [WhatsAppChatController::class, 'store'])
        ->name('whatsapp-chat.conversations.store');
    Route::post('whatsapp-chat/messages', [WhatsAppChatController::class, 'storeMessage'])
        ->name('whatsapp-chat.messages.store');
    Route::get('whatsapp-chat/conversations/{conversation}/messages', [WhatsAppChatController::class, 'showMessages'])
        ->name('whatsapp-chat.messages.show');
    
    // Support Ticket Routes
    Route::get('support-tickets', [SupportTicketController::class, 'index'])->name('support-tickets.index');
    Route::get('support-tickets/create', [SupportTicketController::class, 'create'])->name('support-tickets.create');
    Route::post('support-tickets', [SupportTicketController::class, 'store'])->name('support-tickets.store');
    Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support-tickets.show');
    Route::get('support-tickets/{ticket}/edit', [SupportTicketController::class, 'edit'])->name('support-tickets.edit');
    Route::put('support-tickets/{ticket}', [SupportTicketController::class, 'update'])->name('support-tickets.update');
    Route::delete('support-tickets/{ticket}', [SupportTicketController::class, 'destroy'])->name('support-tickets.destroy');
    Route::post('support-tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage'])->name('support-tickets.messages.store');
    
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

});

require __DIR__.'/settings.php';
