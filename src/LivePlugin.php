<?php

namespace Waypoint\Live;

use InvalidArgumentException;
use Waypoint\Container;
use Waypoint\Plugin\{ClientAsset, PluginBase, ViewHelper};
use Waypoint\Routing\PropertyInjector;

/**
 * Register with `$app->plugin(new LivePlugin([Counter::class, Search::class]))`: only the listed components can
 * be created, the browser names the class when it connects. Adds `$this->live(Counter::class, $props)` for
 * views, the WebSocket route `/_live` (LiveController) and the client script. Runs under Tenma only.
 */
final class LivePlugin extends PluginBase implements ViewHelper, ClientAsset
{
    private Container $container;

    /** @param list<class-string<Component>> $components */
    public function __construct(private array $components)
    {
    }

    public function name(): string
    {
        return 'live';
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        $container->set($this);
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

    /** A listed component with its #[Inject] properties set; anything else is refused. */
    public function create(string $class): Component
    {
        if (!in_array($class, $this->components, true)) {
            throw new InvalidArgumentException("'$class' is not a live component of this app");
        }
        $component = new $class();
        // ponytail: services the component injects must be reachable from a controller or need no constructor arguments
        (new PropertyInjector($this->container))->injectReflected($component);
        return $component;
    }

    /** @param array<string, mixed> $props */
    private function renderInPage(string $class, array $props = []): string
    {
        $component = $this->create($class);
        $component->mount($props);
        return sprintf(
            '<div wp-live="%s" wp-live-props="%s">%s</div>',
            htmlspecialchars($class, ENT_QUOTES),
            htmlspecialchars(json_encode((object) $props, JSON_THROW_ON_ERROR), ENT_QUOTES),
            $component->render(),
        );
    }
}
