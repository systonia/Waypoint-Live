@use (Waypoint\Live\Tests\Fixtures\Sub\Phase as Stage)
@code $open = $this->phase === Phase::Open @endcode
<p>{{ Mood::Calm->name }} {{ shout($this->phase->name) }} {{ $open ? 'open' : 'closed' }} {{ new Markup('<b>') }} {{ Waypoint\Live\Tests\Fixtures\Mood::Calm->name }} {{ Stage::Closed->name }}</p>
