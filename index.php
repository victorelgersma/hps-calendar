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
    ?>
    <article class="event<?= $isPast ? ' past' : '' ?>">
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
    <style>
        :root {
            --bg: #faf8f4; --card: #ffffff; --text: #1f1d1a; --muted: #6b665e;
            --line: #e6e1d8; --accent: #b3261e; --badge-bg: #fbe9e7;
            --tag-bg: #e8eef7; --tag-text: #25467a;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #161514; --card: #1f1e1c; --text: #ece8e1; --muted: #a39d93;
                --line: #2e2c29; --accent: #ef8a80; --badge-bg: #3a2320;
                --tag-bg: #1f2a3b; --tag-text: #9dbbea;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 16px/1.5 Georgia, "Iowan Old Style", "Times New Roman", serif;
        }
        main { max-width: 720px; margin: 0 auto; padding: 48px 16px 64px; }
        header h1 { margin: 0; font-size: 2rem; letter-spacing: -0.01em; }
        header p { margin: 4px 0 0; color: var(--muted); }
        h2 {
            font: 600 0.8rem/1 system-ui, sans-serif; text-transform: uppercase;
            letter-spacing: 0.08em; color: var(--muted);
            margin: 40px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--line);
        }
        .event {
            display: flex; gap: 18px; background: var(--card);
            border: 1px solid var(--line); border-radius: 10px;
            padding: 16px; margin-bottom: 12px;
        }
        .event.past { opacity: 0.6; }
        .date {
            flex: 0 0 56px; text-align: center; font-family: system-ui, sans-serif;
            display: flex; flex-direction: column; line-height: 1.1;
        }
        .date .dow, .date .mon { font-size: 0.72rem; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; }
        .date .day { font-size: 1.9rem; font-weight: 700; color: var(--accent); margin: 2px 0; }
        .date.range { flex-basis: 64px; }
        .date.range .day { font-size: 1.3rem; white-space: nowrap; }
        .date.range .dow, .date.range .mon { white-space: nowrap; }
        .body { min-width: 0; }
        .body h3 { margin: 0 0 4px; font-size: 1.1rem; line-height: 1.35; }
        .meta, .speaker { margin: 0; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.88rem; }
        .desc { margin: 8px 0 0; }
        .labels { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 6px; }
        .badge {
            display: inline-block; font: 600 0.7rem/1 system-ui, sans-serif;
            text-transform: uppercase; letter-spacing: 0.06em;
            background: var(--badge-bg); color: var(--accent);
            padding: 4px 7px; border-radius: 4px;
        }
        .tag {
            display: inline-block; font: 500 0.75rem/1 system-ui, sans-serif;
            color: var(--tag-text); background: var(--tag-bg);
            padding: 5px 10px; border-radius: 999px; text-decoration: none;
            border: 1px solid transparent;
        }
        .tag:hover { border-color: var(--tag-text); }
        .filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 20px; }
        .filters .label { font: 0.8rem system-ui, sans-serif; color: var(--muted); margin-right: 2px; }
        .filters .tag { font-size: 0.85rem; padding: 6px 12px; background: transparent; border-color: var(--line); color: var(--text); }
        .filters .tag.active { background: var(--tag-text); border-color: var(--tag-text); color: var(--card); }
        .attachments { list-style: none; padding: 0; margin: 10px 0 0; display: flex; flex-wrap: wrap; gap: 8px; }
        .attachments a {
            font: 0.85rem system-ui, sans-serif; color: var(--text); text-decoration: none;
            border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px;
        }
        .attachments a:hover { border-color: var(--accent); color: var(--accent); }
        .empty, .error { color: var(--muted); font-style: italic; }
        .error { color: var(--accent); }
        details { margin-top: 40px; }
        summary { cursor: pointer; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.9rem; }
        .problems { color: var(--accent); font: 0.85rem system-ui, sans-serif; }
        .problems summary { color: var(--accent); }
        footer { margin-top: 56px; color: var(--muted); font: 0.85rem system-ui, sans-serif; }
        footer a { color: inherit; }
        @media (max-width: 480px) {
            .event { gap: 12px; padding: 14px; }
            .date { flex-basis: 44px; }
            .date .day { font-size: 1.5rem; }
            .date.range { flex-basis: 56px; }
            .date.range .day { font-size: 1.1rem; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>HPS Talks Utrecht</h1>
        <p>Unofficial calendar of talks related to History and Philosophy of Science in Utrecht. Maintained by Victor Elgersma-Azmanov. </p>
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
        <details>
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
</body>
</html>
