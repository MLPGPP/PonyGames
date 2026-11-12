<?php
/**
 * Header and footer for the PHP pages (game pages). The static pages
 * (index.html, games.html, ...) have their own copy of this markup:
 * when you change the menu or footer there, change it here too.
 * Paths are absolute because game pages live under /game/.
 */

const ASSET_V = '20261112a'; // bump together with the ?v= in the static pages

/** <!DOCTYPE> through the header, for simple PHP pages (login, account, ...). */
function render_page_start(string $title, string $active = '', string $description = '', bool $noindex = true): void
{
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <script>
    try {
      var pgTheme = localStorage.getItem("pg-theme");
      if (pgTheme) document.documentElement.dataset.theme = pgTheme;
    } catch (e) {}
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($title) ?> | Pony Games</title>
<?php if ($description !== ''): ?>
  <meta name="description" content="<?= h($description) ?>" />
<?php endif; ?>
<?php if ($noindex): ?>
  <meta name="robots" content="noindex" />
<?php endif; ?>
  <meta name="referrer" content="same-origin">
  <meta name="theme-color" content="#16213e" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Silkscreen&display=swap">
  <link rel="stylesheet" href="/css/style.css?v=<?= ASSET_V ?>" />
</head>
<body>
<?php
    render_header($active);
}

function render_page_end(array $scripts = []): void
{
    render_footer();
    echo '  <script src="/js/main.js?v=' . ASSET_V . "\"></script>\n";
    foreach ($scripts as $src) echo '  <script src="' . h($src) . '?v=' . ASSET_V . "\"></script>\n";
    echo "</body>\n</html>\n";
}

/** The "Log in" / account button in the header. On static pages main.js fills it in. */
function account_link_html(): string
{
    $u = function_exists('current_user') ? current_user() : null;
    if ($u) {
        return '<a href="/account" class="account-link is-logged-in" data-account-link data-server="1" title="Your account">'
            . avatar_html($u, 'account-link__avatar') . '<span class="account-link__name">' . h($u['display_name']) . '</span></a>';
    }
    return '<a href="/login" class="account-link" data-account-link data-server="1">'
        . '<svg class="account-link__icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>'
        . '<span class="account-link__name">Log in</span></a>';
}

function render_header(string $active = 'games'): void
{
    $on = fn(string $k) => $active === $k ? ' class="active"' : '';
?>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">
        <img src="/textlogo.png" alt="Pony Games logo">
      </a>
      <nav class="site-nav" aria-label="Main navigation">
        <a href="/"<?= $on('home') ?>>Home</a>
        <div class="nav-dropdown">
          <a href="/games" class="nav-dropdown__trigger<?= $active === 'games' ? ' active' : '' ?>">Games</a>
          <button type="button" class="nav-dropdown__toggle" aria-expanded="false" aria-controls="games-menu" aria-label="Show game categories">
            <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <ul id="games-menu" class="nav-dropdown__menu">
            <li><a href="/games?status=Released"><span class="nav-dropdown__dot nav-dropdown__dot--done" aria-hidden="true"></span>Released games</a></li>
            <li><a href="/games?status=In%20Development"><span class="nav-dropdown__dot nav-dropdown__dot--wip" aria-hidden="true"></span>Unfinished games</a></li>
            <li><a href="/upcoming"><span class="nav-dropdown__dot nav-dropdown__dot--soon" aria-hidden="true"></span>Upcoming games</a></li>
            <li><a href="/games?status=Cancelled"><span class="nav-dropdown__dot nav-dropdown__dot--abandoned" aria-hidden="true"></span>Cancelled games</a></li>
            <li><a href="/games?tag=Mod"><span class="nav-dropdown__dot nav-dropdown__dot--mod" aria-hidden="true"></span>Mods</a></li>
            <li class="nav-dropdown__sep"><a href="/submit"><span class="nav-dropdown__plus" aria-hidden="true">＋</span>Submit a game</a></li>
          </ul>
        </div>
        <a href="/resources"<?= $on('resources') ?>>Resources</a>
        <a href="/bounties"<?= $on('bounties') ?>>Bounties</a>
        <a href="/about"<?= $on('about') ?>>About</a>
        <select id="theme-switcher" class="theme-switcher" aria-label="Site theme">
          <option value="midnight">Moonbeam</option>
          <option value="light">Sunbeam</option>
          <option value="trixie">Boaster Blue</option>
          <option value="twilight">Magical Purple</option>
          <option value="applejack">Rural Orange</option>
          <option value="pinkie">Party Pink</option>
          <option value="rarity">Rare White</option>
          <option value="rainbow">Speedy Blue</option>
          <option value="fluttershy">Butterfly Yellow</option>

        </select>
        <?= account_link_html() ?>
      </nav>
    </div>
  </header>
<?php
}

function render_footer(): void
{
?>
  <footer class="site-footer">
    <div class="container">
      <div id='mlpring'>
        <script type="text/javascript" src="https://mlp-ring.neocities.org/ring-js/onionring-variables.js"></script>
        <script type="text/javascript" src="https://mlp-ring.neocities.org/ring-js/onionring-widget.js"></script>
      </div>
      <nav class="site-footer__socials" aria-label="Follow Pony Games">
        <span class="site-footer__follow">Follow the herd</span>
        <a class="social-link social-link--mastodon" href="https://pone.social/@ponygames" target="_blank" rel="me noopener noreferrer" aria-label="Mastodon (pone.social)" title="Mastodon (pone.social)">
          <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 0 0 .023-.043v-1.809a.052.052 0 0 0-.02-.041.053.053 0 0 0-.046-.01 20.282 20.282 0 0 1-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 0 1-.319-1.433.053.053 0 0 1 .066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z"/></svg>
        </a>
        <a class="social-link social-link--twitter" href="https://x.com/pony_games_" target="_blank" rel="me noopener noreferrer" aria-label="Twitter (@pony_games_)" title="Twitter (@pony_games_)">
          <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
        </a>
        <a class="social-link social-link--youtube" href="https://www.youtube.com/@PonyGamesProject" target="_blank" rel="me noopener noreferrer" aria-label="YouTube" title="YouTube">
          <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
        </a>
        <a class="social-link social-link--discord" href="https://discord.gg/H2vbaAUMzP" target="_blank" rel="me noopener noreferrer" aria-label="Discord server" title="Discord server">
          <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>
        </a>
      </nav>
      <p class="site-footer__credit"><img class="site-footer__mascot" src="/assets/mascot-head.png" alt="" width="28" height="37" loading="lazy" />&copy; 2026 Pony Games. Made by the herd, for everypony. <span class="site-footer__sep" aria-hidden="true">·</span> <a href="/rules">Site Rules</a></p>
    </div>
  </footer>
<?php
}
