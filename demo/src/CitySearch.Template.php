<input type="search" data-on-input="search" value="{{ $this->query }}" placeholder="Stadt suchen" aria-label="Stadt suchen">
<p>{{ $this->selected === null ? 'Nichts ausgewählt' : 'Ausgewählt: ' . $this->selected }}</p>
<ul>
@foreach ($this->cities->search($this->query) as $city)
    <app-city-row key="{{ $city }}" city="{{ $city }}" selected="{{ $city === $this->selected }}" pick="{{ $this->select(...) }}"></app-city-row>
@endforeach
</ul>
