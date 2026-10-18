<?php

namespace Tenma\Lsp;

interface Provider
{
    public function thisClass(string $file): ?string;

    public function completions(string $file, string $text, int $offset): array;

    public function definition(string $file, string $text, int $offset): ?array;

    public function diagnostics(string $file, string $text): array;
}
