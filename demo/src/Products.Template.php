<?php /** @var \Demo\Products $this */ ?>
<ul>
<?php foreach ($this->products as $product): ?>
    <li data-key="<?= $product ?>"><button data-on-click="add" data-arg="<?= $product ?>"><?= $product ?> in den Warenkorb</button></li>
<?php endforeach ?>
</ul>
