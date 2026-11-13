<?php
/**
 * Local development only: run the whole site, including game pages and the
 * community API, with PHP's built-in server:
 *
 *     php -S localhost:8000 router.php
 *
 * On the real server nginx does this routing (see deploy/nginx-ponygames.conf).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// page addresses, like nginx: /games -> games.php; old /games.html -> /games
if (preg_match('#^/(games|upcoming|resources|bounties|about)\.html$#', $path, $m)) {
    header('Location: /' . $m[1] . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    return true;
}
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}
if (preg_match('#^/(games|upcoming|resources|bounties|about|rules|account|login|logout|submit)$#', $path, $m)) {
    $_SERVER['SCRIPT_NAME'] = '/' . $m[1] . '.php';
    require __DIR__ . '/' . $m[1] . '.php';
    return true;
}
if (preg_match('#^/game/([^/]+)/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/game.php';
    return true;
}
if (preg_match('#^/s/([a-z0-9]+)/?$#', $path, $m)) {
    $_GET['c'] = $m[1];
    require __DIR__ . '/s.php';
    return true;
}
if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}
if (preg_match('#^/(inc|deploy)/#', $path) || preg_match('#/\.#', $path)) {
    http_response_code(404);
    return true;
}
return false; // let the built-in server serve the file as is
