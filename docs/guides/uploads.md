---
title: Uploads
description: Receive files from a file input or a form as Waypoint UploadedFile objects, store them safely, and know the limits.
order: 90
---

A file input with `data-on-change`, or a form with `data-on-submit` that contains one, sends its files over the WebSocket. The method gets each file as a `Waypoint\Http\UploadedFile`, the same class a controller gets for a classic upload.

## Product pictures

The bakery's product pictures live in `public/products`. A back-office component to upload new ones:

```php
<?php
// src/Components/ProductPhotos.php

namespace Bakery\Components;

use Bakery\Services\Pictures;
use Waypoint\Attributes\{Authenticated, Inject};
use Waypoint\Http\UploadedFile;
use Waypoint\Live\Component;

/** New pictures for the shop, in the back office. */
#[Component]
#[Authenticated]
final class ProductPhotos
{
    #[Inject]
    private Pictures $pictures;

    private ?string $preview = null;

    /** @var list<string> */
    private array $saved = [];

    private ?string $error = null;

    /** Null when the selection was cleared. */
    public function pick(?UploadedFile $file): void
    {
        $this->preview = $file === null ? null : "$file->name ($file->size bytes)";
    }

    /** @param array{product: string, photos?: list<UploadedFile>} $form */
    public function save(array $form): void
    {
        $this->error = null;
        foreach ($form['photos'] ?? [] as $photo) {
            if (!in_array($photo->type, ['image/png', 'image/jpeg'], true) || $photo->size > 5_000_000) {
                $this->error = "$photo->name is no PNG or JPEG up to 5 MB";
                continue;
            }
            $path = $this->pictures->pathFor($photo);
            $photo->moveTo($path);
            $this->saved[] = basename($path);
        }
    }
}
```

```html
<!-- src/Components/ProductPhotos.Template.php -->
<input type="file" accept="image/*" data-on-change="pick">
@if ($this->preview !== null)
    <p>Picked {{ $this->preview }}</p>
@endif
<form data-on-submit="save">
    <input name="product" required>
    <input type="file" name="photos[]" accept="image/png,image/jpeg" multiple>
    <button>Upload</button>
</form>
@if ($this->error !== null)
    <p class="error">{{ $this->error }}</p>
@endif
<p>{{ count($this->saved) }} saved</p>
```

`Pictures` picks a name of its own for every file:

```php
<?php
// src/Services/Pictures.php

namespace Bakery\Services;

use Waypoint\Http\UploadedFile;

/** Where the product pictures go: public/products, under names of our own. */
final class Pictures
{
    public string $directory = __DIR__ . '/../../public/products';

    /** A name of our own: never the browser's name as a path. */
    public function pathFor(UploadedFile $file): string
    {
        $extension = match ($file->type) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => 'bin',
        };
        return $this->directory . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    }
}
```

## What the method gets

| Element | The method gets |
|---|---|
| `<input type="file" data-on-change="pick">` | one `UploadedFile`, or `null` when the selection was cleared |
| `<input type="file" multiple data-on-change="pick">` | a list of `UploadedFile` |
| a form field `name="photo"` | `$form['photo']`: one `UploadedFile` |
| a form field `name="photos[]" multiple` | `$form['photos']`: a list of `UploadedFile` |
| an empty file input in a form | the field is left out |

An `UploadedFile` has `name`, `type` and `size`, `contents()` for the bytes, and `moveTo($path)`, which writes the file once. Under Tenma it writes without blocking the worker.

## Handle files with care

- **Name and type come from the browser**, as with any upload. Never use the name as a path; Live already strips directories from it, but a name of your own (as `Pictures` does) is the safe choice. Check the content before trusting the type, for example with `getimagesizefromstring($file->contents())` for an image.
- **Move it, don't keep it.** The file's content is in memory. An `UploadedFile` kept in a component's property stays in memory for the life of the tab; store the file and keep its path or ID.
- **Check the size** in the method if the app has a limit below the one of the connection (below).

## Order and errors

Events keep their order: the browser reads a picked file first, and a click right after picking waits until the file is read and sent. A file the browser cannot read marks the component with `data-live-error`, and the call is not sent.

## Limits

- A file travels base64-encoded inside one WebSocket message, so a message is about a third larger than its files.
- A message is at most Tenma's `--max-body` (10 MB by default): all files of one call together must fit.
- At most 20 files per call.

For files larger than that, a classic upload to a controller route remains the better fit; the component can show the result afterwards.

## Testing

[`Mounted::upload()`](../reference/Waypoint/Live/Testing/Mounted.md#upload) picks a file in a `data-on-change` file input:

```php
$photos = $this->staffTab(self::OWNER)->mount('app-product-photos');
$photos->upload('input[type=file]', 'pretzel.png', $bytes, 'image/png')
    ->assertSee('Picked pretzel.png');
```

See also: [Forms and binding](forms.md), [Deployment and limits](deployment.md#limits).
