<?php
/**
 * Admin: game jams.
 *   - the jam timer on the home page (data/jam-config.json): which jam is coming up or running, and when;
 *   - the jam history (data/jams.json): past jams with a picture and a link, newest first.
 * Saves go through save_content(), so every change is backed up and can be rolled back.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

$csrf = admin_require();

const JAM_STATES = [
    'none' => 'No jam: show the last jam',
    'upcoming' => 'Upcoming: count down to the start',
    'active' => 'Running: count down to the end',
];
const JAM_MESSAGES = [
    'none' => 'When there is no jam',
    'upcoming' => 'Before the countdown to the start',
    'active' => 'Before the countdown to the end',
    'complete' => 'When the countdown to the start reaches zero',
    'ended' => 'When the jam is over',
];

if (isset($_GET['download'])) {
    $which = $_GET['download'] === 'jam-config' ? 'jam-config' : 'jams';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename(CONTENT_FILES[$which]['file']) . '"');
    readfile(content_path($which));
    exit;
}

/** "2026-11-14T02:00" (what the timer and <input type=datetime-local> use), or '' */
function jam_date(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) throw new RuntimeException('Dates look like 2026-11-14 02:00.');
    return $v;
}
function jam_url(string $v, string $what): string
{
    $v = trim($v);
    if ($v !== '' && !preg_match('#^https?://\S+$#i', $v)) throw new RuntimeException("$what has to be a link starting with https://");
    return $v;
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } else {
        try {
            $do = (string)($_POST['do'] ?? '');
            $t = fn(string $k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));

            if ($do === 'rollback') {
                $which = ($_POST['which'] ?? '') === 'jam-config' ? 'jam-config' : 'jams';
                $_SESSION['admin_flash'] = rollback_content($which, (int)($_POST['log'] ?? 0)) . '. A backup of the version before the rollback was kept, so you can undo it again.';
                header('Location: jams.php'); exit;
            }

            if ($do === 'save-timer') {
                $cfg = load_content('jam-config');
                $state = (string)($_POST['state'] ?? 'none');
                if (!isset(JAM_STATES[$state])) throw new RuntimeException('Pick what the timer shows.');
                $cfg['state'] = $state;
                $cfg['lastJamUrl'] = jam_url($t('lastJamUrl'), 'The link of the last jam');
                $cfg['lastJamLabel'] = $t('lastJamLabel');
                $cfg['startDate'] = jam_date($t('startDate'));
                $cfg['endDate'] = jam_date($t('endDate'));
                $cfg['currentJamUrl'] = jam_url($t('currentJamUrl'), 'The link of the jam');
                $cfg['currentJamLabel'] = $t('currentJamLabel');
                $msgs = (array)($cfg['messages'] ?? []);
                foreach (JAM_MESSAGES as $k => $_) if ($t('msg_' . $k) !== '') $msgs[$k] = $t('msg_' . $k);
                $cfg['messages'] = $msgs;
                if ($state !== 'none' && ($cfg['currentJamLabel'] === '' || $cfg['currentJamUrl'] === '')) {
                    throw new RuntimeException('An upcoming or running jam needs a name and a link.');
                }
                if ($state === 'upcoming' && $cfg['startDate'] === '') throw new RuntimeException('An upcoming jam needs a start date.');
                if ($state === 'active' && $cfg['endDate'] === '') throw new RuntimeException('A running jam needs an end date.');
                if ($cfg['startDate'] !== '' && $cfg['endDate'] !== '' && $cfg['endDate'] <= $cfg['startDate']) {
                    throw new RuntimeException('The end has to be after the start.');
                }
                save_content('jam-config', $cfg, 'Jam timer: ' . JAM_STATES[$state] . ($state !== 'none' ? ' (' . $cfg['currentJamLabel'] . ')' : ''));
                $_SESSION['admin_flash'] = 'Saved the jam timer. It shows on the home page right away.';
                header('Location: jams.php'); exit;
            }

            if ($do === 'finish') {
                // the jam is over: it becomes the "last jam", and gets a spot in the history
                $cfg = load_content('jam-config');
                $label = (string)($cfg['currentJamLabel'] ?? '');
                $url = (string)($cfg['currentJamUrl'] ?? '');
                if ($label === '' || $url === '') throw new RuntimeException('There is no current jam to finish.');
                $cfg['state'] = 'none';
                $cfg['lastJamLabel'] = $label;
                $cfg['lastJamUrl'] = $url;
                save_content('jam-config', $cfg, "Jam timer: finished $label");
                $jams = load_content('jams');
                $known = array_filter($jams, fn($j) => rtrim((string)($j['url'] ?? ''), '/') === rtrim($url, '/'));
                if (!$known) {
                    array_unshift($jams, ['name' => $label, 'image' => '', 'url' => $url]);
                    save_content('jams', array_values($jams), "Jam history: added $label");
                    $_SESSION['admin_flash'] = "“{$label}” is now the last jam, and it's at the top of the history. Add its picture below.";
                } else {
                    $_SESSION['admin_flash'] = "“{$label}” is now the last jam (it was already in the history).";
                }
                header('Location: jams.php'); exit;
            }

            if ($do === 'save-history') {
                $names = (array)($_POST['jam_name'] ?? []);
                $images = (array)($_POST['jam_image'] ?? []);
                $urls = (array)($_POST['jam_url'] ?? []);
                $list = [];
                foreach ($names as $i => $name) {
                    $name = trim((string)$name);
                    $image = trim((string)($images[$i] ?? ''));
                    $url = trim((string)($urls[$i] ?? ''));
                    if ($name === '' && $image === '' && $url === '') continue; // empty row
                    if ($name === '') throw new RuntimeException('Every jam needs a name (row ' . ($i + 1) . ').');
                    jam_url($url, "The link of “{$name}”");
                    if ($image !== '' && !preg_match('#^(https?://\S+|/?assets/\S+)$#i', $image)) {
                        throw new RuntimeException("The picture of “{$name}” has to be a link (https://…) or a path in assets/.");
                    }
                    $list[] = ['name' => $name, 'image' => $image, 'url' => $url];
                }
                $old = load_content('jams');
                if ($list === $old) {
                    $_SESSION['admin_flash'] = 'Nothing changed.';
                } else {
                    $diff = count($list) - count($old);
                    save_content('jams', $list, 'Jam history: ' . ($diff > 0 ? "added $diff" : ($diff < 0 ? 'removed ' . -$diff : 'edited')) . ' (' . count($list) . ' jams)');
                    $_SESSION['admin_flash'] = 'Saved the jam history.';
                }
                header('Location: jams.php#history'); exit;
            }
            throw new RuntimeException('Unknown action.');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$cfg = load_content('jam-config');
$jams = load_content('jams');
$msgs = (array)($cfg['messages'] ?? []);
$v = fn(string $k) => h((string)($cfg[$k] ?? ''));

admin_head('Jams');
admin_nav('jams');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }
render_recent_changes('jam-config', $csrf, 'Recent changes to the timer');
render_recent_changes('jams', $csrf, 'Recent changes to the history');
?>
  <div class="toolbar">
    <h2>Jams</h2>
    <div class="toolbar__right"><a class="button" href="/" target="_blank">View the home page ↗</a></div>
  </div>

  <form method="post" class="editor" id="timer">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="do" value="save-timer">
    <div class="panel">
      <h3>Jam timer <span class="muted">the countdown on the home page</span></h3>
      <fieldset class="jam-states">
        <?php foreach (JAM_STATES as $k => $label): ?>
        <label class="check"><input type="radio" name="state" value="<?= $k ?>"<?= ($cfg['state'] ?? 'none') === $k ? ' checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach ?>
      </fieldset>
      <h4>The jam <span class="muted">upcoming or running</span></h4>
      <div class="grid2">
        <label>Name <input name="currentJamLabel" value="<?= $v('currentJamLabel') ?>" placeholder="Off-con Game Jam: Rebirth"></label>
        <label>Link <input name="currentJamUrl" value="<?= $v('currentJamUrl') ?>" placeholder="https://itch.io/jam/…"></label>
        <label>Starts <span class="muted">UTC</span><input type="datetime-local" name="startDate" value="<?= $v('startDate') ?>" data-utc></label>
        <label>Ends <span class="muted">UTC</span><input type="datetime-local" name="endDate" value="<?= $v('endDate') ?>" data-utc></label>
      </div>
      <p class="muted">Times are in UTC, like the timer on the site uses them. Under each date you see what that is in your own time zone.</p>
      <h4>The last jam <span class="muted">shown when there is no jam</span></h4>
      <div class="grid2">
        <label>Name <input name="lastJamLabel" value="<?= $v('lastJamLabel') ?>"></label>
        <label>Link <input name="lastJamUrl" value="<?= $v('lastJamUrl') ?>" placeholder="https://itch.io/jam/…"></label>
      </div>
      <details>
        <summary>Texts of the timer</summary>
        <div class="grid2">
          <?php foreach (JAM_MESSAGES as $k => $label): ?>
          <label><?= h($label) ?><input name="msg_<?= $k ?>" value="<?= h((string)($msgs[$k] ?? '')) ?>"></label>
          <?php endforeach ?>
        </div>
      </details>
    </div>
    <div class="savebar">
      <button class="primary">Save the timer</button>
      <?php if (($cfg['state'] ?? 'none') !== 'none' && !empty($cfg['currentJamLabel'])): ?>
      <button class="secondary" name="do" value="finish" formnovalidate
        data-confirm="Is “<?= $v('currentJamLabel') ?>” over? It becomes the last jam, the timer stops, and it's added to the history.">✓ This jam is over</button>
      <?php endif ?>
    </div>
  </form>

  <form method="post" class="editor" id="history">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="do" value="save-history">
    <div class="panel">
      <h3>Jam history <span class="muted"><?= count($jams) ?> jams, newest first (on the home page)</span></h3>
      <ol class="jam-rows" id="jam-rows">
        <?php foreach (array_merge($jams, [['name' => '', 'image' => '', 'url' => '']]) as $i => $j): $empty = $i === count($jams); ?>
        <li class="jam-row<?= $empty ? ' jam-row--new' : '' ?>">
          <img class="jam-row__img" src="<?= h((string)($j['image'] ?? '')) ?>" alt="" loading="lazy"<?= empty($j['image']) ? ' hidden' : '' ?>>
          <div class="jam-row__fields">
            <input name="jam_name[]" value="<?= h((string)($j['name'] ?? '')) ?>" placeholder="<?= $empty ? 'Add a jam: name' : 'Name' ?>" aria-label="Name">
            <input name="jam_url[]" value="<?= h((string)($j['url'] ?? '')) ?>" placeholder="https://itch.io/jam/…" aria-label="Link">
            <input name="jam_image[]" value="<?= h((string)($j['image'] ?? '')) ?>" placeholder="Picture: https://img.itch.zone/… (the jam's banner)" aria-label="Picture" data-preview>
          </div>
          <div class="jam-row__acts">
            <button type="button" class="small" data-move="-1" title="Move up" aria-label="Move up">↑</button>
            <button type="button" class="small" data-move="1" title="Move down" aria-label="Move down">↓</button>
            <button type="button" class="small danger" data-remove title="Remove" aria-label="Remove">✕</button>
          </div>
        </li>
        <?php endforeach ?>
      </ol>
      <p><button type="button" class="small" id="jam-add">＋ Add a jam at the top</button></p>
    </div>
    <div class="savebar"><button class="primary">Save the history</button> <a href="jams.php">Undo my changes</a></div>
  </form>
  <p class="muted note-git">Changes go live right away (with a backup of the old file). Download <a href="?download=jams">jams.json</a> and <a href="?download=jam-config">jam-config.json</a> </p>
</main>
<script src="admin.js?v=3"></script>
<script src="jams.js?v=1"></script>
</body></html>
