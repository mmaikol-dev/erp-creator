<?php

namespace App\Services\AiAssistant\Pipeline\IO;

use App\Services\AiAssistant\Pipeline\CompiledSpec;

final class RouteInserter
{
    public function insert(string $routesContent, CompiledSpec $spec): string
    {
        $routeSlug = $spec->routeSlug;
        $controllerClass = $spec->controllerClass;

        $snippet = "    Route::resource('{$routeSlug}', \\App\\Http\\Controllers\\{$controllerClass}::class);\n";

        if (str_contains($routesContent, "Route::resource('{$routeSlug}'")) {
            return $routesContent;
        }

        $marker = "    Route::inertia('dashboard', 'dashboard')->name('dashboard');";
        if (str_contains($routesContent, $marker)) {
            return str_replace($marker, $snippet.$marker, $routesContent);
        }

        $groupEnd = "});\n\nrequire __DIR__.'/settings.php';";
        if (str_contains($routesContent, $groupEnd)) {
            return str_replace($groupEnd, $snippet.$groupEnd, $routesContent);
        }

        return rtrim($routesContent)."\n".$snippet;
    }
}
