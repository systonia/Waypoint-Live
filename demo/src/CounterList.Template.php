<?php /** @var \Demo\CounterList $this */ ?>
<?php foreach ($this->counters as $n): ?>
<p>Zähler <?= $n ?>: <app-counter key="<?= $n ?>"></app-counter></p>
<?php endforeach ?>
<button data-on-click="add">Zähler hinzufügen</button>
<button data-on-click="removeFirst">Ersten entfernen</button>
