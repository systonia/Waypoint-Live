<?php

namespace Tenma\Lsp;

interface Variables
{
    public function variables(string $file, string $text): array;
}
