<?php

require __DIR__ . '/../vendor/autoload.php';

// Tenma declares Tenma\Lsp\Provider at runtime; the tests run under plain PHP
if (!interface_exists(\Tenma\Lsp\Provider::class)) {
    foreach (['Provider', 'Tokens', 'Imports', 'Variables', 'Elements'] as $stub) {
        require __DIR__ . "/Stubs/$stub.php";
    }
}
