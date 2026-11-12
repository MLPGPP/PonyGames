<?php
/**
 * Site Rules. Linked from the sign-up form ("By creating an account you
 * agree…"), the review and discussion forms and the footer of every page.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

const RULES_UPDATED = 'November 11, 2026';
const RULES_MAIL = '<a href="mailto:contact@pony.games">contact@pony.games</a>';

// [number, icon, title, text]. The text is HTML (only our own links in it).
$rules = [
    ['1', '🔞', 'NSFW stays in NSFW', 'Explicit language and topics are only allowed on explicit games (tagged with gore or NSFW).'],
    ['2', '🧭', 'Stay on topic', 'Reviews and comments must stay on topic. It\'s not allowed to bring in outside drama/gossip or make observations about the game\'s creator or the team behind it.'],
    ['3', '⭐', 'Quality reviews', 'Reviews must be truthful and objective. They have to have quantifiable information. The reviewer must have played the game to review it.'],
    ['4', '🔗', 'No links', 'Posting external links on the site is strictly prohibited. You can email us info at ' . RULES_MAIL . ' or share it via the <a href="/submit">submission forms</a>.'],
    ['5', '🛡️', 'No targeted harassment', 'Calls to action (aka RAIDS) and targeted campaigns are not allowed on the site. Neither is doxing.'],
    ['6', '📣', 'No spamming or extremely low quality comments', 'Posting the same comments and reviews over and over in multiple or the same game pages with no changes is prohibited. Posting extremely low quality comments is also not allowed; Example: “sadfgdgjhke LOL :), me am me jajajja”.'],
    ['7', '🐴', 'One account per pony', 'Only one account per person is permitted. If you need multiple accounts, please reach us via email at ' . RULES_MAIL . '. If you\'re found to have multiple accounts without permission, all your accounts will be banned.'],
    ['8', '🎭', 'No impersonators', 'Pretending to be a member or staff or a dev will get you banned.'],
    ['9', '🏳️', 'Report and move on', 'If you see someone going against the spirit of the rules or breaking them outright, report them using the report button (🏳) on the message or the game and move on. Don\'t get into needless arguments.'],
    ['∞', '⚖️', 'Leeway', 'Mods are the final interpreters of the rules. If they feel a user breaks the spirit of a rule, the mods are allowed to inflict punishment, with a proper justification.'],
];

render_page_start('Site Rules', 'about', 'The Site Rules of Pony Games: how we keep reviews and discussions friendly for everypony.', false);
?>
  <main class="container">
    <h1 class="page-title">Site Rules</h1>

    <div class="about-content rules">
      <section class="about-section">
        <p class="rules__lead">
          Pony Games is a friendly place for everypony who loves pony fan games. These rules are for everything you add to the site:
          reviews, discussion messages, game submissions and corrections, bounty tips, and your display name.
          By creating an account you agree to follow them.
        </p>
      </section>

      <ol class="rules__list">
<?php foreach ($rules as [$num, $icon, $title, $text]): ?>
        <li class="rules__item" id="rule-<?= $num === '∞' ? 'leeway' : $num ?>">
          <span class="rules__icon" aria-hidden="true"><?= $icon ?></span>
          <div>
            <h2 class="rules__title">Rule #<?= $num ?>. <?= h($title) ?></h2>
            <p><?= $text ?></p>
          </div>
        </li>
<?php endforeach ?>
      </ol>

      <section class="about-section">
        <h2>When rules are broken</h2>
        <p>
          Moderators can hide or remove reviews and messages, and suspend or ban accounts, for a while or for good.
          A ban comes with a reason, which you see when you try to log in.
        </p>
        <p>
          Questions about these rules, or think a moderator got it wrong? Talk to us on our
          <a href="https://discord.gg/H2vbaAUMzP" target="_blank" rel="noopener">Discord server</a>.
        </p>
        <p class="rules__updated">Last updated: <?= RULES_UPDATED ?></p>
      </section>
    </div>
  </main>
<?php
render_page_end();
