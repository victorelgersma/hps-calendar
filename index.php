<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function safe_url(?string $url): ?string
{
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}

// ---- Load events ----------------------------------------------------------
// Every event is its own file in events/, e.g. events/2026-09-25-one-health.json.
// A broken file is skipped (and listed at the bottom of the page) instead of
// taking the whole calendar down.
$error = null;
$events = [];
$problems = [];
$files = glob(__DIR__ . '/events/*.json') ?: [];

if (!is_dir(__DIR__ . '/events')) {
    $error = 'The events/ folder is missing.';
}

foreach ($files as $file) {
    $name = basename($file);
    $ev = json_decode((string) @file_get_contents($file), true);

    if (!is_array($ev)) {
        $problems[] = "$name: not valid JSON (" . json_last_error_msg() . ')';
        continue;
    }
    if (empty($ev['date']) || empty($ev['title'])) {
        $problems[] = "$name: needs at least a \"date\" and a \"title\"";
        continue;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ev['date']);
    if (!$date || $date->format('Y-m-d') !== $ev['date']) {
        $problems[] = "$name: \"date\" must look like 2026-09-25";
        continue;
    }
    // Optional last day for multi-day events (conferences, workshops)
    $end = !empty($ev['date-end'])
        ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ev['date-end'])
        : false;
    if (!$end || $end < $date) {
        $end = $date;
    }
    $ev['_date'] = $date;
    $ev['_end']  = $end;
    $ev['_sort'] = $date->format('Y-m-d') . ' ' . ($ev['time-start'] ?? '00:00') . ' ' . $name;
    $events[] = $ev;
}

usort($events, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));

// ---- Tags & filtering -----------------------------------------------------
// Events may carry "tags": ["Philosophy of Physics", ...]; ?tag=... filters.
function event_tags(array $ev): array
{
    return array_values(array_filter(array_map(
        fn($t) => trim((string) $t),
        is_array($ev['tags'] ?? null) ? $ev['tags'] : []
    ), fn($t) => $t !== ''));
}

function tag_url(?string $tag): string
{
    return $tag === null ? '?' : '?tag=' . urlencode($tag);
}

$allTags = [];
foreach ($events as $ev) {
    foreach (event_tags($ev) as $t) {
        $allTags[mb_strtolower($t)] ??= $t;   // first spelling wins
    }
}
natcasesort($allTags);

$requested = isset($_GET['tag']) && is_string($_GET['tag']) ? mb_strtolower(trim($_GET['tag'])) : '';
$activeTag = $allTags[$requested] ?? null;

if ($activeTag !== null) {
    $events = array_values(array_filter($events, fn($ev) => in_array(
        mb_strtolower($activeTag),
        array_map('mb_strtolower', event_tags($ev)),
        true
    )));
}

$today = new DateTimeImmutable('today');
// An event stays "upcoming" until its last day has passed
$upcoming = array_values(array_filter($events, fn($ev) => $ev['_end'] >= $today));
$past     = array_reverse(array_values(array_filter($events, fn($ev) => $ev['_end'] < $today)));

// Group upcoming events by month
$byMonth = [];
foreach ($upcoming as $ev) {
    $byMonth[$ev['_date']->format('F Y')][] = $ev;
}

function badge(DateTimeImmutable $date, DateTimeImmutable $end, DateTimeImmutable $today): ?string
{
    $days = (int) $today->diff($date)->format('%r%a');
    return match (true) {
        $days < 0 && $end >= $today => 'Ongoing',
        $days === 0 => 'Today',
        $days === 1 => 'Tomorrow',
        $days > 1 && $days < 7 => 'This week',
        default => null,
    };
}

/**
 * Human-readable date line, e.g.
 *   "Friday 25 September 2026"
 *   "Wednesday 25 – Friday 27 August 2027"
 *   "Wednesday 30 September – Friday 2 October 2026"
 *   "Wednesday 30 December 2026 – Friday 1 January 2027"
 */
function date_range_text(DateTimeImmutable $d, DateTimeImmutable $end): string
{
    if ($d == $end) {
        return $d->format('l j F Y');
    }
    if ($d->format('Y') !== $end->format('Y')) {
        return $d->format('l j F Y') . ' – ' . $end->format('l j F Y');
    }
    if ($d->format('m') !== $end->format('m')) {
        return $d->format('l j F') . ' – ' . $end->format('l j F Y');
    }
    return $d->format('l j') . ' – ' . $end->format('l j F Y');
}

function render_event(array $ev, DateTimeImmutable $today, bool $isPast = false): void
{
    $d = $ev['_date'];
    $end = $ev['_end'];
    $multi = $end != $d;
    $sameMonth = $d->format('Y-m') === $end->format('Y-m');
    $time = trim(($ev['time-start'] ?? '') . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : ''));
    $badge = $isPast ? null : badge($d, $end, $today);
    // Everything a visitor might search for, lower-cased, for the search bar
    $haystack = mb_strtolower(implode(' ', array_filter([
        $ev['title'] ?? '', $ev['speaker'] ?? '', $ev['location'] ?? '',
        $ev['reading'] ?? '', $ev['description'] ?? '',
        implode(' ', event_tags($ev)), date_range_text($d, $end),
    ], 'is_string')));
    ?>
    <article class="event<?= $isPast ? ' past' : '' ?>" data-search="<?= e($haystack) ?>">
        <div class="date<?= $multi ? ' range' : '' ?>">
            <?php if ($multi): ?>
                <span class="dow"><?= e($d->format('D') . '–' . $end->format('D')) ?></span>
                <span class="day"><?= e($d->format('j') . '–' . $end->format('j')) ?></span>
                <span class="mon"><?= e($sameMonth ? $d->format('M') : $d->format('M') . '–' . $end->format('M')) ?></span>
            <?php else: ?>
                <span class="dow"><?= e($d->format('D')) ?></span>
                <span class="day"><?= e($d->format('j')) ?></span>
                <span class="mon"><?= e($d->format('M')) ?></span>
            <?php endif; ?>
        </div>
        <div class="body">
            <?php $tags = event_tags($ev); if ($badge || $tags): ?>
                <div class="labels">
                    <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
                    <?php foreach ($tags as $t): ?>
                        <a class="tag" href="<?= e(tag_url($t)) ?>"><?= e($t) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <h3><?= e($ev['title']) ?></h3>
            <p class="meta">
                <time datetime="<?= e($d->format('Y-m-d') . (!empty($ev['time-start']) ? 'T' . $ev['time-start'] : '')) ?>">
                    <?= e(date_range_text($d, $end)) ?><?= $time !== '' ? ' · ' . e($time) : '' ?>
                </time>
                <?php if (!empty($ev['location'])): ?> · <?= e($ev['location']) ?><?php endif; ?>
            </p>
            <?php if (!empty($ev['speaker'])): ?><p class="speaker"><?= e($ev['speaker']) ?></p><?php endif; ?>
            <?php if (!empty($ev['reading'])): ?><p class="reading"><span>Reading:</span> <?= e($ev['reading']) ?></p><?php endif; ?>
            <?php if (!empty($ev['description'])): ?><p class="desc"><?= nl2br(e($ev['description'])) ?></p><?php endif; ?>
            <?php
            $links = [];
            foreach ((array) ($ev['attachments'] ?? []) as $att) {
                $url = safe_url($att['URL'] ?? $att['url'] ?? null);
                if ($url) {
                    $links[] = ['title' => $att['title'] ?? 'Link', 'url' => $url];
                }
            }
            if ($links): ?>
                <ul class="attachments">
                    <?php foreach ($links as $l): ?>
                        <li><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e($l['title']) ?> ↗</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HPS Talks Utrecht</title>
    <?php /* ?v= changes whenever style.css changes, so browsers never use a stale copy */ ?>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body>
<main>
    <header>
        <div class="title-row">
            <h1>HPS Talks Utrecht</h1>
            <div class="search" hidden>
                <button type="button" class="search-toggle" aria-label="Search" aria-expanded="false" aria-controls="q">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <input id="q" type="search" aria-label="Search" autocomplete="off" tabindex="-1">
            </div>
        </div>
        <p>Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
        <p><small>Unofficial overview compiled by a student. Always check the details with the organisers.</small></p>
        <p class="buttons">
            <a class="button" href="correction.php">Submit a correction</a>
            <a class="button" href="contributing.php">Submit your talk</a>
        </p>
        <?php if ($allTags): ?>
            <nav class="filters" aria-label="Filter by topic">
                <span class="label">Show:</span>
                <a class="tag<?= $activeTag === null ? ' active' : '' ?>" href="<?= e(tag_url(null)) ?>"<?= $activeTag === null ? ' aria-current="page"' : '' ?>>All</a>
                <?php foreach ($allTags as $t): ?>
                    <a class="tag<?= $activeTag === $t ? ' active' : '' ?>" href="<?= e(tag_url($t)) ?>"<?= $activeTag === $t ? ' aria-current="page"' : '' ?>><?= e($t) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <p class="empty" id="no-results" hidden>No events match your search.</p>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php elseif (!$upcoming): ?>
        <p class="empty"><?= $activeTag !== null ? 'No upcoming ' . e($activeTag) . ' events at the moment.' : 'No upcoming events at the moment.' ?></p>
    <?php else: ?>
        <?php foreach ($byMonth as $month => $list): ?>
            <section>
                <h2><?= e($month) ?></h2>
                <?php foreach ($list as $ev) render_event($ev, $today); ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($past): ?>
        <details id="past">
            <summary>Past events (<?= count($past) ?>)</summary>
            <?php foreach ($past as $ev) render_event($ev, $today, true); ?>
        </details>
    <?php endif; ?>

    <?php if ($problems): ?>
        <details class="problems">
            <summary><?= count($problems) ?> event file<?= count($problems) === 1 ? '' : 's' ?> could not be shown</summary>
            <ul>
                <?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <footer>
        Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>
        · <a href="https://github.com/victorelgersma/hps-calendar" target="_blank" rel="noopener">Source on GitHub</a>
    </footer>
</main>
<script>
// Instant search: hides cards (and empty month headings) that don't match.
// Every word must appear somewhere in the event; ?q=... is kept in the URL.
(function () {
    var box = document.querySelector('.search');
    var input = document.getElementById('q');
    var toggle = box && box.querySelector('.search-toggle');
    var cards = Array.prototype.slice.call(document.querySelectorAll('article.event'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('main > section'));
    var past = document.getElementById('past');
    var none = document.getElementById('no-results');
    if (!box || !cards.length) return;
    box.hidden = false;

    var params = new URLSearchParams(location.search);
    input.value = params.get('q') || '';

    function norm(s) {
        return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    cards.forEach(function (c) { c._text = norm(c.getAttribute('data-search') || ''); });

    function apply() {
        var words = norm(input.value).split(/\s+/).filter(Boolean);
        var shown = 0;
        cards.forEach(function (c) {
            var hit = words.every(function (w) { return c._text.indexOf(w) !== -1; });
            c.hidden = !hit;
            if (hit) shown++;
        });
        sections.forEach(function (s) {
            s.hidden = !s.querySelector('article.event:not([hidden])');
        });
        if (past) {
            var pastHits = past.querySelectorAll('article.event:not([hidden])').length;
            past.hidden = pastHits === 0;
            if (words.length && pastHits) past.open = true;
        }
        none.hidden = !(words.length && shown === 0);

        if (input.value) params.set('q', input.value); else params.delete('q');
        var qs = params.toString();
        history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }
    function setOpen(open) {
        box.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        input.tabIndex = open ? 0 : -1;
        if (open) input.focus();
    }
    toggle.addEventListener('click', function () {
        if (!box.classList.contains('open')) setOpen(true);
        else if (!input.value) setOpen(false);
        else input.focus();
    });
    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { input.value = ''; apply(); setOpen(false); toggle.focus(); }
    });
    input.addEventListener('blur', function () {
        if (!input.value) setTimeout(function () {
            if (document.activeElement !== toggle) setOpen(false);
        }, 150);
    });
    input.addEventListener('input', apply);
    if (input.value) { box.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); input.tabIndex = 0; apply(); }
})();
</script>
</body>
</html>