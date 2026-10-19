<?php

require __DIR__ . '/../vendor/autoload.php';

// Tenma declares Tenma\Lsp\Provider at runtime; under plain PHP the tests take the interfaces from tenma/stubs
if (!interface_exists(\Tenma\Lsp\Provider::class)) {
    foreach (glob(__DIR__ . '/../vendor/tenma/stubs/src/Tenma/Lsp/*.php') as $stub) {
        require $stub;
    }
}
