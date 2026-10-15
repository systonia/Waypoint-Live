@foreach ($this->counters as $n)
<p data-key="{{ $n }}">Zähler {{ $n }}: <app-counter key="{{ $n }}"></app-counter></p>
@endforeach
<button data-on-click="add">Zähler hinzufügen</button>
<button data-on-click="removeFirst">Ersten entfernen</button>
<button data-on-click="reverse">Umdrehen</button>
