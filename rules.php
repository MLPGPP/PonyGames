<?php
/**
 * Site Rules. Linked from the sign-up form ("By creating an account you
 * agree…"), the review and discussion forms and the footer of every page.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

const RULES_UPDATED = 'November 11, 2026';

$rules = [
    ['💬', 'Be kind', 'Criticise games, not people. No insults, threats, harassment or bullying of other members or of developers. Disagreeing is fine; being mean about it isn\'t.'],
    ['🚫', 'No hate', 'No racism, sexism, homophobia, transphobia or other hate, slurs or hateful symbols, in messages, reviews or display names.'],
    ['🛡️', 'Keep it safe for everypony', 'No sexual or gory content in reviews, messages or display names. Games tagged NSFW can be listed (they\'re hidden by default), but talk about them stays tasteful.'],
    ['⭐', 'Honest reviews', 'Only review games you\'ve played. No fake reviews, no review bombing, and no rating your own game with extra accounts.'],
    ['📣', 'No spam', 'No repeating the same message, no posting just to collect stars. Links aren\'t allowed in reviews and messages. Developers are welcome to answer questions about their own game in its discussion.'],
    ['🧭', 'Stay on topic', 'A game\'s discussion is about that game. Warn others before spoilers (start with "Spoiler:").'],
    ['🔒', 'Respect privacy', 'Don\'t share anyone\'s personal information, like real names, addresses or contact details, without their permission.'],
    ['🐴', 'One pony, one account', 'Don\'t pretend to be another member, a developer or a moderator. Making a new account to get around a ban gets every account banned.'],
    ['🏳️', 'Report, don\'t fight back', 'See something that breaks these rules? Use the report button (🏳) on the message or game and let the moderators handle it.'],
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
<?php foreach ($rules as $i => [$icon, $title, $text]): ?>
        <li class="rules__item" id="rule-<?= $i + 1 ?>">
          <span class="rules__icon" aria-hidden="true"><?= $icon ?></span>
          <div>
            <h2 class="rules__title"><?= $i + 1 ?>. <?= h($title) ?></h2>
            <p><?= h($text) ?></p>
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
