<input type="search" data-on-input="search" value="{$this->query}" placeholder="Stadt suchen" aria-label="Stadt suchen">
<p><?= $this->selected === null ? 'Nichts ausgewählt' : 'Ausgewählt: ' . htmlspecialchars($this->selected) ?></p>
<ul>
<?php foreach ($this->cities->search($this->query) as $city): ?>
    <li><button data-on-click="select" data-arg="{$city}"<?= $city === $this->selected ? ' aria-current="true"' : '' ?>>{$city}</button></li>
<?php endforeach ?>
</ul>
