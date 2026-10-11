<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Http\UploadedFile;
use Waypoint\Live\Component;

#[Component(
    selector: 'test-uploader',
    template: 'Uploader.Template.php'
)]
final class Uploader
{
    public string $summary = '';

    public function pick(?UploadedFile $file): void
    {
        $this->summary = $file === null ? 'none' : "$file->name $file->type $file->size {$file->contents()}";
    }

    /** @param array<string, mixed> $form */
    public function save(array $form): void
    {
        $photos = $form['photos'] ?? [];
        $this->summary = $form['title'] . ': ' . implode(', ', array_map(fn (UploadedFile $photo) => $photo->name, is_array($photos) ? $photos : []));
    }
}
