<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waypoint Live</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; }
        section { border: 1px solid #ccc; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
        ul { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: .25rem; }
        [aria-current] { font-weight: bold; }
        html[wp-live-offline] body::before { content: "Verbindung getrennt, verbinde neu …"; display: block; background: #fdd; padding: .5rem; }
    </style>
</head>
<body>
<?= $content ?>
<?= $this->waypointJsTag() ?>
<?= $this->pluginAssetTags() ?>
</body>
</html>
