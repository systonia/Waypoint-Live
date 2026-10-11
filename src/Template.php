<?php

namespace Waypoint\Live;

use InvalidArgumentException;

/**
 * A component's template: a PHP file rendered with `$this` as the component, private members included.
 * `{$this->count}` in the HTML is escaped; `<?php foreach ... ?>` works as in any view, `<?= ?>` stays raw.
 * Compiled once when the plugin starts; the methods its `data-on-*` attributes name are the only ones the
 * browser can call.
 */
final class Template
{
    private readonly string $code;

    /** @var list<string> Methods named by `data-on-*="method"` in the template. */
    public readonly array $actions;

    public function __construct(string $path)
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Template '$path' not found");
        }
        $code = '';
        $actions = [];
        foreach (token_get_all($source) as $token) {
            // Only the HTML between PHP blocks: "{$x}" inside PHP code is a string interpolation
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                preg_match_all('/\bdata-on-[a-z]+="([A-Za-z_]\w*)"/', $token[1], $named);
                array_push($actions, ...$named[1]);
                $token = (string) preg_replace_callback(
                    '/\{(\$[^{}]+)\}(\n?)/',
                    // PHP swallows a newline after a closing tag: echo it instead, so lines (and error line numbers) stay put
                    static fn (array $m) => '<?= \htmlspecialchars((string) (' . $m[1] . '), \ENT_QUOTES)' . ($m[2] !== '' ? ' . "\n"' : '') . ' ?>' . $m[2],
                    $token[1],
                );
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->code = '?>' . $code;
        $this->actions = array_values(array_unique($actions));
    }

    public function render(object $component): string
    {
        ob_start();
        try {
            // ponytail: eval on every render; a compiled file in a cache directory lets opcache keep it once renders get hot
            (function (string $__code): void {
                eval($__code);
            })->call($component, $this->code);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
