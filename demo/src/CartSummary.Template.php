<?php /** @var \Demo\CartSummary $this */ ?>
<p>Warenkorb: <?= $this->cart->count() ?> Artikel</p>
<ul>
<?php foreach ($this->cart->items as $product => $quantity): ?>
    <li data-key="<?= $product ?>"><?= $quantity ?> × <?= $product ?></li>
<?php endforeach ?>
</ul>
<button data-on-click="clear">Leeren</button>
