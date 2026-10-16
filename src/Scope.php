<?php

namespace Waypoint\Live;

use LogicException;
use ReflectionClass;
use Waypoint\Attributes\Inject;
use Waypoint\Container;
use Waypoint\Routing\PropertyInjector;

/** @internal One circuit's #[Scoped] services; everything else comes from the app's container. */
final class Scope extends Container
{
    /** @var array<string, bool> */
    private static array $isScoped = [];

    /** @var array<string, true> App-wide services that inject nothing #[Scoped], not even further down */
    private static array $appWide = [];

    /** @var array<class-string, object> */
    private array $scoped = [];

    public function __construct(private readonly Container $app)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id)
    {
        if (!self::isScoped($id)) {
            self::refuseScopedIn($id);
            return $this->app->get($id);
        }
        $service = $this->scoped[$id] ?? null;
        if ($service instanceof $id) {
            return $service;
        }
        // Registered before its properties: two scoped services may inject each other
        $service = $this->scoped[$id] = new $id();
        (new PropertyInjector($this))->injectReflected($service);
        return $service;
    }

    /** @internal A scoped service the circuit creates itself, such as its Connection. */
    public function put(object $service): void
    {
        $this->scoped[$service::class] = $service;
    }

    /** @return list<class-string> The tab's own services so far, for live.inspect() */
    public function classes(): array
    {
        return array_keys($this->scoped);
    }

    /** PropertyInjector asks this before get(): the app's services count, constructor arguments or not. */
    public function has(string $class): bool
    {
        return self::isScoped($class) || $this->app->has($class);
    }

    /**
     * The app's container fills an app-wide service's #[Inject] properties itself: a #[Scoped] one among them,
     * directly or through another service, would be one instance for every tab and user.
     *
     * @param list<string> $path The services that led here
     */
    private static function refuseScopedIn(string $class, array $path = []): void
    {
        if (isset(self::$appWide[$class]) || in_array($class, $path, true) || !class_exists($class)) {
            return;
        }
        $path[] = $class;
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $type = PropertyInjector::namedType($property);
            if ($property->getAttributes(Inject::class) === [] || $type === null) {
                continue;
            }
            if (self::isScoped($type)) {
                throw new LogicException(implode(' injects ', $path) . " injects #[Scoped] $type: an app-wide service would share it between all tabs. Make $class #[Scoped] too, or inject $type where it is used");
            }
            self::refuseScopedIn($type, $path);
        }
        self::$appWide[$class] = true;
    }

    private static function isScoped(string $class): bool
    {
        return self::$isScoped[$class] ??= class_exists($class) && (new ReflectionClass($class))->getAttributes(Scoped::class) !== [];
    }
}
