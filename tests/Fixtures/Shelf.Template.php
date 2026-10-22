{{/* A shelf:
     every book, or a note */}}
@if ($this->open)
<ul>
@foreach ($this->books as $book)
    <li data-on-click="pick" data-arg="{{ $book }}">{{ $book }}</li>
@endforeach
</ul>
@elseif (count($this->books) > 0)
    closed
@else
    empty
@endif
<test-item key="{{ $this->books[0] }}" pick="{{ $this->pick(...) }}"></test-item>
{{ new \Waypoint\Html('<hr>') }}
mail@example.com @@if
