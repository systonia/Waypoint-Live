<?php

namespace Waypoint\Live;

use ReflectionClass;
use Waypoint\Container;
use Waypoint\Routing\PropertyInjector;

/** @internal One circuit's #[Scoped] services; everything else comes from the app's container. */
final class Scope extends Container
{
    /** @var array<class-string, bool> */
    private static array $isScoped = [];

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
        self::$isScoped[$id] ??= class_exists($id) && (new ReflectionClass($id))->getAttributes(Scoped::class) !== [];
        if (!self::$isScoped[$id]) {
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
}
