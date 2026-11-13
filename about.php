<?php
/**
 * About Pony Games, with the FAQ (/about). Used to be about.html; the old address still works
 * (it redirects here, see deploy/nginx-ponygames.conf). Header, footer and
 * <head> come from inc/layout.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

render_page_start('About', 'about', 'About Pony Games: contacts, credits, and community info.', false, [
    'canonical' => '/about',
    'head' => <<<'HTML'
  <script type="application/ld+json">{"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": "Is Pony Games free?", "acceptedAnswer": {"@type": "Answer", "text": "Yes. Pony Games is a free, non-profit fan project. We don't sell the games ourselves: every listing links to where the developers share their game, and almost all pony fan games are free to download."}}, {"@type": "Question", "name": "Do I need an account?", "acceptedAnswer": {"@type": "Answer", "text": "Not to browse, play or download. You need one to like and bookmark games, write reviews and join a game's discussion. Your bookmarks then follow you to all your devices, and you can collect gold stars and badges. Create one here."}}, {"@type": "Question", "name": "A game is missing. How do I add it?", "acceptedAnswer": {"@type": "Answer", "text": "Use the Submit a game form (also in the Games menu). Fill in what you know, add a few screenshots and the download links. A moderator checks every submission before it goes on the site. You don't have to be the developer to submit a game."}}, {"@type": "Question", "name": "Some info about a game is wrong or outdated.", "acceptedAnswer": {"@type": "Answer", "text": "Open the game's page and click the ✎ icon at the top right (\"Suggest a correction\"). The form is already filled in with the current info, so you only change what's wrong, and you can reorder or add screenshots too. The game shows \"Change under review\" until a moderator has looked at it."}}, {"@type": "Question", "name": "A download link is broken.", "acceptedAnswer": {"@type": "Answer", "text": "Click the 🏳 icon on the game's page and choose \"Broken or dead link\". If you know a working link, please add it in the details, or suggest a correction with the new link."}}, {"@type": "Question", "name": "What is the bounty board?", "acceptedAnswer": {"@type": "Answer", "text": "The bounty board lists games we know existed but can't find a working copy of. If you have one of them (or know where it lives), send a tip from its wanted poster. There's no reward except the eternal gratitude of the herd, and maybe a gold star if you have an account."}}, {"@type": "Question", "name": "What are gold stars and badges?", "acceptedAnswer": {"@type": "Answer", "text": "Thank-yous from the moderators for helping the site: submitting games, fixing info, finding lost games. They're given by hand and show up next to your name on reviews and messages, and on your account page."}}, {"@type": "Question", "name": "Why are some games hidden or blurred?", "acceptedAnswer": {"@type": "Answer", "text": "Games tagged NSFW or with gore are hidden from the lists by default and their pictures are blurred until you choose to see them. You can show them with the filters on the games page. If a game is missing such a tag, please report it."}}, {"@type": "Question", "name": "Can I review a game more than once?", "acceptedAnswer": {"@type": "Answer", "text": "You can write one review per game, but you can edit or delete it at any time, on the game's page or on your account page. The same goes for your messages in a game's discussion."}}]}</script>
HTML
]);
?>

  <main class="container">

    <h1 class="page-title">About</h1>

    <div class="about-content">

      <!-- ===================== MASCOT (click to enlarge) ===================== -->
      <img class="about-mascot" src="assets/mascot.png" alt="Pony Games mascot" tabindex="0" role="button" aria-label="Enlarge mascot image" />

      <!-- ===================== ABOUT TEXT ===================== -->
      <section class="about-section">
        <h2>What Is This?</h2>
        <p>
          Pony Games is a community-run directory for pony fan games,
          game jam events, and free development resources. We aim to be the
          central place where players discover games and developers find tools.
        </p>
      </section>

      <!-- ===================== FAQ ===================== -->
      <section class="about-section faq" id="faq">
        <h2>FAQ</h2>
        <details class="faq__item">
          <summary class="faq__q">Is Pony Games free?</summary>
          <div class="faq__a"><p>Yes. Pony Games is a free, non-profit fan project. We don't sell the games ourselves: every listing links to where the developers share their game, and almost all pony fan games are free to download.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">Do I need an account?</summary>
          <div class="faq__a"><p>Not to browse, play or download. You need one to like and bookmark games, write reviews and join a game's discussion. Your bookmarks then follow you to all your devices, and you can collect gold stars and badges. <a href="/login?tab=signup">Create one here</a>.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">A game is missing. How do I add it?</summary>
          <div class="faq__a"><p>Use the <a href="/submit">Submit a game</a> form (also in the Games menu). Fill in what you know, add a few screenshots and the download links. A moderator checks every submission before it goes on the site. You don't have to be the developer to submit a game.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">Some info about a game is wrong or outdated.</summary>
          <div class="faq__a"><p>Open the game's page and click the ✎ icon at the top right ("Suggest a correction"). The form is already filled in with the current info, so you only change what's wrong, and you can reorder or add screenshots too. The game shows "Change under review" until a moderator has looked at it.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">A download link is broken.</summary>
          <div class="faq__a"><p>Click the 🏳 icon on the game's page and choose "Broken or dead link". If you know a working link, please add it in the details, or suggest a correction with the new link.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">What is the bounty board?</summary>
          <div class="faq__a"><p>The <a href="/bounties">bounty board</a> lists games we know existed but can't find a working copy of. If you have one of them (or know where it lives), send a tip from its wanted poster. There's no reward except the eternal gratitude of the herd, and maybe a gold star if you have an account.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">What are gold stars and badges?</summary>
          <div class="faq__a"><p>Thank-yous from the moderators for helping the site: submitting games, fixing info, finding lost games. They're given by hand and show up next to your name on reviews and messages, and on your account page.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">Why are some games hidden or blurred?</summary>
          <div class="faq__a"><p>Games tagged NSFW or with gore are hidden from the lists by default and their pictures are blurred until you choose to see them. You can show them with the filters on the games page. If a game is missing such a tag, please report it.</p></div>
        </details>
        <details class="faq__item">
          <summary class="faq__q">Can I review a game more than once?</summary>
          <div class="faq__a"><p>You can write one review per game, but you can edit or delete it at any time, on the game's page or on your account page. The same goes for your messages in a game's discussion.</p></div>
        </details>
      </section>
      <!-- ===================== CONTACTS ===================== -->
      <section class="about-section">
        <h2>Contact</h2>
        <ul>
          <li>Email: <a href="mailto:littlehorseappreciator@proton.me">littlehorseappreciator@proton.me</a></li>
          <li>We're open for the suggestions, let your voice be known <a href="https://forms.gle/9bsbD59P9hF9iNb59">here</a>.</li>
        </ul>
      </section>

      <!-- ===================== CREDITS ===================== -->
      <section class="about-section">
        <h2>Credits</h2>
        <ul>
          <li>Original idea: Zaifer</li>
          <li>Site design &amp; webdev: Sea Swirl, Gonderlane, Zaifer</li>
          <li>Hosting: Meen-Shei, Marius, Alethila</li>
          <li>Art: XallaanaCyoxCyor, Maddragon</li>
          <li>Game directory upkeep and management: MLP Game Preservation Project group, Sea Swirl, XallaanaCyoxCyor, GuessWho</li>
          <li>Game listings: submitted by community developers</li>
          <li>Resource assets: respective creators (see individual listings)</li>
          <li>
            My Little Pony is a trademark of Hasbro.
            This is an unofficial fan project and is not affiliated with or endorsed by Hasbro.
          </li>
        </ul>
      </section>

    </div>

  </main>
<?php
render_page_end([], [
    'html' => <<<'HTML'
  <!-- ===================== MASCOT LIGHTBOX ===================== -->
  <div id="mascot-lightbox" class="mascot-lightbox" hidden>
    <button class="mascot-lightbox__close" type="button" aria-label="Close">&times;</button>
    <img class="mascot-lightbox__img" src="assets/mascot.png" alt="Pony Games mascot, enlarged" />
  </div>

HTML
]);
