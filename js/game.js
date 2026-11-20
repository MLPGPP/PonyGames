/* Individual game page: gallery, likes, bookmarks, reviews, discussion, reports. */
(function () {
  'use strict';

  var article = document.querySelector('.game[data-game-id]');
  if (!article) return;
  var gameId = article.dataset.gameId;
  var gameTitle = article.dataset.gameTitle || 'this game';
  var API = '/api/community.php';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function post(payload) {
    payload.game = gameId;
    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'pony-games' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.error || 'Something went wrong. Please try again.');
        return data;
      });
    });
  }

  /* ---------- gallery ---------- */
  var gallery = article.querySelector('.gallery');
  if (gallery) {
    var slides = Array.prototype.slice.call(gallery.querySelectorAll('.gallery__slide'));
    var thumbs = Array.prototype.slice.call(gallery.querySelectorAll('.gallery__thumb'));
    var current = 0;
    function show(i) {
      if (!slides.length) return;
      if (window.pgVideo) window.pgVideo.stop(slides[current]); // leaving a video stops it
      current = (i + slides.length) % slides.length;
      slides.forEach(function (s, n) { s.hidden = n !== current; });
      thumbs.forEach(function (t, n) {
        t.classList.toggle('is-active', n === current);
        if (n === current) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
      });
    }
    // videos (after the pictures) load from YouTube when their cover is pressed
    gallery.addEventListener('click', function (e) {
      var start = e.target.closest('[data-yt-start]');
      var slide = start && start.closest('.gallery__video');
      if (slide && window.pgVideo) window.pgVideo.start(slide, gameTitle);
    });
    var prev = gallery.querySelector('.gallery__nav--prev');
    var next = gallery.querySelector('.gallery__nav--next');
    if (prev) prev.addEventListener('click', function () { show(current - 1); });
    if (next) next.addEventListener('click', function () { show(current + 1); });
    thumbs.forEach(function (t) {
      t.addEventListener('click', function () { show(Number(t.dataset.index) || 0); });
    });
    gallery.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { show(current - 1); }
      else if (e.key === 'ArrowRight') { show(current + 1); }
    });
    // swipe on touch screens
    var startX = null;
    var main = gallery.querySelector('.gallery__main');
    if (main && slides.length > 1) {
      // no swiping on a video (its time bar is dragged sideways)
      main.addEventListener('touchstart', function (e) { startX = e.target.closest('video') ? null : e.touches[0].clientX; }, { passive: true });
      main.addEventListener('touchend', function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
        startX = null;
      });
    }
    var reveal = gallery.querySelector('.gallery__reveal');
    if (reveal) {
      reveal.addEventListener('click', function () {
        gallery.classList.add('is-revealed');
        reveal.remove();
      });
    }
  }

  /* ---------- likes and bookmarks are for members ---------- */
  var loginHint = article.querySelector('[data-login-hint]');
  function needsLogin(btn) {
    if (!btn || !btn.hasAttribute('data-login-required')) return false;
    if (loginHint) {
      loginHint.hidden = false;
      loginHint.classList.remove('is-flash'); void loginHint.offsetWidth; loginHint.classList.add('is-flash');
    } else {
      toast('Please log in first.');
    }
    return true;
  }

  /* ---------- Flash player in place of the screenshots ---------- */
  var player = article.querySelector('[data-player]');
  // phones and tablets: Flash games want a keyboard and mouse, so the game opens in its own tab there
  function canEmbed() {
    return !!player && !(window.matchMedia && matchMedia('(pointer: coarse)').matches && !matchMedia('(any-pointer: fine)').matches);
  }
  function sizePlayer() {
    var frame = player && player.querySelector('iframe');
    if (!frame) return;
    // the player page: 16px page padding, a box of at most 750px with 12px padding,
    // a 4:3 game and a 56px control bar under it (+ 2px to spare)
    var box = Math.min(750, frame.clientWidth - 32);
    frame.style.height = Math.round((box - 24) * 0.75 + 126) + 'px';
  }
  function openPlayer(url, title) {
    var holder = player.querySelector('[data-player-frame]');
    holder.innerHTML = '';
    var frame = document.createElement('iframe');
    frame.className = 'game-player__iframe';
    frame.src = url;
    frame.title = 'Play ' + (title || gameTitle);
    frame.setAttribute('allow', 'autoplay; fullscreen; gamepad');
    frame.setAttribute('allowfullscreen', '');
    holder.appendChild(frame);
    player.querySelector('[data-player-newtab]').href = url;
    player.hidden = false;
    gallery.querySelectorAll('.gallery__video').forEach(function (v) { if (window.pgVideo) window.pgVideo.stop(v); });
    gallery.classList.add('is-playing');
    sizePlayer();
    player.scrollIntoView({ behavior: 'smooth', block: 'start' });
    frame.focus();
  }
  function closePlayer() {
    player.querySelector('[data-player-frame]').innerHTML = ''; // stops the game and its sound
    player.hidden = true;
    gallery.classList.remove('is-playing');
    if (location.hash === '#play') history.replaceState(null, '', location.pathname + location.search);
  }
  if (player && gallery) {
    article.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-embed]');
      if (!a || !canEmbed() || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      e.preventDefault(); // the click still counts as a play (main.js)
      openPlayer(a.href);
    });
    player.querySelector('[data-player-close]').addEventListener('click', closePlayer);
    window.addEventListener('resize', sizePlayer);
    // "Play" in the games popup lands here with #play
    if (location.hash === '#play' && canEmbed()) {
      var first = article.querySelector('a[data-embed]');
      if (first) openPlayer(first.href);
    }
  }

  /* ---------- like ---------- */
  var likeBtn = article.querySelector('[data-like]');
  var likeCount = article.querySelector('[data-like-count]');
  function setLiked(liked, count) {
    if (!likeBtn) return;
    likeBtn.setAttribute('aria-pressed', liked ? 'true' : 'false');
    likeBtn.classList.toggle('is-on', !!liked);
    likeBtn.querySelector('.action-btn__label').textContent = liked ? 'Liked' : 'Like';
    if (likeCount && typeof count === 'number') likeCount.textContent = count;
  }
  if (likeBtn) {
    fetch(API + '?game=' + encodeURIComponent(gameId), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) setLiked(d.liked, d.likes); })
      .catch(function () {});
    likeBtn.addEventListener('click', function () {
      if (needsLogin(likeBtn)) return;
      if (likeBtn.disabled) return;
      likeBtn.disabled = true;
      var was = likeBtn.getAttribute('aria-pressed') === 'true';
      var n = Number(likeCount && likeCount.textContent) || 0;
      setLiked(!was, Math.max(0, n + (was ? -1 : 1))); // optimistic
      if (!was) { likeBtn.classList.remove('pop'); void likeBtn.offsetWidth; likeBtn.classList.add('pop'); }
      post({ action: 'like' })
        .then(function (d) { setLiked(d.liked, d.likes); })
        .catch(function (err) { setLiked(was, n); toast(err.message); })
        .then(function () { likeBtn.disabled = false; });
    });
  }

  /* ---------- bookmark (saved to the account, cached in this browser) ---------- */
  var BM_KEY = 'pg-bookmarks';
  function readBookmarks() {
    try { var v = JSON.parse(localStorage.getItem(BM_KEY) || '[]'); return Array.isArray(v) ? v.map(String) : []; }
    catch (e) { return []; }
  }
  function writeBookmarks(list) {
    try { localStorage.setItem(BM_KEY, JSON.stringify(list)); return true; } catch (e) { return false; }
  }
  var bmBtn = article.querySelector('[data-bookmark]');
  function setBookmarked(on) {
    bmBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    bmBtn.classList.toggle('is-on', on);
    bmBtn.querySelector('.action-btn__label').textContent = on ? 'Bookmarked' : 'Bookmark';
  }
  if (bmBtn && bmBtn.hasAttribute('data-login-required')) {
    bmBtn.addEventListener('click', function () { needsLogin(bmBtn); });
  } else if (bmBtn) {
    setBookmarked(readBookmarks().indexOf(gameId) !== -1);
    // logged in: the account's bookmarks arrive a moment later (main.js)
    document.addEventListener('pg:bookmarks', function () { setBookmarked(readBookmarks().indexOf(gameId) !== -1); });
    bmBtn.addEventListener('click', function () {
      var list = readBookmarks();
      var i = list.indexOf(gameId);
      var on = i === -1;
      if (on) list.unshift(gameId); else list.splice(i, 1);
      writeBookmarks(list);
      setBookmarked(on);
      if (!window.pgAccountApi) return;
      window.pgAccountApi({ action: 'bookmark', game: gameId, on: on }).then(function () {
        var c = article.querySelector('[data-bookmark-count]');
        if (c) c.textContent = Math.max(0, (Number(c.textContent) || 0) + (on ? 1 : -1));
        toast(on ? 'Saved to your bookmarks.' : 'Removed from your bookmarks.');
      }).catch(function (err) {
        // undo, the account didn't take it
        var back = readBookmarks();
        var j = back.indexOf(gameId);
        if (on && j !== -1) back.splice(j, 1);
        if (!on && j === -1) back.unshift(gameId);
        writeBookmarks(back);
        setBookmarked(!on);
        toast(err.message || 'Could not save that bookmark.');
      });
    });
  }

  /* ---------- small toast ---------- */
  var toastEl;
  var toastTimer;
  function toast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 3200);
  }

  /* ---------- reviews & discussion (members only) ---------- */
  function starsHtml(rating) {
    var out = '';
    for (var i = 1; i <= 5; i++) {
      var fill = Math.max(0, Math.min(1, rating - (i - 1)));
      out += '<span class="stars__star" style="--fill:' + Math.round(fill * 100) + '%"></span>';
    }
    return '<span class="stars" aria-hidden="true">' + out + '</span>';
  }
  var canReply = !!article.querySelector('.post-form[data-kind="comment"]');
  var REPLY_ICON = '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>';
  // tag: "div" for the first message of a thread, "li" for replies and reviews
  function postHtml(p, tag) {
    tag = tag || 'li';
    var isReply = !!p.parent_id;
    var d = new Date(p.created_at * 1000);
    var when = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    var rating = p.kind === 'review' && p.rating
      ? starsHtml(p.rating) + '<span class="visually-hidden">' + p.rating + ' out of 5 stars</span>' : '';
    var initial = (String(p.name || '').trim().charAt(0) || '?').toUpperCase();
    var member = p.stars !== null && p.stars !== undefined;
    var avatar = !member ? '' : p.avatar ? '<img class="post__avatar" src="' + esc(p.avatar) + '" alt="" width="30" height="30">'
      : p.avatar_color ? '<span class="post__avatar pony-avatar" style="--avatar-bg: ' + esc(p.avatar_color) + '" aria-hidden="true"></span>' : '';
    var chip = member ? '<span class="post__member" title="Pony Games member">★ ' + Number(p.stars) + '</span>' : '';
    var badges = member && p.badges && p.badges.length
      ? '<span class="post__badges" aria-label="Badges">' + p.badges.map(function (b) {
          return '<span class="badge-chip" title="' + esc(b.name + ': ' + b.desc) + '" role="img" aria-label="' + esc(b.name) + '">' + esc(b.icon) + '</span>';
        }).join('') + '</span>' : '';
    var edited = p.edited_at ? ' <span class="post__edited">· edited</span>' : '';
    var tool = p.mine
      ? '<span class="post__tools"><button type="button" class="post__edit" data-post-edit>Edit</button>'
        + '<button type="button" class="post__edit post__edit--danger" data-post-delete>Delete</button></span>'
      : '<button type="button" class="post__report" data-report-post="' + p.id + '" aria-label="Report this post" title="Report">'
        + '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4m0 0h13l-2 4 2 4H4"/></svg></button>';
    var foot = p.kind === 'comment' && canReply
      ? '<div class="post__foot"><button type="button" class="post__reply" data-post-reply>' + REPLY_ICON + 'Reply</button></div>' : '';
    return '<' + tag + ' id="post-' + p.id + '" class="post post--new' + (isReply ? ' post--reply' : '') + (member ? ' post--member' : '') + (avatar ? ' post--avatar' : '') + (p.mine ? ' post--mine' : '') + '" data-post="' + p.id + '" data-kind="' + esc(p.kind) + '"'
      + (isReply ? ' data-parent="' + Number(p.parent_id) + '"' : '')
      + (p.mine ? ' data-body="' + esc(p.body) + '"' + (p.rating ? ' data-rating="' + Number(p.rating) + '"' : '') : '')
      + ' data-initial="' + esc(initial) + '">' + avatar
      + '<div class="post__head"><strong class="post__name">' + esc(p.name) + '</strong>' + (p.mine ? '<span class="post__you">you</span>' : '') + chip + badges + rating
      + '<time class="post__date" datetime="' + d.toISOString() + '">' + when + '</time>' + edited + tool + '</div>'
      + '<p class="post__body">' + esc(p.body).replace(/\n/g, '<br>') + '</p>' + foot + '</' + tag + '>';
  }
  // discussion threads, Reddit-style: every reply is a node under the message it answers
  function threadHtml(p) {
    return '<li class="thread" data-thread="' + p.id + '">' + postHtml(p, 'div') + '<ul class="post-replies" data-replies data-depth="1"></ul></li>';
  }
  function nodeHtml(p, depth) {
    return '<li class="reply-node" data-node="' + p.id + '">' + postHtml(p, 'div')
      + '<ul class="post-replies" data-replies data-depth="' + depth + '"></ul></li>';
  }
  var GONE_HTML = '<p class="post-gone">This message was removed.</p>';
  function repliesOf(node) { return node && node.querySelector(':scope > .post-replies'); }
  // "Hide 3 replies" / "Show 3 replies" under a message that has replies
  function syncToggle(node) {
    var ul = repliesOf(node);
    var postEl = node && node.querySelector(':scope > .post');
    if (!ul || !postEl) return;
    var n = ul.querySelectorAll('[data-post]').length;
    var foot = postEl.querySelector(':scope > .post__foot');
    var btn = foot && foot.querySelector('[data-toggle-replies]');
    if (!n) { if (btn) btn.remove(); ul.classList.remove('is-collapsed'); return; }
    if (!foot) { foot = document.createElement('div'); foot.className = 'post__foot'; postEl.appendChild(foot); }
    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'post__toggle';
      btn.setAttribute('data-toggle-replies', '');
      foot.appendChild(btn);
    }
    var shut = ul.classList.contains('is-collapsed');
    var label = n + (n === 1 ? ' reply' : ' replies');
    btn.textContent = (shut ? 'Show ' : 'Hide ') + label;
    btn.setAttribute('aria-expanded', String(!shut));
    ul.setAttribute('data-hidden-label', label + ' hidden. Click to show.');
  }
  function syncUp(el) {
    // every message above el gets its count updated
    for (var node = el && el.closest('.reply-node, .thread'); node; node = node.parentElement && node.parentElement.closest('.reply-node, .thread')) syncToggle(node);
  }
  function toggleReplies(ul, open) {
    ul.classList.toggle('is-collapsed', open === undefined ? !ul.classList.contains('is-collapsed') : !open);
    syncToggle(ul.parentElement);
  }
  Array.prototype.forEach.call(article.querySelectorAll('.thread, .reply-node'), syncToggle);
  // the line next to the replies, or the "Hide/Show N replies" button, folds a branch
  article.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle-replies]');
    if (t) { var node = t.closest('.reply-node, .thread'); toggleReplies(repliesOf(node)); return; }
    if (e.target.classList && e.target.classList.contains('post-replies')) toggleReplies(e.target);
  });
  // opened from a notification: unfold the branch the message is in
  if (/^#post-\d+$/.test(location.hash)) {
    var hit = document.getElementById(location.hash.slice(1));
    for (var up = hit && hit.closest('.post-replies'); up; up = up.parentElement.closest('.post-replies')) toggleReplies(up, true);
  }

  // your own posts: "Edit" (reviews open the review form, messages are edited in place) and "Delete"
  var compose = document.getElementById('review-compose');
  function setCount(sel, delta) {
    var c = article.querySelector(sel);
    if (c) { var n = Math.max(0, (Number(c.textContent) || 0) + delta); c.textContent = n || ''; }
  }
  function showEmpty(kind) {
    var list = article.querySelector('[data-list="' + kind + '"]');
    var empty = article.querySelector('[data-empty="' + kind + '"]');
    if (empty && list && !list.querySelector('[data-post]:not(.is-removing)')) empty.hidden = false;
  }
  if (window.PGPostTools) {
    window.PGPostTools(article, {
      bodySelector: '.post__body',
      onEditReview: function () {
        if (!compose) return false;
        compose.open = true;
        compose.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var ta = compose.querySelector('textarea');
        if (ta) setTimeout(function () { ta.focus(); }, 300);
        return true;
      },
      onSaved: function (el, p) {
        // keep what the edit doesn't send back: the tag and the thread
        if (el.dataset.parent) p.parent_id = el.dataset.parent;
        var node = el.parentElement;
        el.outerHTML = postHtml(p, el.tagName.toLowerCase());
        syncToggle(node);
      },
      onDeleted: function (el, d) {
        if (d.kind === 'review') {
          // the review form writes a new review again
          var form = compose && compose.querySelector('form');
          if (form) {
            form.reset();
            Array.prototype.forEach.call(form.querySelectorAll('[name=rating]'), function (r) { r.checked = false; });
            form.querySelector('textarea').textContent = '';
            form.removeAttribute('data-editing');
            form.querySelector('[type=submit]').textContent = 'Post review';
            var label = compose.querySelector('[data-compose-label]');
            if (label) label.textContent = 'Write a review';
          }
          setTimeout(function () { showEmpty('review'); }, 260);
          toast('Your review is deleted.');
        } else {
          setCount('[data-comment-count]', -1);
          var node = el.closest('.reply-node, .thread');
          var above = node && node.parentElement;
          setTimeout(function () {
            if (node && node.classList.contains('reply-node')) {
              // a reply: the replies under it move up a level, into its place (the server does the same)
              var kids = repliesOf(node);
              Array.prototype.slice.call(kids ? kids.children : []).forEach(function (li) {
                Array.prototype.forEach.call(li.querySelectorAll('.post-replies'), function (u) {
                  u.setAttribute('data-depth', Math.max(1, (Number(u.getAttribute('data-depth')) || 2) - 1));
                });
                node.before(li);
              });
              node.remove();
            } else if (node && !node.querySelector('[data-post]')) node.remove(); // a thread with no replies goes
            else if (node && !node.querySelector(':scope > .post, :scope > .post-gone')) node.insertAdjacentHTML('afterbegin', GONE_HTML);
            if (above && document.body.contains(above)) syncUp(above);
            showEmpty('comment');
          }, 300);
          toast('Your message is deleted.');
        }
      }
    });
  }

  Array.prototype.forEach.call(article.querySelectorAll('.post-form[data-kind]'), function (form) {
    var kind = form.dataset.kind;
    var msg = form.querySelector('.post-form__msg');
    var btn = form.querySelector('[type=submit]');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var payload = {
        action: kind,
        body: (fd.get('body') || '').toString().trim(),
        website: (fd.get('website') || '').toString()
      };
      if (kind === 'review') {
        payload.rating = Number(fd.get('rating')) || 0;
        if (!payload.rating) { msg.textContent = 'Please pick a rating from 1 to 5 stars.'; msg.className = 'post-form__msg is-error'; return; }
      }
      btn.disabled = true;
      msg.className = 'post-form__msg';
      msg.textContent = form.hasAttribute('data-editing') ? 'Saving…' : 'Posting…';
      post(payload).then(function (d) {
        if (d.edited && d.post) {
          // your review was updated: show the new version where the old one was
          var old = article.querySelector('[data-list="review"] [data-post="' + d.post.id + '"]');
          if (old) old.outerHTML = postHtml(d.post);
          else article.querySelector('[data-list="review"]').insertAdjacentHTML('afterbegin', postHtml(d.post));
          msg.className = 'post-form__msg is-ok';
          msg.textContent = 'Your review is updated.';
          if (compose) setTimeout(function () { compose.open = false; msg.textContent = ''; }, 1600);
          return;
        }
        if (d.post) {
          var list = article.querySelector('[data-list="' + kind + '"]');
          list.insertAdjacentHTML('afterbegin', kind === 'comment' ? threadHtml(d.post) : postHtml(d.post));
          var empty = article.querySelector('[data-empty="' + kind + '"]');
          if (empty) empty.hidden = true;
          if (kind === 'comment') {
            var c = article.querySelector('[data-comment-count]');
            if (c) c.textContent = (Number(c.textContent) || 0) + 1;
          }
        }
        if (kind === 'review') {
          // from now on this form edits that review
          form.setAttribute('data-editing', '');
          btn.textContent = 'Save changes';
          var label = compose && compose.querySelector('[data-compose-label]');
          if (label) label.textContent = 'Edit your review';
          msg.className = 'post-form__msg is-ok';
          msg.textContent = 'Thanks for your review! 🐴💖';
          if (compose) setTimeout(function () { compose.open = false; msg.textContent = ''; }, 1800);
        } else {
          form.reset();
          msg.className = 'post-form__msg is-ok';
          msg.textContent = 'Posted!';
        }
      }).catch(function (err) {
        msg.className = 'post-form__msg is-error';
        msg.textContent = err.message;
      }).then(function () { if (btn) btn.disabled = false; });
    });
  });

  // tapping a badge says what it is (phones have no hover tooltip)
  article.addEventListener('click', function (e) {
    var chip = e.target.closest('.badge-chip');
    if (chip && chip.title) toast(chip.title);
  });

  /* ---------- replies in the discussion ---------- */
  var replyForm = null;
  function closeReply() {
    if (!replyForm) return;
    var opener = replyForm._opener;
    replyForm.remove();
    replyForm = null;
    if (opener && document.body.contains(opener)) opener.focus();
  }
  article.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-post-reply]');
    if (!btn) return;
    var target = btn.closest('[data-post]');
    var node = target && target.parentElement;
    if (!target || !node || !btn.closest('.thread')) return;
    if (replyForm && replyForm._target === target) { replyForm.querySelector('textarea').focus(); return; }
    closeReply();
    var name = (target.querySelector('.post__name') || {}).textContent || 'this message';
    var form = document.createElement('form');
    form.className = 'reply-form';
    form.innerHTML = '<p class="reply-form__to">' + REPLY_ICON + ' Replying to <strong>' + esc(name) + '</strong></p>'
      + '<label><span class="visually-hidden">Your reply</span><textarea name="body" rows="2" maxlength="1500" minlength="2" required></textarea></label>'
      + '<div class="reply-form__foot"><button type="submit" class="btn btn--accent btn--small">Reply</button>'
      + '<button type="button" class="btn btn--ghost btn--small" data-reply-cancel>Cancel</button>'
      + '<p class="post-form__msg" role="status"></p></div>';
    form._target = target;
    form._opener = btn;
    target.after(form); // right under the message you answer
    replyForm = form;
    var ta = form.querySelector('textarea');
    ta.focus();
    form.querySelector('[data-reply-cancel]').addEventListener('click', closeReply);
    form.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeReply(); });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var msg = form.querySelector('.post-form__msg');
      var send = form.querySelector('[type=submit]');
      send.disabled = true;
      msg.className = 'post-form__msg';
      msg.textContent = 'Posting…';
      post({ action: 'comment', body: ta.value.trim(), reply_to: Number(target.dataset.post), website: '' }).then(function (d) {
        if (!d.post) return;
        var ul = repliesOf(node);
        if (!ul) {
          ul = document.createElement('ul');
          ul.className = 'post-replies';
          ul.setAttribute('data-replies', '');
          node.appendChild(ul);
        }
        var depth = (Number(ul.getAttribute('data-depth')) || 1) + 1;
        ul.insertAdjacentHTML('beforeend', nodeHtml(d.post, depth));
        toggleReplies(ul, true);
        syncUp(ul);
        setCount('[data-comment-count]', 1);
        form._opener = null;
        closeReply();
        toast('Your reply is posted.');
      }).catch(function (err) {
        msg.className = 'post-form__msg is-error';
        msg.textContent = err.message;
        send.disabled = false;
      });
    });
  });

  /* ---------- report dialog ---------- */
  var dialog = document.getElementById('report-dialog');
  if (dialog) {
    var rForm = dialog.querySelector('form');
    var about = dialog.querySelector('[data-report-about]');
    var rMsg = rForm.querySelector('.post-form__msg');
    var rBtn = rForm.querySelector('[type=submit]');
    var reportPost = null;
    var opener = null;

    function openReport(postId, trigger) {
      reportPost = postId;
      opener = trigger || null;
      rForm.reset();
      rMsg.textContent = '';
      rMsg.className = 'post-form__msg';
      rBtn.disabled = false;
      rBtn.hidden = false;
      var sel = rForm.querySelector('[name=reason]');
      if (postId) {
        var p = article.querySelector('[data-post="' + postId + '"] .post__name');
        about.textContent = 'You are reporting a post by ' + (p ? p.textContent : 'a visitor') + ' on ' + gameTitle + '.';
        sel.value = 'Spam or abuse';
      } else {
        about.textContent = 'Something wrong with the page for ' + gameTitle + '? Let the moderators know.';
      }
      if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    }
    function closeReport() {
      if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }
    dialog.addEventListener('close', function () { if (opener) opener.focus(); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) closeReport(); });
    var closeBtn = dialog.querySelector('[data-close]');
    if (closeBtn) closeBtn.addEventListener('click', closeReport);

    var gameBtn = article.querySelector('[data-report-game]');
    if (gameBtn) gameBtn.addEventListener('click', function () { openReport(null, gameBtn); });
    article.addEventListener('click', function (e) {
      var b = e.target.closest('[data-report-post]');
      if (b) openReport(b.dataset.reportPost, b);
    });

    // wrong info or a dead link: point to the correction form
    var fixHint = rForm.querySelector('[data-fix-hint]');
    var reasonSel = rForm.querySelector('[name=reason]');
    function syncFixHint() {
      if (fixHint) fixHint.hidden = reportPost !== null || !/Wrong information|Broken or dead link|Missing NSFW/.test(reasonSel.value);
    }
    reasonSel.addEventListener('change', syncFixHint);
    dialog.addEventListener('close', function () { if (fixHint) fixHint.hidden = true; });

    rForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(rForm);
      var payload = {
        action: 'report',
        reason: (fd.get('reason') || '').toString(),
        details: (fd.get('details') || '').toString().trim(),
        website: (fd.get('website') || '').toString()
      };
      if (reportPost) payload.post = reportPost;
      rBtn.disabled = true;
      rMsg.className = 'post-form__msg';
      rMsg.textContent = 'Sending…';
      post(payload).then(function () {
        rMsg.className = 'post-form__msg is-ok';
        rMsg.textContent = 'Thank you! A moderator will take a look.';
        rBtn.hidden = true;
        setTimeout(closeReport, 1800);
      }).catch(function (err) {
        rMsg.className = 'post-form__msg is-error';
        rMsg.textContent = err.message;
        rBtn.disabled = false;
      });
    });
  }
})();
