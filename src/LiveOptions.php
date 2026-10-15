<?php

namespace Waypoint\Live;

/** Configure with `$app->configure(function (LiveOptions $live) { ... })`. */
class LiveOptions
{
    /**
     * In dev, where a file in an error opens: `{file}` and `{line}` are filled in. VSCodium:
     * `vscodium://file/{file}:{line}`, PhpStorm: `phpstorm://open?file={file}&line={line}`.
     */
    public string $editor = 'vscode://file/{file}:{line}';
}
