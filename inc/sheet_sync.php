<?php
/**
 * Copies the game list to its own tab in the Google Sheet after every change
 * to games.json (admin saves, accepted submissions, rollbacks).
 *
 * The site sends the whole list to a small Google Apps Script web app in the
 * sheet (tools/google-sheet-sync.gs), which rewrites only that one tab. The
 * other tabs are never touched. Off until both settings are in .env:
 *
 *   SHEET_WEBHOOK_URL     the web app address (https://script.google.com/macros/s/…/exec)
 *   SHEET_WEBHOOK_SECRET  a long random password, the same as SECRET in the script
 *   SHEET_TAB             optional, the tab's name (default "site"); the script
 *                         only writes tabs in its own ALLOWED_TABS list
 *
 * The sync runs after the page is sent (so saving doesn't wait for Google), and
 * the result is shown in /admin/games.php, with a "Sync now" button.
 */

declare(strict_types=1);

const SHEET_HEADER = [
    'id', 'title', 'page', 'made by', 'status', 'release date', 'added', 'engine', 'platforms',
    'genres', 'tags', 'warnings', 'characters', 'playtime', 'short description', 'description',
    'website', 'downloads', 'cover picture', 'pictures', 'videos',
];

function sheet_sync_enabled(): bool
{
    return env('SHEET_WEBHOOK_URL') !== '' && env('SHEET_WEBHOOK_SECRET') !== '';
}

/**
 * One row per game, in the order of SHEET_HEADER (all cells text or numbers).
 * $links gets, per column that holds several links, one list per row of
 * [label, url]: the sheet shows those labels on one line, each one clickable.
 */
function sheet_rows(array $games, ?array &$links = null): array
{
    $links = ['downloads' => [], 'videos' => []];
    $base = site_url();
    $abs = fn(string $u) => $u !== '' && $u[0] === '/' ? $base . $u : $u;
    $list = fn($v) => implode(', ', array_map('strval', array_filter((array)$v, 'is_scalar')));
    $rows = [];
    foreach ($games as $g) {
        if (!isset($g['id'])) continue;
        $tags = ['Genre' => [], 'Other' => [], 'warn' => []];
        foreach ((array)($g['tags'] ?? []) as $t) {
            $label = is_array($t) ? (string)($t['label'] ?? '') : (string)$t;
            $type = is_array($t) ? (string)($t['type'] ?? 'Other') : 'Other';
            if ($label === '') continue;
            $tags[$type === 'Genre' ? 'Genre' : ($type === 'Other' ? 'Other' : 'warn')][] = $label;
        }
        // downloads on one line: "Windows 1.0.2 · Linux 1.0.2", each one a link (mirrors are in "source")
        $downloads = [];
        foreach ((array)($g['downloads'] ?? []) as $d) {
            if (!is_array($d) || empty($d['url'])) continue;
            $name = trim(($d['label'] ?? '') . ' ' . ($d['version'] ?? ''));
            $downloads[] = [$name !== '' ? $name : 'download', $abs((string)$d['url'])];
        }
        $videos = [];
        foreach (array_values(array_filter((array)($g['videos'] ?? []), 'is_string')) as $i => $v) $videos[] = ['video ' . ($i + 1), $v];
        $links['downloads'][] = $downloads;
        $links['videos'][] = $videos;
        $oneLine = fn(array $l) => implode(' · ', array_column($l, 0));
        $rows[] = [
            is_numeric($g['id']) ? (int)$g['id'] : (string)$g['id'],
            (string)($g['title'] ?? ''),
            $base . game_path($g),
            $list($g['author'] ?? []),
            (string)($g['status'] ?? ''),
            (string)($g['releaseDate'] ?? ''),
            (string)($g['dateAdded'] ?? ''),
            (string)($g['engine'] ?? ''),
            $list($g['platforms'] ?? []),
            implode(', ', $tags['Genre']),
            implode(', ', $tags['Other']),
            implode(', ', $tags['warn']),
            $list($g['characters'] ?? []),
            (string)($g['playtime'] ?? ''),
            (string)($g['shortDescription'] ?? ''),
            mb_substr((string)($g['fullDescription'] ?? ''), 0, 45000), // a cell holds 50,000 characters
            (string)($g['url'] ?? ''),
            $oneLine($downloads),
            $abs((string)($g['thumbnail'] ?? '')),
            count((array)($g['screenshots'] ?? [])),
            $oneLine($videos),
        ];
    }
    return $rows;
}

function sheet_status_file(): string
{
    return backups_dir() . '/sheet-sync.json';
}

/** ['ok' => bool, 'at' => time, 'rows' => n, 'error' => '...'] of the last sync, or null */
function sheet_sync_status(): ?array
{
    $s = json_decode((string)@file_get_contents(sheet_status_file()), true);
    return is_array($s) ? $s : null;
}

/** Sends the current games.json to the sheet. Returns the new status. */
function sheet_sync_now(): array
{
    $status = ['ok' => false, 'at' => time(), 'rows' => 0, 'error' => ''];
    try {
        if (!sheet_sync_enabled()) throw new RuntimeException('SHEET_WEBHOOK_URL and SHEET_WEBHOOK_SECRET are not set in .env');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP has no curl');
        $data = load_content('games');
        $rows = sheet_rows((array)($data['games'] ?? []), $links);
        $body = json_encode([
            'secret' => env('SHEET_WEBHOOK_SECRET'),
            'tab' => env('SHEET_TAB', 'site'),
            'updated' => gmdate('Y-m-d H:i') . ' UTC',
            'header' => SHEET_HEADER,
            'rows' => $rows,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ch = curl_init(env('SHEET_WEBHOOK_URL'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // Apps Script answers with a redirect to the result
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) throw new RuntimeException('could not reach Google: ' . $err);
        $res = json_decode((string)$out, true);
        if (!is_array($res)) throw new RuntimeException('unexpected answer from the script (HTTP ' . $code . '). Is it deployed as a web app with access for "Anyone"?');
        if (empty($res['ok'])) throw new RuntimeException('the script said: ' . ($res['error'] ?? 'unknown error'));
        $status['ok'] = true;
        $status['rows'] = (int)($res['rows'] ?? count($rows));
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();
        error_log('sheet sync: ' . $e->getMessage());
    }
    @file_put_contents(sheet_status_file(), json_encode($status));
    return $status;
}

/**
 * Called by save_content() for games.json: syncs once, at the end of the
 * request, after the answer went to the browser.
 */
function sheet_sync_later(): void
{
    static $queued = false;
    if ($queued || !sheet_sync_enabled()) return;
    $queued = true;
    after_response('sheet_sync_now', 90); // inc/bootstrap.php: doesn't hold up the next page
}
