<?php

namespace App\Services\AiAssistant\Pipeline;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PipelineValidator
{
    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function lintGeneratedChanges(array $changes): array
    {
        $errors = [];
        $checked = [];
        $checkedMigrations = [];

        foreach ($changes as $change) {
            $path = (string) ($change['path'] ?? '');
            $after = (string) ($change['after'] ?? '');

            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $tmpPath = storage_path('app/pipeline-lint-'.md5($path.$after).'.php');
            File::ensureDirectoryExists(dirname($tmpPath));
            File::put($tmpPath, $after);

            $checked[] = $path;
            $process = new Process(['php', '-l', $tmpPath], base_path());
            $process->setTimeout(20);
            $process->run();

            if (! $process->isSuccessful()) {
                $errors[] = [
                    'path' => $path,
                    'error' => trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                ];
            }

            File::delete($tmpPath);

            if (str_starts_with($path, 'database/migrations/')) {
                $checkedMigrations[] = $path;

                foreach ($this->migrationSanityErrors($path, $after) as $migrationError) {
                    $errors[] = [
                        'path' => $path,
                        'error' => $migrationError,
                    ];
                }
            }
        }

        return [
            'ok' => $errors === [],
            'checked_php_files' => $checked,
            'checked_migrations' => $checkedMigrations,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function validateAppliedStep(array $changes): array
    {
        $commands = $this->commands();
        $results = [];

        foreach ($commands as $command) {
            $parts = preg_split('/\s+/', trim($command)) ?: [];
            $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

            if ($parts === []) {
                continue;
            }

            $process = new Process($parts, base_path());
            $process->setTimeout($this->timeoutSeconds());

            try {
                $process->run();

                $results[] = [
                    'command' => $command,
                    'ok' => $process->isSuccessful(),
                    'output' => $this->truncate($process->getOutput()),
                    'error_output' => $this->truncate($process->getErrorOutput()),
                ];
            } catch (ProcessTimedOutException $exception) {
                $results[] = [
                    'command' => $command,
                    'ok' => false,
                    'output' => '',
                    'error_output' => 'Timed out: '.$exception->getMessage(),
                ];
            }
        }

        $ok = true;
        foreach ($results as $result) {
            if (! (bool) ($result['ok'] ?? false)) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'commands' => $results,
            'changed_paths' => array_values(array_map(
                static fn (array $change): string => (string) ($change['path'] ?? ''),
                $changes
            )),
        ];
    }

    /**
     * @return list<string>
     */
    private function commands(): array
    {
        /** @var list<string> $commands */
        $commands = config('ai-assistant.pipeline.validation_commands', [
            'npm run types',
        ]);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $commands
        ), static fn (string $value): bool => $value !== ''));
    }

    private function timeoutSeconds(): int
    {
        return max(10, (int) config('ai-assistant.pipeline.validation_timeout_seconds', 180));
    }

    private function truncate(string $value): string
    {
        $max = max(1000, (int) config('ai-assistant.pipeline.validation_max_output_chars', 16000));

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'\n...[truncated]';
    }

    /**
     * @return list<string>
     */
    private function migrationSanityErrors(string $path, string $content): array
    {
        $errors = [];

        if (preg_match("/->string\\s*\\(\\s*'[^']+'\\s*,\\s*\\[/", $content) === 1) {
            $errors[] = 'Invalid migration pattern: string() cannot receive enum options array. Use enum() instead.';
        }

        if (preg_match("/Schema::create\\s*\\(\\s*'([^']+)'/", $content, $createMatch) === 1) {
            $createdTable = (string) ($createMatch[1] ?? '');

            if ($createdTable !== ''
                && preg_match("/Schema::dropIfExists\\s*\\(\\s*'([^']+)'/", $content, $dropMatch) === 1
            ) {
                $droppedTable = (string) ($dropMatch[1] ?? '');

                if ($droppedTable !== $createdTable) {
                    $errors[] = "Migration down() drops '{$droppedTable}' but up() creates '{$createdTable}'.";
                }
            }
        }

        return $errors;
    }
}
