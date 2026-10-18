<?php

namespace Tenma\Lsp;

interface Imports
{
    public function imports(string $file, string $text): array;
}
