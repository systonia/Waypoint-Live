<?php

namespace Waypoint\Live;

use Attribute;

/**
 * Marks a class as a live component, whose state stays on the server while its template shows in the browser.
 *
 *     #[Component]
 *     final class AddToCart { #[Input] public int $product = 0; public function add(): void { ... } }
 *
 * The selector is `app-` and the class name in kebab case (`app-add-to-cart`), the template
 * `<ClassName>.Template.php` next to the class's file. Both can be given, the template path relative to the class's
 * file:
 *
 *     #[Component(
 *         selector: 'shop-cart',
 *         template: 'templates/Cart.php'
 *     )]
 *
 * Views place the component with `<app-add-to-cart product="3"></app-add-to-cart>` or
 * `$this->live('app-add-to-cart', ['product' => 3])`; the browser only ever sees the selector. A plugin's components
 * name their selector, so that they never meet an app's.
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

    /** @internal The selector of a class by its short $name: given, or `app-` and $name in kebab case */
    public function selectorOf(string $name): string
    {
        return $this->selector ?? 'app-' . strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $name));
    }

    /** @internal A class's template by its short $name, relative to its file: given, or `$name.Template.php` */
    public function templateOf(string $name): string
    {
        return $this->template ?? "$name.Template.php";
    }
}
