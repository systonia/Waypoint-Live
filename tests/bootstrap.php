<?php

require __DIR__ . '/../vendor/autoload.php';

// Tenma declares Lsp\Provider at runtime; under plain PHP the tests take the interfaces from tenma/stubs
if (!interface_exists(\Lsp\Provider::class)) {
    foreach (glob(__DIR__ . '/../vendor/tenma/stubs/src/Lsp/*.php') as $stub) {
        require $stub;
    }
}
