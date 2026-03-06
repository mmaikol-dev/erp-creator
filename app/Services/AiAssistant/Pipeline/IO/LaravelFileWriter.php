<?php

namespace App\Services\AiAssistant\Pipeline\IO;

use Illuminate\Support\Facades\File;

final class LaravelFileWriter implements FileWriter
{
    public function exists(string $absolutePath): bool
    {
        return File::exists($absolutePath);
    }

    public function read(string $absolutePath): string
    {
        return (string) File::get($absolutePath);
    }

    public function write(string $absolutePath, string $content): void
    {
        $this->ensureDirectory($absolutePath);
        File::put($absolutePath, $content);
    }

    public function delete(string $absolutePath): void
    {
        File::delete($absolutePath);
    }

    public function ensureDirectory(string $absolutePath): void
    {
        File::ensureDirectoryExists(dirname($absolutePath));
    }
}
