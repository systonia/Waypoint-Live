<?php

namespace Waypoint\Live;

/**
 * @internal Blade-style syntax in templates, found in the HTML between PHP tags (never inside `<?php ?>`):
 *
 *     {{ expr }}            escaped, as <?= expr ?>; a lone one in a child tag's attribute passes the value itself
 *     {!! expr !!}          raw, as <?php echo expr ?>
 *     {{-- comment --}}     nothing
 *     @if (cond) @elseif (cond) @else @endif
 *     @foreach (expr) @endforeach
 *     @@                    a literal @
 *
 * A directive starts a line or follows whitespace or `>`: `info@example.com` and `@media` stay text. Every
 * construct becomes PHP on the same lines, so errors, the editor and the problems keep the template's lines.
 */
final class Directives
{
    /** Directives that take an expression in parentheses, and those that take none. */
    private const WITH_EXPRESSION = ['if', 'elseif', 'foreach'];

    private const WITHOUT = ['else', 'endif', 'endforeach'];

    /**
     * @var list<array{kind: 'echo'|'raw'|'comment'|'directive'|'broken'|'at', start: int, end: int, name?: string, expression?: array{int, int}}>
     *     What was found, by byte offsets: `expression` is the PHP inside, `name` the directive's; a broken
     *     directive (no parentheses) stays as written
     */
    public array $found = [];

    /** @var list<array{line: int, message: string, severity: 'error'|'warning'}> Errors keep the template from compiling */
    public array $problems = [];

    public function __construct(private readonly string $source)
    {
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $this->scan($offset, $offset + strlen($text));
            }
            $offset += strlen($text);
        }
        $this->blocks();
    }

    /** The template as plain PHP, line for line. */
    public function compile(): string
    {
        $code = '';
        $at = 0;
        foreach ($this->found as $found) {
            $code .= substr($this->source, $at, $found['start'] - $at) . $this->php($found);
            $at = $found['end'];
        }
        return $code . substr($this->source, $at);
    }

    /** @param array{kind: string, start: int, end: int, name?: string, expression?: array{int, int}} $found */
    private function php(array $found): string
    {
        [$from, $to] = $found['expression'] ?? [0, 0];
        $expression = substr($this->source, $from, $to - $from);
        // PHP swallows the newline after a closing tag: an echo at the end of a line prints it, as text would
        $newline = ($this->source[$found['end']] ?? '') === "\n" ? ', "\n"' : '';
        return match ($found['kind']) {
            'echo' => "<?= $expression$newline ?>",
            'raw' => "<?php echo $expression$newline ?>",
            'comment' => '<?php /*' . str_replace('*/', '* /', substr($this->source, $found['start'] + 4, $found['end'] - $found['start'] - 8)) . '*/ ?>',
            'at' => '@',
            'broken' => substr($this->source, $found['start'], $found['end'] - $found['start']),
            // From the name on as written, so a condition over several lines keeps them
            default => '<?php ' . substr($this->source, $found['start'] + 1, $found['end'] - $found['start'] - 1)
                . (in_array($found['name'] ?? '', self::WITH_EXPRESSION, true) || ($found['name'] ?? '') === 'else' ? ':' : '') . ' ?>',
        };
    }

    private function scan(int $from, int $to): void
    {
        $i = $from;
        while ($i < $to) {
            $next = strcspn($this->source, '{@', $i, $to - $i) + $i;
            if ($next >= $to) {
                return;
            }
            $i = match (true) {
                substr_compare($this->source, '{{--', $next, 4) === 0 => $this->comment($next, $to),
                substr_compare($this->source, '{!!', $next, 3) === 0 => $this->echo($next, $to, '{!!', '!!}', 'raw'),
                substr_compare($this->source, '{{', $next, 2) === 0 => $this->echo($next, $to, '{{', '}}', 'echo'),
                $this->source[$next] === '@' => $this->directive($next, $to),
                default => $next + 1,
            };
        }
    }

    private function comment(int $start, int $to): int
    {
        $end = strpos($this->source, '--}}', $start + 4);
        if ($end === false || $end + 4 > $to) {
            $this->problem($start, '{{-- has no --}}');
            return $to;
        }
        $this->found[] = ['kind' => 'comment', 'start' => $start, 'end' => $end + 4];
        return $end + 4;
    }

    /** @param 'echo'|'raw' $kind */
    private function echo(int $start, int $to, string $open, string $close, string $kind): int
    {
        $end = $this->skip($start + strlen($open), $to, $close);
        if ($end === null) {
            $this->problem($start, "$open has no $close");
            return $to;
        }
        if (trim(substr($this->source, $start + strlen($open), $end - $start - strlen($open))) === '') {
            $this->problem($start, "$open $close is empty: put an expression between them");
        }
        $this->found[] = ['kind' => $kind, 'start' => $start, 'end' => $end + strlen($close), 'expression' => [$start + strlen($open), $end]];
        return $end + strlen($close);
    }

    private function directive(int $start, int $to): int
    {
        if (substr_compare($this->source, '@@', $start, 2) === 0) {
            $this->found[] = ['kind' => 'at', 'start' => $start, 'end' => $start + 2];
            return $start + 2;
        }
        $before = $start > 0 ? $this->source[$start - 1] : "\n";
        if (preg_match('/\G@([a-z]+)\b/', $this->source, $m, 0, $start) !== 1 || !(ctype_space($before) || $before === '>')) {
            return $start + 1;
        }
        $name = $m[1];
        $end = $start + strlen($m[0]);
        if (in_array($name, self::WITHOUT, true)) {
            $this->found[] = ['kind' => 'directive', 'start' => $start, 'end' => $end, 'name' => $name];
            return $end;
        }
        if (!in_array($name, self::WITH_EXPRESSION, true)) {
            // Only a hint: @elsa may be a name in the text, and stays text
            $close = self::closest($name);
            if ($close !== null) {
                $this->problem($start, "@$name is no directive and stays text. Did you mean @$close?", 'warning');
            }
            return $end;
        }
        $open = $end + strspn($this->source, " \t\r\n", $end, $to - $end);
        if (($this->source[$open] ?? '') !== '(') {
            $this->problem($start, "@$name needs " . ($name === 'foreach' ? 'what to loop over: @foreach ($items as $item)' : 'a condition: @' . $name . ' ($ok)'));
            // Still a block, so its end is no second problem
            $this->found[] = ['kind' => 'broken', 'start' => $start, 'end' => $end, 'name' => $name];
            return $end;
        }
        $close = $this->skip($open + 1, $to, ')', '(');
        if ($close === null) {
            // The rest of the text is the expression now: no further problems from it
            $this->problem($start, "@$name ( has no )");
            return $to;
        }
        $this->found[] = ['kind' => 'directive', 'start' => $start, 'end' => $close + 1, 'name' => $name, 'expression' => [$open + 1, $close]];
        return $close + 1;
    }

    /**
     * Where $close comes, outside strings and, with $open, outside nested pairs; null if it never does before $to.
     */
    private function skip(int $i, int $to, string $close, ?string $open = null): ?int
    {
        $depth = 0;
        while ($i < $to) {
            $char = $this->source[$i];
            if ($char === "'" || $char === '"') {
                // To the closing quote, past escaped ones
                $i++;
                while ($i < $to && $this->source[$i] !== $char) {
                    $i += $this->source[$i] === '\\' ? 2 : 1;
                }
            } elseif ($open !== null && $char === $open) {
                $depth++;
            } elseif (substr_compare($this->source, $close, $i, strlen($close)) === 0) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
            $i++;
        }
        return null;
    }

    /** Every block closed by its own end, in order. */
    private function blocks(): void
    {
        /** @var list<array{string, int}> $open */
        $open = [];
        foreach ($this->found as $found) {
            $name = $found['name'] ?? null;
            $line = $this->line($found['start']);
            if ($name === 'if' || $name === 'foreach') {
                $open[] = [$name, $line];
                continue;
            }
            if ($name === null || !in_array($name, ['elseif', 'else', 'endif', 'endforeach'], true)) {
                continue;
            }
            $block = $name === 'endforeach' ? 'foreach' : 'if';
            $top = $open === [] ? null : $open[count($open) - 1];
            if ($top === null || $top[0] !== $block) {
                $this->problems[] = ['line' => $line, 'message' => "@$name without @$block" . ($top !== null ? ": the @{$top[0]} of line {$top[1]} is still open" : ''), 'severity' => 'error'];
                // An end whose block is further out closes the ones in between: they are reported above
                if (str_starts_with($name, 'end') && in_array($block, array_column($open, 0), true)) {
                    do {
                        $closed = array_pop($open);
                    } while ($closed !== null && $closed[0] !== $block);
                }
                continue;
            }
            if ($name === 'endif' || $name === 'endforeach') {
                array_pop($open);
            }
        }
        foreach ($open as [$block, $line]) {
            $this->problems[] = ['line' => $line, 'message' => "@$block has no @end$block", 'severity' => 'error'];
        }
        usort($this->problems, static fn (array $a, array $b) => $a['line'] <=> $b['line']);
    }

    private static function closest(string $name): ?string
    {
        foreach ([...self::WITH_EXPRESSION, ...self::WITHOUT] as $directive) {
            // Not for short names: @on (Vue) or @id are no typo of @if
            if (strlen($name) >= 4 && levenshtein($name, $directive) <= 2) {
                return $directive;
            }
        }
        return null;
    }

    /** @param 'error'|'warning' $severity */
    private function problem(int $offset, string $message, string $severity = 'error'): void
    {
        $this->problems[] = ['line' => $this->line($offset), 'message' => $message, 'severity' => $severity];
    }

    public function line(int $offset): int
    {
        return substr_count($this->source, "\n", 0, $offset) + 1;
    }
}
