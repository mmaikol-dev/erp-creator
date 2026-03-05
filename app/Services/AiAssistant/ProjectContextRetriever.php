<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class ProjectContextRetriever
{
    public function __construct(private OllamaClient $ollama)
    {
        //
    }

    /**
     * @return array{chunks: list<string>, warnings: list<string>}
     */
    public function retrieve(string $query): array
    {
        if (! (bool) config('ai-assistant.retrieval.enabled', true)) {
            return [
                'chunks' => [],
                'warnings' => ['Retrieval is disabled by configuration.'],
            ];
        }

        $embeddingModel = (string) config('ai-assistant.models.embedding');

        try {
            $queryVector = $this->ollama->embedding($embeddingModel, $query);
        } catch (Throwable $exception) {
            return [
                'chunks' => [],
                'warnings' => ['Embedding retrieval is unavailable: '.$exception->getMessage()],
            ];
        }

        $index = $this->cachedIndexOnly();
        $warnings = [];

        if ($index === []) {
            $lazyWarm = (bool) config('ai-assistant.retrieval.lazy_warm', true);

            if ($lazyWarm && $this->shouldLazyWarmForQuery($query)) {
                $lazyWarmMaxSeconds = max(1, (int) config('ai-assistant.retrieval.lazy_warm_max_seconds', 8));

                try {
                    $this->warmIndex(false, $lazyWarmMaxSeconds);
                    $index = $this->cachedIndexOnly();

                    if ($index !== []) {
                        $warnings[] = 'Retrieval index was lazily warmed for this request.';
                    }
                } catch (Throwable $exception) {
                    $warnings[] = 'Retrieval lazy warm failed: '.$exception->getMessage();
                }
            }

            if ($index === []) {
                return [
                    'chunks' => [],
                    'warnings' => $warnings,
                ];
            }
        }

        $scores = [];

        foreach ($index as $item) {
            if (! isset($item['vector']) || ! is_array($item['vector'])) {
                continue;
            }

            $scores[] = [
                'score' => $this->cosineSimilarity($queryVector, $item['vector']),
                'content' => $item['content'],
                'path' => $item['path'],
            ];
        }

        usort($scores, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $maxChunks = max(1, (int) config('ai-assistant.retrieval.max_chunks', 4));
        $topChunks = array_slice($scores, 0, $maxChunks);

        return [
            'chunks' => array_values(array_map(
                fn (array $chunk): string => "File: {$chunk['path']}\n{$chunk['content']}",
                $topChunks
            )),
            'warnings' => $warnings,
        ];
    }

    /**
     * Build and cache the retrieval index.
     *
     * @return array{cache_key: string, entries: int, files: int, chunks: int}
     */
    public function warmIndex(bool $force = false, ?int $maxDurationSeconds = null): array
    {
        $cacheKey = $this->cacheKey();

        if (! $force) {
            /** @var mixed $existing */
            $existing = Cache::get($cacheKey);

            if (is_array($existing) && $existing !== []) {
                return [
                    'cache_key' => $cacheKey,
                    'entries' => count($existing),
                    'files' => count(array_unique(array_map(
                        fn (array $item): string => (string) ($item['path'] ?? ''),
                        $existing
                    ))),
                    'chunks' => count($existing),
                ];
            }
        }

        $embeddingModel = (string) config('ai-assistant.models.embedding');
        $sourceFiles = $this->sourceFiles();
        $index = [];
        $filesWithChunks = 0;
        $chunks = 0;
        $startedAt = microtime(true);
        $timeBudget = is_int($maxDurationSeconds) ? max(1, $maxDurationSeconds) : null;
        $timedOut = false;

        foreach ($sourceFiles as $relativePath) {
            if ($timeBudget !== null && (microtime(true) - $startedAt) >= $timeBudget) {
                $timedOut = true;
                break;
            }

            $absolutePath = base_path($relativePath);

            if (! File::exists($absolutePath) || File::isDirectory($absolutePath)) {
                continue;
            }

            $content = trim((string) File::get($absolutePath));
            $fileChunks = $this->splitIntoChunks($content);

            if ($fileChunks === []) {
                continue;
            }

            $hadChunk = false;

            foreach ($fileChunks as $chunkContent) {
                if ($timeBudget !== null && (microtime(true) - $startedAt) >= $timeBudget) {
                    $timedOut = true;
                    break;
                }

                try {
                    $vector = $this->ollama->embedding($embeddingModel, $chunkContent);
                } catch (Throwable) {
                    continue;
                }

                if ($vector === []) {
                    continue;
                }

                $index[] = [
                    'path' => $relativePath,
                    'content' => $chunkContent,
                    'vector' => $vector,
                ];
                $chunks++;
                $hadChunk = true;
            }

            if ($hadChunk) {
                $filesWithChunks++;
            }
        }

        if ($timedOut && $index === []) {
            throw new RuntimeException("Retrieval warm timed out after {$timeBudget}s before indexing any entries.");
        }

        if ($index === []) {
            throw new RuntimeException('Unable to build retrieval index entries from source files.');
        }

        $ttl = max(60, (int) config('ai-assistant.retrieval.cache_ttl', 86400));
        Cache::put($cacheKey, $index, now()->addSeconds($ttl));

        return [
            'cache_key' => $cacheKey,
            'entries' => count($index),
            'files' => $filesWithChunks,
            'chunks' => $chunks,
        ];
    }

    /**
     * @return list<array{path: string, content: string, vector: list<float>}>
     */
    private function cachedIndexOnly(): array
    {
        try {
            $cacheKey = $this->cacheKey();
        } catch (Throwable) {
            return [];
        }

        /** @var mixed $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            /** @var list<array{path: string, content: string, vector: list<float>}> $cached */
            return $cached;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $allowedExtensions = ['php', 'ts', 'tsx', 'md', 'json'];
        $maxFiles = max(5, (int) config('ai-assistant.retrieval.max_files', 40));
        $maxChars = max(200, (int) config('ai-assistant.retrieval.max_file_chars', 2400));
        $relativePaths = [];

        /** @var list<string> $scanPaths */
        $scanPaths = config('ai-assistant.retrieval.paths', []);

        foreach ($scanPaths as $scanPath) {
            $absolutePath = base_path($scanPath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            foreach (File::allFiles($absolutePath) as $file) {
                if (count($relativePaths) >= $maxFiles) {
                    break 2;
                }

                if (! in_array($file->getExtension(), $allowedExtensions, true)) {
                    continue;
                }

                if ($file->getSize() > $maxChars * 2) {
                    continue;
                }

                $relativePaths[] = ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }
        }

        return $relativePaths;
    }

    /**
     * @return list<string>
     */
    private function splitIntoChunks(string $content): array
    {
        $normalized = trim($content);

        if ($normalized === '') {
            return [];
        }

        $maxChars = max(200, (int) config('ai-assistant.retrieval.max_file_chars', 2400));
        $chunkSize = max(300, (int) config('ai-assistant.retrieval.chunk_size', 900));
        $overlap = max(0, (int) config('ai-assistant.retrieval.chunk_overlap', 180));

        $limited = mb_substr($normalized, 0, $maxChars);
        $chunks = [];
        $cursor = 0;
        $length = mb_strlen($limited);

        while ($cursor < $length) {
            $chunks[] = mb_substr($limited, $cursor, $chunkSize);
            $cursor += max(1, $chunkSize - $overlap);
        }

        return $chunks;
    }

    private function shouldLazyWarmForQuery(string $query): bool
    {
        $normalized = mb_strtolower(trim($query));

        if ($normalized === '') {
            return false;
        }

        if (mb_strlen($normalized) < 18) {
            return false;
        }

        $complexSignals = [
            'laravel',
            'controller',
            'migration',
            'model',
            'route',
            'test',
            'bug',
            'fix',
            'debug',
            'implement',
            'build',
            'refactor',
            'error',
        ];

        foreach ($complexSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
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

    private function cacheKey(): string
    {
        $fingerprints = [];

        /** @var list<string> $scanPaths */
        $scanPaths = config('ai-assistant.retrieval.paths', []);

        foreach ($scanPaths as $scanPath) {
            $absolutePath = base_path($scanPath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            try {
                foreach (File::allFiles($absolutePath) as $file) {
                    $fingerprints[] = $file->getRelativePathname().':'.$file->getMTime();
                }
            } catch (Throwable) {
                // Ignore unreadable directories and proceed with available ones.
            }
        }

        if ($fingerprints === []) {
            throw new RuntimeException('Unable to build retrieval index fingerprint.');
        }

        sort($fingerprints);

        return 'ai-assistant:index:'.md5(implode('|', $fingerprints));
    }
}
