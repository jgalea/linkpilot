# LinkPilot Scanner — BLC Feature Parity Plan

> Goal: achieve functional parity with Broken Link Checker's **currently useful** features
> while skipping legacy/cruft. Ship iteratively in phases; each phase is independently
> testable against BLC on a real site.

## Scope (what's in vs. out)

### In scope — everything from BLC that's still relevant
- Parsers: html_link, image (src + srcset), iframe embeds, plaintext URL detection
- Checkers: HTTP HEAD/GET (parallel), YouTube oEmbed, Vimeo oEmbed
- Containers: post/page content, custom post meta, comments (optional), ACF fields (if ACF active)
- Admin: broken list, edit-in-place URL rewrite, unlink, dismiss
- Politeness: per-host token-bucket rate limiting
- Notifications: email digest on new broken links
- Redirect chain: show hop-by-hop for redirected URLs
- Exclusions: domain allowlist, URL pattern exclusions, post-type exclusions

### Out of scope — BLC features to skip
- Blogroll / bookmarks scanning (WP deprecated)
- RSS / Atom feed scanning
- Dead-service specialized checkers (rapidshare, mediafire, dailymotion-embed)
- WPMU DEV branding bloat (hub connector, cross-sell, black friday)
- Multisite-specific admin features (add later if demand exists)

---

## Phase status

### ✅ Phase 1 — Extraction parity
- html_link extraction
- image src / srcset extraction
- iframe src extraction (catches YouTube/Vimeo embeds)
- plaintext YouTube/Vimeo URL detection (catches WP auto-embeds)
- own-domain always allowlisted
- cloaked LP URLs always excluded

### ✅ Phase 2 — HTTP checker parity
- Parallel HEAD with WP core Requests::request_multiple()
- GET fallback for 400/403/405/501 (hosts that reject HEAD)
- Range: bytes=0-0 on GET fallback (minimal bandwidth)
- **Per-host token-bucket rate limiter** (3 req/sec/host, 0.5s min interval)

### ✅ Phase 3 — Edit-in-place
- Rewrite URL across all posts (with revisions preserved)
- Unlink (remove <a> wrapper, keep text)
- Dismiss (hide from broken list, don't touch content)
- Row actions on admin table

### 🟡 Phase 4 — Specialized embed checkers
- YouTube: check via oEmbed API (https://www.youtube.com/oembed?url=...). Detects removed/private videos.
- Vimeo: check via oEmbed API (https://vimeo.com/api/oembed.json?url=...). Detects removed videos.
- Wire into LP_Scanner_Checker::check_batch as a first pass before generic HTTP.

### 🟡 Phase 5 — Container expansion
- Standard post meta scanner (any meta_value that looks like a URL)
- Comment content scanner (opt-in setting, default off)
- ACF field scanner (if ACF plugin active): `url` field type + `link` field type + `text` fields containing URLs

### 🟡 Phase 6 — Email notifications
- Weekly digest: "N new broken links detected this week"
- Per-URL notification (opt-in): email when a specific URL first becomes broken
- Uses WP's wp_mail() with a template

### 🟡 Phase 7 — Redirect chain
- Store the full hop chain (URL → URL → final) in scanner DB
- Show hops in the admin table ("redirects through 3 URLs")
- Flag "too many hops" (>5) as warning

### 🟡 Phase 8 — Advanced exclusions
- URL pattern exclusion (substring or regex per line in textarea)
- Post-type exclusion (checkbox per post type)
- Individual URL exclusion (dismiss already covers this as the UI)
- Category/tag exclusion (skip posts in selected taxonomies)

### 🟡 Phase 9 — UX polish
- Pagination on broken list (>200 broken URLs)
- Filter by status / host / code
- Sort by post count, last checked, status
- Bulk actions (dismiss all, re-check all)
- CSV export of broken URLs

---

## Test plan

Side-by-side comparison on jeangalea.com:
1. Enable LinkPilot Scanner (done)
2. Run full scan (`lp_job_scanner_extract`)
3. Run full check (`lp_job_scanner_check`)
4. Compare broken-link count with BLC's broken-link count
5. For each URL in BLC but not in LP Scanner, diagnose why (missing parser, excluded, etc.)
6. Iterate until ≥ 95% parity
7. Deactivate BLC after 1 week of parallel operation with no parity regressions

## Done criteria (v1 public release)

- All Phase 1–6 ticked
- Plugin Check passes (zero errors)
- 95%+ broken-link parity with BLC on a real-site test
- Handles ≥ 10k posts without timeout (chunked AJAX + rate limiter scales)
- Documentation page covering: what it scans, what it skips, how to add exclusions
