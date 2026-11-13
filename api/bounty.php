<?php
/**
 * Bounty board tips (called by js/bounties.js).
 *
 *   POST /api/bounty.php   (JSON body, header X-Requested-With: pony-games)
 *        { bounty: "<id>", links, notes, name?, contact? }      a tip for a wanted game
 *        { bounty: "new", title, author?, links?, notes, ... }  suggest a new bounty
 *
 * Tips are only visible to moderators (/admin/?view=bounties).
 */

declare(strict_types=1);
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/discord.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $code = 400): never
{
    reply(['error' => $message], $code);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Method not allowed', 405);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') fail('Bad request');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        fail('Bad origin', 403);
    }

    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Bad request');
    if (!empty($in['website'])) reply(['ok' => true]); // honeypot

    $text = function (string $key, int $max, string $what) use ($in): string {
        $v = trim((string)($in[$key] ?? ''));
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        if (mb_strlen($v) > $max) fail("Your $what is too long (at most $max characters).");
        return $v;
    };

    $bountyId = (string)($in['bounty'] ?? '');
    $isNew = $bountyId === 'new';
    if (!$isNew && !isset(all_bounties()[$bountyId])) fail('That bounty no longer exists.', 404);

    $title   = $isNew ? $text('title', 120, 'game title') : '';
    $author  = $isNew ? $text('author', 120, 'author') : '';
    $links   = $text('links', 3000, 'list of links');
    $notes   = $text('notes', 2000, 'note');
    $name    = $text('name', 60, 'name');
    $contact = $text('contact', 120, 'contact info');

    if ($isNew && mb_strlen($title) < 2) fail('Please fill in the title of the missing game.');
    if (!$isNew && $links === '' && mb_strlen($notes) < 10) {
        fail('Please share a link, or tell us a bit more about where you saw it.');
    }

    if (!rate_ok('bounty', 6, 600) || !rate_ok('bounty-day', 40, 86400)) {
        fail("Thanks for all the tips! Please wait a bit before sending more.", 429);
    }

    $account = current_user();
    if ($account) $name = (string)$account['display_name']; // members always send under their own name
    db()->prepare('INSERT INTO bounty_tips (bounty_id, title, author, links, notes, name, contact, ip_hash, created_at, user_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$bountyId, $title, $author, $links, $notes, $name, $contact, ip_hash(), time(), $account ? (int)$account['id'] : null]);
    discord_tip_new((int)db()->lastInsertId()); // to the moderators' Discord channel, if set up
    reply(['ok' => true], 201);
} catch (Throwable $e) {
    error_log('bounty api: ' . $e->getMessage());
    fail('Something went wrong on our side. Please try again later.', 500);
}
