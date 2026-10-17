<?php

require __DIR__ . '/../vendor/autoload.php';

// Tenma declares Tenma\Lsp\Provider at runtime; the tests run under plain PHP
if (!interface_exists(\Tenma\Lsp\Provider::class)) {
    eval(<<<'PHP'
        namespace Tenma\Lsp;

        interface Provider
        {
            public function thisClass(string $file): ?string;

            public function completions(string $file, string $text, int $offset): array;

            public function definition(string $file, string $text, int $offset): ?array;

            public function diagnostics(string $file, string $text): array;
        }

        interface Tokens
        {
            public function tokens(string $file, string $text): array;
        }

        interface Imports
        {
            public function imports(string $file, string $text): array;
        }

        interface Variables
        {
            public function variables(string $file, string $text): array;
        }

        interface Elements
        {
            public function elements(string $class): array;
        }
        PHP);
}
