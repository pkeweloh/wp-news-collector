# News Collector

A WordPress plugin that aggregates RSS/RSSHub feeds (Telegram channels), parses each item into structured content, re-uploads media to [Catbox](https://catbox.moe) for persistent hosting, and displays the feed via shortcodes with an Instagram-style modal and canonical per-item URLs.

## Features

- **Scheduled ingestion** via Action Scheduler. Configurable interval.
- **Robust parser** handling Telegram/RSSHub quirks: blockquote article extraction, `too_big` video fallback, image dedup, YouTube ID detection, "Forwarded From" stripping, OG metadata scraping.
- **Catbox media hosting**. Downloads media locally and re-uploads via `fileupload` multipart (works with Telegram CDN token-protected URLs that `urlupload` cannot access). Monthly album organisation with automatic daily sync.
- **Expired Telegram links re-minted.** Signed `cdn*.telesco.pe` URLs die in hours, but the message does not: a retry re-reads `t.me/{channel}/{id}?embed=1`, which re-signs the media on every request, and matches each stored piece by position. No Telegram account or API credentials involved.
- **URL shortener resolution** for `t.co`, `bit.ly`, `tinyurl.com`, etc.
- **Feed UI:**
  - Single-image display (no crop, natural dimensions).
  - Media grid for 2–4 items (1:1 crop, lightbox).
  - Single-video inline autoplay (muted, looped) via `IntersectionObserver` (one at a time).
  - Article card with site name, title, excerpt and cover image.
  - YouTube CTA when no other media is present.
  - `too_big` videos shown as Telegram links with poster.
  - Infinite scroll via REST + `IntersectionObserver`.
  - Instagram-style modal with `history.pushState`. Back button closes the modal; direct URL visits load the full-page item view.
  - Media lightbox (keyboard + swipe) inside modal/detail only.
- **Admin UI** with items list (filters: `too_big` / `upload_failed` / hidden), bulk actions, per-item media editor (paste a Catbox URL for `too_big` videos), sources CRUD, and Catbox albums dashboard.
- **Translation-ready.** Ships with `es_ES`. Date format and timezone follow the WordPress site settings.

## Requirements

- PHP **8.1+**
- WordPress **6.0+**
- MySQL 5.7+ / MariaDB 10.3+
- Pretty permalinks recommended (plain mode falls back to `?nc_item=N`)

## Installation

1. Upload the `wp-news-collector` folder to `wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Run the DB migration: deactivate and reactivate the plugin once (creates the four custom tables).
4. Set **Settings → General → Site Language** to match your audience so the correct translation loads.
5. Set **Settings → General → Timezone** to your local timezone (dates are displayed in this timezone).
6. Add RSS/RSSHub feed URLs under **News Collector → Sources**, e.g.:
   `http://rsshub.example/telegram/channel/your_channel`
7. Optionally enable Catbox under **News Collector → Settings** and add your `userhash`.
8. Make sure **Settings → Permalinks** is not set to "Plain".

## Shortcodes

### `[news_feed]`

```
[news_feed]
[news_feed limit="10" source="mychannel"]
[news_feed source="channel1,channel2" limit="30" show_videos="false"]
```

| Attribute     | Default | Description |
|---------------|---------|-------------|
| `source`      | `''`    | Filter by source handle(s), comma-separated. Empty = all sources. |
| `limit`       | `20`    | Maximum items to load initially (cap: 200). Infinite scroll loads more. |
| `show_images` | `true`  | Render the image/media grid. |
| `show_videos` | `true`  | Render videos and too_big links. |

### `[news_widget]`

```
[news_widget]
[news_widget count="5" title="Latest news"]
[news_widget count="8" source="channel1,channel2"]
```

| Attribute | Default          | Description |
|-----------|------------------|-------------|
| `count`   | `5`              | Number of items (1–10). |
| `source`  | `''`             | Filter by source handle(s), comma-separated. |
| `title`   | `''`             | Optional heading above the list. |

### `[news_sources]`

```
[news_sources]
[news_sources title="Channels"]
```

Renders the list of active sources with their Telegram links. Useful in sidebars.

### Sidebar widget

**News Collector → Latest news** also appears as a classic WordPress widget under **Appearance → Widgets**, with the same options as `[news_sources]`.

### Canonical URLs

- `/{slug}/{id}`: full-page item view. The slug defaults to `noticia` and is configurable in Settings.
- `/wp-json/nc/v1/item/{id}`: REST endpoint returning `{id, permalink, title, html}`.
- `/wp-json/nc/v1/feed?page=N&page_size=20&source=`: paginated feed used by infinite scroll.

## Theme customisation

### CSS design tokens

Override any of these in your theme CSS:

```css
:root {
  --nc-radius:       8px;       /* card and image border-radius */
  --nc-accent:       #2271b1;   /* source name, article border, links */
  --nc-card-bg:      #fff;      /* card background */
  --nc-border:       #e2e2e2;   /* card borders and dividers */
  --nc-muted:        #888;      /* secondary text (dates, excerpts) */
  --nc-surface:      #f4f4f4;   /* image placeholder background */
  --nc-text:         #222;      /* body text inside cards */
}
```

Example, square corners with custom accent:
```css
:root { --nc-radius: 0; --nc-accent: #e45d12; }
```

### Template overrides

Copy any template to your theme to customise the HTML:

```
wp-content/themes/your-theme/
└── wp-news-collector/
    ├── item.php          ← feed card
    ├── item-detail.php   ← modal + standalone page content
    ├── feed.php          ← feed container (loop, sentinel, disclaimer)
    └── single-item.php   ← full /noticia/{id} page (header + item-detail + footer)
```

Child themes are checked before parent themes; plugin defaults are the final fallback.

## Settings

Stored as option `nc_settings`:

| Key                      | Default | Description |
|--------------------------|---------|-------------|
| `catbox_enabled`         | `false`    | Re-upload media to catbox.moe. |
| `catbox_userhash`        | `''`       | Catbox account userhash (optional, required for albums). |
| `fetch_interval_minutes` | `30`       | Recurring fetch interval in minutes. |
| `max_items_per_source`   | `50`       | Items inserted per source per cycle. |
| `item_slug`              | `item`     | URL prefix for single-item pages (e.g. set to `news` for `/news/123`). |
| `source_slug`            | `source`   | URL prefix for per-source landing pages (`/source/<channel>/`). |
| `catbox_retry_enabled`   | `true`     | Sweep failed uploads automatically. |
| `catbox_retry_interval`  | `3600`     | Seconds between retry sweeps. |
| `catbox_retry_batch_size` | `10`      | Uploads attempted per sweep. |
| `catbox_retry_max_attempts` | `8`     | Attempts before a piece counts as out of attempts (`0` = no cap). |
| `catbox_retry_breaker_threshold` | `3` | Consecutive failures that abort a sweep (`0` = never). |

## Database

Six custom tables created on activation, dropped on uninstall:

| Table | Contents |
|-------|----------|
| `{prefix}nc_sources` | RSS feed sources (url, name, enabled) |
| `{prefix}nc_items` | Parsed items (text, images JSON, videos JSON, audios JSON, article JSON, …) |
| `{prefix}nc_catbox_uploads` | Per-file Catbox upload state (url, type, album, error, retry backoff) |
| `{prefix}nc_catbox_upload_attempts` | One row per upload attempt (trigger, outcome, error) |
| `{prefix}nc_catbox_albums` | Monthly Catbox albums (YYYY-MM → album short code) |
| `{prefix}nc_source_covers` | Recurring channel cover images pending review |

## Background jobs

Managed by Action Scheduler (bundled in `vendor/`). View under **Tools → Scheduled Actions**.

| Hook | Trigger | Description |
|------|---------|-------------|
| `nc_fetch_all_sources` | Every N minutes | Full fetch + parse + upload cycle. |
| `nc_backfill_catbox` | Manual | Re-upload non-Catbox media on all items. |
| `nc_catbox_sync` | Daily | Reconcile uploads and assign to monthly albums. |
| `nc_catbox_retry` | Every N minutes | Retry failed uploads with per-row backoff and a circuit-breaker. |
| `nc_detect_covers` | Daily | Flag recurring channel cover images for review. |
| `nc_clean_covers` | Daily | Strip confirmed covers from stored items. |

Trigger manually:
```php
as_enqueue_async_action( 'nc_fetch_all_sources', [], 'nc' );
```

## Translation

Ships with `es_ES` (Spanish). To add another language:

1. Copy `languages/wp-news-collector-es_ES.po` as `wp-news-collector-{locale}.po`.
2. Translate the `msgstr` entries.
3. Compile with `msgfmt wp-news-collector-{locale}.po -o wp-news-collector-{locale}.mo`  
   (or use [Poedit](https://poedit.net/)).
4. Place both files in `wp-content/languages/plugins/`.

## License

[GPL-2.0-or-later](LICENSE)
