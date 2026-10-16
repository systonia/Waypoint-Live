---
title: "Input"
description: "A public property the page sets when it places the component: `$this->live('app-counter', ['count' => 3])`."
order: 100
generated: true
---

`Waypoint\Live\Input` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Input
```

A public property the page sets when it places the component: `$this->live('app-counter', ['count' => 3])`.
The value comes back from the browser when it connects, so treat it like a query parameter, never as proof
of who the user is. Properties without #[Input] cannot be set from outside.
