# Changelog

## 1.4.0 (2026-10-12)

- "Online now", "today", the hourly heatmap and every date range use the site's time
  zone. Visitors no longer stay "online" for hours on sites outside UTC.
- Category, tag and author pages are no longer counted against an unrelated post with the
  same number; they, the blog front page and other pages are listed by address.
  "Page not found" pages, searches and previews are not counted.
- Cache-compatible mode works: a small script in the page reports the visit, so pages
  served from a page cache are counted. The settings page names an active page cache
  plugin. Reports from the script count a visitor and page at most once every 30 seconds,
  and excluded logged-in roles stay excluded.
- The 5-minute clean-up and the email report are scheduled again. The report can be daily
  or weekly and covers complete days.
- The statistics box on the post editing screen is laid out properly.
- Faster: date ranges use the table's index, and a new index serves per-page counts.
- The chart library and fonts come with the plugin; the dashboard loads nothing from other
  servers.
- Card labels say what is counted: visitors are recognised one day at a time.

## 1.3.5 (2026-10-12)

- IP addresses are no longer stored. Updating removes the addresses stored by earlier
  versions and their cached country lookups. Only the shortened address is sent to the
  country lookup service, and the city is no longer requested.
- The world map ships ready-drawn with the plugin: no more bands across the map from
  Russia, Fiji and Antarctica, every country can light up, small countries with visits get
  a dot, and nothing is downloaded to draw it.

## 1.3.0 – 1.3.4

- Earlier versions, in use on anoise.org since early 2026.
