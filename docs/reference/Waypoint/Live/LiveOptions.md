---
title: "LiveOptions"
description: "Live's options, set with `$app->configure(fn (LiveOptions $live) => $live->editor = '...')`."
order: 100
generated: true
---

`Waypoint\Live\LiveOptions` · class · namespace [`Waypoint\Live`](index.md)

```php
class LiveOptions
```

Live's options, set with `$app->configure(fn (LiveOptions $live) => $live->editor = '...')`.

## Properties

### $editor

```php
public string $editor = 'vscode://file/{file}:{line}';
```

In dev, where a file in an error opens: `{file}` and `{line}` are filled in. VSCodium:
`vscodium://file/{file}:{line}`, PhpStorm: `phpstorm://open?file={file}&line={line}`.
