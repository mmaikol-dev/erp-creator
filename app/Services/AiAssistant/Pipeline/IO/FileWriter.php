<?php

namespace App\Services\AiAssistant\Pipeline\IO;

interface FileWriter
{
    public function exists(string $absolutePath): bool;

    public function read(string $absolutePath): string;

    public function write(string $absolutePath, string $content): void;

    public function delete(string $absolutePath): void;

    public function ensureDirectory(string $absolutePath): void;
}
