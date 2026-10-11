<h1>Waypoint Live</h1>
<p>PHP-Komponenten, eine WebSocket-Verbindung, kein eigenes JavaScript.</p>

<section>
    <h2>Zähler</h2>
    <?= $this->live('app-counter', ['count' => 3]) ?>
</section>

<section>
    <h2>Verschachtelt</h2>
    <?= $this->live('app-counter-list') ?>
</section>

<section>
    <h2>Warenkorb pro Tab</h2>
    <p>Zwei getrennte Komponenten teilen sich einen <code>#[Scoped]</code>-Service; ein zweiter Tab hat seinen eigenen.</p>
    <?= $this->live('app-products') ?>
    <?= $this->live('app-cart') ?>
</section>

<section>
    <h2>Suche in <?= (int) $model['cityCount'] ?> Städten</h2>
    <?= $this->live('app-city-search') ?>
</section>

<section>
    <h2>Server-Push</h2>
    <?= $this->live('app-clock') ?>
</section>
