---
title: "Every"
description: "Runs the method every $seconds while the browser is connected and pushes a new render afterwards."
order: 100
generated: true
---

`Waypoint\Live\Every` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Every
```

Runs the method every $seconds while the browser is connected and pushes a new render afterwards.

## Properties

### $seconds

```php
public readonly float $seconds;
```

**Type** `float`

Time between two runs, more than 0; the first run comes $seconds after the component is created

## Methods

### __construct()

```php
public function __construct(public readonly float $seconds)
```

**Parameters**

- `$seconds` `float` — Time between two runs, more than 0; the first run comes $seconds after the component is created
