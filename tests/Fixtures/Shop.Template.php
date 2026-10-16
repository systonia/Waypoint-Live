@foreach ($this->products as $product)
<button data-on-click="remove($product)">{{ $product->name }}</button>
<input data-on-input="rename($product)">
@endforeach
<button data-on-click="reverse">reverse</button>
<button data-on-click="add">add</button>
