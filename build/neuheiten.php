<?php
// ==========================================
// 1. KONFIGURATION
// ==========================================
date_default_timezone_set('Europe/Berlin');

// Der Publishable Key ist fuer oeffentliche Clients vorgesehen. Der Zugriff
// auf die Tabelle muss trotzdem ueber Supabase RLS auf Lesen beschraenkt sein.
$SUPABASE_URL = getenv('SUPABASE_URL') ?: 'https://poucwezzrtbmxjrzmisr.supabase.co';
$SUPABASE_ANON = getenv('SUPABASE_ANON') ?: 'sb_publishable_O2lQ-miR10U3Y4mTAw1E7A__YZF-nfp';
$AFFILIATE_TAG = getenv('AFFILIATE_TAG') ?: 'otakushelf06-21';

// ==========================================
// 2. DATEN FUER DEN STATISCHEN BUILD LADEN
// ==========================================
$url = $SUPABASE_URL . '/rest/v1/manga_releases?select=*&is_active=eq.true'
    . '&release_date=gte.' . date('Y-m-01')
    . '&order=release_date.asc&limit=5000';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_ANON",
    "Authorization: Bearer $SUPABASE_ANON"
]);
$data = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($data === false || $httpCode !== 200) {
    fwrite(STDERR, "Supabase-Abruf fehlgeschlagen (HTTP $httpCode): $curlError\n");
    exit(1);
}

$releases = json_decode($data, true);
if (!is_array($releases)) {
    fwrite(STDERR, "Supabase-Antwort ist kein gueltiges JSON-Array.\n");
    exit(1);
}

// SCHRITT 2: Monatliche Gruppierungs-Logik
$groupedReleases = [];
$monthsGerman = [
    '01' => 'Januar', '02' => 'Februar', '03' => 'März', '04' => 'April',
    '05' => 'Mai', '06' => 'Juni', '07' => 'Juli', '08' => 'August',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'
];

// Verlage tragen als Erscheinungsdatum oft den Auslieferungstag ein, der ein paar
// Tage vor dem Katalog-Monat liegt (Carlsen: "Juli"-Titel = 30.06.). Der Scraper
// behaelt solche Zeilen bewusst, sonst wuerden sie zum Monatsersten geloescht.
// Hier darf trotzdem kein abgelaufener Monat mehr auftauchen -- die App filtert
// an derselben Grenze (supabase_manga_releases_volume_source.dart).
$currentMonthStart = date('Y-m-01');

// Ausgeblendet wird nur, was aus dem DNB-Neuerscheinungsdienst stammt UND kein
// Cover hat -- erkennbar am DNB-Permalink in shop_url, den dnb_scraper.py setzt.
// Verlagstitel ohne Coverbild haben ein tagesgenaues Datum und bleiben sichtbar.
function isDnbAnnouncement($manga) {
    if (!empty($manga['cover_url'])) {
        return false;
    }
    return strpos($manga['shop_url'] ?? '', 'https://d-nb.info/') === 0;
}

$announcementCount = 0;

if (!empty($releases)) {
    foreach ($releases as $manga) {
        if (!empty($manga['release_date']) && $manga['release_date'] < $currentMonthStart) {
            continue;
        }
        if (isDnbAnnouncement($manga)) {
            $announcementCount++;
        }
        if (!empty($manga['release_date'])) {
            $timestamp = strtotime($manga['release_date']);
            $year = date('Y', $timestamp);
            $monthKey = date('m', $timestamp);
            $groupKey = $year . '-' . $monthKey;
            
            if (!isset($groupedReleases[$groupKey])) {
                $groupedReleases[$groupKey] = [
                    'title' => $monthsGerman[$monthKey] . ' ' . $year,
                    'items' => []
                ];
            }
            $groupedReleases[$groupKey]['items'][] = $manga;
        } else {
            if (!isset($groupedReleases['unknown'])) {
                $groupedReleases['unknown'] = [
                    'title' => 'Ohne konkretes Datum',
                    'items' => []
                ];
            }
            $groupedReleases['unknown']['items'][] = $manga;
        }
    }
}

function getAmazonLink($isbn, $tag) {
    $isbn = preg_replace('/[^0-9]/', '', $isbn);
    if (strlen($isbn) == 13 && strpos($isbn, '978') === 0) {
        $isbn10_core = substr($isbn, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$isbn10_core[$i] * (10 - $i);
        }
        $check_digit = 11 - ($sum % 11);
        if ($check_digit == 10) {
            $check_digit = 'X';
        } elseif ($check_digit == 11) {
            $check_digit = '0';
        }
        return "https://www.amazon.de/dp/" . $isbn10_core . $check_digit . "?tag=" . urlencode($tag);
    }
    return "https://www.amazon.de/s?k=" . urlencode($isbn) . "&tag=" . urlencode($tag);
}

function formatGermanDate($dateString) {
    if (!$dateString) return "Unbekannt";
    $timestamp = strtotime($dateString);
    return date('d.m.Y', $timestamp);
}
?>

<!DOCTYPE html>
<html lang="de" class="js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a14">
    <title>Neuheiten – Otaku Shelf</title>
    <link rel="canonical" href="https://www.otaku-shelf.info/neuheiten.html">

    <link rel="icon" href="favicon.png" type="image/png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;900&family=Manrope:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #8b5cf6;
            --primary-deep: #6a0dad;
            --primary-glow: #a78bfa;
            --accent: #06b6d4;
            --accent-glow: #22d3ee;
            --premium: #f59e0b;
            --supporter: #ec4899;
            --bg: #0a0a14;
            --bg-elev: #11111e;
            --surface: #181828;
            --surface-2: #1f1f33;
            --border: rgba(139, 92, 246, 0.18);
            --border-strong: rgba(139, 92, 246, 0.45);
            --text: #ececf5;
            --text-muted: #9292ac;
            --text-soft: #6f6f88;
            --font-display: 'Orbitron', system-ui, sans-serif;
            --font-body: 'Manrope', system-ui, sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
            --container: 1200px;
            --radius-sm: 8px;
            --radius: 16px;
            --radius-lg: 24px;
            --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: var(--font-body);
            font-weight: 400;
            font-size: clamp(15px, 1vw + 12px, 17px);
            line-height: 1.65;
            color: var(--text);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 84px;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 15% 10%, rgba(139, 92, 246, 0.18), transparent 60%),
                radial-gradient(ellipse 50% 40% at 85% 30%, rgba(6, 182, 212, 0.12), transparent 60%);
            pointer-events: none;
            z-index: -1;
        }

        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; height: auto; }
        
        .container {
            width: 100%;
            max-width: var(--container);
            margin-inline: auto;
            padding-inline: clamp(20px, 4vw, 40px);
        }

        h1, h2, h3 {
            font-family: var(--font-display);
            font-weight: 800;
            line-height: 1.1;
            text-transform: uppercase;
        }
        
        h1 { font-size: clamp(2.2rem, 5.5vw + 0.5rem, 4.5rem); letter-spacing: -0.02em; text-align: center; margin-bottom: 10px;}
        .lead { color: var(--text-muted); font-size: clamp(1rem, 0.5vw + 0.95rem, 1.15rem); text-align: center; max-width: 60ch; margin: 0 auto 40px;}

        .gradient-text {
            background: linear-gradient(95deg, var(--primary-glow) 0%, var(--accent-glow) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: inline-block;
        }

        .eyebrow {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--accent-glow);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .eyebrow::before, .eyebrow::after {
            content: '';
            width: 24px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
        }

        /* HEADER */
        .header {
            position: fixed;
            top: 0; left: 0; right: 0; z-index: 50;
            background: rgba(10, 10, 20, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
        }
        .header__inner { display: flex; align-items: center; justify-content: space-between; padding-block: 16px; gap: 20px; }
        .header__logo { display: inline-flex; align-items: center; gap: 12px; font-family: var(--font-display); font-weight: 900; font-size: 1.15rem; letter-spacing: 0.08em; text-transform: uppercase; }
        .header__logo img { width: 36px; height: 36px; border-radius: 10px; box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.06), 0 6px 16px -6px var(--primary); }
        .nav { display: flex; gap: 32px; }
        .nav a { color: var(--text-muted); font-weight: 500; font-size: 0.95rem; transition: color 0.2s var(--ease); }
        .nav a:hover { color: var(--text); }
        @media (max-width: 880px) { .nav { display: none; } }

        /* SEKTIONS STYLING & NAVIGATION */
        .month-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-bottom: 50px;
        }

        .month-nav a {
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 8px 20px;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.2s var(--ease);
            text-decoration: none;
        }

        .month-nav a:hover {
            background: rgba(139, 92, 246, 0.15);
            border-color: var(--primary-glow);
            color: var(--text);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px -4px rgba(139, 92, 246, 0.4);
        }

        /* Titel aus dem DNB-Neuerscheinungsdienst haben kein Cover und nur einen
           Monat statt eines Datums. Sie sind standardmaessig ausgeblendet und
           lassen sich ueber den Schalter dazuholen. Verlagstitel ohne Coverbild
           sind davon nicht betroffen, die bleiben sichtbar. */
        .cover-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 18px auto 0;
            padding: 12px 18px;
            max-width: 720px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .cover-toggle label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            color: var(--text);
            font-weight: 600;
        }
        .cover-toggle input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .cover-toggle .hint { font-size: 0.82rem; color: var(--text-soft); }

        .feature-card.dnb-announcement { display: none; }
        body.show-announcements .feature-card.dnb-announcement { display: flex; }
        .month-section.is-empty,
        .month-nav a.is-empty { display: none; }

        .month-section {
            margin-top: 60px;
            margin-bottom: 40px;
            scroll-margin-top: 110px; /* WICHTIG: Verhindert, dass der Titel unterm Header klemmt */
        }
        .month-title {
            font-size: clamp(1.4rem, 2.5vw + 0.5rem, 2rem);
            color: var(--text);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .month-title::after {
            content: '';
            flex-grow: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--border-strong), transparent);
        }

        /* MANGA GRID & CARDS */
        .page-section { padding-block: 40px 100px; }

        .manga-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }

        .feature-card {
            position: relative;
            background: linear-gradient(160deg, var(--surface), var(--bg-elev));
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.4s var(--ease-out), border-color 0.4s var(--ease), box-shadow 0.4s var(--ease);
            isolation: isolate;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 50% 0%, rgba(139, 92, 246, 0.15), transparent 60%);
            opacity: 0;
            transition: opacity 0.4s var(--ease);
            z-index: -1;
            pointer-events: none;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            border-color: var(--border-strong);
            box-shadow: 0 12px 32px -16px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(139, 92, 246, 0.25);
        }

        .feature-card:hover::before { opacity: 1; }

        .card-image-wrapper {
            height: 320px;
            background: rgba(0,0,0,0.2);
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            position: relative;
        }
        
        .card-image-wrapper img {
            max-height: 100%;
            border-radius: 4px;
            box-shadow: 0 15px 35px -10px rgba(0,0,0,0.6);
            transition: transform 0.4s var(--ease-out);
        }
        
        .feature-card:hover .card-image-wrapper img {
            transform: scale(1.05);
        }

        .card-content {
            padding: 24px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .publisher-badge {
            display: inline-block;
            font-family: var(--font-mono);
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--accent-glow);
            margin-bottom: 8px;
        }

        .manga-title {
            font-size: 1.15rem;
            margin-bottom: 16px;
            text-transform: none;
            letter-spacing: 0;
            color: var(--text);
            line-height: 1.4;
        }

        .meta-list {
            margin-bottom: 24px;
            font-size: 0.9rem;
            color: var(--text-muted);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .meta-list span {
            color: var(--text);
            font-weight: 600;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 24px;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.95rem;
            border-radius: 999px;
            transition: transform 0.25s var(--ease-out), box-shadow 0.25s var(--ease), background 0.25s var(--ease);
            white-space: nowrap;
            width: 100%;
            margin-top: auto;
        }

        .btn--primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-deep));
            color: white;
            box-shadow: 0 4px 18px -4px var(--primary), 0 0 0 1px rgba(255, 255, 255, 0.06) inset;
        }

        .btn--primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px -6px var(--primary), 0 0 0 1px rgba(255, 255, 255, 0.1) inset;
        }

        /* FOOTER */
        .footer {
            padding-block: 56px 40px;
            border-top: 1px solid var(--border);
            background: var(--bg-elev);
            text-align: center;
            margin-top: 60px;
        }
        .footer__brand { display: inline-flex; align-items: center; gap: 10px; font-family: var(--font-display); font-weight: 800; font-size: 0.95rem; margin-bottom: 16px;}
        .footer p { color: var(--text-muted); font-size: 0.88rem; }
    </style>
</head>
<body>

    <header class="header">
        <div class="container header__inner">
            <a href="index.html" class="header__logo">
                <img src="favicon.png" alt="" width="36" height="36">
                <span class="logo-text gradient-text">OTAKU SHELF</span>
            </a>
            <nav class="nav">
                <a href="index.html#features">Funktionen</a>
                <a href="index.html#gallery">UI-Showcase</a>
                <a href="index.html#faq">FAQ</a>
            </nav>
        </div>
    </header>

<main class="page-section">
        <div class="container">
            <span class="eyebrow">Release-Kalender</span>
            <h1>Aktuelle <span class="gradient-text">Neuheiten</span></h1>
            <p class="lead">Entdecke die anstehenden Veröffentlichungen der großen Manga-Verlage in Deutschland. Chronologisch sortiert und immer up to date.</p>

            <?php if (!empty($groupedReleases)): ?>
                
                <div class="month-nav">
                    <?php foreach ($groupedReleases as $key => $group): ?>
                        <a href="#monat-<?= htmlspecialchars($key) ?>" data-month="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($group['title']) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if ($announcementCount > 0): ?>
                <div class="cover-toggle">
                    <label>
                        <input type="checkbox" id="toggle-announcements">
                        Vorab-Ankündigungen anzeigen
                    </label>
                    <span class="hint"><?= $announcementCount ?> weitere Titel aus dem Neuerscheinungsdienst der Deutschen Nationalbibliothek – nur monatsgenau und ohne Coverbild.</span>
                </div>
                <?php endif; ?>

                <?php foreach ($groupedReleases as $key => $group): ?>
                    
                    <section class="month-section" id="monat-<?= htmlspecialchars($key) ?>">
                        <h2 class="month-title"><span class="gradient-text"><?= htmlspecialchars($group['title']) ?></span></h2>
                        
                        <div class="manga-grid">
                            <?php foreach ($group['items'] as $manga): ?>
                                <?php 
                                    $isLimited = (stripos($manga['title'], 'limited') !== false && $manga['publisher'] === 'Dokico');
                                ?>
                                <article class="feature-card<?= isDnbAnnouncement($manga) ? ' dnb-announcement' : '' ?>">
                                    <div class="card-image-wrapper">
                                        <?php if (!empty($manga['cover_url'])): ?>
                                            <img src="<?= htmlspecialchars($manga['cover_url']) ?>" alt="Cover von <?= htmlspecialchars($manga['title']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div style="color: var(--text-soft);">Kein Cover</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="card-content">
                                        <div class="publisher-badge"><?= htmlspecialchars($manga['publisher']) ?></div>
                                        <h3 class="manga-title"><?= htmlspecialchars($manga['title']) ?></h3>
                                        
                                        <div class="meta-list">
                                            <div>Release: <span><?= formatGermanDate($manga['release_date']) ?></span></div>
                                            
                                            <?php if (!empty($manga['price'])): ?>
                                                <div>Preis: <span><?= htmlspecialchars($manga['price']) ?></span></div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($manga['pages'])): ?>
                                                <div>Umfang: <span><?= htmlspecialchars($manga['pages']) ?> S.</span></div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($manga['age_rating'])): ?>
                                                <div>Alter: <span><?= htmlspecialchars($manga['age_rating']) ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($isLimited && !empty($manga['shop_url'])): ?>
                                            <a href="<?= htmlspecialchars($manga['shop_url']) ?>" target="_blank" rel="sponsored nofollow" class="btn btn--primary" style="background: linear-gradient(135deg, var(--supporter), #be185d); box-shadow: 0 4px 18px -4px var(--supporter);">
                                                Exklusiv bei Dokico
                                            </a>
                                        <?php elseif (!empty($manga['isbn13']) && strpos($manga['isbn13'], 'DOKICO') === false): ?>
                                            <a href="<?= getAmazonLink($manga['isbn13'], $AFFILIATE_TAG) ?>" target="_blank" rel="sponsored nofollow" class="btn btn--primary">
                                                Bei Amazon ansehen
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>

                            <?php endforeach; ?>
                        </div>
                    </section>

                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 40px;">Aktuell konnten keine Daten geladen werden. Bitte versuche es später erneut.</p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer__brand">
                <img src="favicon.png" alt="" width="24" height="24">
                <span class="gradient-text">OTAKU SHELF</span>
            </div>
            <p>&copy; <?= date('Y') ?> Otaku Shelf. Alle Rechte vorbehalten.</p>
            <p style="margin-top: 8px; font-size: 0.8rem; color: var(--text-soft);">
                * Als Amazon-Partner verdiene ich an qualifizierten Verkäufen.<br>Diese Seite ist ein Projekt von Sammlern für Sammler.
            </p>
        </div>
    </footer>

    <script>
        // Monate, die nur aus Ankuendigungen ohne Cover bestehen, sollen nicht als
        // leere Ueberschrift stehenbleiben -- weder im Abschnitt noch in der Navigation.
        (function () {
            var toggle = document.getElementById('toggle-announcements');
            if (!toggle) return;

            var sections = Array.prototype.slice.call(document.querySelectorAll('.month-section'));
            var navLinks = {};
            document.querySelectorAll('.month-nav a[data-month]').forEach(function (a) {
                navLinks[a.dataset.month] = a;
            });

            function apply(show) {
                document.body.classList.toggle('show-announcements', show);
                sections.forEach(function (section) {
                    var visible = section.querySelectorAll('.feature-card:not(.dnb-announcement)').length;
                    var empty = !show && visible === 0;
                    section.classList.toggle('is-empty', empty);
                    var key = section.id.replace('monat-', '');
                    if (navLinks[key]) navLinks[key].classList.toggle('is-empty', empty);
                });
            }

            var stored = null;
            try { stored = localStorage.getItem('showDnbAnnouncements'); } catch (e) {}
            toggle.checked = stored === '1';
            apply(toggle.checked);

            toggle.addEventListener('change', function () {
                apply(toggle.checked);
                try { localStorage.setItem('showDnbAnnouncements', toggle.checked ? '1' : '0'); } catch (e) {}
            });
        })();
    </script>

</body>
</html>
