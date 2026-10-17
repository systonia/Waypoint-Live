<?php

namespace Waypoint\Live;

use Waypoint\View\Checks;

/**
 * The components' templates in `vendor/bin/waypoint-check` (composer.json names it under
 * extra.waypoint.checks): what Problems finds, and each template as the PHP PHPStan reads, with `$this` as its
 * component, private members included.
 */
final class TemplateChecks implements Checks
{
    private ?Components $components = null;

    public function __construct(private readonly string $root)
    {
    }

    public function problems(): array
    {
        return $this->components()->check();
    }

    public function generate(string $directory): array
    {
        $components = $this->components();
        $broken = array_column(array_filter($components->check(), static fn (array $p) => $p['severity'] === 'error'), 'file', 'file');
        $templates = [];
        foreach ($components->sources() as $selector => [$class, $path]) {
            $source = file_get_contents($path);
            if ($source === false || isset($broken[$path])) {
                continue;
            }
            // Line for line: the compiled template leaves PHP on the first line, after its namespace and imports and the
            // bind. The closing tag at the end ends a template that ends in PHP, and is only text after one that ends in HTML
            $generated = "$directory/$selector.php";
            $bind = '\\' . Check::class . '::bind(\\' . $class . '::class, function ($__child, $__action): void { ';
            file_put_contents($generated, '<?php ' . Template::compile($source, $components->selectors(), class: $class, first: $bind) . "\n?><?php });\n");
            $templates[$generated] = $path;
        }
        return $templates;
    }

    private function components(): Components
    {
        return $this->components ??= Components::scan($this->root);
    }
}
