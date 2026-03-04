<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Log;
use Throwable;

class BoostContextService
{
    /**
     * @return array{context: string, warnings: list<string>}
     */
    public function buildContext(): array
    {
        $toolExecutorClass = 'Laravel\\Boost\\Mcp\\ToolExecutor';

        if (! class_exists($toolExecutorClass)) {
            return [
                'context' => '',
                'warnings' => [],
            ];
        }

        $executor = app($toolExecutorClass);
        $sections = [];
        $warnings = [];

        $toolMap = [
            'Application Info' => ['Laravel\\Boost\\Mcp\\Tools\\ApplicationInfo', []],
            'Route List' => ['Laravel\\Boost\\Mcp\\Tools\\ListRoutes', []],
            'Artisan Commands' => ['Laravel\\Boost\\Mcp\\Tools\\ListArtisanCommands', []],
        ];

        foreach ($toolMap as $label => [$toolClass, $arguments]) {
            try {
                $response = $executor->execute($toolClass, $arguments);

                if ($response->isError()) {
                    Log::debug("ai-assistant.boost.tool_skipped_error_response", [
                        'tool' => $label,
                    ]);
                    continue;
                }

                $sections[] = "### {$label}\n".trim((string) $response->content());
            } catch (Throwable $exception) {
                Log::debug("ai-assistant.boost.tool_skipped_exception", [
                    'tool' => $label,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'context' => implode("\n\n", $sections),
            'warnings' => $warnings,
        ];
    }
}
