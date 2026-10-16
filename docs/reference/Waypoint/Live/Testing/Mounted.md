---
title: "Mounted"
description: "A component in a Tab: events go through its template as the browser's would, by the method an element names (`click('increment')`) or by a CSS selector (`click('button.reset')`), and the assertions read the HTML the browser would have."
order: 100
generated: true
---

`Waypoint\Live\Testing\Mounted` · final class · namespace [`Waypoint\Live\Testing`](index.md)

```php
final class Mounted
```

A component in a Tab: events go through its template as the browser's would, by the method an element names
(`click('increment')`) or by a CSS selector (`click('button.reset')`), and the assertions read the HTML the
browser would have.

## Methods

### click()

```php
public function click(string $target): \Waypoint\Live\Testing\Mounted
```

The element whose data-on-click names $target, or that $target selects; its data-arg goes along.

**Parameters**

- `$target` `string`

**Returns** [`Mounted`](Mounted.md)

### input()

```php
public function input(string $target, string $value): \Waypoint\Live\Testing\Mounted
```

Types $value into a data-on-input field, without the browser's wait.

**Parameters**

- `$target` `string`
- `$value` `string`

**Returns** [`Mounted`](Mounted.md)

### change()

```php
public function change(string $target, string|bool $value): \Waypoint\Live\Testing\Mounted
```

A data-on-change field changes: its value, or true or false for a checkbox.

**Parameters**

- `$target` `string`
- `$value` `string|bool`

**Returns** [`Mounted`](Mounted.md)

### submit()

```php
public function submit(string $target, array $form): \Waypoint\Live\Testing\Mounted
```

Submits the data-on-submit form that $target names or selects, with $form as its fields.

**Parameters**

- `$target` `string`
- `$form` `array<string, mixed>` — The form's fields as the browser sends them: `name[]` fields as a list

**Returns** [`Mounted`](Mounted.md)

### upload()

```php
public function upload(
    string $target,
    string $name,
    string $content,
    string $type = 'application/octet-stream',
): \Waypoint\Live\Testing\Mounted
```

A file picked in a data-on-change file input: the method gets it as an UploadedFile.

**Parameters**

- `$target` `string`
- `$name` `string`
- `$content` `string`
- `$type` `string`

**Returns** [`Mounted`](Mounted.md)

### find()

```php
public function find(string $selector, string|int|null $key = null): \Waypoint\Live\Testing\Mounted
```

A child placed by this component's template: by its key, or the first of its tag.

**Parameters**

- `$selector` `string`
- `$key` `string|int|null`

**Returns** [`Mounted`](Mounted.md)

### html()

```php
public function html(): string
```

The HTML the browser has inside the component's tag.

**Returns** `string`

### component()

```php
public function component(): object
```

The component itself, for its state.

**Returns** `object`

### assertSee()

```php
public function assertSee(string $text): \Waypoint\Live\Testing\Mounted
```

$text as the template prints it, escaped.

**Parameters**

- `$text` `string`

**Returns** [`Mounted`](Mounted.md)

### assertDontSee()

```php
public function assertDontSee(string $text): \Waypoint\Live\Testing\Mounted
```

That $text, escaped as the template prints it, is not in the component's HTML.

**Parameters**

- `$text` `string`

**Returns** [`Mounted`](Mounted.md)
