<?php

namespace Waypoint\Live\PHPStan;

use PHPStan\Reflection\{ClassReflection, MethodReflection, PropertyReflection};
use PHPStan\Rules\Methods\AlwaysUsedMethodExtension;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;
use Waypoint\Live\{Component, Every};

/**
 * PHPStan does not read templates: to it, a private property that only a component's template shows is never
 * read, and a private method only a template or a timer calls is unused. These count as used in a #[Component]
 * class. Loaded by PHPStan only (extension.neon, included through phpstan/extension-installer).
 */
final class TemplateMembers implements ReadWritePropertiesExtension, AlwaysUsedMethodExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return self::isComponent($property->getDeclaringClass());
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isAlwaysUsed(MethodReflection $method): bool
    {
        return self::isComponent($method->getDeclaringClass())
            || $method->getDeclaringClass()->getNativeReflection()->getMethod($method->getName())->getAttributes(Every::class) !== [];
    }

    private static function isComponent(ClassReflection $class): bool
    {
        return $class->getNativeReflection()->getAttributes(Component::class) !== [];
    }
}
