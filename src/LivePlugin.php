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
        $broadcast = new Broadcast();
        $container->set($this);
        $container->set($this->components);
        $container->set($broadcast);
        // In dev a saved template shows at once, in every open tab and with its state: --watch restarts the
        // workers only for PHP that was included, and a template is read
        if ($this->dev && class_exists(\Tenma::class, false)) {
            // ponytail: polls 4 times a second; a watcher from Tenma once a second user needs one
            \Tenma::setInterval(function () use ($broadcast, $logger): void {
                $changed = $this->components->reload(function (string $file, ?Throwable $e) use ($broadcast, $logger): void {
                    if ($e !== null) {
                        $logger->error('live: ' . $e->getMessage(), ['exception' => $e]);
                    }
                    $broadcast->problem($file, $e);
                    $this->failed("template $file", $e === null ? null : Failure::describe($e, 'template saved'));
                });
                if ($changed) {
                    $broadcast->changedHere();
                }
            }, 0.25);
        }
    }

    /** @internal Whether the app runs in development: then the browser gets details meant for its developer. */
    public function isDev(): bool
    {
        return $this->dev;
    }

    /**
     * @internal In dev: what failed under $key (a component of a tab, a template), or null once it works again. The
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
        $res->mapBody(fn (string $body): string => (string) preg_replace_callback(
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
        ));
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
