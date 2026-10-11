<h1>Waypoint Live</h1>
<p>PHP-Komponenten, eine WebSocket-Verbindung, kein eigenes JavaScript.</p>

<section>
    <h2>Zähler</h2>
    <app-counter count="3"></app-counter>
</section>

<section>
    <h2>Verschachtelt</h2>
    <app-counter-list></app-counter-list>
</section>

<section>
    <h2>Warenkorb pro Tab</h2>
    <p>Zwei getrennte Komponenten teilen sich einen <code>#[Scoped]</code>-Service; ein zweiter Tab hat seinen eigenen.</p>
    <app-products></app-products>
    <app-cart></app-cart>
</section>

<section>
    <h2>Suche in <?= (int) $model['cityCount'] ?> Städten</h2>
    <app-city-search></app-city-search>
</section>

<section>
    <h2>Server-Push</h2>
    <app-clock></app-clock>
</section>
