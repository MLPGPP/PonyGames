<?php
/** Shows an uploaded submission picture to logged-in moderators. Uploads live outside the website folder. */

declare(strict_types=1);
require __DIR__ . '/../inc/submissions.php';
require __DIR__ . '/../inc/admin_ui.php';

admin_session();
if (empty($_SESSION['admin'])) { http_response_code(403); exit('Log in to /admin/ first.'); }
session_write_close();

$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f, $m) || !is_file($path = uploads_dir() . '/' . $f)) {
    http_response_code(404);
    exit('Not found');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $f . '"');
readfile($path);
