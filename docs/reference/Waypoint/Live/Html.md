---
title: "Html"
description: "Markup a template prints as it is: `<?= $this->region('main') ?>` where region() returns Html."
order: 100
generated: true
---

`Waypoint\Live\Html` · final readonly class · namespace [`Waypoint\Live`](index.md)

```php
final readonly class Html implements \Stringable
```

Implements [`Stringable`](https://www.php.net/manual/en/class.stringable.php)

Markup a template prints as it is: `<?= $this->region('main') ?>` where region() returns Html. Only for HTML
that was built safely, such as another template's output; anything from a user stays a string and is escaped.

## Methods

### __construct()

```php
public function __construct(private readonly string $html)
```

**Parameters**

- `$html` `string`: Markup that is safe as it is: escape everything from a user before it goes in

### __toString()

```php
public function __toString(): string
```

**Returns** `string`
