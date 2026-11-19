# Friday Shop (Code Samples)

[ภาษาไทย](README.th.md) · Live site: [shop.friday-pet.com](https://shop.friday-pet.com)

This is a small-pet supply shop I built and run in Thailand. The main repo is private since it's a live commercial site, so I pulled out a few standalone files here as code samples. Happy to walk through the full codebase in an interview.

## What's in `samples/`

- [`multi-sqlite-setup.php`](samples/multi-sqlite-setup.php) - Splits SQLite into separate WAL-mode files by domain to avoid write locks, using `ATTACH DATABASE` when a query needs to join across them.
- [`rate-limit.php`](samples/rate-limit.php) - Two-part rate limiter: SQLite-backed lockout for login attempts, and an APCu / `flock()` file fallback for high-frequency endpoints so telemetry doesn't lock the main DB.
- [`cloudflare-worker.js`](samples/cloudflare-worker.js) - Cloudflare Worker that checks for `text/markdown` in `Accept` on `/` and proxies to the Markdown endpoint.
- [`webmcp-tools-excerpt.js`](samples/webmcp-tools-excerpt.js) - Browser WebMCP tool registration snippet with structured error objects and POST-body request handling.
- [`build-pipeline-excerpt.js`](samples/build-pipeline-excerpt.js) - Splits storefront, deferred, and admin bundles and caches builds by file-content MD5 instead of `mtime`.

## Stack

PHP 8.3, SQLite (WAL), React, TanStack Query, Tailwind, esbuild, Workbox, Cloudflare Workers
