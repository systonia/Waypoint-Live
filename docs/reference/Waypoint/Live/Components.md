---
title: "Components"
description: "The app's live components: those given to LivePlugin, and those another plugin adds in its boot(), as `$container->get(Components::class)->add(Login::class)`."
order: 100
generated: true
---

`Waypoint\Live\Components` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Components
```

The app's live components: those given to LivePlugin, and those another plugin adds in its boot(), as
`$container->get(Components::class)->add(Login::class)`. Only these can be created; the browser names them by
selector. The templates are compiled on first use, once every selector is known.

## Methods

### add()

```php
public function add(string ...$classes): \Waypoint\Live\Components
```

Adds components, at boot: a class without #[Component], a selector that is taken or no custom element name,
or a missing template is refused at once.

**Parameters**

- `...$classes` `class-string`: Classes with a #[Component] attribute

**Returns** [`Components`](Components.md)

**Throws**

- [`InvalidArgumentException`](https://www.php.net/manual/en/class.invalidargumentexception.php): for a class that cannot be a component
- [`LogicException`](https://www.php.net/manual/en/class.logicexception.php): once a page has used the components

### selectors()

```php
public function selectors(): array
```

**Returns** `list<string>`: The selectors of every component added so far
