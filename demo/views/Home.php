<h1>Waypoint Live</h1>
<p>Drei PHP-Komponenten, eine WebSocket-Verbindung, kein eigenes JavaScript.</p>

<section>
    <h2>Zähler</h2>
    <?= $this->live('app-counter', ['count' => 3]) ?>
</section>

<section>
    <h2>Suche in <?= (int) $model['cityCount'] ?> Städten</h2>
    <?= $this->live('app-city-search') ?>
</section>

<section>
    <h2>Server-Push</h2>
    <?= $this->live('app-clock') ?>
</section>
