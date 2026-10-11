<?php
// Run with: tenma index.php (live components need Tenma), then open http://localhost:8090

require __DIR__ . '/vendor/autoload.php';

use Demo\{CitySearch, CityRow, Clock, Counter, CounterList, HomeController};
use Waypoint\Live\LivePlugin;
use Waypoint\Options\RendererOptions;
use Waypoint\Waypoint;

date_default_timezone_set('Europe/Berlin');

$app = Waypoint::create();
$app->plugin(new LivePlugin([Counter::class, CounterList::class, CitySearch::class, CityRow::class, Clock::class]));
$app->configure(function (RendererOptions $opts) {
    $opts->directory = __DIR__ . '/views';
    $opts->layout = '_Layout.php';
});
$app->attach([HomeController::class]);

Tenma::serve(8090, $app);
echo "Live components: http://localhost:8090\n";
