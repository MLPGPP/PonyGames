<?php
/**
 * Asset requests (/requests): members ask for assets ("make me a walk cycle"),
 * other members claim and make them. Logic and statuses: inc/requests.php.
 * Moderators approve new requests in /admin/requests.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/requests.php';
require __DIR__ . '/inc/layout.php';

$me = current_user();
$pdo = db();
requests_expire_claims();

/* ---------------------------------------------------------------- actions */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? request_url($id) : '/requests';
    $r = $id ? request_get($id) : null;
    $now = time();

    if (!csrf_ok()) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (!$me) {
        flash('error', 'Please log in first.');
    } elseif (user_banned($me)) {
        flash('error', ban_message($me));
    } elseif (!user_verified($me)) {
        flash('error', NEED_CONFIRM_MESSAGE);
    } elseif ($action === 'create') {
        $title = trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? '')) ?? '');
        $kind = (string)($_POST['kind'] ?? '');
        $desc = trim(str_replace("\r", '', (string)($_POST['description'] ?? '')));
        $forGame = trim(preg_replace('/\s+/u', ' ', (string)($_POST['for_game'] ?? '')) ?? '');
        $refs = request_links((string)($_POST['refs'] ?? ''));
        $back = '/requests#ask';
        // what was typed, so the form opens again with it when something is wrong
        $_SESSION['request_draft'] = ['title' => $title, 'kind' => $kind, 'description' => $desc, 'refs' => (string)($_POST['refs'] ?? ''), 'for_game' => $forGame];
        if (mb_strlen($title) < 4 || mb_strlen($title) > 100) flash('error', 'Give your request a short title (4 to 100 characters).');
        elseif (!isset(REQUEST_KINDS[$kind])) flash('error', 'Pick what kind of asset you need.');
        elseif (mb_strlen($desc) < 20 || mb_strlen($desc) > 2000) flash('error', 'Describe what you need in 20 to 2000 characters: style, size, how many…');
        elseif (mb_strlen($forGame) > 100) flash('error', 'Keep “for which game” short.');
        elseif (has_link($title . ' ' . $desc . ' ' . $forGame)) flash('error', 'Links go in the “references” box, not in the text.');
        elseif (!rate_ok('request', 3, 86400)) flash('error', 'You can post 3 requests a day. Please try again tomorrow.');
        else {
            $pdo->prepare('INSERT INTO asset_requests (user_id, title, kind, description, refs, for_game, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$me['id'], $title, $kind, $desc, implode("\n", $refs), $forGame, 'pending', $now, $now]);
            $new = request_get((int)$pdo->lastInsertId());
            discord_request_event('new', $new);
            unset($_SESSION['request_draft']);
            flash('ok', 'Thanks! A moderator will look at your request soon. You\'ll hear under the bell when it\'s on the board.');
            $back = '/requests#mine';
        }
    } elseif (!$r) {
        flash('error', 'That request doesn\'t exist anymore.');
    } elseif ($action === 'claim') {
        if ($r['status'] !== 'open') flash('error', 'Someone else just picked this one up, or it\'s not open anymore.');
        elseif ((int)$r['user_id'] === (int)$me['id']) flash('error', 'That\'s your own request!');
        elseif ((int)$pdo->query("SELECT COUNT(*) FROM asset_requests WHERE status = 'claimed' AND claimed_by = " . (int)$me['id'])->fetchColumn() >= 3)
            flash('error', 'You\'re already making 3 things. Finish or give up one of those first.');
        else {
            $days = request_claim_days($_POST['days'] ?? 0);
            $until = $now + $days * 86400;
            $st = $pdo->prepare("UPDATE asset_requests SET status = 'claimed', claimed_by = ?, claimed_at = ?, claim_until = ?, updated_at = ? WHERE id = ? AND status = 'open'");
            $st->execute([(int)$me['id'], $now, $until, $now, $id]);
            if ($st->rowCount()) {
                request_notify((int)$r['user_id'], $r, $me['display_name'] . ' is making “' . $r['title'] . '”',
                    'Someone picked up your request! They hope to have it ready by ' . date('j M Y', $until) . '.');
                discord_request_event('claimed', $r, ['maker' => $me['display_name'], 'note' => 'Needs ' . REQUEST_CLAIM_CHOICES[$days] . ', until ' . date('j M Y', $until) . '.']);
                flash('ok', 'It\'s yours, until ' . date('j M', $until) . '! When it\'s done, come back and add the link. Need longer? You can ask for more time.');
            }
        }
    } elseif ($action === 'extend') {
        if ($r['status'] !== 'claimed' || (int)$r['claimed_by'] !== (int)$me['id']) flash('error', 'You\'re not making this one.');
        else {
            $days = request_claim_days($_POST['days'] ?? 0);
            $until = max(request_claim_until($r), $now) + $days * 86400;
            if ($until > $now + 180 * 86400) flash('error', 'That\'s more than half a year from now. Pick a shorter time, or give it up so someone else can try.');
            else {
                $pdo->prepare('UPDATE asset_requests SET claim_until = ?, updated_at = ? WHERE id = ?')->execute([$until, $now, $id]);
                request_notify((int)$r['user_id'], $r, $me['display_name'] . ' needs a bit more time',
                    'They\'re still working on “' . $r['title'] . '” and hope to have it ready by ' . date('j M Y', $until) . '.');
                discord_request_event('extended', $r, ['maker' => $me['display_name'], 'note' => 'Now until ' . date('j M Y', $until) . '.']);
                flash('ok', 'Done, you now have until ' . date('j M', $until) . '.');
            }
        }
    } elseif ($action === 'release') {
        if ($r['status'] !== 'claimed' || (int)$r['claimed_by'] !== (int)$me['id']) flash('error', 'You\'re not making this one.');
        else {
            $pdo->prepare("UPDATE asset_requests SET status = 'open', claimed_by = NULL, claimed_at = NULL, claim_until = NULL, updated_at = ? WHERE id = ?")->execute([$now, $id]);
            request_notify((int)$r['user_id'], $r, '“' . $r['title'] . '” is open again', 'The pony who was making it had to stop. Someone else can pick it up now.');
            discord_request_event('released', $r, ['maker' => $me['display_name']]);
            flash('ok', 'No problem, it\'s back on the board.');
        }
    } elseif ($action === 'done') {
        $url = request_links((string)($_POST['result_url'] ?? ''), 1)[0] ?? '';
        $note = mb_substr(trim(str_replace("\r", '', (string)($_POST['result_note'] ?? ''))), 0, 500);
        if ($r['status'] !== 'claimed' || (int)$r['claimed_by'] !== (int)$me['id']) flash('error', 'You\'re not making this one.');
        elseif ($url === '') flash('error', 'Add a link to the result (itch.io, Google Drive, DeviantArt…).');
        elseif (has_link($note)) flash('error', 'Put only one link, in the link box.');
        else {
            // a moderator checks it first (admin/requests.php); the requester hears about it then
            $pdo->prepare("UPDATE asset_requests SET status = 'review', result_url = ?, result_note = ?, updated_at = ? WHERE id = ?")
                ->execute([$url, $note, $now, $id]);
            $r = request_get($id);
            discord_request_event('delivered', $r, ['maker' => $me['display_name']]);
            flash('ok', 'Wonderful, thank you! A moderator will have a quick look, then the requester gets it and you get your gold stars.');
        }
    } elseif ($action === 'close') {
        if ((int)$r['user_id'] !== (int)$me['id'] || !in_array($r['status'], ['pending', 'open', 'claimed', 'review'], true)) flash('error', 'You can\'t close this request.');
        else {
            $pdo->prepare("UPDATE asset_requests SET status = 'closed', mod_note = 'Closed by the requester.', updated_at = ? WHERE id = ?")->execute([$now, $id]);
            if (in_array($r['status'], ['claimed', 'review'], true) && $r['claimed_by']) request_notify((int)$r['claimed_by'], $r, '“' . $r['title'] . '” was closed',
                'The requester doesn\'t need it anymore, so you can stop working on it. Thanks for picking it up!');
            discord_request_event('closed', $r, ['note' => 'Closed by the requester.', 'maker' => (string)($r['maker'] ?? '')]);
            flash('ok', 'Your request is closed.');
            $back = '/requests#mine';
        }
    }
    header('Location: ' . $back, true, 303);
    exit;
}

/* ---------------------------------------------------------------- page */
$show = in_array($_GET['show'] ?? '', ['claimed', 'done'], true) ? $_GET['show'] : 'open';
if (isset($_GET['r']) && ($one = request_get((int)$_GET['r'])) && in_array($one['status'], ['open', 'claimed', 'review', 'done'], true)) $show = $one['status'] === 'review' ? 'claimed' : $one['status']; // a link to one request
$list = requests_on_board($show);
$counts = requests_counts();
$mine = [];
if ($me) {
    $st = $pdo->prepare("SELECT * FROM asset_requests WHERE user_id = ? AND status IN ('pending', 'rejected', 'closed') ORDER BY created_at DESC LIMIT 20");
    $st->execute([(int)$me['id']]);
    $mine = $st->fetchAll();
}
$flash = take_flash();
$draft = $_SESSION['request_draft'] ?? [];
unset($_SESSION['request_draft']);
$askError = $flash && $flash[0] !== 'ok' && $draft; // a request that couldn't be sent: the form opens again with the message
$canPost = $me && !user_banned($me) && user_verified($me);
$linkHtml = fn(string $u) => '<a href="' . h($u) . '" target="_blank" rel="noopener noreferrer nofollow ugc">' . h(preg_replace('#^https?://(www\.)?#', '', mb_strimwidth($u, 0, 60, '…'))) . '</a>';
$ago = function (int $t): string {
    $d = time() - $t;
    if ($d < 3600) return max(1, intdiv($d, 60)) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    if ($d < 86400 * 60) return intdiv($d, 86400) . ' days ago';
    return date('j M Y', $t);
};

render_page_start('Asset requests: ask the herd for game assets', 'resources', 'Need a sprite, a song or a sound for your pony game? Ask for it on the Pony Games request board, or pick up a request and make something for someone else.', false, [
    'canonical' => '/requests'
]);
?>

  <main class="container bounty-page req-page">

    <section class="bounty-hero req-hero">
      <div class="bounty-hero__text">
        <p class="bounty-hero__kicker">Asset requests</p>
        <h1 class="page-title bounty-hero__title">Need something made? <span>Ask the herd!</span></h1>
        <p class="bounty-hero__lead">
          Looking for a sprite, a tune or a sound effect for your pony game, and it's not in the
          <a href="/resources">resources</a> yet? Post a request. Good with pixels or music?
          Pick one up with <b>I'll make this</b> and help another pony out.
          Finished requests may also end up in the resources, for everyone to use.
        </p>
        <p class="bounty-hero__reward">Reward: gold stars, the 🎨 Asset Maker badge and a very happy pony.</p>
      </div>
      <dl class="bounty-hero__stats">
        <div><dt>Open</dt><dd><?= $counts['open'] ?></dd></div>
        <div><dt>Being made</dt><dd><?= $counts['claimed'] + $counts['review'] ?></dd></div>
        <div><dt>Done</dt><dd><?= $counts['done'] ?></dd></div>
      </dl>
    </section>

<?php if ($flash && !$askError): ?>
    <p class="form-alert form-alert--<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($flash[1]) ?></p>
<?php endif ?>

    <div class="bounty-toolbar">
      <nav class="bounty-tabs" aria-label="Show requests">
        <?php foreach (['open' => 'Open', 'claimed' => 'Being made', 'done' => 'Done'] as $k => $l): ?>
          <a class="bounty-tab<?= $show === $k ? ' is-active' : '' ?>" href="/requests<?= $k === 'open' ? '' : '?show=' . $k ?>"<?= $show === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
        <?php endforeach ?>
      </nav>
      <a class="btn btn--accent req-ask-btn" href="#ask" data-ask>Request an asset</a>
    </div>

    <div class="req-grid">
<?php if (!$list): ?>
      <p class="req-empty"><?= ['open' => 'No open requests right now. Need something? Be the first to ask!', 'claimed' => 'Nobody is making anything right now. Pick up an open request!', 'done' => 'Nothing finished yet. Soon!'][$show] ?></p>
<?php endif ?>
<?php foreach ($list as $r):
    $isMine = $me && (int)$r['user_id'] === (int)$me['id'];
    $isMaker = $me && (int)($r['claimed_by'] ?? 0) === (int)$me['id']; ?>
      <article class="req-card req-card--<?= h($r['status']) ?>" id="request-<?= (int)$r['id'] ?>">
        <p class="req-card__kind"><?= h(REQUEST_KINDS[$r['kind']] ?? $r['kind']) ?></p>
        <h2 class="req-card__title"><?= h($r['title']) ?></h2>
        <p class="req-card__meta">Asked by <b><?= h((string)($r['requester'] ?? 'a former member')) ?></b> · <?= $ago((int)$r['created_at']) ?><?= $r['for_game'] !== '' ? ' · for <i>' . h($r['for_game']) . '</i>' : '' ?></p>
        <div class="req-card__desc"><?= nl2br(h($r['description'])) ?></div>
        <?php if ($r['refs'] !== ''): ?>
          <p class="req-card__refs"><span>References:</span> <?= implode(' · ', array_map($linkHtml, explode("\n", $r['refs']))) ?></p>
        <?php endif ?>

        <div class="req-card__foot">
        <?php if ($r['status'] === 'claimed'): ?>
          <p class="req-card__state">✋ <b><?= h((string)$r['maker']) ?></b> is making this · until <?= date('j M', request_claim_until($r)) ?></p>
        <?php elseif ($r['status'] === 'review'): ?>
          <p class="req-card__state">📦 <b><?= h((string)$r['maker']) ?></b> finished it! A moderator is having a quick look before it's shared.</p>
        <?php elseif ($r['status'] === 'done'): ?>
          <p class="req-card__state">🎉 Made by <b><?= h((string)($r['maker'] ?? 'a former member')) ?></b>: <?= $linkHtml((string)$r['result_url']) ?>
            <?= $r['resource_id'] !== null ? ' · <a href="/resources?resource=' . (int)$r['resource_id'] . '">in the resources</a>' : '' ?></p>
          <?php if ($r['result_note'] !== ''): ?><p class="req-card__note">“<?= h($r['result_note']) ?>”</p><?php endif ?>
        <?php endif ?>

        <?php if ($r['status'] === 'open' && !$isMine): ?>
          <?php if ($canPost): ?>
          <form method="post" class="req-card__act req-card__claim"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <?= request_claim_select('I need about') ?>
            <button class="btn btn--accent">I'll make this</button></form>
          <?php elseif (!$me): ?>
          <a class="btn btn--ghost req-card__act" href="/login?next=<?= rawurlencode(request_url((int)$r['id'])) ?>">Log in to make this</a>
          <?php endif ?>
        <?php endif ?>

        <?php if ($isMaker && $r['status'] === 'claimed'): ?>
          <details class="req-card__deliver">
            <summary class="btn btn--accent">It's done! ▾</summary>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <label class="post-form__field">Link to the result <input name="result_url" type="url" required maxlength="500" placeholder="https://… (itch.io, Google Drive, DeviantArt…)"></label>
              <label class="post-form__field">A note for the requester <span class="bounty-form__opt">(optional)</span>
                <textarea name="result_note" rows="2" maxlength="500" placeholder="License, file formats, how to use it…"></textarea></label>
              <p class="bounty-form__privacy">A moderator checks it first. It may then also be added to the <a href="/resources">resources</a> with your name, so every pony can use it.</p>
              <button class="btn btn--accent">Deliver</button>
            </form>
          </details>
          <details class="req-card__deliver req-card__more">
            <summary class="btn btn--ghost">Need more time? ▾</summary>
            <form method="post" class="req-card__claim"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?= request_claim_select('Add') ?>
              <button class="btn btn--accent">Ask for more time</button></form>
          </details>
          <form method="post" class="req-card__act"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--ghost" data-confirm="Give this request back so someone else can make it?">Give it up</button></form>
        <?php endif ?>

        <?php if ($isMine && in_array($r['status'], ['open', 'claimed', 'review'], true)): ?>
          <form method="post" class="req-card__act"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--ghost" data-confirm="Close your request? It will leave the board.">I don't need it anymore</button></form>
        <?php endif ?>
        </div>
      </article>
<?php endforeach ?>
    </div>

    <dialog class="bounty-dialog req-dialog" id="ask" aria-labelledby="ask-title"<?= $askError ? ' data-open' : '' ?>>
      <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
      <div class="bounty-form">
        <p class="bounty-form__kicker">Asset request</p>
        <h2 class="bounty-form__title" id="ask-title">Request an asset</h2>
<?php if (!$me): ?>
        <p class="bounty-form__intro">You need an account to post a request, so we can tell you when someone picks it up.</p>
        <p><a class="btn btn--accent" href="/login?next=<?= rawurlencode('/requests#ask') ?>">Log in or sign up</a></p>
<?php elseif (!$canPost): ?>
        <p class="form-alert form-alert--error"><?= h(user_banned($me) ? ban_message($me) : NEED_CONFIRM_MESSAGE) ?></p>
<?php else: ?>
        <p class="bounty-form__intro">Tell the herd what you need. A moderator checks each request before it goes on the board.</p>
<?php if ($askError): ?>
        <p class="form-alert form-alert--error" role="alert"><?= h($flash[1]) ?></p>
<?php endif ?>
        <form method="post" class="req-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="create">
          <div class="req-form__row">
            <label class="post-form__field">What do you need? <input name="title" required minlength="4" maxlength="100" value="<?= h((string)($draft['title'] ?? '')) ?>" placeholder="e.g. Rarity walk cycle, 8 frames, 64×64"></label>
            <label class="post-form__field">Kind
              <select name="kind" required><option value="">Pick one…</option>
                <?php foreach (REQUEST_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($draft['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach ?>
              </select></label>
          </div>
          <label class="post-form__field">Describe it <span class="bounty-form__opt">style, size, format, how many, what it's for</span>
            <textarea name="description" required minlength="20" maxlength="2000" rows="5" placeholder="The more you tell, the easier it is to make. No links here, those go below."><?= h((string)($draft['description'] ?? '')) ?></textarea></label>
          <label class="post-form__field">References <span class="bounty-form__opt">(optional) links to examples, one per line, at most 5</span>
            <textarea name="refs" rows="2" maxlength="2600" placeholder="https://…"><?= h((string)($draft['refs'] ?? '')) ?></textarea></label>
          <label class="post-form__field">For which game? <span class="bounty-form__opt">(optional)</span>
            <input name="for_game" maxlength="100" value="<?= h((string)($draft['for_game'] ?? '')) ?>" placeholder="Your game's name, or “a jam game”"></label>
          <p class="bounty-form__privacy">Good to know: what gets made for your request may also be added to the <a href="/resources">resources</a>, so other ponies can use it too.
            Please follow the <a href="/rules">Site Rules</a>: nothing NSFW, and nobody owes you anything. Be kind!</p>
          <div class="post-form__foot"><button class="btn btn--accent">Send request</button></div>
        </form>
<?php endif ?>
      </div>
    </dialog>

<?php if ($mine): ?>
    <section class="req-mine" id="mine">
      <h2 class="section-title">Your other requests</h2>
      <ul class="req-mine__list">
      <?php foreach ($mine as $r): ?>
        <li><b><?= h($r['title']) ?></b> · <span class="req-status req-status--<?= h($r['status']) ?>"><?= h(REQUEST_STATUSES[$r['status']]) ?></span>
          <?= $r['mod_note'] !== '' && $r['status'] === 'rejected' ? '<br><span class="bounty-form__opt">' . h($r['mod_note']) . '</span>' : '' ?>
          <?php if ($r['status'] === 'pending'): ?>
          <form method="post" class="req-mine__close"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="link-button" data-confirm="Withdraw this request?">withdraw</button></form>
          <?php endif ?></li>
      <?php endforeach ?>
      </ul>
    </section>
<?php endif ?>

  </main>
<?php
render_page_end(['/js/requests.js']);
