<?php

namespace Waypoint\Live;

use InvalidArgumentException;
use ReflectionProperty;
use Throwable;
use Waypoint\Container;
use Waypoint\Environment;
use Waypoint\Logger;
use Waypoint\Http\{Request, Response};
use Waypoint\Plugin\{ClientAsset, PluginBase, ResponseHook, ViewHelper};
use Waypoint\Routing\PropertyInjector;

/**
 * Register with `$app->plugin(new LivePlugin([Counter::class, Search::class]))`: classes with a #[Component]
 * attribute, and only these can be created; the browser names them by selector. A plugin brings its own through
 * the Components service. Adds
 * `$this->live('app-counter', $inputs)` and `<app-counter count="3"></app-counter>` for views, the WebSocket route `/_live` (LiveController) and the client
 * script. Runs under Tenma only.
 */
final class LivePlugin extends PluginBase implements ViewHelper, ClientAsset, ResponseHook
{
    private Container $container;

    private Components $components;

    private PageRequest $page;

    private bool $dev = false;

    private LiveOptions $options;

    private Logger $logger;

    /** @var array<string, true> In dev: the warnings about pages already logged by this worker */
    private array $warned = [];

    /** @var array<string, array{file: string, line: int, message: string}> In dev: what failed and not yet rendered again, for the editor */
    private array $failures = [];

    /** @param list<class-string> $components Classes with a #[Component] attribute; other plugins add theirs to Components */
    public function __construct(array $components = [])
    {
        $this->components = (new Components())->add(...$components);
        $this->page = new PageRequest();
    }

    public function middlewares(): array
    {
        return [$this->page];
    }

    public function name(): string
    {
        return 'live';
    }

    public function boot(Container $container, Environment $environment, Logger $logger, LiveOptions $options): void
    {
        $this->container = $container;
        $this->dev = $environment->isDev();
        $this->options = $options;
        $this->logger = $logger;
        $broadcast = new Broadcast();
        $container->set($this);
        $container->set($this->components);
        $container->set($broadcast);
        // In dev a saved template shows at once, in every open tab and with its state: --watch restarts the
        // workers only for PHP that was included, and a template is read
        if ($this->dev && class_exists(\Tenma::class, false)) {
            // ponytail: polls 4 times a second; a watcher from Tenma once a second user needs one
            $checked = false;
            \Tenma::setInterval(function () use ($broadcast, $logger, &$checked): void {
                $saved = false;
                $changed = $this->components->reload(function (string $file, ?Throwable $e) use ($broadcast, $logger, &$saved): void {
                    $saved = true;
                    if ($e !== null) {
                        $logger->error('live: ' . $e->getMessage(), ['exception' => $e]);
                    }
                    // The editor gets it from check() below
                    $broadcast->problem($file, $e);
                });
                if ($changed) {
                    $broadcast->changedHere();
                }
                // Once the plugins have added theirs, and after every saved template
                if (!$checked || $saved) {
                    $checked = true;
                    $this->check($logger);
                }
            }, 0.25);
        }
    }

    /** @internal Whether the app runs in development: then the browser gets details meant for its developer. */
    public function isDev(): bool
    {
        return $this->dev;
    }

    /** @internal In dev: a tab is gone, and what failed in it with it. */
    public function forget(string $tab): void
    {
        $before = count($this->failures);
        $this->failures = array_filter($this->failures, static fn (string $key) => !str_starts_with($key, "$tab "), ARRAY_FILTER_USE_KEY);
        if (count($this->failures) !== $before && function_exists('Tenma\\Internal\\diagnostics')) {
            \Tenma::diagnostics('waypoint-live', array_values($this->failures));
        }
    }

    /**
     * In dev: what Problems finds in all components goes to the terminal and the editor (Tenma::diagnostics()),
     * or to the log under a Tenma without it.
     */
    private function check(Logger $logger): void
    {
        $problems = $this->components->check();
        if (function_exists('Tenma\\Internal\\diagnostics')) {
            \Tenma::diagnostics('waypoint-live:check', $problems);
            return;
        }
        foreach ($problems as $problem) {
            $logger->warning("live: {$problem['file']}:{$problem['line']}: {$problem['message']}");
        }
    }

    /**
     * @internal In dev: what failed under $key (a component of a tab), or null once it renders again. The
     * editor shows the failures at their line, through Tenma::diagnostics() and `tenma lsp`.
     *
     * @param array{file: string, line: int, message: string, class: string, cause: string, selector: ?string}|null $failure
     */
    public function failed(string $key, ?array $failure): void
    {
        if (!$this->dev || ($failure === null && !isset($this->failures[$key]))) {
            return;
        }
        if ($failure === null) {
            unset($this->failures[$key]);
        } else {
            $where = ($failure['selector'] !== null ? "<{$failure['selector']}> " : '') . $failure['cause'];
            $this->failures[$key] = ['file' => $failure['file'], 'line' => $failure['line'], 'message' => "$where: {$failure['class']}: {$failure['message']}"];
        }
        // Under a Tenma that has it; a list per worker, the last one wins
        if (function_exists('Tenma\\Internal\\diagnostics')) {
            \Tenma::diagnostics('waypoint-live', array_values($this->failures));
        }
    }

    /**
     * @internal In dev, after `tenma --watch` ran the app again: 'state' when what changed is a component or its
     * template, so the open pages keep their components' state; 'page' when they load again.
     */
    public function reloaded(): string
    {
        $path = function_exists('tenma_reloaded_by') ? \Tenma::reloadedBy() : null;
        $path = $path === null ? null : (realpath($path) ?: $path);
        foreach ($this->components->sources() as [$class, $template]) {
            if ($path !== null && in_array($path, [realpath($template), realpath((string) (new \ReflectionClass($class))->getFileName())], true)) {
                return 'state';
            }
        }
        return 'page';
    }

    /** @internal In dev: how the browser links a file to the editor, `{file}` and `{line}` filled in. */
    public function editor(): string
    {
        return $this->options->editor;
    }

    /** @internal The template a selector renders with now: in dev a saved one replaces the last. */
    public function template(string $selector): ?Template
    {
        return $this->components->get($selector)[1] ?? null;
    }

    public function classes(): array
    {
        return [LiveController::class];
    }

    public function hooks(): array
    {
        return [$this];
    }

    public function helpers(): array
    {
        return ['live' => $this->renderInPage(...)];
    }

    public function assets(): array
    {
        return ['live.js' => __DIR__ . '/../assets/live.js'];
    }

    /**
     * A listed component with its #[Inject] properties and its inputs set; anything else is refused.
     *
     * @param array<array-key, mixed> $inputs
     * @return array{object, Template}
     */
    public function create(string $selector, array $inputs, Scope $services): array
    {
        [$class, $template] = $this->components->get($selector) ?? throw new InvalidArgumentException("'$selector' is not a live component of this app");
        $component = new $class();
        // ponytail: services the component injects must be reachable from a controller or need no constructor arguments
        (new PropertyInjector($services))->injectReflected($component);
        $this->setInputs($component, $selector, $inputs);
        return [$component, $template];
    }

    /**
     * Sets #[Input] properties; any other name is refused.
     *
     * @param array<array-key, mixed> $inputs
     */
    public function setInputs(object $component, string $selector, array $inputs): void
    {
        foreach ($inputs as $name => $value) {
            if (!$this->isInput($component::class, (string) $name)) {
                throw new InvalidArgumentException("'$name' is not an #[Input] of '$selector'");
            }
            $component->{$name} = $value;
        }
    }

    /**
     * @internal Whether $name is an #[Input] the page and parent templates may set.
     *
     * @param class-string $class
     */
    public static function isInput(string $class, string $name): bool
    {
        $property = property_exists($class, $name) ? new ReflectionProperty($class, $name) : null;
        return $property !== null && $property->isPublic() && !$property->isStatic() && $property->getAttributes(Input::class) !== [];
    }

    /**
     * Expands the components' tags in a rendered HTML page. Attributes are strings in double quotes: an #[Input]
     * becomes an input, any other (class, id, ...) stays on the tag. An attribute without a value, e.g. data-live
     * on a rendered component, leaves the tag alone.
     */
    public function after(array $plan, Request $req, Response $res): void
    {
        if (!str_starts_with((string) $res->getHeader('Content-Type'), 'text/html') || $this->components->selectors() === []) {
            return;
        }
        $selectors = implode('|', array_map(static fn (string $s) => preg_quote($s, '/'), $this->components->selectors()));
        $res->mapBody(fn (string $body): string => $this->warn((string) preg_replace_callback(
            '/<(' . $selectors . ')((?:\s+[A-Za-z_][\w.:-]*="[^"]*")*)\s*(?:\/>|>\s*<\/\1>)/',
            function (array $m) use ($req): string {
                preg_match_all('/\s+([A-Za-z_][\w.:-]*)="([^"]*)"/', $m[2], $attributes, PREG_SET_ORDER);
                $inputs = [];
                $rest = '';
                foreach ($attributes as [$attribute, $name, $value]) {
                    if ($this->isInput(($this->components->get($m[1]) ?? [\stdClass::class])[0], $name)) {
                        $inputs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
                    } else {
                        $rest .= $attribute;
                    }
                }
                return Circuit::page($this, $m[1], $inputs, $rest, $req);
            },
            $body,
        )));
    }

    /** @internal In dev: a warning about how the app uses Live, logged once per worker for each $kind (or text). */
    public function notice(string $message, ?string $kind = null): void
    {
        $kind ??= $message;
        if ($this->dev && !isset($this->warned[$kind])) {
            $this->warned[$kind] = true;
            $this->logger->warning("live: $message");
        }
    }

    /** In dev: logs what Problems finds in a rendered page, each once; the page as it is. */
    private function warn(string $page): string
    {
        if ($this->dev) {
            $classes = array_map(static fn (array $found) => $found[0], $this->components->sources());
            foreach (Problems::inPage($page, $classes) as $message) {
                $this->notice("in a page, $message");
            }
        }
        return $page;
    }

    /** A new circuit's services: its own #[Scoped] ones, the app's for the rest. */
    public function scope(): Scope
    {
        return new Scope($this->container);
    }

    /** @param array<string, mixed> $inputs */
    private function renderInPage(string $selector, array $inputs = []): string
    {
        return Circuit::page($this, $selector, $inputs, '', $this->page->current());
    }
}
