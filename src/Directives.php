<?php

namespace Waypoint\Live;

/**
 * @internal Directives in templates, found in the HTML between PHP tags (never inside `<?php ?>`):
 *
 *     {{ expr }}            escaped, as <?= expr ?>; a lone one in a child tag's attribute passes the value itself;
 *                           a value of type Html prints as it is
 *     {{/* comment *∕}}     nothing: a PHP block comment in braces (∕ stands for /, which would end this docblock)
 *     @if (cond) @elseif (cond) @else @endif
 *     @foreach (expr) @endforeach
 *     @code … @endcode      PHP, as <?php … ?>; the body is PHP only, no directives in it
 *     `@use (A\B)`, `@use (A\B as C)`: a class for the whole template, after the component's class file's imports
 *     @class.late="$cond"   in an HTML start tag: the class `late` when $cond is truthy, merged into the tag's class
 *                           attribute through classes(); the name runs to the first = outside [ ]
 *     @@                    a literal @
 *
 * A directive starts a line or follows whitespace or `>`: `info@example.com` and `@media` stay text. Every
 * construct becomes PHP on the same lines, so errors, the editor and the problems keep the template's lines.
 *
 * @phpstan-type Found array{kind: 'echo'|'comment'|'directive'|'broken'|'at'|'code'|'use'|'class'|'insert', start: int, end: int, name?: string, expression?: array{int, int}, class?: string, code?: string, from?: int}
 */
final class Directives
{
    /** Directives that take an expression in parentheses, and those that take none. */
    private const WITH_EXPRESSION = ['if', 'elseif', 'foreach', 'use'];

    private const WITHOUT = ['else', 'endif', 'endforeach'];

    /**
     * @var list<Found>
     *     What was found, by byte offsets: `expression` is the PHP inside, `name` the directive's, `class` the
     *     name of a @class., `code` the PHP it becomes when that is set already, from `from` on if set; a
     *     broken directive (no parentheses) stays as written
     */
    public array $found = [];

    /** @var array<string, string> alias => class, from `@use` */
    public array $uses = [];

    /** @var list<array{line: int, message: string, severity: 'error'|'warning'}> Errors keep the template from compiling */
    public array $problems = [];

    /** @param list<string> $selectors The app's components: a @class. on their tags is an error */
    public function __construct(private readonly string $source, private readonly array $selectors = [])
    {
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $this->scan($offset, $offset + strlen($text));
            }
            $offset += strlen($text);
        }
        $this->tags();
        $this->blocks();
        $this->statements();
    }

    /** The template as plain PHP, line for line. */
    public function compile(): string
    {
        $code = '';
        $at = 0;
        foreach ($this->found as $found) {
            $from = $found['from'] ?? $found['start'];
            $code .= substr($this->source, $at, $from - $at) . $this->php($found);
            $at = $found['end'];
        }
        return $code . substr($this->source, $at);
    }

    /** @param Found $found */
    private function php(array $found): string
    {
        if (isset($found['code'])) {
            return $found['code'];
        }
        [$from, $to] = $found['expression'] ?? [0, 0];
        $expression = substr($this->source, $from, $to - $from);
        // PHP swallows the newline after a closing tag: an echo at the end of a line prints it, as text would
        $newline = ($this->source[$found['end']] ?? '') === "\n" ? ', "\n"' : '';
        return match ($found['kind']) {
            'echo' => "<?= $expression$newline ?>",
            'code' => "<?php $expression ?>",
            // Goes in the header on line 1 (Template::header); the tag swallows the newline, as a directive's does
            'use' => '<?php ?>',
            'comment' => '<?php /*' . str_replace('*/', '* /', $expression) . '*/ ?>',
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
                preg_match('#\G\{\{\s*/\*#', $this->source, $open, 0, $next) === 1 => $this->comment($next, $next + strlen($open[0]), $to),
                substr_compare($this->source, '{{', $next, 2) === 0 => $this->echo($next, $to),
                $this->source[$next] === '@' => $this->directive($next, $to),
                default => $next + 1,
            };
        }
    }

    // {{ and a PHP block comment and }}, spaces allowed between them: $from is where the comment's text starts
    private function comment(int $start, int $from, int $to): int
    {
        if (preg_match('#\*/\s*\}\}#', $this->source, $close, PREG_OFFSET_CAPTURE, $from) !== 1 || $close[0][1] + strlen($close[0][0]) > $to) {
            $this->problem($start, '{{/* has no */}}');
            return $to;
        }
        $end = $close[0][1] + strlen($close[0][0]);
        $this->found[] = ['kind' => 'comment', 'start' => $start, 'end' => $end, 'expression' => [$from, $close[0][1]]];
        return $end;
    }

    private function echo(int $start, int $to): int
    {
        $end = $this->skip($start + 2, $to, '}}');
        if ($end === null) {
            $this->problem($start, '{{ has no }}');
            return $to;
        }
        if (trim(substr($this->source, $start + 2, $end - $start - 2)) === '') {
            $this->problem($start, '{{ }} is empty: put an expression between them');
        }
        if (self::opensComment(substr($this->source, $start + 2, $end - $start - 2))) {
            $this->problem($start, '{{ }} has a /* without */: it would comment out the template up to the next */');
        }
        $this->found[] = ['kind' => 'echo', 'start' => $start, 'end' => $end + 2, 'expression' => [$start + 2, $end]];
        return $end + 2;
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
        if ($name === 'code') {
            return $this->code($start, $end, $to);
        }
        if ($name === 'class' && ($this->source[$end] ?? '') === '.') {
            return $this->conditional($start, $end + 1, $to);
        }
        if ($name === 'endcode') {
            $this->problem($start, '@endcode without @code');
            return $end;
        }
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
            $this->problem($start, "@$name needs " . match ($name) {
                'foreach' => 'what to loop over: @foreach ($items as $item)',
                'use' => 'a class: @use (App\\Status)',
                default => "a condition: @$name (\$ok)",
            });
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
        if (self::opensComment(substr($this->source, $open + 1, $close - $open - 1))) {
            $this->problem($start, "@$name ( ) has a /* without */: it would comment out the template up to the next */");
        }
        if ($name === 'use') {
            $this->import($start, trim(substr($this->source, $open + 1, $close - $open - 1)));
        }
        $this->found[] = ['kind' => $name === 'use' ? 'use' : 'directive', 'start' => $start, 'end' => $close + 1, 'name' => $name, 'expression' => [$open + 1, $close]];
        return $close + 1;
    }

    private function import(int $start, string $use): void
    {
        if (preg_match('/^\\\\?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)(?:\s+as\s+([A-Za-z_]\w*))?$/i', $use, $m) !== 1) {
            $this->problem($start, "@use ($use): name a class, as in @use (App\\Status) or @use (App\\Status as State)");
            return;
        }
        $name = $m[1];
        $alias = ($m[2] ?? '') !== '' ? $m[2] : substr((string) strrchr("\\$name", '\\'), 1);
        if (array_key_exists(strtolower($alias), array_change_key_case($this->uses))) {
            $this->problem($start, "@use ($use): $alias is imported above already");
            return;
        }
        $this->uses[$alias] = $name;
        // Only a warning: the name may be used in a branch that never runs
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
            $this->problem($start, "@use ($use): there is no class, enum, interface or trait $name", 'warning');
        }
    }

    // The body is PHP: it ends at the first @endcode outside its strings and comments
    private function code(int $start, int $from, int $to): int
    {
        $tokens = token_get_all('<?php ' . substr($this->source, $from, $to - $from));
        $at = $from - 6;
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                $this->problem($start, '@code has a ?>, which would end the PHP early: use @endcode to end the code');
                return $to;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($token === '@' && is_array($next) && $next[0] === T_STRING && $next[1] === 'endcode') {
                if (trim(substr($this->source, $from, $at - $from)) === '') {
                    $this->problem($start, '@code is empty', 'warning');
                }
                $this->found[] = ['kind' => 'code', 'start' => $start, 'end' => $at + 8, 'name' => 'code', 'expression' => [$from, $at]];
                return $at + 8;
            }
            $at += strlen(is_array($token) ? $token[1] : $token);
        }
        $this->problem($start, self::opensComment(substr($this->source, $from, $to - $from))
            ? '@code has a /* without */: it would comment out the template up to the next */'
            : '@code has no @endcode');
        return $to;
    }

    // @class.name="expr": the name runs to the first = outside square brackets, so md:w-1/2, data-[state=open]:block
    // and [&>*]:p-4 are names; the value to the next "
    private function conditional(int $start, int $from, int $to): int
    {
        $depth = 0;
        for ($i = $from; $i < $to; $i++) {
            $char = $this->source[$i];
            if ($depth === 0 && ($char === '=' || $char === '>' || $char === '"' || $char === "'" || ctype_space($char))) {
                break;
            }
            $depth = max(0, $depth + match ($char) {
                '[' => 1,
                ']' => -1,
                default => 0,
            });
        }
        $class = substr($this->source, $from, $i - $from);
        if ($class === '') {
            $this->problem($start, '@class. needs a class name and a condition: @class.late="$order->late"');
            return $i;
        }
        $close = substr_compare($this->source, '="', $i, 2) === 0 ? strpos($this->source, '"', $i + 2) : false;
        if ($close === false || $close >= $to) {
            $this->problem($start, "@class.$class needs a condition: @class.$class=\"\$order->late\"");
            return $i;
        }
        if (self::opensComment(substr($this->source, $i + 2, $close - $i - 2))) {
            $this->problem($start, "@class.$class has a /* without */: it would comment out the template up to the next */");
        }
        $this->found[] = ['kind' => 'class', 'start' => $start, 'end' => $close + 1, 'name' => 'class', 'class' => $class, 'expression' => [$i + 2, $close]];
        return $close + 1;
    }

    /** The @class. of each start tag become one classes() call in its class attribute. */
    private function tags(): void
    {
        $items = array_filter($this->found, static fn (array $found) => $found['kind'] === 'class');
        if ($items === []) {
            return;
        }
        // The HTML alone: a > in PHP, in {{ }}, in a directive or in a class name ends no tag
        $html = $this->source;
        $offset = 0;
        foreach (token_get_all($this->source) as $token) {
            $length = strlen(is_array($token) ? $token[1] : $token);
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
                $html = substr_replace($html, str_repeat(' ', $length), $offset, $length);
            }
            $offset += $length;
        }
        foreach ($this->found as $found) {
            $html = substr_replace($html, str_repeat(' ', $found['end'] - $found['start']), $found['start'], $found['end'] - $found['start']);
        }
        preg_match_all('/<([A-Za-z][\w:-]*)(?:"[^"]*"|\'[^\']*\'|[^\'">])*>/', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $placed = [];
        foreach ($tags as [[$tag, $at], [$name]]) {
            $mine = array_filter($items, static fn (array $item) => $item['start'] > $at && $item['start'] < $at + strlen($tag));
            if ($mine !== []) {
                $placed += $mine;
                $this->tag($tag, $at, $name, $mine);
            }
        }
        // A stray one stays text
        $stray = array_diff_key($items, $placed);
        foreach ($stray as $item) {
            $class = $item['class'] ?? '';
            $this->problem($item['start'], "@class.$class belongs in an HTML start tag: <li @class.$class=\"\$order->late\">");
        }
        $found = array_diff_key($this->found, $stray);
        usort($found, static fn (array $a, array $b) => $a['start'] <=> $b['start']);
        $this->found = $found;
    }

    /**
     * One tag's @class. as a classes() call: appended to its class attribute, or as one where the first was.
     *
     * @param array<int, Found> $items
     */
    private function tag(string $tag, int $at, string $name, array $items): void
    {
        $first = (int) array_key_first($items);
        if (in_array($name, $this->selectors, true)) {
            $this->problem($items[$first]['start'], "<$name @class." . ($items[$first]['class'] ?? '') . ">: a child component takes inputs, not classes. Put the class on an element inside its template, or pass an input");
            foreach (array_keys($items) as $i) {
                $this->found[$i]['code'] = '';
            }
            return;
        }
        preg_match_all('/\sclass\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag, $attributes, PREG_OFFSET_CAPTURE);
        if (count($attributes[0]) > 1) {
            $this->problem($items[$first]['start'], "<$name> has two class attributes: the browser takes the first only");
        }
        $conditions = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $class = $item['class'] ?? '';
            [$from, $to] = $item['expression'] ?? [0, 0];
            if (isset($seen[$class])) {
                $this->problem($item['start'], "@class.$class comes twice in <$name>", 'warning');
            }
            $seen[$class] = true;
            // ponytail: the conditions move to one place, so in a tag over several lines an error in one is reported
            // at the class attribute's line (or the first @class.); per-condition lines need them evaluated in place
            $conditions[] = var_export($class, true) . ' => (' . substr($this->source, $from, $to - $from) . ')';
            // With the spaces before it: no gaps left in the tag
            $this->found[$i] = ['code' => '', 'from' => $item['start'] - strspn(strrev(substr($this->source, 0, $item['start'])), " \t")] + $item;
        }
        $call = '\\' . __NAMESPACE__ . '\\classes([' . implode(', ', $conditions) . '])';
        if ($attributes[1] === []) {
            $this->found[$first]['code'] = " class=\"<?= $call ?>\"";
            return;
        }
        // Before the closing quote, with a space only when the value had one and the call adds something
        [$value, $offset] = $attributes[1][0];
        $quote = $at + $offset + strlen($value) - 1;
        $empty = trim(substr($this->source, $at + $offset + 1, strlen($value) - 2)) === '';
        $this->found[] = ['kind' => 'insert', 'start' => $quote, 'end' => $quote, 'code' => '<?= ' . ($empty ? $call : "rtrim(' ' . $call)") . ' ?>'];
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

    /**
     * PHP that every render would run into a fatal error eval cannot catch, refused before: imports, namespaces and
     * declare (the template has its component's), named functions and classes (declared again on the second
     * render), break and continue outside a loop, yield outside a function, and assignments to $this.
     */
    private function statements(): void
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($this->compile()) as $token) {
            $line = is_array($token) ? $token[2] : $line;
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = [is_array($token) ? $token[0] : $token, is_array($token) ? $token[1] : $token, $line];
            }
        }
        // What each open { or alternative syntax belongs to: 'loop', 'function', 'class' or 'block'
        $stack = [];
        $pending = null;
        $afterDo = false;
        /** @var array<int, string> $opens What the ) at an index opens: the condition of a loop ends there */
        $opens = [];
        $assignments = ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC];
        foreach ($tokens as $i => [$id, $text, $at]) {
            $previous = $tokens[$i - 1][0] ?? T_OPEN_TAG;
            $next = $tokens[$i + 1][0] ?? null;
            $wasDo = $afterDo;
            $afterDo = false;
            $inClass = end($stack) === 'class';
            $message = match (true) {
                $id === T_USE && !$inClass && $previous !== ')' => 'use in a template: it has the imports of its component\'s class file, and @use (App\\Status) adds one',
                $id === T_NAMESPACE => 'namespace in a template: it has the namespace of its component\'s class',
                $id === T_DECLARE => 'declare in a template: PHP takes it only at the start of a file',
                $id === T_FUNCTION && !$inClass && ($next === T_STRING || ($next === '&' && ($tokens[$i + 2][0] ?? null) === T_STRING)) => 'A named function in a template is declared again on the next render, which PHP refuses: use a closure or a method of the component',
                in_array($id, [T_INTERFACE, T_TRAIT, T_ENUM], true) || ($id === T_CLASS && !in_array($previous, [T_DOUBLE_COLON, T_NEW], true)) => (in_array(strtolower($text), ['enum', 'interface'], true) ? 'An' : 'A') . " $text in a template is declared again on the next render, which PHP refuses: declare it in a file of its own",
                in_array($id, [T_YIELD, T_YIELD_FROM], true) && !in_array('function', $stack, true) => 'yield outside a function: a template is no generator',
                $id === T_VARIABLE && $text === '$this' && (in_array($next, $assignments, true) || in_array($previous, [T_AS, T_INC, T_DEC, T_GLOBAL, T_STATIC, T_UNSET], true) || ($previous === '(' && ($tokens[$i - 2][0] ?? null) === T_UNSET) || ($previous === '&' && ($tokens[$i - 2][0] ?? null) === T_AS)) => '$this is the component: a template cannot assign or unset it',
                in_array($id, [T_BREAK, T_CONTINUE], true) => self::loops($text, $stack, $tokens[$i + 1] ?? null),
                default => null,
            };
            if ($message !== null) {
                $this->problems[] = ['line' => $at, 'message' => $message, 'severity' => 'error'];
            }
            if ($id === T_DO) {
                $pending = 'do';
                continue;
            }
            // The while of do { } while () opens nothing. Alternative syntax opens with a colon; a body without
            // braces is a loop up to its ;
            if (in_array($id, [T_FOR, T_FOREACH, T_SWITCH], true) || ($id === T_WHILE && !$wasDo)) {
                $close = self::closing($tokens, $i + 1);
                $opens[$close] = match ($tokens[$close + 1][0] ?? null) {
                    '{' => 'brace',
                    ':' => 'loop',
                    default => 'statement',
                };
                continue;
            }
            match (true) {
                $id === T_FUNCTION => $pending = 'function',
                $id === T_CLASS && $previous === T_NEW => $pending = 'class',
                $id === '{' => [$stack[] = $pending ?? 'block', $pending = null],
                $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES => $stack[] = 'block',
                $id === '}' => $afterDo = array_pop($stack) === 'do',
                in_array($id, [T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH], true) => array_pop($stack),
                $id === ';' || $id === T_CLOSE_TAG => $pending = $pending === 'function' ? null : $pending,
                default => null,
            };
            if ($id === ';' || $id === T_CLOSE_TAG) {
                self::endStatements($stack);
            }
            // After the ) of a loop's condition
            $kind = $opens[$i] ?? null;
            unset($opens[$i]);
            if ($kind === 'brace') {
                $pending = 'loop';
            } elseif ($kind !== null) {
                $stack[] = $kind;
            }
        }
    }

    /**
     * Why a break or continue can never run where it stands, or null: its number of loops is more than there are
     * around it in the template, or no number.
     *
     * @param list<string> $stack
     * @param array{int|string, string, int}|null $next
     */
    private static function loops(string $keyword, array $stack, ?array $next): ?string
    {
        $keyword = strtolower($keyword);
        $levels = $next !== null && $next[0] === T_LNUMBER ? (int) $next[1] : (in_array($next[0] ?? null, [';', T_CLOSE_TAG], true) ? 1 : 0);
        if ($levels < 1) {
            return "$keyword takes a positive whole number of loops, or none";
        }
        $loops = 0;
        foreach (array_reverse($stack) as $kind) {
            if ($kind === 'function' || $kind === 'class') {
                break;
            }
            $loops += in_array($kind, ['loop', 'do', 'statement'], true) ? 1 : 0;
        }
        if ($loops >= $levels) {
            return null;
        }
        return $loops === 0 ? "$keyword outside a loop: PHP refuses it before the template runs" : "$keyword $levels: only $loops " . ($loops === 1 ? 'loop is' : 'loops are') . ' around it';
    }

    /**
     * The index of the ) that closes the ( at $i, or $i - 1 if there is none.
     *
     * @param list<array{int|string, string, int}> $tokens
     */
    private static function closing(array $tokens, int $i): int
    {
        if (($tokens[$i][0] ?? null) !== '(') {
            return $i - 1;
        }
        $depth = 0;
        for (; isset($tokens[$i]); $i++) {
            $depth += match ($tokens[$i][0]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $i;
            }
        }
        return $i - 1;
    }

    /**
     * A ; ends the loops whose body is that one statement.
     *
     * @param list<string> $stack
     */
    private static function endStatements(array &$stack): void
    {
        while (end($stack) === 'statement') {
            array_pop($stack);
        }
    }

    /** Whether PHP code ends in a block comment it never closes; it would run on through what follows. */
    private static function opensComment(string $code): bool
    {
        $tokens = token_get_all("<?php $code");
        $last = end($tokens);
        return is_array($last) && in_array($last[0], [T_COMMENT, T_DOC_COMMENT], true) && str_starts_with($last[1], '/*')
            && (strlen($last[1]) < 4 || !str_ends_with($last[1], '*/'));
    }

    private static function closest(string $name): ?string
    {
        // Not @use: @user in a text is no typo
        foreach ([...array_diff(self::WITH_EXPRESSION, ['use']), ...self::WITHOUT] as $directive) {
            // Not for short names: @on or @id in plain text are no typo of @if
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
