<?php

namespace Waypoint\Live;

use ReflectionClass;
use Waypoint\Container;
use Waypoint\Routing\PropertyInjector;

/** @internal One circuit's #[Scoped] services; everything else comes from the app's container. */
final class Scope extends Container
{
    /** @var array<string, bool> */
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
        if (!self::isScoped($id)) {
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

    /** PropertyInjector asks this before get(): the app's services count, constructor arguments or not. */
    public function has(string $class): bool
    {
        return self::isScoped($class) || $this->app->has($class);
    }

    private static function isScoped(string $class): bool
    {
        return self::$isScoped[$class] ??= class_exists($class) && (new ReflectionClass($class))->getAttributes(Scoped::class) !== [];
    }
}
