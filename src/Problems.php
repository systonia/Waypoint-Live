<?php

namespace Waypoint\Live;

use CompileError;
use ReflectionClass;

/**
 * @internal Mistakes in a template that the compiler lets through and that would fail only later, at a
 * click or a render, or not at all: an action the component lacks, an attribute that is no input of the
 * child, a child tag the page would leave unexpanded, a `//` comment in `<?= ?>`, PHP that does not parse,
 * directives that do not close or are misspelt.
 * The editor shows them while the template is typed.
 */
final class Problems
{
    /**
     * @param class-string $class The component the template belongs to
     * @param array<string, class-string> $components selector => class, every component of the app
     * @return list<array{line: int, message: string, severity: 'error'|'warning'}>
     */
    public static function inTemplate(string $source, string $class, array $components): array
    {
        // Directives first: what they become is plain PHP on the same lines, for every other check
        $directives = new Directives($source);
        $php = $directives->compile();
        // A line comment in an echo tag also breaks the syntax: one message for it is enough
        $comments = self::comments($php);
        $problems = [
            ...$directives->problems,
            ...($comments !== [] || in_array('error', array_column($directives->problems, 'severity'), true) ? $comments : self::syntax($source, array_keys($components), $class)),
            ...self::actions($source, $class),
            ...self::children($php, $components),
        ];
        usort($problems, static fn (array $a, array $b) => $a['line'] <=> $b['line']);
        return $problems;
    }

    /**
     * Mistakes in a component's class: a timer that cannot run, an input the page cannot set.
     *
     * @param class-string $class
     * @return list<array{line: int, message: string, severity: 'error'}>
     */
    public static function inComponent(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $problems = [];
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(Every::class) as $attribute) {
                $message = match (true) {
                    $method->getNumberOfRequiredParameters() > 0 => "#[Every] {$method->getName()}(): a timer calls its method without arguments",
                    $method->isStatic() => "#[Every] {$method->getName()}(): a timer calls a method of the component, not a static one",
                    $attribute->newInstance()->seconds <= 0 => "#[Every] {$method->getName()}(): the seconds must be more than 0",
                    default => null,
                };
                if ($message !== null) {
                    $problems[] = ['line' => (int) $method->getStartLine(), 'message' => $message, 'severity' => 'error'];
                }
            }
        }
        $source = (string) @file_get_contents((string) $reflection->getFileName());
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Input::class) === [] || $property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $message = match (true) {
                !$property->isPublic() => 'is not public',
                $property->isStatic() => 'is static',
                $property->isReadOnly() => 'is readonly',
                default => null,
            };
            if ($message !== null) {
                // Reflection knows no line of a property: where its name is declared
                $at = preg_match('/^.*\$' . $property->getName() . '\b/m', $source, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : 0;
                $problems[] = ['line' => self::line($source, $at), 'message' => "#[Input] \${$property->getName()} $message: the page and parent templates set inputs from outside", 'severity' => 'error'];
            }
        }
        return $problems;
    }

    /**
     * @param list<string> $selectors
     * @param class-string $class
     * @return list<array{line: int, message: string, severity: 'error'}>
     */
    private static function syntax(string $source, array $selectors, string $class): array
    {
        try {
            // Compiled only: `return` comes first, so nothing of it runs. The compiler keeps the lines.
            // ponytail: a function declared in a template would be declared here too
            eval(Template::compile($source, $selectors, class: $class, first: 'return; '));
            return [];
        } catch (CompileError $e) {
            return [['line' => $e->getLine(), 'message' => $e->getMessage(), 'severity' => 'error']];
        }
    }

    /** @return list<array{line: int, message: string, severity: 'error'}> */
    private static function comments(string $source): array
    {
        $problems = [];
        $echo = false;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            $echo = match ($token[0]) {
                T_OPEN_TAG_WITH_ECHO => true,
                T_CLOSE_TAG => false,
                default => $echo,
            };
            if ($echo && $token[0] === T_COMMENT && !str_starts_with($token[1], '/*')) {
                $problems[] = ['line' => $token[2], 'message' => 'A line comment inside <?= ?> also comments out what the template adds before ?>: use /* */', 'severity' => 'error'];
            }
        }
        return $problems;
    }

    /**
     * @param class-string $class
     * @return list<array{line: int, message: string, severity: 'error'}>
     */
    private static function actions(string $source, string $class): array
    {
        $reflection = new ReflectionClass($class);
        $short = $reflection->getShortName();
        $callable = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() && !$method->isStatic() && !str_starts_with($method->getName(), '__')) {
                $callable[] = $method->getName();
            }
        }
        $problems = [];
        preg_match_all('/\bdata-on-([a-z]+)="([^"]*)"/', $source, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($found as [[$attribute, $offset], , [$value]]) {
            // A method, or a call with arguments the server binds: remove($item)
            $name = preg_match('/^([A-Za-z_]\w*)(?:\(.*\))?$/s', $value, $m) === 1 ? $m[1] : null;
            $message = match (true) {
                $name === null => "$attribute: name a method, or call one with arguments: remove(\$item)"
                    . (str_contains($value, '(') ? ". A \" ends the attribute: use ' for strings" : ''),
                !$reflection->hasMethod($name) => "$attribute: $short has no method $name()" . self::didYouMean($name, $callable, '()'),
                !$reflection->getMethod($name)->isPublic() => "$attribute: $short::$name() is not public, the browser cannot call it",
                $reflection->getMethod($name)->isStatic() => "$attribute: $short::$name() is static, the browser calls methods of the component",
                default => null,
            };
            if ($message !== null) {
                $problems[] = ['line' => self::line($source, $offset), 'message' => $message, 'severity' => 'error'];
            }
        }
        return $problems;
    }

    /**
     * @param array<string, class-string> $components
     * @return list<array{line: int, message: string, severity: 'error'|'warning'}>
     */
    private static function children(string $source, array $components): array
    {
        $problems = [];
        $value = '"(?:<\?=.*?\?>|[^"])*"';
        preg_match_all('/<([a-z][a-z0-9]*(?:-[a-z0-9]+)+)(?=[\s>\/])/', $source, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($tags as [[$start, $offset], [$selector]]) {
            $line = self::line($source, $offset);
            $class = $components[$selector] ?? null;
            if ($class === null) {
                $close = self::didYouMean($selector, array_keys($components), '', '<', '>');
                if ($close !== '') {
                    $problems[] = ['line' => $line, 'message' => "<$selector> is no component$close", 'severity' => 'warning'];
                }
                continue;
            }
            // The form the compiler expands: attributes in double quotes, an empty body
            $tag = '/\G<' . preg_quote($selector, '/') . '((?:\s+[A-Za-z_][\w.:-]*(?:=' . $value . ')?)*)\s*(?:\/>|>\s*<\/' . preg_quote($selector, '/') . '>)/s';
            if (preg_match($tag, $source, $m, 0, $offset) !== 1) {
                $problems[] = ['line' => $line, 'message' => "<$selector> stays as it is: a component's tag needs attributes in double quotes and an empty body, <$selector ...></$selector>", 'severity' => 'error'];
                continue;
            }
            $inputs = array_values(array_filter(
                array_map(static fn (\ReflectionProperty $p) => $p->getName(), (new ReflectionClass($class))->getProperties()),
                static fn (string $name) => LivePlugin::isInput($class, $name),
            ));
            preg_match_all('/([A-Za-z_][\w.:-]*)(?:=' . $value . ')?/s', $m[1], $attributes);
            foreach ($attributes[1] as $name) {
                if ($name !== 'key' && !in_array($name, $inputs, true)) {
                    $short = (new ReflectionClass($class))->getShortName();
                    $hint = self::didYouMean($name, $inputs, '') ?: ($inputs === [] ? ". $short has no inputs" : '. Inputs: ' . implode(', ', $inputs));
                    $problems[] = ['line' => $line, 'message' => "<$selector $name>: '$name' is not an #[Input] of $short$hint", 'severity' => 'error'];
                }
            }
        }
        return $problems;
    }

    /**
     * In a rendered page, after its tags were expanded: a component's tag that stayed as it was, an attribute close
     * to an input of its component, a tag close to a selector. Warnings: the page renders anyway.
     *
     * @param array<string, class-string> $components
     * @return list<string>
     */
    public static function inPage(string $html, array $components): array
    {
        $messages = [];
        preg_match_all('/<([a-z][a-z0-9]*(?:-[a-z0-9]+)+)((?:\s[^>]*)?)>/', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $selector, $attributes]) {
            $class = $components[$selector] ?? null;
            if ($class === null) {
                $close = self::didYouMean($selector, array_keys($components), '', '<', '>');
                if ($close !== '') {
                    $messages[] = "<$selector> is no component$close Or is it missing from new LivePlugin([...])?";
                }
                continue;
            }
            if (preg_match('/\sdata-live\b/', $attributes) !== 1) {
                $messages[] = "<$selector> stays as it is: a component's tag needs attributes in double quotes and an empty body, <$selector ...></$selector>";
                continue;
            }
            $inputs = array_values(array_filter(
                array_map(static fn (\ReflectionProperty $p) => $p->getName(), (new ReflectionClass($class))->getProperties()),
                static fn (string $name) => LivePlugin::isInput($class, $name),
            ));
            preg_match_all('/\s([A-Za-z_][\w.:-]*)=/', $attributes, $names);
            foreach ($names[1] as $name) {
                $close = self::didYouMean($name, $inputs, '');
                if ($close !== '' && !in_array($name, $inputs, true)) {
                    $messages[] = "<$selector $name>: '$name' stays an attribute of the tag, it is no #[Input]$close";
                }
            }
        }
        return array_values(array_unique($messages));
    }

    /** @param list<string> $candidates */
    private static function didYouMean(string $name, array $candidates, string $suffix, string $before = '', string $after = ''): string
    {
        $best = null;
        $distance = 3;
        foreach ($candidates as $candidate) {
            $d = levenshtein(strtolower($name), strtolower($candidate));
            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }
        return $best !== null ? ". Did you mean $before$best$suffix$after?" : '';
    }

    private static function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }
}
