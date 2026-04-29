<?php
/**
 * PostGlider Adapter — SearchIQ front-end style overrides
 *
 * Loaded on all front-end pages. CSS is scoped to .siq- selectors so it
 * only affects SearchIQ output. Fixes facet collapse and polishes result cards
 * without overriding SearchIQ's built-in colour/font customization controls.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_head', function () {
    ?>
<style id="pg-siq-styles">
/* ── Facet sidebar ─────────────────────────────────────────────────────────── */

/* Prevent collapsed appearance when a facet has few or no items yet */
.siq-blogrfct-facet {
    min-height: 140px;
}

/* Give facet headings a little breathing room */
.siq-blogrfct-facet h3,
.siq-blogrfct-facet .siq-facet-title {
    margin-top: 0;
    padding-top: 0;
}

/* ── Result cards ──────────────────────────────────────────────────────────── */

/*
 * SearchIQ result cards that have a featured image should show it at a
 * consistent square-ish ratio. This targets the image wrapper regardless of
 * the exact class SearchIQ uses for the thumbnail container.
 */
[class*="siq-"][class*="thumb"],
[class*="siq-"][class*="image"],
[class*="siq-"][class*="img"] {
    overflow: hidden;
}

[class*="siq-"][class*="thumb"] img,
[class*="siq-"][class*="image"] img,
[class*="siq-"][class*="img"] img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    display: block;
    border-radius: 6px;
}

/* Hide image placeholder divs that have no child img (empty box artifact) */
[class*="siq-"][class*="thumb"]:not(:has(img)),
[class*="siq-"][class*="image"]:not(:has(img)),
[class*="siq-"][class*="img"]:not(:has(img)) {
    display: none;
}

/* ── Search input ──────────────────────────────────────────────────────────── */

/* Ensure the search bar spans full width on mobile */
@media (max-width: 640px) {
    [class*="siq-"][class*="search"] input[type="search"],
    [class*="siq-"][class*="search"] input[type="text"] {
        width: 100%;
        box-sizing: border-box;
    }
}
</style>
    <?php
}, 20 );
