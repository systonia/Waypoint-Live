<?php

namespace Waypoint\Live;

use Attribute;

/**
 * Marks a class as a live component, whose state stays on the server while its template shows in the browser.
 *
 *     #[Component]
 *     final class CitySearch { public string $query = ''; public function search(string $query): void { ... } }
 *
 * The selector is `app-` and the class name in kebab case (`app-city-search`), the template
 * `<ClassName>.Template.php` next to the class's file. Both can be given, the template path relative to the class's
 * file:
 *
 *     #[Component(
 *         selector: 'shop-cart',
 *         template: 'templates/Cart.php'
 *     )]
 *
 * Views place the component with `<app-city-search></app-city-search>` or `$this->live('app-city-search')`; the
 * browser only ever sees the selector. A plugin's components name their selector, so that they never meet an app's.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Component
{
    /**
     * @param string|null $selector The tag that places the component: lowercase, with a dash (`shop-cart`); null for
     *     `app-` and the class name in kebab case
     * @param string|null $template The template file, relative to the class's file; null for
     *     `<ClassName>.Template.php` next to it
     */
    public function __construct(public readonly ?string $selector = null, public readonly ?string $template = null)
    {
    }

    /** @internal The selector of $class: given, or `app-` and the class name in kebab case */
    public function selectorOf(string $class): string
    {
        $name = substr($class, (int) strrpos('\\' . $class, '\\'));
        return $this->selector ?? 'app-' . strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $name));
    }

    /** @internal The template file of $class: given, or `<ClassName>.Template.php`, relative to the class's file */
    public function templateOf(string $class): string
    {
        return $this->template ?? substr($class, (int) strrpos('\\' . $class, '\\')) . '.Template.php';
    }
}
