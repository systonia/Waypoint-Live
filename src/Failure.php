<?php

namespace Waypoint\Live;

use Throwable;

/**
 * @internal What the browser shows its developer about an error, in dev only: never in production, where it only
 * learns that something failed. Paths, source lines and messages are for the developer's eyes.
 */
final class Failure
{
    private const CONTEXT = 3;

    private const FRAMES = 30;

    /**
     * @param string $cause What the circuit was doing: `click "add"`, `mount`, `#[Every] tick`
     * @return array{class: string, message: string, file: string, line: int, selector: ?string, cause: string, template: ?array{string, int}, snippet: list<array{int, string}>, trace: list<array{call: string, file: string, line: int, vendor: bool}>}
     */
    public static function describe(Throwable $e, string $cause, ?string $selector = null): array
    {
        $template = Template::origin($e);
        [$file, $line] = [$e->getFile(), $e->getLine()];
        // Thrown by the template's own code, or by Live on its behalf (an array printed): the template's line
        if ($template !== null && (str_ends_with($file, "eval()'d code") || str_starts_with($file, __DIR__ . '/'))) {
            [$file, $line] = $template;
        }
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, self::FRAMES) as $frame) {
            if (!isset($frame['file']) || str_ends_with($frame['file'], "eval()'d code")) {
                continue;
            }
            $trace[] = [
                'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()',
                'file' => $frame['file'],
                'line' => $frame['line'] ?? 0,
                // Folded in the overlay: the app's frames are what matters
                'vendor' => str_contains($frame['file'], '/vendor/') || str_starts_with($frame['file'], __DIR__ . '/'),
            ];
        }
        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $file,
            'line' => $line,
            'selector' => $selector,
            'cause' => $cause,
            'template' => $template,
            'snippet' => self::snippet($file, $line),
            'trace' => $trace,
        ];
    }

    /** @return list<array{int, string}> The lines around $line, numbered */
    private static function snippet(string $file, int $line): array
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return [];
        }
        $snippet = [];
        for ($n = max(1, $line - self::CONTEXT); $n <= min(count($lines), $line + self::CONTEXT); $n++) {
            $snippet[] = [$n, $lines[$n - 1]];
        }
        return $snippet;
    }
}
