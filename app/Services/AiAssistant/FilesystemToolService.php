<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FilesystemToolService
{
    /**
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    public function execute(array $call): array
    {
        $tool = isset($call['tool']) && is_string($call['tool'])
            ? trim($call['tool'])
            : '';
        /** @var array<string, mixed> $arguments */
        $arguments = isset($call['arguments']) && is_array($call['arguments'])
            ? $call['arguments']
            : [];

        if ($tool === '') {
            throw new RuntimeException('Tool call is missing "tool".');
        }

        $arguments = $this->normalizeArgumentsForTool($tool, $arguments);

        return match ($tool) {
            'read_file' => $this->readFile($arguments),
            'write_file' => $this->writeFile($arguments),
            'edit' => $this->editFile($arguments),
            'append_file' => $this->appendFile($arguments),
            'create_directory' => $this->createDirectory($arguments),
            'list_directory' => $this->listDirectory($arguments),
            'search_code' => $this->searchCode($arguments),
            'run_shell' => $this->runShell($arguments),
            'web_search' => $this->webSearch($arguments),
            default => throw new RuntimeException("Unknown tool: {$tool}"),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function extractToolCalls(string $payload): array
    {
        $decoded = $this->decodeJsonPayload($payload);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var mixed $calls */
        $calls = $decoded['tool_calls'] ?? null;

        if (! is_array($calls) || $calls === []) {
            return [];
        }

        $normalized = [];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $tool = isset($call['tool']) && is_string($call['tool'])
                ? trim($call['tool'])
                : '';
            $arguments = isset($call['arguments']) && is_array($call['arguments'])
                ? $call['arguments']
                : [];

            if ($tool === '') {
                continue;
            }

            $normalized[] = [
                'tool' => $tool,
                'arguments' => $arguments,
            ];
        }

        return $normalized;
    }

    public function toolInstructions(): string
    {
        $maxRead = max(1000, (int) config('ai-assistant.tools.filesystem.max_read_chars', 200000));

        return implode("\n", [
            'Filesystem tools are available.',
            'If you need a tool, output ONLY strict JSON in this exact shape and nothing else:',
            '{"tool_calls":[{"tool":"search_code","arguments":{"query":"AiAssistantController","path":"app"}}]}',
            'Supported tool names: read_file, write_file, edit, append_file, create_directory, list_directory, search_code, run_shell, web_search.',
            'write_file arguments: path (string), content (string).',
            'edit arguments: path (string), either content (full replacement string) OR old_text + new_text.',
            'append_file arguments: path (string), content (string).',
            'create_directory arguments: path (string).',
            'list_directory arguments: path (string).',
            'search_code arguments: query (string), optional path (string), optional regex (bool), optional case_sensitive (bool).',
            'run_shell arguments: command (string), optional cwd/path (string), optional timeout_seconds (int).',
            'web_search arguments: query (string), optional domains (array|string), optional recency_days (int), optional max_results (int).',
            'When web_search is used, final user-facing response must include source links/citations.',
            "read_file will return up to {$maxRead} characters.",
            'If read_file returns exists=false, create the file with write_file instead of retrying read_file.',
            'For create-page/resource requests, keep using tools until routes, backend handlers, and frontend pages are all implemented.',
            'Use Laravel React page paths with lowercase directory names (e.g. resources/js/pages, not resources/js/Pages).',
            'Also update sidebar navigation so the new page is directly reachable from the sidebar.',
            'Do not stop at a plan when files still need to be created or updated.',
            'Never include raw tool_calls JSON in user-facing prose or markdown fences.',
            'Final answer must include a concise changed-files list.',
            'After tool results are returned, either provide final answer or request another tool call JSON.',
        ]);
    }

    public function webSearchInstructions(): string
    {
        return implode("\n", [
            'Web search tool is available for fresh/external information.',
            'Use web_search only when internal/project context is insufficient or when the user asks for latest/current external facts.',
            'Do not use web_search for local codebase questions that can be answered with project files/tools.',
            'Do not guess recent facts; use web_search when freshness matters.',
            'Use at most 1-2 focused searches, then answer directly.',
            'If you need this tool, output ONLY strict JSON and nothing else:',
            '{"tool_calls":[{"tool":"web_search","arguments":{"query":"latest Laravel 12 release notes","max_results":5}}]}',
            'web_search arguments: query (string), optional domains (array|string), optional recency_days (int), optional max_results (int).',
            'After using web_search, final user-facing response must include a Sources section with direct URLs.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function normalizeArgumentsForTool(string $tool, array $arguments): array
    {
        $aliases = [
            'path',
            'file',
            'filepath',
            'file_path',
            'filename',
            'target',
            'target_path',
            'directory',
            'dir',
        ];

        if (! isset($arguments['path']) || ! is_string($arguments['path']) || trim($arguments['path']) === '') {
            foreach ($aliases as $alias) {
                if (! isset($arguments[$alias]) || ! is_string($arguments[$alias])) {
                    continue;
                }

                $candidate = trim($arguments[$alias]);

                if ($candidate !== '') {
                    $arguments['path'] = $candidate;
                    break;
                }
            }
        }

        if ((! isset($arguments['path']) || trim((string) $arguments['path']) === '')
            && in_array($tool, ['list_directory', 'create_directory'], true)
        ) {
            $arguments['path'] = '.';
        }

        if (! isset($arguments['content']) || ! is_string($arguments['content'])) {
            if (isset($arguments['contents']) && is_string($arguments['contents'])) {
                $arguments['content'] = $arguments['contents'];
            } elseif (isset($arguments['text']) && is_string($arguments['text'])) {
                $arguments['content'] = $arguments['text'];
            }
        }

        if (! isset($arguments['old_text']) || ! is_string($arguments['old_text'])) {
            if (isset($arguments['old']) && is_string($arguments['old'])) {
                $arguments['old_text'] = $arguments['old'];
            } elseif (isset($arguments['find']) && is_string($arguments['find'])) {
                $arguments['old_text'] = $arguments['find'];
            }
        }

        if (! isset($arguments['new_text']) || ! is_string($arguments['new_text'])) {
            if (isset($arguments['new']) && is_string($arguments['new'])) {
                $arguments['new_text'] = $arguments['new'];
            } elseif (isset($arguments['replace']) && is_string($arguments['replace'])) {
                $arguments['new_text'] = $arguments['replace'];
            }
        }

        if ((! isset($arguments['query']) || ! is_string($arguments['query']) || trim($arguments['query']) === '')
            && in_array($tool, ['search_code', 'web_search'], true)
        ) {
            if (isset($arguments['text']) && is_string($arguments['text']) && trim($arguments['text']) !== '') {
                $arguments['query'] = $arguments['text'];
            } elseif (isset($arguments['term']) && is_string($arguments['term']) && trim($arguments['term']) !== '') {
                $arguments['query'] = $arguments['term'];
            }
        }

        if ((! isset($arguments['command']) || ! is_string($arguments['command']) || trim($arguments['command']) === '')
            && $tool === 'run_shell'
        ) {
            if (isset($arguments['cmd']) && is_string($arguments['cmd']) && trim($arguments['cmd']) !== '') {
                $arguments['command'] = $arguments['cmd'];
            } elseif (isset($arguments['script']) && is_string($arguments['script']) && trim($arguments['script']) !== '') {
                $arguments['command'] = $arguments['script'];
            }
        }

        if ($tool === 'web_search' && ! isset($arguments['domains'])) {
            if (isset($arguments['domain']) && is_string($arguments['domain']) && trim($arguments['domain']) !== '') {
                $arguments['domains'] = [trim($arguments['domain'])];
            } elseif (isset($arguments['site']) && is_string($arguments['site']) && trim($arguments['site']) !== '') {
                $arguments['domains'] = [trim($arguments['site'])];
            }
        }

        return $arguments;
    }

    private function enabled(): bool
    {
        return (bool) config('ai-assistant.tools.filesystem.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function readFile(array $arguments): array
    {
        $path = $this->requirePath($arguments);
        $maxChars = max(1000, (int) config('ai-assistant.tools.filesystem.max_read_chars', 200000));

        if (! File::exists($path)) {
            return [
                'tool' => 'read_file',
                'path' => $path,
                'exists' => false,
                'content' => '',
                'truncated' => false,
                'message' => 'File does not exist.',
            ];
        }

        if (File::isDirectory($path)) {
            throw new RuntimeException("Path is a directory, not a file: {$path}");
        }

        $content = (string) File::get($path);

        return [
            'tool' => 'read_file',
            'path' => $path,
            'exists' => true,
            'content' => mb_substr($content, 0, $maxChars),
            'truncated' => mb_strlen($content) > $maxChars,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function writeFile(array $arguments): array
    {
        $path = $this->requirePath($arguments);
        $content = $this->requireContent($arguments);
        $maxWrite = max(1000, (int) config('ai-assistant.tools.filesystem.max_write_chars', 400000));

        if (mb_strlen($content) > $maxWrite) {
            throw new RuntimeException("Content too large for write_file (max {$maxWrite} chars).");
        }

        $directory = dirname($path);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        File::put($path, $content);

        return [
            'tool' => 'write_file',
            'path' => $path,
            'bytes_written' => strlen($content),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function appendFile(array $arguments): array
    {
        $path = $this->requirePath($arguments);
        $content = $this->requireContent($arguments);
        $maxWrite = max(1000, (int) config('ai-assistant.tools.filesystem.max_write_chars', 400000));

        if (mb_strlen($content) > $maxWrite) {
            throw new RuntimeException("Content too large for append_file (max {$maxWrite} chars).");
        }

        $directory = dirname($path);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        File::append($path, $content);

        return [
            'tool' => 'append_file',
            'path' => $path,
            'bytes_appended' => strlen($content),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function editFile(array $arguments): array
    {
        $path = $this->requirePath($arguments);
        $maxWrite = max(1000, (int) config('ai-assistant.tools.filesystem.max_write_chars', 400000));

        if (! File::exists($path) || File::isDirectory($path)) {
            throw new RuntimeException("File does not exist for edit: {$path}");
        }

        $existing = (string) File::get($path);

        if (isset($arguments['content']) && is_string($arguments['content'])) {
            $content = $arguments['content'];

            if (mb_strlen($content) > $maxWrite) {
                throw new RuntimeException("Content too large for edit/content replacement (max {$maxWrite} chars).");
            }

            File::put($path, $content);

            return [
                'tool' => 'edit',
                'path' => $path,
                'mode' => 'replace_all',
                'bytes_written' => strlen($content),
            ];
        }

        $oldText = isset($arguments['old_text']) && is_string($arguments['old_text'])
            ? $arguments['old_text']
            : '';
        $newText = isset($arguments['new_text']) && is_string($arguments['new_text'])
            ? $arguments['new_text']
            : '';

        if ($oldText === '') {
            throw new RuntimeException('edit requires either "content" or "old_text" + "new_text".');
        }

        $replaceAll = (bool) ($arguments['all'] ?? false);

        if (! str_contains($existing, $oldText)) {
            throw new RuntimeException('edit old_text was not found in target file.');
        }

        $updated = $replaceAll
            ? str_replace($oldText, $newText, $existing, $count)
            : preg_replace('/'.preg_quote($oldText, '/').'/', $newText, $existing, 1, $count);

        if (! is_string($updated)) {
            throw new RuntimeException('edit failed while applying replacement.');
        }

        if (mb_strlen($updated) > $maxWrite) {
            throw new RuntimeException("Edited content too large (max {$maxWrite} chars).");
        }

        File::put($path, $updated);

        return [
            'tool' => 'edit',
            'path' => $path,
            'mode' => $replaceAll ? 'replace_all_matches' : 'replace_first_match',
            'replacements' => $count ?? 0,
            'bytes_written' => strlen($updated),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createDirectory(array $arguments): array
    {
        $path = $this->requirePath($arguments);

        if (! File::exists($path)) {
            File::makeDirectory($path, 0775, true);
        }

        return [
            'tool' => 'create_directory',
            'path' => $path,
            'exists' => File::exists($path),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listDirectory(array $arguments): array
    {
        $path = $this->requirePath($arguments);

        if (! File::exists($path)) {
            throw new RuntimeException("Directory does not exist: {$path}");
        }

        if (! File::isDirectory($path)) {
            throw new RuntimeException("Path is not a directory: {$path}");
        }

        $directories = array_map(
            fn (string $entry): string => basename($entry).'/',
            File::directories($path)
        );
        $files = array_map(
            fn (\SplFileInfo $entry): string => $entry->getFilename(),
            File::files($path)
        );

        return [
            'tool' => 'list_directory',
            'path' => $path,
            'entries' => array_values([...$directories, ...$files]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function searchCode(array $arguments): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Filesystem tools are disabled.');
        }

        $query = $this->requireQuery($arguments);
        $regex = (bool) ($arguments['regex'] ?? false);
        $caseSensitive = (bool) ($arguments['case_sensitive'] ?? false);
        $scope = isset($arguments['path']) && is_string($arguments['path']) && trim($arguments['path']) !== ''
            ? $this->resolvePath(trim($arguments['path']))
            : $this->normalizePath(base_path());

        $this->assertAllowedPath($scope);

        if (! File::exists($scope)) {
            throw new RuntimeException("Search path does not exist: {$scope}");
        }

        $maxResults = max(1, (int) config('ai-assistant.tools.filesystem.max_search_results', 60));
        $maxFileBytes = max(1024, (int) config('ai-assistant.tools.filesystem.max_search_file_bytes', 300000));
        $allowedExtensions = ['php', 'js', 'jsx', 'ts', 'tsx', 'json', 'md', 'yml', 'yaml', 'xml', 'env'];

        $filePaths = [];

        if (File::isDirectory($scope)) {
            foreach (File::allFiles($scope) as $file) {
                if (! in_array($file->getExtension(), $allowedExtensions, true)) {
                    continue;
                }

                if ($file->getSize() > $maxFileBytes) {
                    continue;
                }

                $filePaths[] = $file->getPathname();
            }
        } else {
            $filePaths[] = $scope;
        }

        $matches = [];
        $filesScanned = 0;

        foreach ($filePaths as $filePath) {
            if (count($matches) >= $maxResults) {
                break;
            }

            if (! File::exists($filePath) || File::isDirectory($filePath)) {
                continue;
            }

            $content = (string) File::get($filePath);
            $filesScanned++;
            $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

            foreach ($lines as $index => $line) {
                $matched = false;

                if ($regex) {
                    $flags = $caseSensitive ? '' : 'i';
                    $result = @preg_match("/{$query}/{$flags}", (string) $line);
                    $matched = $result === 1;
                } else {
                    $matched = $caseSensitive
                        ? str_contains((string) $line, $query)
                        : str_contains(mb_strtolower((string) $line), mb_strtolower($query));
                }

                if (! $matched) {
                    continue;
                }

                $matches[] = [
                    'path' => $this->relativePath($filePath),
                    'line_number' => $index + 1,
                    'line' => mb_substr(trim((string) $line), 0, 300),
                ];

                if (count($matches) >= $maxResults) {
                    break 2;
                }
            }
        }

        return [
            'tool' => 'search_code',
            'query' => $query,
            'path' => $scope,
            'regex' => $regex,
            'case_sensitive' => $caseSensitive,
            'files_scanned' => $filesScanned,
            'match_count' => count($matches),
            'matches' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runShell(array $arguments): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Filesystem tools are disabled.');
        }

        if (! (bool) config('ai-assistant.tools.filesystem.shell.enabled', false)) {
            throw new RuntimeException('Shell tool is disabled.');
        }

        $command = isset($arguments['command']) && is_string($arguments['command'])
            ? trim($arguments['command'])
            : '';

        if ($command === '') {
            throw new RuntimeException('Tool argument "command" is required for run_shell.');
        }

        $blockedPattern = $this->matchingBlockedShellPattern($command);

        if ($blockedPattern !== null) {
            throw new RuntimeException("Shell command is blocked by safety policy (matched: {$blockedPattern}).");
        }

        if (! $this->isShellCommandAllowed($command)) {
            throw new RuntimeException('Shell command is not allowed by configured prefixes.');
        }

        $cwdInput = isset($arguments['path']) && is_string($arguments['path']) && trim($arguments['path']) !== ''
            ? trim($arguments['path'])
            : (isset($arguments['cwd']) && is_string($arguments['cwd']) ? trim($arguments['cwd']) : '.');

        $cwd = $this->resolvePath($cwdInput === '' ? '.' : $cwdInput);
        $this->assertAllowedPath($cwd);

        $timeout = isset($arguments['timeout_seconds'])
            ? (int) $arguments['timeout_seconds']
            : (int) config('ai-assistant.tools.filesystem.shell.timeout_seconds', 30);
        $timeout = min(300, max(2, $timeout));
        $maxOutput = max(500, (int) config('ai-assistant.tools.filesystem.shell.max_output_chars', 12000));

        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException("Shell command timed out after {$timeout}s: ".$exception->getMessage());
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        return [
            'tool' => 'run_shell',
            'command' => $command,
            'cwd' => $cwd,
            'exit_code' => $process->getExitCode(),
            'ok' => $process->isSuccessful(),
            'stdout' => mb_substr($stdout, 0, $maxOutput),
            'stderr' => mb_substr($stderr, 0, $maxOutput),
            'stdout_truncated' => mb_strlen($stdout) > $maxOutput,
            'stderr_truncated' => mb_strlen($stderr) > $maxOutput,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function webSearch(array $arguments): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Filesystem tools are disabled.');
        }

        if (! (bool) config('ai-assistant.tools.web_search.enabled', false)) {
            throw new RuntimeException('Web search tool is disabled.');
        }

        $apiKey = trim((string) config('ai-assistant.tools.web_search.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Web search API key is missing.');
        }

        $query = $this->requireQuery($arguments);
        $timeout = max(3, (int) config('ai-assistant.tools.web_search.timeout_seconds', 20));
        $maxResults = max(1, (int) config('ai-assistant.tools.web_search.max_results', 5));
        $baseUrl = rtrim((string) config('ai-assistant.tools.web_search.base_url', 'https://api.tavily.com'), '/');
        $endpoint = (string) config('ai-assistant.tools.web_search.endpoint', '/search');

        if (isset($arguments['max_results'])) {
            $maxResults = min(10, max(1, (int) $arguments['max_results']));
        }

        /** @var list<string> $allowedDomains */
        $allowedDomains = array_values(array_filter(array_map(
            fn (mixed $domain): string => $this->normalizeDomain((string) $domain),
            (array) config('ai-assistant.tools.web_search.allowed_domains', [])
        )));

        /** @var list<string> $blockedDomains */
        $blockedDomains = array_values(array_filter(array_map(
            fn (mixed $domain): string => $this->normalizeDomain((string) $domain),
            (array) config('ai-assistant.tools.web_search.blocked_domains', [])
        )));

        $requestedDomains = $this->parseRequestedDomains($arguments['domains'] ?? null);
        $includeDomains = $this->resolveIncludedDomains($requestedDomains, $allowedDomains);
        $excludeDomains = $blockedDomains;

        if ($requestedDomains !== [] && $allowedDomains !== [] && $includeDomains === []) {
            throw new RuntimeException('Requested domains are not allowed by web search policy.');
        }

        $payload = [
            'api_key' => $apiKey,
            'query' => $query,
            'max_results' => $maxResults,
            'search_depth' => 'basic',
            'include_answer' => false,
            'include_raw_content' => false,
        ];

        if ($includeDomains !== []) {
            $payload['include_domains'] = $includeDomains;
        }

        if ($excludeDomains !== []) {
            $payload['exclude_domains'] = $excludeDomains;
        }

        if (isset($arguments['recency_days'])) {
            $days = max(1, (int) $arguments['recency_days']);
            $payload['time_range'] = $days <= 1 ? 'day' : ($days <= 7 ? 'week' : ($days <= 30 ? 'month' : 'year'));
        }

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->post("{$baseUrl}{$endpoint}", $payload);

        if ($response->failed()) {
            $body = mb_substr(trim((string) $response->body()), 0, 600);
            throw new RuntimeException("Web search request failed ({$response->status()}): {$body}");
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();
        /** @var mixed $rawResults */
        $rawResults = $json['results'] ?? [];

        if (! is_array($rawResults)) {
            $rawResults = [];
        }

        $results = [];

        foreach ($rawResults as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : '';
            if ($url === '') {
                continue;
            }

            $host = $this->extractHostFromUrl($url);
            if ($host !== '' && $this->domainIsBlocked($host, $blockedDomains)) {
                continue;
            }

            $results[] = [
                'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '',
                'url' => $url,
                'domain' => $host,
                'snippet' => isset($row['content']) && is_string($row['content'])
                    ? mb_substr(trim($row['content']), 0, 600)
                    : '',
                'score' => isset($row['score']) ? (float) $row['score'] : null,
                'published_at' => isset($row['published_date']) && is_string($row['published_date'])
                    ? $row['published_date']
                    : null,
            ];
        }

        return [
            'tool' => 'web_search',
            'query' => $query,
            'include_domains' => $includeDomains,
            'exclude_domains' => $excludeDomains,
            'result_count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requirePath(array $arguments): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Filesystem tools are disabled.');
        }

        $inputPath = isset($arguments['path']) && is_string($arguments['path'])
            ? trim($arguments['path'])
            : '';

        if ($inputPath === '') {
            throw new RuntimeException('Tool argument "path" is required (aliases: file, filepath, filename, directory).');
        }

        $resolved = $this->resolvePath($inputPath);

        $this->assertAllowedPath($resolved);

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireContent(array $arguments): string
    {
        $content = $arguments['content'] ?? null;

        if (! is_string($content)) {
            throw new RuntimeException('Tool argument "content" must be a string.');
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireQuery(array $arguments): string
    {
        $query = isset($arguments['query']) && is_string($arguments['query'])
            ? trim($arguments['query'])
            : '';

        if ($query === '') {
            throw new RuntimeException('Tool argument "query" is required.');
        }

        return $query;
    }

    private function resolvePath(string $path): string
    {
        $path = $this->normalizeKnownPathAliases($path);

        if ($this->isAbsolutePath($path)) {
            return $this->normalizePath($path);
        }

        return $this->normalizePath(base_path($path));
    }

    private function normalizeKnownPathAliases(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '') {
            return $path;
        }

        $normalized = preg_replace(
            '#(^|/)resources/js/pages(?=/|$)#i',
            '$1resources/js/pages',
            $normalized
        ) ?? $normalized;

        return $normalized;
    }

    private function assertAllowedPath(string $path): void
    {
        if ((bool) config('ai-assistant.tools.filesystem.allow_any_path', true)) {
            return;
        }

        /** @var list<string> $roots */
        $roots = (array) config('ai-assistant.tools.filesystem.roots', [base_path()]);
        $normalizedRoots = array_values(array_filter(array_map(
            fn (string $root): string => $this->normalizePath($root),
            $roots
        )));

        foreach ($normalizedRoots as $root) {
            if ($root !== '' && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return;
            }

            if ($path === $root) {
                return;
            }
        }

        throw new RuntimeException("Path is outside allowed roots: {$path}");
    }

    private function normalizePath(string $path): string
    {
        $clean = str_replace("\0", '', trim($path));

        if ($clean === '') {
            throw new RuntimeException('Path is empty after normalization.');
        }

        $real = realpath($clean);

        if ($real !== false) {
            return $real;
        }

        return rtrim(str_replace('\\', '/', $clean), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function relativePath(string $absolutePath): string
    {
        $root = $this->normalizePath(base_path());

        if ($absolutePath === $root) {
            return '.';
        }

        $normalized = $this->normalizePath($absolutePath);

        if (str_starts_with($normalized, $root.'/')) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }

        return $normalized;
    }

    private function isShellCommandAllowed(string $command): bool
    {
        if ((bool) config('ai-assistant.tools.filesystem.shell.allow_any_command', false)) {
            return true;
        }

        /** @var list<string> $prefixes */
        $prefixes = (array) config('ai-assistant.tools.filesystem.shell.allowed_prefixes', []);
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $command) ?? $command));

        if ($normalized === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            $needle = mb_strtolower(trim($prefix));

            if ($needle === '') {
                continue;
            }

            if ($normalized === $needle || str_starts_with($normalized, $needle.' ')) {
                return true;
            }
        }

        return false;
    }

    private function matchingBlockedShellPattern(string $command): ?string
    {
        /** @var list<string> $patterns */
        $patterns = (array) config('ai-assistant.tools.filesystem.shell.blocked_patterns', []);
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $command) ?? $command));

        if ($normalized === '') {
            return null;
        }

        foreach ($patterns as $pattern) {
            $needle = mb_strtolower(trim($pattern));

            if ($needle === '') {
                continue;
            }

            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseRequestedDomains(mixed $domains): array
    {
        if (is_string($domains)) {
            $parts = preg_split('/[,\s]+/', $domains) ?: [];

            return array_values(array_filter(array_map(
                fn (string $part): string => $this->normalizeDomain($part),
                $parts
            )));
        }

        if (is_array($domains)) {
            return array_values(array_filter(array_map(
                fn (mixed $part): string => $this->normalizeDomain((string) $part),
                $domains
            )));
        }

        return [];
    }

    /**
     * @param  list<string>  $requestedDomains
     * @param  list<string>  $allowedDomains
     * @return list<string>
     */
    private function resolveIncludedDomains(array $requestedDomains, array $allowedDomains): array
    {
        if ($requestedDomains === []) {
            return $allowedDomains;
        }

        if ($allowedDomains === []) {
            return $requestedDomains;
        }

        $allowedSet = array_fill_keys($allowedDomains, true);

        return array_values(array_filter($requestedDomains, fn (string $domain): bool => isset($allowedSet[$domain])));
    }

    private function normalizeDomain(string $domain): string
    {
        $normalized = mb_strtolower(trim($domain));
        $normalized = preg_replace('#^https?://#', '', $normalized) ?? $normalized;
        $normalized = explode('/', $normalized)[0] ?? $normalized;

        return trim($normalized);
    }

    private function extractHostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return '';
        }

        return $this->normalizeDomain($host);
    }

    /**
     * @param  list<string>  $blockedDomains
     */
    private function domainIsBlocked(string $domain, array $blockedDomains): bool
    {
        if ($domain === '') {
            return false;
        }

        foreach ($blockedDomains as $blocked) {
            if ($blocked !== '' && ($domain === $blocked || str_ends_with($domain, '.'.$blocked))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonPayload(string $payload): ?array
    {
        $trimmed = trim($payload);

        if ($trimmed === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            /** @var mixed $decodedString */
            $decodedString = json_decode(trim($decoded), true);

            if (is_array($decodedString)) {
                return $decodedString;
            }
        }

        if (preg_match('/```json\s*([\s\S]*?)\s*```/i', $trimmed, $matches) === 1) {
            /** @var mixed $fencedDecoded */
            $fencedDecoded = json_decode(trim((string) $matches[1]), true);

            if (is_array($fencedDecoded)) {
                return $fencedDecoded;
            }
        }

        $unescaped = stripcslashes($trimmed);

        if ($unescaped !== $trimmed) {
            /** @var mixed $unescapedDecoded */
            $unescapedDecoded = json_decode($unescaped, true);

            if (is_array($unescapedDecoded)) {
                return $unescapedDecoded;
            }
        }

        $recovered = $this->recoverLikelyToolCallJson($trimmed);

        if (is_array($recovered)) {
            return $recovered;
        }

        return $this->extractJsonObjectWithToolCalls($trimmed);
    }

    /**
     * Attempt to recover near-valid tool payloads (for example missing final "}" on outer object).
     *
     * @return array<string, mixed>|null
     */
    private function recoverLikelyToolCallJson(string $payload): ?array
    {
        $candidate = trim($payload);

        if ($candidate === '') {
            return null;
        }

        // Common malformed output: starts as {"tool_calls": ... ] but misses final outer brace.
        if (preg_match('/^\{\s*"tool_calls"\s*:/', $candidate) === 1) {
            $openBraces = substr_count($candidate, '{');
            $closeBraces = substr_count($candidate, '}');

            if ($openBraces > $closeBraces) {
                $candidate .= str_repeat('}', $openBraces - $closeBraces);
            }

            /** @var mixed $decoded */
            $decoded = json_decode($candidate, true);

            if (is_array($decoded) && array_key_exists('tool_calls', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract the first valid JSON object containing `tool_calls` from mixed text.
     *
     * @return array<string, mixed>|null
     */
    private function extractJsonObjectWithToolCalls(string $text): ?array
    {
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $isEscaped = false;

            for ($index = $start; $index < $length; $index++) {
                $char = $text[$index];

                if ($inString) {
                    if ($isEscaped) {
                        $isEscaped = false;

                        continue;
                    }

                    if ($char === '\\') {
                        $isEscaped = true;

                        continue;
                    }

                    if ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;

                    continue;
                }

                if ($char === '{') {
                    $depth++;

                    continue;
                }

                if ($char !== '}') {
                    continue;
                }

                $depth--;

                if ($depth !== 0) {
                    continue;
                }

                $candidate = substr($text, $start, $index - $start + 1);
                /** @var mixed $decoded */
                $decoded = json_decode($candidate, true);

                if (! is_array($decoded) || ! array_key_exists('tool_calls', $decoded)) {
                    break;
                }

                return $decoded;
            }
        }

        return null;
    }
}
