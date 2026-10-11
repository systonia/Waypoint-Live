<?php use Demo\{CitySearch, Clock, Counter}; ?>
<h1>Waypoint Live</h1>
<p>Drei PHP-Komponenten, eine WebSocket-Verbindung, kein eigenes JavaScript.</p>

<section>
    <h2>Zähler</h2>
    <?= $this->live(Counter::class, ['start' => 3]) ?>
</section>

<section>
    <h2>Suche in <?= (int) $model['cityCount'] ?> Städten</h2>
    <?= $this->live(CitySearch::class) ?>
</section>

<section>
    <h2>Server-Push</h2>
    <?= $this->live(Clock::class) ?>
</section>
