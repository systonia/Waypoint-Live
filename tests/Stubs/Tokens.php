<?php

namespace Tenma\Lsp;

interface Tokens
{
    public function tokens(string $file, string $text): array;
}
