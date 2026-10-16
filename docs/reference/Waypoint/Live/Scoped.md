---
title: "Scoped"
description: "One instance per circuit (browser tab) instead of one per app: a cart, a wizard's progress, a tab's filters."
order: 100
generated: true
---

`Waypoint\Live\Scoped` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Scoped
```

One instance per circuit (browser tab) instead of one per app:
a cart, a wizard's progress, a tab's filters. Every component of the tab that injects it gets the same
instance; another tab gets its own. It lives as long as the circuit. Needs a constructor without required
arguments; its own #[Inject] properties are set, scoped ones from the same circuit.
