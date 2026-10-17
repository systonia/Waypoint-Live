@foreach ($this->shades() as $shade)
<button data-on-click="pick($shade)">{{ $shade->name }}</button>
@endforeach
<button data-on-click="feel(Mood::Calm)">calm</button>
<p>{{ $this->picked }}</p>
