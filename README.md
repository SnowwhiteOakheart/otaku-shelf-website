# Otaku Shelf Website

Quellcode der offiziellen Website von [Otaku Shelf](https://www.otaku-shelf.info/).

Die Website wird automatisch mit GitHub Actions gebaut und auf GitHub Pages
veröffentlicht. Dabei erzeugt `build/neuheiten.php` aus den öffentlich lesbaren
Supabase-Daten eine statische `neuheiten.html`. Ein geplanter Workflow
aktualisiert diese Seite alle sechs Stunden.

## Struktur

- `public/`: direkt veröffentlichte HTML-Dateien und Bilder
- `build/neuheiten.php`: Generator für die statische Neuheiten-Seite
- `.github/workflows/pages.yml`: Build und Deployment zu GitHub Pages

## Lokaler Build

Voraussetzung ist PHP 8.3 mit der cURL-Erweiterung.

```bash
mkdir -p _site
cp -a public/. _site/
php build/neuheiten.php > _site/neuheiten.html
```

Der im Generator verwendete Supabase Publishable Key ist ausdrücklich für
öffentliche Clients vorgesehen. Schreibzugriffe und nichtöffentliche Daten
müssen unabhängig davon durch Row Level Security geschützt sein.
