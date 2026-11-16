# Pony Games

<div align="center">
  <img src="https://games.mare.by/static/ponygames_logo.png" alt="logo" width="200">
</div>
<div align="center">
  <img src="https://pony.games/textlogo.png" alt="logo" width="400">
</div>

Community-run directory of pony fan games, jams, and dev resources.
No build step. Every page is PHP with plain JavaScript: the pages share one
header, footer and `<head>` (`inc/layout.php`), and the game list, bounties
and resources are filled in by JavaScript from `data/*.json`. Each game also
has its own page (`/game/<id>-<slug>`), with likes, reviews, a discussion and
reports stored in SQLite.

## Running locally

Needs PHP 8.1+ with `pdo_sqlite` (and ideally `intl`). From the repo root:

    php -S localhost:8000 router.php

Then open http://localhost:8000. `router.php` does what the nginx rewrites
do in production (page addresses, game pages, short links, sitemap.xml) and
blocks private folders. The site needs PHP; opening the files directly
(file://) doesn't work.

## Page addresses

Pages have short addresses: `/games`, `/upcoming`, `/resources`, `/requests`, `/bounties`,
`/about`, `/rules`, `/account`, `/login`, `/submit` (and `/game/12-title` for
games). nginx maps them to the `.php` files (`rewrite` lines in
`deploy/nginx-ponygames.conf`; on aaPanel they go under Website → URL rewrite).
The pages used to be `.html` files. The nginx rule redirects the old
addresses, so links shared like `games.html?game=332` still open that game
(as `/games?game=332`). Without that rule they give a 404, so keep it in the
server config. The stats count old and new as one page.

## Layout

    index.php, games.php, upcoming.php, resources.php, bounties.php, about.php,
    rules.php         the pages (vanilla JS, one shared stylesheet)
    inc/layout.php    the shared <head>, header and footer, and ASSET_V: bump it
                      after changing css/ or js/, so browsers fetch the new files
    game.php          one page per game: /game/<id>-<slug>
    sitemap.php       sitemap.xml with every page and game (for Google)
    api/community.php likes, reviews, discussion, reports (JSON, used by js/game.js)
    api/ratings.php   scores, likes, bookmarks, downloads and plays per game (for the cards)
    api/track.php     counts clicks on download / Play links (once per visitor, game and day)
                      and page views (for /admin/stats.php)
                      Flash games on flash.mare.by open in a player on the game page itself
                      (iframe in place of the screenshots; EMBED_PLAY_HOSTS in game.php and js/main.js).
                      The games popup sends "Play" to the game page (#play). Phones and tablets open
                      the game in its own tab.
    api/bounty.php    tips and new-bounty suggestions from the bounty board
    submit.php        submit a new game, or fix the info of a listed one (?edit=<id>)
    login.php         log in / sign up / forgot password (+ reset.php, verify.php, logout.php)
    account.php       your account: bookmarks, gold stars, badges, settings
    api/me.php        who is logged in (for the header button and bookmark sync)
    api/account.php   bookmark sync for logged-in users
    admin/            moderation: reports, submissions, posts, bounty tips, accounts,
                      games.php + resources.php (edit the content)
    inc/              shared PHP (bootstrap.php: data, database; auth.php: accounts;
                      submissions.php, content.php: editing games.json/resources.json;
                      layout.php: header/footer; notify.php: bell + daily email;
                      discord.php: posts for the moderators' Discord channel)
    router.php        for `php -S` only; production uses deploy/nginx-ponygames.conf
    css/style.css     all styles
    js/main.js        cards (+ hover card), filters, news, jam history
    js/game.js        game page: gallery, like, bookmark, reviews, discussion, report
    js/bounties.js    bounty board posters and tip form
    js/submit.js      submit / fix-info form
    js/shot-editor.js picture editor (order, cover, crop), for submit.php and /admin
    js/post-tools.js  edit / delete your own reviews and messages (game page, account)
    js/account.js     account page
    js/timer.js       homepage jam countdown, configured by data/jam-config.json
    data/*.json       all site content (games, posts, jams, resources)
    s/<id>/           game screenshots, one folder per game id
    assets/           site images (mascot etc.)
    tools/            jam-editor.html, a form-based editor for data/jams.json
                      so jam history can be edited without touching JSON
    static/admin/     Decap CMS config. Needs a git-gateway backend (Netlify),
                      which the current hosting doesn't have, so it's inactive.

## Data conventions

- `author` is a string in old entries, an array of strings in new ones.
- Tag `type` is one of warning / black / genre / other (case-insensitive).
  Games tagged with a `warning` type are hidden behind the NSFW toggle.
- `releaseDate` is `YYYY-MM-DD`, or just `YYYY` if only the year is known,
  or empty/absent if unknown. Never DD.MM.YYYY, JS date parsing chokes on it.
- `dateAdded` is when the listing was added to the site, not when the game
  came out. It drives the "Newest" sidebar and default sort.
- Playtime buckets: `<5 min`, `5m-30m`, `30m-2h`, `2h-4h`, `4h-10h`, `10h+`,
  plus `Unknown`.
- `status` is one of these four (exact spelling):

  | Value            | Shown on the site as | Where it appears                      |
  |------------------|----------------------|---------------------------------------|
  | `Released`       | Released games       | /games                                |
  | `In Development` | Unfinished games     | /games, playable but unfinished       |
  | `Cancelled`      | Cancelled games      | /games, no longer being worked on     |
  | `Upcoming`       | Upcoming games       | /upcoming only: announced, but no playable demo yet |

  The "Games" menu in the header links to each one
  (`/games?status=Released` etc.). Move a game from `Upcoming` to
  `In Development` once it has a playable build. (Old `Abandoned` values
  still work and show as Cancelled.)
- Mods are normal entries with an extra tag `{ "label": "Mod", "type": "Other" }`.
  "Mods" in the Games menu is `/games?tag=Mod`.
- A game's page URL comes from its `id` and title: `/game/332-drifter`.
  Only the id matters; a wrong or old slug redirects to the right one, so
  renaming a game doesn't break links. Old `games.html?game=ID` links
  still open the game too. Never reuse an id.
- Upcoming games show "follow" buttons instead of downloads: `url` is the
  dev page (Steam, itch.io, a website...), and an optional `socials` list
  adds more, e.g.

      "socials": [
        { "url": "https://www.patreon.com/somedev" },
        { "url": "https://x.com/somedev", "label": "Dev's Twitter" }
      ]

  The button text and icon come from the link (Patreon, Twitter, YouTube,
  Discord, Steam, itch.io, ...); `label` is optional.
- Trailers and gameplay videos: an optional `videos` list, shown after the
  screenshots on the game page and in the games popup. Each entry is a
  YouTube link or an https link to a video file (.mp4, .webm, .ogv, .m4v, .mov):

      "videos": [
        "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
        "https://example.org/trailer.mp4"
      ]

  Moderators add them in `/admin/` → Games (Pictures & videos), visitors can
  suggest them in the submit form. Nothing is loaded from YouTube until
  someone presses play (then from youtube-nocookie.com); the cover picture
  comes from i.ytimg.com. Video files play in the browser's own player and
  only load once they're shown (the first slide loads just its length).

## Submitting resources

`/submit?resource` (third tab on the submit page, also linked from Resources)
lets anyone share an asset: name, makers, type(s), license, asset pack,
description, how to credit, download links (at least one) and up to 12 preview
pictures (`js/submit-resource.js`, same picture editor as for games). It goes to
`api/submit.php` as `kind=resource` and shows up under **Admin → Submissions**
as "🧩 New resource". **Accept** with *Publish* ticked adds it to
`data/resources.json` (previews in `asset_previews/<id>/`) and the sender gets a
message under the bell with a link to it (`/resources?resource=<id>` opens it).

## Asset requests

`/requests` (`requests.php`, logic in `inc/requests.php`) is the bounty board
the other way around: members ask for assets ("a Rarity walk cycle, 8 frames")
and other members make them.

1. A member (logged in, email confirmed) posts a request: title, kind, a
   description, up to 5 reference links and what it's for. Max 3 a day.
2. A moderator approves or rejects it in **Admin → Requests**, with an optional
   message. Only approved requests are on the board.
3. Any other member clicks **I'll make this** and picks how long they need
   (3 days to 3 months, `REQUEST_CLAIM_CHOICES`; **Need more time?** adds
   more, up to half a year ahead). When that runs out without a result the
   request is open again. Nobody can make more than 3 things at once. **Give it up**
   puts it back on the board.
4. The maker clicks **It's done!** and adds a link to the result. It then
   waits for a moderator (**Admin → Requests → To check**), who either
   **approves** it (the requester gets it, the maker gold stars and the 🎨
   Asset Maker badge; tick *Also add it to the resources* to go on to the
   resource editor, filled in from the request) or **sends it back** to the
   maker with what to fix. A done request can also be added to the
   resources later (**🧩 Add to the resources**).

The requester can close their request at any time. Everyone involved hears
about every step under the bell 🔔 and in the daily email; moderators get it
all in Discord (`DISCORD_WEBHOOK_REQUESTS`, or the submissions channel).
Moderators can also close, reopen and delete requests. The admin menu shows
how many requests wait for approval or for a reward.

## Bounties

`/bounties` (bounties.php) is the bounty board: games we know existed but can't find.
Visitors click "I've seen it!" on a poster to send links; those tips land in
the moderation panel (`/admin/` → Bounties, above the list), together with suggestions
for new bounties. Nothing is published automatically.

The posters come from `data/bounties.json` (the entries that ship with the
repo are examples, replace them):

```json
{
  "id": "1",                       // unique, never reuse
  "title": "Cloudsdale Courier",
  "authors": ["SomePony"],
  "year": "2012",
  "platform": "Flash",
  "description": "What the game was about.",
  "lastSeen": "A Mediafire link in a 2012 forum post",
  "lastSeenUrl": "https://web.archive.org/...",   // optional
  "image": "s/bounties/courier.png",              // optional, else a silhouette
  "status": "wanted"                              // or "found"
}
```

Moderators can do all of this in `/admin/` → **Bounties** (add, edit, upload a
picture, mark as found and link the game). By hand it works like this:
when a game is found, add it to games.json, then set `"status": "found"`,
`"foundGameId": "<its id in games.json>"` and optionally `"foundBy": "name"`.
The poster gets a FOUND stamp and links to the game's page.

**Answering tips.** In /admin/bounties.php every open tip has **✓ Answer & close ▾**:
choose *Helpful* or *Not what we needed*, write a message and give gold stars
(members only). The member sees it under the bell 🔔 and in their daily email
(empty message: a short standard text). They also hear about it when their
suggestion becomes a bounty (**Make a bounty of this**), when they get **+1 ★**,
and when a bounty they sent a tip for is marked as **found** (with a link to
the game when it's on the site).

## Submissions (new games and corrections)

`submit.php` replaces the Google Form. People can add a new game (all fields
of games.json, links, pictures) or fix the info of a listed game (the form is
filled in with the current info and pictures; "Edit" on every game page links
there). Both use the same picture editor as the admin (see below): put the
pictures in order, pick the cover, crop, remove or add (up to 12 new or cropped
pictures, 5 MB each). Nothing changes on the site by itself:

1. In `/admin/` → Submissions, open a submission. Corrections show a
   before/after table, new games all their details. When the pictures of a
   correction were changed you see the pictures now and the suggested order
   (new, cropped, moved and removed ones are marked).
2. Accept with "Publish it right away" (the default) and games.json and the
   pictures are updated: the pictures are renamed to 0 (cover), 1, 2… just
   like the editor does, and the save can be rolled back under Games → Recent
   changes. Without publishing, the page shows a ready-made games.json entry
   to copy by hand.
3. Accept (optionally with gold stars and a badge for members) or reject,
   with a short message the member sees on their account page.

While a correction for a game is waiting, the game page and the popup show
"⏳ Change under review" (from `pending` in `/api/me.php`). Pictures that a
correction keeps are remembered by their fingerprint, so a correction still
publishes correctly when the game's pictures were reordered in the meantime;
pictures that were removed since are left out (the admin page says so).

Uploaded pictures are stored next to the database (`uploads/`), outside the
website folder, and only moderators can see them until they're published.

## Screenshot editor

The **Pictures** part of the game form in `/admin/` → Games is a screenshot editor
(`js/shot-editor.js`, also used on submit.php):

- drag the pictures into the best order (or use the ← → buttons / arrow keys);
  the first one is the cover; ★ makes a picture the cover;
- 🗑 removes a picture, ✂ crops it (free, 16:9, 16:10, 4:3 or 1:1; saved as JPG);
- add pictures by choosing or dropping them.

When the game is saved, its pictures are renamed to `0.ext` (cover), `1.ext`,
`2.ext`… in the game's `s/<n>/` folder and games.json is updated. "Save & next
game →" and the Previous/Next links make it quick to go through many games.
The old files are moved to `backups/screenshots/` next to the database and
belong to that save: "Roll back" puts the pictures back together with
games.json. They are only kept as long as that save can be rolled back (the
last 3 saves), so there are never more picture backups than that. A few games
share a folder with another game; when one of those gets new pictures it gets
its own folder, so the other game keeps its pictures.

## Who can use /admin

- The **owner password** (`ADMIN_PASSWORD_HASH` in `.env`) always works and can do everything.
- **Site accounts** can be given access in `/admin/` → Accounts → Manage → "Admin panel access":
  - *Moderator*: reports, submissions, reviews & discussion, games, resources, bounties;
  - *Admin*: all of that, plus accounts (stars, badges, password links) and giving others access.
  They just log in on the site and open `/admin/` (or the "Admin panel" link on their account page).
  Access is checked on every page, so taking it away works immediately. Nobody can change their own access.
- The change log under "Recent changes" shows who made each change.

## Editing games and resources in /admin

`/admin/` → **Games** and **Resources** list everything with search, and let
moderators add, edit and delete entries (all fields, tags, download links
with mirrors, pictures; "Edit as JSON" for the rest). Resources also has an
**Asset packs** page for the pack download links. Accepting a submission can
publish it straight into games.json too (with its pictures copied into `s/`).

These pages write `data/games.json` / `data/resources.json` **on the server**
(PHP needs write access to `data/`, `s/` and `asset_previews/`). Every save
first keeps a copy of the old file in `backups/` next to the database. The
last 3 saves per file can be rolled back with "↶ Roll back" under Recent changes
(rolling back to before a change also undoes the changes after it). games.json is written in exactly the same style as now
(2 spaces, `\u0027`-style escapes), so git diffs only show real changes.

Because the server's files change, bring them back into git now and then:
"⬇ games.json" / "⬇ resources.json" downloads the server's version (plus any
new pictures in `s/` and `asset_previews/`). Otherwise the next upload of the
repo overwrites what was edited online.

### Jams

`/admin/` → **Jams** manages both jam files:

- **Jam timer** (`data/jam-config.json`, the countdown on the home page):
  no jam / upcoming / running, the jam's name, link, start and end (in UTC,
  the page shows your local time next to it), the last jam, and the timer
  texts. "✓ This jam is over" makes the current jam the last jam, stops the
  timer and adds it to the top of the history (then add its picture).
- **Jam history** (`data/jams.json`, newest first): name, link and picture
  per jam; add, reorder (↑ ↓) and remove rows.

Both are backed up and can be rolled back like the other files, and can be
downloaded from the bottom of the page to put into git.

### News

`/admin/` → **News** writes the posts of "News from the herd" on the home
page (`data/posts.json`): title, date (newest first), the text in Markdown,
and an optional picture above the text. Pictures can be uploaded into the
text or above it; they're saved in `assets/news/` (PHP needs write access
there and to `data/posts.json`). Backed up and rolled back like the other
files; "posts.json" at the bottom of the page downloads it for git.

## Stats

`/admin/stats.php` (menu "Stats") shows the last 7, 30, 90 days or 12 months,
compared with the period before: visits and page views, downloads and plays,
new accounts, reviews, discussion posts, likes, bookmarks, submissions and
reports; charts per day (per week for 12 months, hover for the numbers);
top games, top pages, the sites visitors come from and desktop vs. mobile.
Pick one day with the date field (‹ › step a day) or by clicking a day in a
chart: the numbers are then for that day, compared with the day before.

Page views are sent by `js/main.js` to `api/track.php` and kept in the
`page_views` table. There's no cookie: the visitor is a hash of IP address +
browser + day, so a "visit" is one visitor on one day and nobody can be
followed across days. Only the referring site's host is kept. Bots are
skipped; browsers that send Do Not Track / Global Privacy Control are counted
too, because nothing personal is kept or shared. Rows
older than about 400 days are removed. Days are UTC.

**Where visitors come from** counts visits (not pages) by their source: the
other site they clicked a link on, one of your share links, or "Direct /
unknown" when the browser didn't say (typed in, a bookmark, an app like
Discord or WhatsApp, or a privacy browser such as Zen that strips it).

**Share links** (`/admin/links.php`, "🔗 Share links" on the Stats page): a
link shortener for your own pages. Give a link a name ("Discord announcement")
and pick a page; you get a short link like `pony.games/s/k3x9qa` to share.
`s.php` sends it on to the page with `?s=<code>`, and visits through it show
under that name in "Where visitors come from", also from apps that send no
referrer. Link previews (Discord, etc.) don't count: they run no script.
Only codes made there count (`api/track.php` checks them), so visitors can't
invent sources; unknown codes go to the home page. The code is removed from
the address bar right after, so it isn't copied along. Deleting a share link
stops it (it then goes to the home page); old visits stay in the stats.
`/s/<code>` needs the nginx rule in `deploy/nginx-ponygames.conf`;
`/s.php?c=<code>` works without it.

## Accounts, gold stars and badges

Visitors can browse, download and report without an account. Liking,
bookmarking, writing a review or joining a game's discussion needs one:

- likes are stored per member (`visitor` = `u:<user id>` in the likes table).
  Older anonymous likes keep counting; when that browser's owner logs in and
  opens the game, their old like moves to the account so it isn't counted twice;
- bookmarks live in the account (`user_bookmarks`). Bookmarks someone made as a
  guest in the past are kept in the browser (`pg-bookmarks-guest`) and added to
  the account at their next log in;

- one review per member and game; posting again isn't possible, but members
  can edit their review ("Edit" on it, or "Edit your review"), which marks it
  as edited. Reviews posted anonymously before this stay up;
- the discussion (stored as kind `comment` in the posts table) is a simple
  list of messages under each game.
- members can edit or delete their own reviews and messages, on the game
  page and on their account page ("Your reviews & discussion"); see
  `js/post-tools.js` and the `edit` / `delete` actions in `api/community.php`.

With an account also:

- bookmarks are kept on the account, so they show up on every device
  (bookmarks made before logging in are added the first time);
- reviews and messages show the account's name, picture and gold stars;
- `/account.php` shows the bookmarks carousel, gold stars and badges
  (tab "Account") and lets people change name, email, password or
  delete the account (tab "Settings").

People log in with email + password. Gold stars and badges
are given **by hand** in `/admin/` → Accounts (or with the "+1 ★" button
next to a bounty tip from a logged-in member). The badge list is `BADGES`
in `inc/auth.php`; add new ones there, but never change an existing id.

Pages: `login.php` (log in / sign up / forgot password), `account.php`,
`reset.php`, `verify.php`, `logout.php`. The server shows the account
button in the header; `api/me.php` tells the page's JavaScript who is logged
in (likes, bookmarks, the notification bell).

**Email** Mail is needed. Account are like not be able to place posts or reviews if their email is not verified: "forgot password" tells people to ask a moderator, who
makes a one-time reset link in `/admin/` → Accounts → Manage. To send mail
(password resets and email confirmation), add an SMTP account to `.env`:

    SMTP_HOST=smtp.example.com
    SMTP_PORT=587
    SMTP_SECURE=tls            # tls (port 587), ssl (port 465) or none
    SMTP_USER=...
    SMTP_PASS=...
    MAIL_FROM=Pony Games <noreply@pony.games>

### Confirming the email address

When email is set up (SMTP in `.env`), a new account has to click the link in
the confirmation email before it can like, bookmark, review or join a
discussion; until then the account page and the game pages say so, with a
"send it again" button. Accounts that existed before this rule are exempt
(`verify_exempt`). An admin can confirm an address by hand on the account's
Manage page. Without SMTP nothing is checked (nobody could confirm), and
`REQUIRE_EMAIL_CONFIRM=0` in `.env` turns the check off.

### Bans

Admins can ban an account from its **Manage** page (Accounts): a reason (the
user sees it when they try to log in) and a duration (1 day to a year, or for
good). A banned account is logged out everywhere and can't log in, like,
bookmark, review or join a discussion until the ban ends or is lifted. Banning
can also delete all their reviews and/or discussion messages; the same can
be done without a ban ("Their reviews & discussion"). Accounts with admin
panel access can't be banned. Every ban is kept in `user_bans` (shown as Ban
history on the account).

### Replies in the discussion

Discussions are threaded Reddit-style: every message has a **Reply** button
(for members with a confirmed email), and a reply sits under the exact
message it answers, at any depth. Clicking the line next to a branch (or
"Hide N replies") folds it. The member whose message got a reply gets a
notification under the bell, linking straight to the reply (and it's in the
daily summary email, below). Several replies to the same message while the
notification is still unread become one ("Rarity and others replied").
When a message with replies is deleted, its replies move up to the message
above it; if the first message of a thread goes, the thread shows "This
message was removed". In the database a reply is a normal `comment` post
with `parent_id` (the thread's first message), `reply_to` (the message it
answers) and `reply_user` (who wrote that). Posting is limited to 10
messages per 10 minutes and 60 per day per visitor.

Reviews and discussion messages may not contain links (`has_link()` in
`inc/auth.php`): http(s)://, www. and bare addresses like example.com or
discord.gg/abc are refused, also when editing. File names (setup.exe,
readme.md) and normal sentences are fine.

### Notifications for bookmarked games

When a game someone bookmarked changes (a new download or version, a new
status or release date, a new name, new pictures or videos, other details),
they get a notification under the bell 🔔 in the header (`inc/notify.php`,
`api/notifications.php`). Changes to the same game within 6 hours are merged
into one notification. Brand-new games never notify (nobody has bookmarked
them yet).

### Daily summary email

Notifications are not emailed one by one: members with a confirmed email get
**one summary a day**, only when something happened, with the game updates
and replies that are new since the last summary and that they haven't
already opened under the bell. What goes in it follows their choices under
Account → Settings → Notifications (game updates and/or replies; bell and/or
email). Every summary has a one-click "turn off" link (`notify-off.php`,
signed per account). Banned accounts get nothing.

It goes out after 9:00 Amsterdam time (`DIGEST_HOUR` / `DIGEST_TZ` in
`inc/notify.php`). No cron job is needed: the first visit after that hour
starts it in the background (via `api/me.php`), and it runs only once a day.
To have it on time even when nobody visits, add a cron job:

    5 9 * * *  php /path/to/site/cron/daily-digest.php

(`--force` sends again right away; only what wasn't mailed yet.) The `cron/`
folder is blocked for web visitors in the nginx config.

## Discord channel for the moderators

With `DISCORD_WEBHOOK_REPORTS` and `DISCORD_WEBHOOK_SUBMISSIONS` in `.env`
(see `.env.example`), the site posts in a Discord text channel
(`inc/discord.php`):

- **Reports channel**: every new report (game, reason, details, who reported
  it, the reported text, and whether it was hidden automatically), and when a
  moderator handles it: what was done, by whom, and the messages that went out.
- **Submissions channel**: every new game or correction (who sent it, what
  changed), and when it's accepted or rejected: by whom, the reply, stars or
  badge, and whether it's on the site yet.
- **Bounties channel** (`DISCORD_WEBHOOK_BOUNTIES`; empty = the submissions
  channel): every new tip or suggested bounty, a tip closed or deleted, a
  suggestion turned into a bounty, and a bounty marked as found.
- **Requests channel** (`DISCORD_WEBHOOK_REQUESTS`; empty = the submissions
  channel): new asset requests and every step after (approved, claimed, done,
  claim ran out, closed, maker rewarded).

Each message links to the right page in /admin. Mentions like `@everyone` in
what visitors wrote don't ping anyone. The message goes out after the page
was sent, so Discord never slows down the site. To make a webhook: channel
settings → Integrations → Webhooks → New Webhook → Copy Webhook URL. Keep the
URL private: anyone who has it can post in that channel.

## Google Sheet tab

After every change to `games.json` (admin saves, accepted submissions,
rollbacks) the site copies the whole game list to one tab of the Google
Sheet, one row per game (`inc/sheet_sync.php`). It goes through a small
Apps Script in the sheet (`tools/google-sheet-sync.gs`) that only ever writes
the tabs in its `ALLOWED_TABS` list (default `site`); the other tabs are
never touched, and edits made in the `site` tab are overwritten by the next
update. The sync runs after the page is sent, so saving doesn't wait for it.
`/admin/` → Games shows when it last ran and has a "Sync now" button.

Setup: follow the steps at the top of `tools/google-sheet-sync.gs`, then add
to `.env`:

    SHEET_WEBHOOK_URL=https://script.google.com/macros/s/…/exec
    SHEET_WEBHOOK_SECRET=the same long random password as SECRET in the script
    SHEET_TAB=site             # optional

Without these two settings nothing is sent. PHP needs the curl extension.

## Deploying

Hosting is uploaded manually, there's no CI: zip the repo contents
(minus .git) and hand it to whoever has server access.

The server needs PHP-FPM 8.1+ with `pdo_sqlite`, `intl` and `mbstring`.
One-time setup:

1. Add the rules from `deploy/nginx-ponygames.conf` to the site's nginx
   config (page addresses, game pages, short links, sitemap, and the "never
   serve" rules: see Security below).
2. Copy `.env.example` to `.env` in the site root and fill it in (never
   commit `.env`). It lists every setting with an explanation: the site
   address, `HASH_SALT`, the owner password hash, the database location,
   email (SMTP), and the Google Sheet sync.

3. Make sure PHP can write to the folder of the database file, and that
   it can't be downloaded: outside the website folder, or in `storage/`
   with the nginx rule that blocks it (see Security below).
   Back that file up: it holds all accounts, likes, reviews, discussion messages, reports and submissions.
4. Moderate at https://pony.games/admin/. Posts reported by 3 different
   people are hidden automatically until a moderator looks at them.
   **Handle** on a report: choose no action, hide or delete, and write a
   message to the reporter and (when you hide or delete) to the writer.
   It shows up under their bell and in their daily email; an empty message
   gets a short standard text. All open reports about the same post are
   closed together. "No action" on a post hidden by reports shows it again.
   Accepting or rejecting a submission also tells the member (bell + daily
   email), with your reply, or a short standard text, and any stars or badge.

Privacy: visitors without an account get an anonymous cookie (to remember
their likes), and IP addresses are only stored as salted hashes for rate
limits. Bookmarks stay in the visitor's browser unless they log in.

### Security

Everything inside the website folder can be downloaded unless nginx blocks
it. Two things keep private data private:

- **The database can't be downloaded.** By default it is
  `../ponygames-data/community.sqlite`, next to the website folder (not in
  it), where nginx never serves it. The `uploads/` (submission pictures) and
  `backups/` folders are created next to the database. Set another place
  with `COMMUNITY_DB` in `.env`. Two good choices:
  - **Outside the website folder** (safest), e.g.
    `/www/wwwroot/ponygames-data/community.sqlite` while the site is in
    `/www/wwwroot/pony.games/`. On aaPanel, add that folder to the site's
    `open_basedir` (the `.user.ini` in the website folder), or PHP isn't
    allowed to open it.
  - **In `storage/` inside the website folder**:
    `COMMUNITY_DB=/www/wwwroot/pony.games/storage/community.sqlite`. Easier on
    aaPanel (no `open_basedir` change), but it is only safe together with the
    nginx rule `location ^~ /storage/ { deny all; }`: without it anyone can
    download the database, with everyone's email address and password hash.
  The uploads are never served straight from that folder (PHP reads them;
  accepted pictures are copied to `s/`), so blocking it breaks nothing.
- **The "never serve" rules** from `deploy/nginx-ponygames.conf` block `.env`,
  `.git`, `inc/`, `deploy/`, `cron/`, `router.php`, the README, and
  `storage/` / `ponygames-data/` for a database inside the website folder. On aaPanel they go in Website → URL rewrite. They use `=`
  and `^~` on purpose: aaPanel puts its own PHP rule first, so a
  `location ~` rule would never be reached for `.php` files.

Check after setting up a server: these must give **403** (or 404), never the file:

    https://pony.games/.env
    https://pony.games/inc/bootstrap.php
    https://pony.games/cron/daily-digest.php
    https://pony.games/storage/community.sqlite

The admin dashboard checks this too, every 6 hours: if `/.env`, `/inc/` or
the database (when it's inside the website folder) can be opened from
outside, it shows a red "Security" warning at the top (`security_problems()`
in `inc/admin_ui.php`; "Check again" runs it right away).

