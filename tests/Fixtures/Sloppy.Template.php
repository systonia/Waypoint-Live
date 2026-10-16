<p>{{ $this->count }}</p>
@foreach ($this->items as $item)
  {{ $item->nmae }} {{ $this->cuont }}
@endforeach
@code $first = $this->items[0] ?? null @endcode
<p>{{ $first?->nmae }} {{ Item::class }}</p>
<?php if ($this->items === []) echo 'none';
