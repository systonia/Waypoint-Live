<?php

namespace Waypoint\Live;

use Closure;
use RuntimeException;

/**
 * `vendor/bin/live-check`: the components of a project checked without running the app. Problems finds what the
 * app checks in development, and PHPStan reads every template as the PHP it becomes, with `$this` as its
 * component: an undefined property, a method with the wrong arguments, a typo in a loop variable's member, at the
 * template's line. In a `tenma.dev.php` hook, publish() shows PHPStan's findings in the editor:
 *
 *     new Tenma\Hook(
 *         name: 'live-check',
 *         inputs: ['src/**'],
 *         run: fn () => Waypoint\Live\Check::publish(__DIR__),
 *     ),
 */
final class Check
{
    /**
     * @internal What PHPStan reads in a generated file: $template runs with `$this` as a $class, private members included,
     * as a template does.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param Closure(Closure(string, array<string, mixed>): string, Closure(string, list<mixed>): string): void $template
     * @param-closure-this T $template
     */
    public static function bind(string $class, Closure $template): void
    {
    }

    /**
     * What Problems finds in all components of the project at $root.
     *
     * @return list<array{file: string, line: int, message: string, severity: 'error'|'warning'}>
     */
    public static function problems(string $root): array
    {
        return Components::scan($root)->check();
    }

    /**
     * What PHPStan finds in the templates of the project at $root, at their lines. Templates with an error Problems
     * finds are left out: they would only fail to parse. PHPStan runs with the project's phpstan.neon (its level,
     * its extensions) if there is one, otherwise at level 6.
     *
     * @return list<array{file: string, line: int, message: string, severity: 'error'}>
     */
    public static function phpstan(string $root): array
    {
        $binary = "$root/vendor/bin/phpstan";
        if (!is_file($binary)) {
            throw new RuntimeException('live-check needs PHPStan: composer require --dev phpstan/phpstan');
        }
        $components = Components::scan($root);
        $broken = array_column(array_filter($components->check(), static fn (array $p) => $p['severity'] === 'error'), 'file', 'file');
        // In the system's temp directory, one per project: nothing to ignore in the project
        $directory = sys_get_temp_dir() . '/live-check-' . md5((string) realpath($root));
        array_map('unlink', glob("$directory/*.php") ?: []);
        @mkdir($directory, 0777, true);
        $templates = [];
        foreach ($components->sources() as $selector => [$class, $path]) {
            $source = file_get_contents($path);
            if ($source === false || isset($broken[$path])) {
                continue;
            }
            // Line for line: the compiled template leaves PHP on the first line, after its namespace and imports and the
            // bind. The closing tag at the end ends a template that ends in PHP, and is only text after one that ends in HTML
            $generated = "$directory/$selector.php";
            $bind = '\\' . self::class . '::bind(\\' . $class . '::class, function ($__child, $__action): void { ';
            file_put_contents($generated, '<?php ' . Template::compile($source, $components->selectors(), class: $class, first: $bind) . "\n?><?php });\n");
            $templates[(string) realpath($generated)] = $path;
        }
        if ($templates === []) {
            return [];
        }
        // Outside the PHP CLI (under Tenma PHP_BINARY is empty) the php on the PATH runs PHPStan
        $command = [PHP_SAPI === 'cli' ? PHP_BINARY : 'php', $binary, 'analyse', '--no-progress', '--error-format=json', '--memory-limit=1G'];
        $command = [...$command, ...(is_file("$root/phpstan.neon") || is_file("$root/phpstan.neon.dist") ? [] : ['--level=6']), ...array_keys($templates)];
        // stderr into a file: read after stdout through a pipe, a full pipe would leave both processes waiting
        $log = (string) tempnam(sys_get_temp_dir(), 'live-check');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $log, 'w']], $pipes, $root);
        if ($process === false) {
            throw new RuntimeException('PHPStan does not start');
        }
        $output = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        $errors = (string) @file_get_contents($log);
        @unlink($log);
        $result = json_decode($output, true);
        if (!is_array($result) || !is_array($result['files'] ?? null)) {
            throw new RuntimeException('PHPStan failed: ' . trim($errors . "\n" . $output));
        }
        $problems = [];
        foreach ($result['files'] as $file => $found) {
            foreach (is_array($found) && is_array($found['messages'] ?? null) ? $found['messages'] : [] as $message) {
                if (!is_array($message) || !is_int($message['line'] ?? null) || !is_string($message['message'] ?? null)) {
                    continue;
                }
                $problems[] = [
                    'file' => $templates[realpath((string) $file) ?: (string) $file] ?? (string) $file,
                    'line' => $message['line'],
                    'message' => $message['message'],
                    'severity' => 'error',
                ];
            }
        }
        return $problems;
    }

    /** For a tenma.dev.php hook: PHPStan's findings in the templates go to the editor, through Tenma::diagnostics(). */
    public static function publish(string $root): void
    {
        $problems = self::phpstan($root);
        if (function_exists('Tenma\\Internal\\diagnostics')) {
            \Tenma::diagnostics('waypoint-live:phpstan', $problems);
        }
    }
}
