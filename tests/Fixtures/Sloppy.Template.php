<p>{{ $this->count }}</p>
@foreach ($this->items as $item)
  {{ $item->nmae }} {{ $this->cuont }}
@endforeach
<?php if ($this->items === []) echo 'none';
