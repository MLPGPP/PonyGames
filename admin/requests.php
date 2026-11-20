<?php
/**
 * Admin: asset requests (/requests, logic in inc/requests.php). Approve or
 * reject new requests; check delivered results (approve with gold stars and
 * the Asset Maker badge for the maker, and optionally add it to the resources
 * page, or send it back to the maker); close, reopen or delete requests.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';
require __DIR__ . '/../inc/requests.php';

$csrf = admin_require();
$pdo = db();
requests_expire_claims();

$show = (string)($_GET['show'] ?? '');
if (!isset(REQUEST_STATUSES[$show])) {
    $show = 'open';
    foreach (['pending', 'review'] as $s) if ((int)$pdo->query("SELECT COUNT(*) FROM asset_requests WHERE status = '$s'")->fetchColumn()) { $show = $s; break; }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $r = request_get((int)($_POST['id'] ?? 0));
    $note = mb_substr(trim(str_replace("\r", '', (string)($_POST['note'] ?? ''))), 0, 500);
    $now = time();
    $by = admin_who();
    $flash = 'Nothing changed.';
    if ($r) switch ($do) {
        case 'approve':
            if ($r['status'] !== 'pending') break;
            $pdo->prepare("UPDATE asset_requests SET status = 'open', mod_note = ?, handled_by = ?, updated_at = ? WHERE id = ?")->execute([$note, $by, $now, $r['id']]);
            request_notify((int)$r['user_id'], $r, 'Your request “' . $r['title'] . '” is on the board 🎨',
                $note !== '' ? $note : 'Now any pony can pick it up. You\'ll hear under the bell when someone does.');
            discord_request_event('approved', $r, ['by' => $by, 'note' => $note]);
            $flash = 'Approved, it\'s on the board.';
            break;
        case 'reject':
            if ($r['status'] !== 'pending') break;
            $pdo->prepare("UPDATE asset_requests SET status = 'rejected', mod_note = ?, handled_by = ?, updated_at = ? WHERE id = ?")->execute([$note, $by, $now, $r['id']]);
            request_notify((int)$r['user_id'], $r, 'Your request “' . $r['title'] . '” wasn\'t accepted',
                $note !== '' ? $note : 'Sorry, it can\'t go on the board like this. Check the Site Rules, or ask again with a bit more detail.');
            discord_request_event('rejected', $r, ['by' => $by, 'note' => $note]);
            $flash = 'Rejected, the requester got a message.';
            break;
        case 'accept': // a delivered result is fine: the requester gets it, the maker their reward
            if ($r['status'] !== 'review') break;
            $stars = max(0, min(10, (int)($_POST['stars'] ?? 0)));
            $badge = !empty($_POST['badge']);
            $maker = (int)$r['claimed_by'];
            if ($maker && $stars) {
                $pdo->prepare('UPDATE users SET stars = stars + ? WHERE id = ?')->execute([$stars, $maker]);
                $pdo->prepare('INSERT INTO star_events (user_id, amount, reason, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$maker, $stars, 'Made “' . $r['title'] . '” for the request board', $now]);
            }
            if ($maker && $badge) $pdo->prepare('INSERT OR IGNORE INTO user_badges (user_id, badge, note, awarded_at) VALUES (?, ?, ?, ?)')
                ->execute([$maker, 'asset-maker', '', $now]);
            $pdo->prepare("UPDATE asset_requests SET status = 'done', done_at = ?, rewarded = ?, handled_by = ?, updated_at = ? WHERE id = ?")
                ->execute([$now, ($stars || $badge) ? 1 : 0, $by, $now, $r['id']]);
            $earned = array_filter([$stars ? $stars . ' gold star' . ($stars === 1 ? '' : 's') : '', $badge ? 'the 🎨 Asset Maker badge' : '']);
            request_notify((int)$r['user_id'], $r, 'Your request “' . $r['title'] . '” is done 🎉', $r['maker'] . ' made it for you. Go take a look!');
            if ($maker) request_notify($maker, $r, 'Thanks for making “' . $r['title'] . '” ⭐',
                ($note !== '' ? $note . ' ' : '') . 'It\'s on the board now' . ($earned ? ' and you earned ' . implode(' and ', $earned) : '') . '.');
            discord_request_event('done', $r, ['by' => $by, 'maker' => (string)$r['maker'], 'rewards' => implode(' and ', $earned), 'note' => $note]);
            if (!empty($_POST['to_resources'])) { // on to the resource editor, filled in from this request
                $_SESSION['admin_flash'] = 'Approved! Check the details and add pictures, then save to put it in the resources.';
                header('Location: resources.php?new&from_request=' . (int)$r['id']);
                exit;
            }
            $flash = 'Approved: the requester and ' . $r['maker'] . ' got a message' . ($earned ? ', with ' . implode(' and ', $earned) : '') . '.';
            break;
        case 'send_back': // not good yet: back to the maker, with a bit of time to fix it
            if ($r['status'] !== 'review') break;
            $until = max(request_claim_until($r), $now + 7 * 86400);
            $pdo->prepare("UPDATE asset_requests SET status = 'claimed', claim_until = ?, mod_note = ?, handled_by = ?, updated_at = ? WHERE id = ?")
                ->execute([$until, $note, $by, $now, $r['id']]);
            if ($r['claimed_by']) request_notify((int)$r['claimed_by'], $r, '“' . $r['title'] . '” needs a little more work',
                ($note !== '' ? $note : 'A moderator had a look and it isn\'t quite ready yet.') . ' Deliver it again when it\'s fixed (you have until ' . date('j M', $until) . ').');
            discord_request_event('sent_back', $r, ['by' => $by, 'note' => $note, 'maker' => (string)$r['maker']]);
            $flash = 'Sent back to ' . $r['maker'] . '.';
            break;
        case 'close':
            if (!in_array($r['status'], ['open', 'claimed', 'review'], true)) break;
            $pdo->prepare("UPDATE asset_requests SET status = 'closed', mod_note = ?, handled_by = ?, updated_at = ? WHERE id = ?")->execute([$note, $by, $now, $r['id']]);
            $msg = $note !== '' ? $note : 'A moderator took it off the board.';
            request_notify((int)$r['user_id'], $r, 'Your request “' . $r['title'] . '” was closed', $msg);
            if (in_array($r['status'], ['claimed', 'review'], true) && $r['claimed_by']) request_notify((int)$r['claimed_by'], $r, '“' . $r['title'] . '” was closed', $msg . ' You can stop working on it.');
            discord_request_event('closed', $r, ['by' => $by, 'note' => $note, 'maker' => (string)($r['maker'] ?? '')]);
            $flash = 'Closed.';
            break;
        case 'reopen': // done, closed or rejected: back on the board, without a maker
            $pdo->prepare("UPDATE asset_requests SET status = 'open', claimed_by = NULL, claimed_at = NULL, claim_until = NULL, result_url = '', result_note = '', done_at = NULL, resource_id = NULL,
                           rewarded = 0, mod_note = '', handled_by = ?, updated_at = ? WHERE id = ?")->execute([$by, $now, $r['id']]);
            if ($note !== '') request_notify((int)$r['user_id'], $r, 'Your request “' . $r['title'] . '” is open again', $note);
            if ($note !== '' && $r['status'] === 'done' && $r['claimed_by']) request_notify((int)$r['claimed_by'], $r, '“' . $r['title'] . '” is open again', $note);
            discord_request_event('reopened', $r, ['by' => $by, 'note' => $note]);
            $flash = 'Open again.';
            break;
        case 'reward': // the maker of a finished request: gold stars and/or the Asset Maker badge
            if ($r['status'] !== 'done' || !$r['claimed_by'] || $r['rewarded']) break;
            $stars = max(0, min(10, (int)($_POST['stars'] ?? 0)));
            $badge = !empty($_POST['badge']);
            $maker = (int)$r['claimed_by'];
            if ($stars) {
                $pdo->prepare('UPDATE users SET stars = stars + ? WHERE id = ?')->execute([$stars, $maker]);
                $pdo->prepare('INSERT INTO star_events (user_id, amount, reason, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$maker, $stars, 'Made “' . $r['title'] . '” for the request board', $now]);
            }
            if ($badge) $pdo->prepare('INSERT OR IGNORE INTO user_badges (user_id, badge, note, awarded_at) VALUES (?, ?, ?, ?)')
                ->execute([$maker, 'asset-maker', '', $now]);
            $pdo->prepare('UPDATE asset_requests SET rewarded = 1, handled_by = ?, updated_at = ? WHERE id = ?')->execute([$by, $now, $r['id']]);
            $earned = array_filter([$stars ? $stars . ' gold star' . ($stars === 1 ? '' : 's') : '', $badge ? 'the 🎨 Asset Maker badge' : '']);
            request_notify($maker, $r, 'Thanks for making “' . $r['title'] . '” ⭐',
                ($note !== '' ? $note . ' ' : '') . ($earned ? 'You earned ' . implode(' and ', $earned) . '.' : 'The moderators saw it, great work!'));
            discord_request_event('rewarded', $r, ['by' => $by, 'maker' => (string)$r['maker'], 'rewards' => implode(' and ', $earned), 'note' => $note]);
            $flash = 'Thanks sent to ' . $r['maker'] . ($earned ? ' with ' . implode(' and ', $earned) : '') . '.';
            break;
        case 'delete':
            $pdo->prepare('DELETE FROM asset_requests WHERE id = ?')->execute([$r['id']]);
            discord_request_event('deleted', $r, ['by' => $by]);
            $flash = 'Deleted.';
            break;
    }
    $_SESSION['admin_flash'] = $flash;
    header('Location: requests.php?show=' . rawurlencode((string)($_POST['show'] ?? $show)));
    exit;
}

$counts = requests_counts();
$st = $pdo->prepare('SELECT r.*, u.display_name AS requester, c.display_name AS maker FROM asset_requests r
                     LEFT JOIN users u ON u.id = r.user_id LEFT JOIN users c ON c.id = r.claimed_by
                     WHERE r.status = ? ORDER BY ' . ($show === 'done' ? 'r.rewarded, r.done_at DESC' : 'r.updated_at DESC') . ' LIMIT 300');
$st->execute([$show]);
$rows = $st->fetchAll();
$when = fn(int $t) => date('j M Y, H:i', $t);
$link = fn(string $u) => '<a href="' . h($u) . '" target="_blank" rel="noopener noreferrer nofollow">' . h(mb_strimwidth($u, 0, 70, '…')) . '</a>';
$form = function (string $do, int $id, string $inner, string $button, string $class = '') use ($csrf, $show): string {
    return '<form method="post" class="stack req-act"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="do" value="' . $do . '">'
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="show" value="' . h($show) . '">' . $inner
        . '<div><button class="small ' . $class . '"' . ($do === 'delete' ? ' data-confirm="Delete this request for good?"' : '') . '>' . $button . '</button></div></form>';
};
$msg = fn(string $label, string $ph) => '<label>' . $label . ' <span class="muted">shows up under their bell; empty: a short standard text</span><textarea name="note" rows="2" maxlength="500" placeholder="' . h($ph) . '"></textarea></label>';

admin_head('Asset requests');
admin_nav('requests');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
?>
  <h2>Asset requests <a class="muted" href="/requests" target="_blank" rel="noopener">open the board ↗</a></h2>
  <p class="subnav">
    <?php foreach (REQUEST_STATUSES as $k => $l): ?>
      <a href="?show=<?= $k ?>" class="<?= $show === $k ? 'on' : '' ?>"><?= ['pending' => 'To approve', 'review' => 'To check'][$k] ?? h($l) ?> <span class="muted"><?= $counts[$k] ?></span></a>
    <?php endforeach ?>
  </p>
<?php if (!$rows): ?>
  <p class="empty"><?= ['pending' => 'No new requests to approve.', 'review' => 'Nothing delivered to check.'][$show] ?? 'Nothing here.' ?></p>
<?php endif ?>
  <ul class="tips req-admin">
<?php foreach ($rows as $r): ?>
    <li class="tip">
      <div class="tip__head"><strong><?= h($r['title']) ?></strong> <span class="muted">· <?= h(REQUEST_KINDS[$r['kind']] ?? $r['kind']) ?> · by
        <a href="./?view=user&amp;id=<?= (int)$r['user_id'] ?>"><?= h((string)($r['requester'] ?? 'deleted account')) ?></a> · <?= $when((int)$r['created_at']) ?>
        <?= $r['for_game'] !== '' ? ' · for ' . h($r['for_game']) : '' ?></span></div>
      <div class="tip__notes"><?= nl2br(h($r['description'])) ?></div>
      <?php if ($r['refs'] !== ''): ?><div class="tip__links">References: <?= implode(' · ', array_map($link, explode("\n", $r['refs']))) ?></div><?php endif ?>
      <?php if ($r['claimed_by']): ?><div class="tip__answer">Maker: <a href="./?view=user&amp;id=<?= (int)$r['claimed_by'] ?>"><?= h((string)$r['maker']) ?></a>
        <?= $r['status'] === 'claimed' ? ' · claimed ' . $when((int)$r['claimed_at']) . ', runs out ' . date('j M', request_claim_until($r)) : '' ?></div><?php endif ?>
      <?php if (in_array($r['status'], ['review', 'done'], true)): ?><div class="tip__answer">Result: <?= $link((string)$r['result_url']) ?><?= $r['result_note'] !== '' ? ' · “' . h($r['result_note']) . '”' : '' ?>
        <?= $r['rewarded'] ? ' · <b>rewarded</b>' : '' ?>
        <?= $r['resource_id'] !== null ? ' · <a href="resources.php?id=' . (int)$r['resource_id'] . '">in the resources (#' . (int)$r['resource_id'] . ')</a>' : '' ?></div><?php endif ?>
      <?php if ($r['mod_note'] !== '' || $r['handled_by'] !== ''): ?><div class="tip__answer muted"><?= $r['handled_by'] !== '' ? 'Last handled by ' . h($r['handled_by']) : '' ?><?= $r['mod_note'] !== '' ? ': “' . h($r['mod_note']) . '”' : '' ?></div><?php endif ?>

      <div class="req-admin__acts">
      <?php if ($r['status'] === 'pending'): ?>
        <details class="tip__done" open><summary class="button small-button">✓ Approve <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('approve', (int)$r['id'], $msg('Message to ' . h((string)$r['requester']), 'e.g. Nice idea! Good luck.'), 'Put it on the board', 'primary') ?></details>
        <details class="tip__done"><summary class="button small-button">✕ Reject <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('reject', (int)$r['id'], $msg('Message to ' . h((string)$r['requester']), 'e.g. Please describe the size and style you need.'), 'Reject it') ?></details>
      <?php endif ?>
      <?php if ($r['status'] === 'review'): ?>
        <details class="tip__done" open><summary class="button small-button">✓ Approve <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('accept', (int)$r['id'], '<div class="tip__outcome"><label>Gold stars for ' . h((string)$r['maker']) . ' <select name="stars"><option value="0">none</option><option value="1">1</option><option value="2">2</option><option value="3" selected>3</option><option value="5">5</option><option value="10">10</option></select></label>'
            . '<label class="check"><input type="checkbox" name="badge" value="1" checked> 🎨 Asset Maker badge</label></div>'
            . $msg('Message to ' . h((string)$r['maker']), 'e.g. Lovely work, thank you!')
            . '<label class="check"><input type="checkbox" name="to_resources" value="1"> Also add it to the resources (opens the resource editor, filled in)</label>', 'Approve', 'primary') ?></details>
        <details class="tip__done"><summary class="button small-button">↩ Send back <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('send_back', (int)$r['id'], $msg('What should ' . h((string)$r['maker']) . ' fix?', 'e.g. The link asks for a login, please share it publicly.'), 'Send it back') ?></details>
      <?php endif ?>
      <?php if ($r['status'] === 'done' && $r['resource_id'] === null): ?>
        <a class="button small-button" href="resources.php?new&amp;from_request=<?= (int)$r['id'] ?>">🧩 Add to the resources</a>
      <?php endif ?>
      <?php if ($r['status'] === 'done' && $r['claimed_by'] && !$r['rewarded']): ?>
        <details class="tip__done" open><summary class="button small-button">⭐ Reward the maker <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('reward', (int)$r['id'], '<div class="tip__outcome"><label>Gold stars <select name="stars"><option value="0">none</option><option value="1">1</option><option value="2">2</option><option value="3" selected>3</option><option value="5">5</option><option value="10">10</option></select></label>'
            . '<label class="check"><input type="checkbox" name="badge" value="1" checked> 🎨 Asset Maker badge</label></div>'
            . $msg('Message to ' . h((string)$r['maker']), 'e.g. Lovely work, thank you!'), 'Send the thanks', 'primary') ?></details>
      <?php endif ?>
      <?php if (in_array($r['status'], ['open', 'claimed', 'review'], true)): ?>
        <details class="tip__done"><summary class="button small-button">Close <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('close', (int)$r['id'], $msg('Message to ' . h((string)$r['requester']) . (in_array($r['status'], ['claimed', 'review'], true) ? ' and ' . h((string)$r['maker']) : ''), 'e.g. This one has been open a long time, feel free to ask again.'), 'Close it') ?></details>
      <?php endif ?>
      <?php if (in_array($r['status'], ['done', 'closed', 'rejected'], true)): ?>
        <details class="tip__done"><summary class="button small-button">Open again <span class="tip__caret" aria-hidden="true">▾</span></summary>
          <?= $form('reopen', (int)$r['id'], '<label>Message <span class="muted">optional; to the requester' . ($r['status'] === 'done' ? ' and the maker' : '') . '</span><textarea name="note" rows="2" maxlength="500"></textarea></label>', 'Put it back on the board') ?></details>
      <?php endif ?>
        <?= $form('delete', (int)$r['id'], '', 'Delete', 'danger') ?>
      </div>
    </li>
<?php endforeach ?>
  </ul>
  <p class="muted">Makers pick how long they need when they claim a request (3 days to 3 months) and can ask for more time. When that runs out without a result, the request is open again and both ponies get a message.</p>
</main>
<script src="admin.js?v=3"></script>
</body></html>
