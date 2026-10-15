<p>Warenkorb: {{ $this->cart->count() }} Artikel</p>
<ul>
@foreach ($this->cart->items as $product => $quantity)
    <li data-key="{{ $product }}">{{ $quantity }} × {{ $product }}</li>
@endforeach
</ul>
<button data-on-click="clear">Leeren</button>
