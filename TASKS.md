# pg-media-vault — Deferred Tasks

---

## 🔴 Seed `_siq_image_custom_field` in `pg_configure_searchiq()` — prevents the broken-thumbnail bug for every future client (2026-07-10)

Root-caused and fixed live for Stay True Tattoo (`decisions.md` D-2026-07-10e): SearchIQ's cloud
index had `null` `image`/`thumbnailSmallUrl`/`thumbnailLargeUrl` fields for every
`pg_gallery_image` post, causing broken/placeholder thumbnails in search results. The documented
fix (`documentationai-Docs/admin/searchiq.mdx` §Per-client SearchIQ setup) is to set SearchIQ's
**Image Custom Field** to `_pg_image_url` for the `pg_gallery_image` post type — this was applied
manually/ad-hoc for Stay True Tattoo at some point, but `pg_configure_searchiq()`
(`plugins/postglider-adapter/includes/network-api.php`, called from `configure-site`) **never
sets this option**, so it's not part of the automated onboarding seed.

**Fix:** add `$s->postTypesForSearchSelection` / equivalent `_siq_image_custom_field` seeding to
`pg_configure_searchiq()`'s `$s` settings object, matching the working value confirmed live:
`pg_gallery_image:_pg_image_url` (plus the other post types SearchIQ tracks — see the live
`wp option get _siq_image_custom_field` output in D-2026-07-10e for the full comma-separated
format). Verify against a fresh test subsite provisioning end-to-end, not just a settings diff.

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

## 🔴 Wire `searchiq_api_key` through onboarding — SearchIQ is never configured for new clients today (2026-07-10)

`postglider-auto/lib/actions/wpActions.ts`'s `callWpConfigureSite()` never sends a
`searchiq_api_key` param to `POST /wp-json/postglider/v1/configure-site` — so
`pg_configure_searchiq()` never runs during real onboarding, and every new subsite gets
provisioned with `[pg_gallery]` only (no SearchIQ, no facets, no autocomplete). Confirmed via
`pg_setup_gallery_page()`'s own conditional: `$siq_key ? "[searchiq]\n\n[pg_gallery]" :
'[pg_gallery]'`. Stay True Tattoo's SearchIQ setup happened outside this automated path.

This blocks Ken's stated goal: every new client's subsite should get a working faceted gallery
"just like the stay-true one," automatically, at onboarding. See matching entry in
`postglider-auto/TASKS.md` for the app-side half of this fix (where does the `searchiq_api_key`
come from per client — a single shared key across the two merged AppSumo lifetime accounts, one
engine per subsite? needs research into how SearchIQ scopes engines to a key before this can be
wired up correctly).

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
