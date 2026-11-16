<?php
/** sitemap.xml: the main pages plus every game page (served at /sitemap.xml) */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
$base = site_url();
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach (['/', '/games', '/upcoming', '/resources', '/requests', '/bounties', '/submit', '/about', '/rules'] as $p) {
    echo '  <url><loc>', h($base . $p), "</loc></url>\n";
}
foreach (all_games() as $g) {
    $mod = !empty($g['dateAdded']) && ($ts = strtotime($g['dateAdded'])) ? '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>' : '';
    echo '  <url><loc>', h($base . game_path($g)), '</loc>', $mod, "</url>\n";
}
echo "</urlset>\n";
