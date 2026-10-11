<?php foreach ($this->items as $item): ?>
<test-item key="{$item}" name="{$item}" pick="{$this->pick(...)}"></test-item>
<?php endforeach ?>
<p>picked {$this->picked}</p>
<button data-on-click="drop">drop</button>
