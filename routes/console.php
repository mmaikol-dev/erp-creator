<?php

use App\Services\AiAssistant\ProjectContextRetriever;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai-assistant:index {--force : Rebuild index even if cache exists}', function () {
    /** @var ProjectContextRetriever $retriever */
    $retriever = app(ProjectContextRetriever::class);
    $stats = $retriever->warmIndex((bool) $this->option('force'));

    $this->info('AI assistant retrieval index is ready.');
    $this->line("Cache key: {$stats['cache_key']}");
    $this->line("Entries: {$stats['entries']}");
    $this->line("Files: {$stats['files']}");
    $this->line("Chunks: {$stats['chunks']}");
})->purpose('Build or refresh the AI assistant retrieval index');
