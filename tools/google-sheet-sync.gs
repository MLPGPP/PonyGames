/**
 * Pony Games -> Google Sheet
 *
 * The site sends its whole game list here after every change, and this
 * script rewrites ONE tab with it (by default "site"). It never touches
 * any other tab: it only writes tabs listed in ALLOWED_TABS.
 *
 * Setup (once):
 *   1. In the sheet: Extensions -> Apps Script. Paste this whole file
 *      (replacing what's there) and save.
 *   2. Put a long random password in SECRET below. Put the same one in the
 *      site's .env as SHEET_WEBHOOK_SECRET.
 *   3. Deploy -> New deployment -> type "Web app".
 *      Execute as: Me. Who has access: Anyone. Click Deploy and allow access.
 *   4. Copy the web app URL (ends in /exec) into the site's .env as
 *      SHEET_WEBHOOK_URL.
 *   5. In /admin/ -> Games, press "Sync now" once.
 *
 * After changing this script: Deploy -> Manage deployments -> edit (pencil)
 * -> Version: New version -> Deploy. (The URL stays the same.)
 */

const SECRET = 'PUT-THE-SAME-LONG-RANDOM-PASSWORD-AS-IN-THE-SITE-ENV-HERE';
const ALLOWED_TABS = ['site'];

function doPost(e) {
  let body;
  try {
    body = JSON.parse(e.postData.contents);
  } catch (err) {
    return reply_({ ok: false, error: 'not JSON' });
  }
  if (!body || SECRET.indexOf('PUT-THE-SAME') === 0 || body.secret !== SECRET) {
    return reply_({ ok: false, error: 'wrong secret' });
  }
  const tabName = String(body.tab || 'site');
  if (ALLOWED_TABS.indexOf(tabName) === -1) {
    return reply_({ ok: false, error: 'the tab "' + tabName + '" is not in ALLOWED_TABS' });
  }
  const header = body.header || [];
  const rows = (body.rows || []).map(function (r) {
    // same length as the header, and text that looks like a formula stays text
    const out = [];
    for (let i = 0; i < header.length; i++) {
      let v = r[i] === undefined || r[i] === null ? '' : r[i];
      if (typeof v === 'string' && /^[=+\-@]/.test(v)) v = "'" + v;
      out.push(v);
    }
    return out;
  });

  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName(tabName) || ss.insertSheet(tabName);
    sheet.clear(); // contents and formatting; the formatting is put back below
    sheet.clearConditionalFormatRules();
    const all = [header].concat(rows);
    // make room, then write everything at once
    if (sheet.getMaxRows() < all.length) sheet.insertRowsAfter(sheet.getMaxRows(), all.length - sheet.getMaxRows());
    if (sheet.getMaxColumns() < header.length) sheet.insertColumnsAfter(sheet.getMaxColumns(), header.length - sheet.getMaxColumns());
    sheet.getRange(1, 1, all.length, header.length).setValues(all);
    format_(sheet, header, rows.length, body.links || {});
    sheet.getRange(1, 1).setNote('Filled by pony.games, last update ' + (body.updated || new Date().toISOString()) +
      '. Changes made in this tab are overwritten by the next update.');
  } finally {
    lock.releaseLock();
  }
  return reply_({ ok: true, rows: rows.length });
}

/* ---------- look: the same style as the "source" tab ---------- */

// column widths in pixels, by header name
const WIDTHS_ = {
  'id': 45, 'title': 240, 'page': 90, 'made by': 150, 'status': 115, 'release date': 95, 'added': 95,
  'engine': 95, 'platforms': 120, 'genres': 160, 'tags': 160, 'warnings': 130, 'characters': 140,
  'playtime': 80, 'short description': 260, 'description': 320, 'website': 200, 'downloads': 300,
  'cover picture': 110, 'pictures': 70, 'videos': 140,
};
// coloured "chips" like the dropdowns in "source": [text, background, text colour]
const STATUS_COLORS_ = [
  ['Released', '#e8eaed', '#202124'],
  ['In Development', '#fde7c2', '#7a4a00'],
  ['Upcoming', '#d4e9fd', '#0b4a8b'],
  ['Cancelled', '#3d3d3d', '#ffffff'],
];
const PLATFORM_COLORS_ = [
  ['Windows', '#bfe1f6', '#0a53a8'], ['Linux', '#bfe1f6', '#0a53a8'], ['OSX', '#bfe1f6', '#0a53a8'],
  ['Android', '#e6cff2', '#5a3286'], ['Web', '#e8eaed', '#202124'], ['Flash', '#ffe5a0', '#473821'],
  ['Source code', '#d4edbc', '#11734b'], ['VR', '#473822', '#ffe5a0'],
];

function format_(sheet, header, count, links) {
  const cols = header.length;
  const last = count + 1;
  const col = function (name) { return header.indexOf(name) + 1; };
  const body = sheet.getRange(2, 1, Math.max(count, 1), cols);

  sheet.setTabColor('#0f9d58');
  sheet.setFrozenRows(1);
  sheet.setFrozenColumns(col('title') || 2);
  // everything: Arial 10, one line per row, vertically centred
  sheet.getRange(1, 1, last, cols).setFontFamily('Arial').setFontSize(10).setVerticalAlignment('middle')
    .setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  // header: like row 1 of "source"
  sheet.getRange(1, 1, 1, cols).setBackground('#f3f3f3').setFontWeight('normal').setHorizontalAlignment('center')
    .setWrapStrategy(SpreadsheetApp.WrapStrategy.WRAP).setBorder(false, false, true, false, false, false, '#b7b7b7', SpreadsheetApp.BorderStyle.SOLID);
  sheet.setRowHeight(1, 40);
  if (count) sheet.setRowHeights(2, count, 21);
  header.forEach(function (name, i) { if (WIDTHS_[name]) sheet.setColumnWidth(i + 1, WIDTHS_[name]); });

  if (!count) return;
  // the id column: light orange, like in "source"
  if (col('id')) sheet.getRange(2, col('id'), count, 1).setBackground('#fce5cd').setHorizontalAlignment('left');
  // centred short columns
  ['status', 'release date', 'added', 'engine', 'platforms', 'playtime', 'pictures'].forEach(function (name) {
    if (col(name)) sheet.getRange(2, col(name), count, 1).setHorizontalAlignment('center');
  });
  // links: blue and clickable
  ['page', 'website', 'cover picture'].forEach(function (name) {
    const c = col(name);
    if (!c) return;
    const range = sheet.getRange(2, c, count, 1);
    const values = range.getValues();
    range.setRichTextValues(values.map(function (r) {
      const url = String(r[0] || '');
      const label = name === 'page' && url ? 'open' : url;
      const b = SpreadsheetApp.newRichTextValue().setText(label);
      return [/^https?:\/\//.test(url) ? b.setLinkUrl(url).build() : b.build()];
    }));
  });
  // columns with several links (downloads, videos): "Windows 1.0 · Linux 1.0" on one line, each part clickable
  Object.keys(links).forEach(function (name) {
    const c = col(name);
    const perRow = links[name];
    if (!c || !Array.isArray(perRow) || perRow.length !== count) return;
    sheet.getRange(2, c, count, 1).setRichTextValues(perRow.map(function (list) {
      const parts = (list || []).filter(function (l) { return l && l[0]; });
      const text = parts.map(function (l) { return String(l[0]); }).join(' · ');
      const b = SpreadsheetApp.newRichTextValue().setText(text);
      let at = 0;
      parts.forEach(function (l) {
        const label = String(l[0]);
        if (/^https?:\/\//.test(String(l[1] || ''))) b.setLinkUrl(at, at + label.length, String(l[1]));
        at += label.length + 3; // ' · '
      });
      return [b.build()];
    }));
  });
  // coloured status and platform cells
  const rules = [];
  const chip = function (range, list, exact) {
    list.forEach(function (c) {
      const r = SpreadsheetApp.newConditionalFormatRule().setBackground(c[1]).setFontColor(c[2]).setRanges([range]);
      rules.push((exact ? r.whenTextEqualTo(c[0]) : r.whenTextStartsWith(c[0])).build());
    });
  };
  if (col('status')) chip(sheet.getRange(2, col('status'), count, 1), STATUS_COLORS_, true);
  if (col('platforms')) chip(sheet.getRange(2, col('platforms'), count, 1), PLATFORM_COLORS_, false);
  // warnings in red text
  if (col('warnings')) {
    rules.push(SpreadsheetApp.newConditionalFormatRule().whenCellNotEmpty().setFontColor('#b80000')
      .setRanges([sheet.getRange(2, col('warnings'), count, 1)]).build());
  }
  sheet.setConditionalFormatRules(rules);
  // a light line between the rows, like the grid in "source"
  body.setBorder(null, null, null, null, null, true, '#e0e0e0', SpreadsheetApp.BorderStyle.SOLID);
  // filters on the header row (sorting/filtering in the tab itself)
  if (sheet.getFilter()) sheet.getFilter().remove();
  sheet.getRange(1, 1, last, cols).createFilter();
}

// opening the web app URL in a browser shows that it's running
function doGet() {
  return reply_({ ok: true, info: 'Pony Games sheet sync is running. The site sends updates with POST.' });
}

function reply_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
