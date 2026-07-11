# pg-media-vault — Deferred Tasks

---

## ~~Seed `_siq_image_custom_field` for every new client~~ ✅ Fixed (2026-07-10, `decisions.md` D-2026-07-10g)

Superseded the originally-planned approach (seeding `pg_configure_searchiq()`'s `$s` object
directly — turned out `_siq_raw_settings` is a write-only UI pre-fill, not what the plugin
actually reads). Shipped instead as a new endpoint, `POST /wp-json/postglider/v1/searchiq-install`
(`includes/searchiq-install.php`, v0.4.5), which sets the *real* flat options
(`_siq_postTypesForSearchSelection`, `_siq_image_custom_field`), registers the SearchIQ engine,
syncs settings to the cloud, and swaps the Gallery page shortcode — all in one call, on the
target subsite itself. Verified live end-to-end against a clean test subsite.

---

## 🟡 Dead `wp_get_attachment_url` filter in `cpt.php` — real bug, currently harmless in effect

`includes/cpt.php` registers a `wp_get_attachment_url` filter meant to resolve `pg_gallery_image`
stub posts' real image URL for any caller (including SearchIQ) that calls
`wp_get_attachment_url($thumbnail_id)`. Confirmed via WP core source
(`wp-includes/post.php::wp_get_attachment_url()`) that core returns `false` for any post whose
`post_type !== 'attachment'` **before** that filter ever fires — so this filter is dead code for
every `pg_gallery_image` post, always. Currently harmless because SearchIQ's own image resolution
checks the Image Custom Field (see item above) before ever falling back to
`wp_get_attachment_url()`, and a second HTML-content-extraction fallback also exists. But it's
still a real defect: any code that *only* uses `wp_get_attachment_url()` (no custom-field-aware
fallback) will silently fail against our stub posts. Either fix it properly (there's no
`pre_wp_get_attachment_url`-style filter to hook — would need a structural change to how the fake
`_thumbnail_id` is modeled, e.g. a real lightweight `attachment` post type stub) or remove the
dead filter and its misleading docblock. See D-2026-07-10e for the full investigation.

---

## ~~Wire `searchiq_api_key` through onboarding~~ ✅ Fixed (2026-07-10, `decisions.md` D-2026-07-10g)

`postglider-auto`'s `provisionWpSubsite()` now calls the new `searchiq-install` endpoint (see
item above) right after `configure-site`, using a single shared `SEARCHIQ_API_KEY` env var —
SearchIQ auto-assigns each subsite its own distinct `engineKey` on registration, confirming the
single-shared-key model works as expected.

**Still open — Phase 2:** Ken's stated architecture puts the *caller* of this endpoint in
`pg-admin` (admin processes live there, alongside crons), not directly in `postglider-auto`.
Deferred until `pg-admin`'s first Cloud Run infra (currently being built in a separate effort for
a GMB-photo cron, see `pg-admin/CLAUDE.md` Tenants) lands, so this doesn't ship a second,
inconsistent Cloud Run pattern. The WordPress-side endpoint doesn't change for this move.

---

## 🔵 `pg_subject` faceted taxonomy — "Birds/Owls, Fantasy/Dragons" browse experience

Architecture conclusion from D-2026-07-10e (Ken's, recorded in full there): SearchIQ specifically
is worth leaning into for this — `pg_artist` and `pg_content_type` are already registered facet
taxonomies (`cpt.php`), proving the pattern works. Add a `pg_subject` taxonomy populated from the
AI visual/service tags `tagImage()` already produces (`postglider-auto/lib/agents/taggingAgent.ts`),
synced the same way `pg_artist`/`pg_content_type` are today (`sync.php`'s `wp_set_object_terms`
call), giving visitors a faceted subject-matter browse (e.g. "Dragons", "Owls", "Skulls") on top
of the existing Tag/Artist/Date/Category facets. Depends on deciding the taxonomy's term
vocabulary (freeform from AI tags vs. a curated fixed list) — not started.
