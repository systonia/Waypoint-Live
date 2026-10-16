@use (Waypoint\Live\Tests\Fixtures\Sub\Phase as Stage)
@code $open = $this->phase === Phase::Open @endcode
<p>{{ Mood::Calm->name }} {{ shout($this->phase->name) }} {{ $open ? 'open' : 'closed' }} {{ new Markup('<b>') }} {{ Waypoint\Live\Tests\Fixtures\Mood::Calm->name }} {{ Stage::Closed->name }}</p>
<button data-on-click="toggle" class="btn" @class.done="$this->done" @class.phase-[open]:ring="$open">Toggle</button>
