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
$error = null;
$events = [];
$raw = @file_get_contents(__DIR__ . '/events.json');

if ($raw === false) {
    $error = 'Could not read events.json.';
} else {
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        $error = 'events.json is not valid: ' . json_last_error_msg();
    } else {
        foreach ($data['events'] as $ev) {
            if (!is_array($ev) || empty($ev['date']) || empty($ev['title'])) {
                continue; // each event needs at least a date and a title
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ev['date']);
            if (!$date) {
                continue;
            }
            $ev['_date'] = $date;
            $ev['_sort'] = $date->format('Y-m-d') . ' ' . ($ev['time-start'] ?? '00:00');
            $events[] = $ev;
        }
    }
}

usort($events, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));

$today = new DateTimeImmutable('today');
$upcoming = array_values(array_filter($events, fn($ev) => $ev['_date'] >= $today));
$past     = array_reverse(array_values(array_filter($events, fn($ev) => $ev['_date'] < $today)));

// Group upcoming events by month
$byMonth = [];
foreach ($upcoming as $ev) {
    $byMonth[$ev['_date']->format('F Y')][] = $ev;
}

function badge(DateTimeImmutable $date, DateTimeImmutable $today): ?string
{
    $days = (int) $today->diff($date)->format('%r%a');
    return match (true) {
        $days === 0 => 'Today',
        $days === 1 => 'Tomorrow',
        $days > 1 && $days < 7 => 'This week',
        default => null,
    };
}

function render_event(array $ev, DateTimeImmutable $today, bool $isPast = false): void
{
    $d = $ev['_date'];
    $time = trim(($ev['time-start'] ?? '') . (!empty($ev['time-end']) ? '–' . $ev['time-end'] : ''));
    $badge = $isPast ? null : badge($d, $today);
    ?>
    <article class="event<?= $isPast ? ' past' : '' ?>">
        <div class="date">
            <span class="dow"><?= e($d->format('D')) ?></span>
            <span class="day"><?= e($d->format('j')) ?></span>
            <span class="mon"><?= e($d->format('M')) ?></span>
        </div>
        <div class="body">
            <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
            <h3><?= e($ev['title']) ?></h3>
            <p class="meta">
                <time datetime="<?= e($d->format('Y-m-d') . (!empty($ev['time-start']) ? 'T' . $ev['time-start'] : '')) ?>">
                    <?= e($d->format('l j F Y')) ?><?= $time !== '' ? ' · ' . e($time) : '' ?>
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
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #161514; --card: #1f1e1c; --text: #ece8e1; --muted: #a39d93;
                --line: #2e2c29; --accent: #ef8a80; --badge-bg: #3a2320;
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
        .body { min-width: 0; }
        .body h3 { margin: 0 0 4px; font-size: 1.1rem; line-height: 1.35; }
        .meta, .speaker { margin: 0; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.88rem; }
        .desc { margin: 8px 0 0; }
        .badge {
            display: inline-block; font: 600 0.7rem/1 system-ui, sans-serif;
            text-transform: uppercase; letter-spacing: 0.06em;
            background: var(--badge-bg); color: var(--accent);
            padding: 4px 7px; border-radius: 4px; margin-bottom: 6px;
        }
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
        footer { margin-top: 56px; color: var(--muted); font: 0.85rem system-ui, sans-serif; }
        footer a { color: inherit; }
        @media (max-width: 480px) {
            .event { gap: 12px; padding: 14px; }
            .date { flex-basis: 44px; }
            .date .day { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>HPS Talks Utrecht</h1>
        <p>Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
    </header>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php elseif (!$upcoming): ?>
        <p class="empty">No upcoming events at the moment.</p>
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

    <footer>
        Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>
        · <a href="https://github.com/victorelgersma/hps-calendar" target="_blank" rel="noopener">Source on GitHub</a>
    </footer>
</main>
</body>
</html>