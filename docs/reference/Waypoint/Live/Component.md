---
title: "Component"
description: "Marks a class as a live component, whose state stays on the server while its template shows in the browser."
order: 100
generated: true
---

`Waypoint\Live\Component` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Component
```

Marks a class as a live component, whose state stays on the server while its template shows in the browser.

    #[Component]
    final class CitySearch { public string $query = ''; public function search(string $query): void { ... } }

The selector is `app-` and the class name in kebab case (`app-city-search`), the template
`<ClassName>.Template.php` next to the class's file. Both can be given, the template path relative to the class's
file:

    #[Component(
        selector: 'shop-cart',
        template: 'templates/Cart.php'
    )]

Views place the component with `<app-city-search></app-city-search>` or `$this->live('app-city-search')`; the
browser only ever sees the selector. A plugin's components name their selector, so that they never meet an app's.

## Properties

### $selector

```php
public readonly ?string $selector = null;
```

**Type** `string|null`

The tag that places the component: lowercase, with a dash (`shop-cart`); null for `app-` and the class name in kebab case

### $template

```php
public readonly ?string $template = null;
```

**Type** `string|null`

The template file, relative to the class's file; null for `<ClassName>.Template.php` next to it

## Methods

### __construct()

```php
public function __construct(
    public readonly ?string $selector = null,
    public readonly ?string $template = null,
)
```

**Parameters**

- `$selector` `string|null` — The tag that places the component: lowercase, with a dash (`shop-cart`); null for `app-` and the class name in kebab case
- `$template` `string|null` — The template file, relative to the class's file; null for `<ClassName>.Template.php` next to it
