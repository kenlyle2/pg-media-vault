<?php
/**
 * PostGlider Adapter — [pg_gallery] shortcode
 *
 * Renders a responsive image grid from pg_gallery_image CPT posts with
 * live client-side search and tag-chip facets. No external dependencies.
 *
 * Usage: [pg_gallery columns="3" limit="50"]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'pg_gallery', function ( $atts ) {
    $atts = shortcode_atts( [ 'columns' => 3, 'limit' => 50 ], $atts, 'pg_gallery' );

    $posts = get_posts( [
        'post_type'      => 'pg_gallery_image',
        'posts_per_page' => intval( $atts['limit'] ),
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    if ( empty( $posts ) ) {
        return '<p class="pg-gallery-empty">No images yet — check back soon.</p>';
    }

    // Collect items and tag frequency in one pass.
    $tag_freq = [];
    $items    = [];
    foreach ( $posts as $post ) {
        $img_url = get_post_meta( $post->ID, '_pg_image_url', true );
        if ( ! $img_url ) continue;
        $tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
        foreach ( $tags as $t ) {
            $tag_freq[ $t ] = ( $tag_freq[ $t ] ?? 0 ) + 1;
        }
        $items[] = [
            'url'   => $img_url,
            'title' => $post->post_title,
            'tags'  => $tags,
        ];
    }

    if ( empty( $items ) ) {
        return '<p class="pg-gallery-empty">No images yet — check back soon.</p>';
    }

    arsort( $tag_freq ); // most-used tags first in the chip cloud

    $cols = max( 1, intval( $atts['columns'] ) );
    $uid  = wp_unique_id( 'pgg-' );

    ob_start();
    ?>
<div id="<?php echo esc_attr( $uid ); ?>" class="pg-gallery-wrap">

  <div class="pg-gallery-controls">
    <input type="search"
           class="pg-search"
           placeholder="Search images…"
           aria-label="Search gallery images">

    <div class="pg-chips" role="group" aria-label="Filter by tag">
      <?php foreach ( array_keys( $tag_freq ) as $tag ) : ?>
      <button class="pg-chip" data-tag="<?php echo esc_attr( $tag ); ?>" aria-pressed="false">
        <?php echo esc_html( $tag ); ?>
        <span class="pg-chip-n"><?php echo (int) $tag_freq[ $tag ]; ?></span>
      </button>
      <?php endforeach; ?>
    </div>

    <div class="pg-meta">
      <span class="pg-count"></span>
      <button class="pg-clear" hidden>Clear filters</button>
    </div>
  </div>

  <div class="pg-grid" style="grid-template-columns:repeat(<?php echo $cols; ?>,1fr)">
    <?php foreach ( $items as $item ) :
        $data_tags = implode( '|', array_map( 'esc_attr', $item['tags'] ) );
    ?>
    <figure class="pg-item"
            data-tags="<?php echo $data_tags; ?>"
            data-title="<?php echo esc_attr( strtolower( $item['title'] ) ); ?>">
      <img src="<?php echo esc_url( $item['url'] ); ?>"
           alt="<?php echo esc_attr( $item['title'] ); ?>"
           loading="lazy">
      <?php if ( $item['tags'] ) : ?>
      <figcaption><?php echo esc_html( implode( ' · ', array_slice( $item['tags'], 0, 4 ) ) ); ?></figcaption>
      <?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>

  <p class="pg-noresults" hidden>No images match — try fewer filters.</p>

</div>

<style>
#<?php echo esc_attr( $uid ); ?>{
  --acc:#1d6ef6;--chip-bg:#f0f4f8;--chip-r:20px;--gap:1rem;
  font-family:inherit;
}
#<?php echo esc_attr( $uid ); ?> .pg-gallery-controls,
#<?php echo esc_attr( $uid ); ?> .pg-gallery-controls *{box-sizing:border-box}
#<?php echo esc_attr( $uid ); ?> .pg-search{
  display:block;width:100%;padding:.65rem 1rem;
  border:1px solid #d0d5dd;border-radius:8px;font-size:1rem;
  margin-bottom:.75rem;outline:none;transition:border-color .15s;
}
#<?php echo esc_attr( $uid ); ?> .pg-search:focus{border-color:var(--acc);}
#<?php echo esc_attr( $uid ); ?> .pg-chips{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem}
#<?php echo esc_attr( $uid ); ?> .pg-chip{
  background:var(--chip-bg);border:1.5px solid transparent;border-radius:var(--chip-r);
  padding:.28rem .7rem;font-size:.8rem;cursor:pointer;
  display:inline-flex;align-items:center;gap:.3rem;
  transition:background .12s,border-color .12s,color .12s;
}
#<?php echo esc_attr( $uid ); ?> .pg-chip:hover{border-color:var(--acc)}
#<?php echo esc_attr( $uid ); ?> .pg-chip[aria-pressed="true"]{
  background:var(--acc);color:#fff;border-color:var(--acc);
}
#<?php echo esc_attr( $uid ); ?> .pg-chip-n{opacity:.6;font-size:.75em}
#<?php echo esc_attr( $uid ); ?> .pg-meta{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:.75rem;min-height:1.5rem;
}
#<?php echo esc_attr( $uid ); ?> .pg-count{font-size:.8rem;color:#888}
#<?php echo esc_attr( $uid ); ?> .pg-clear{
  background:none;border:none;color:var(--acc);font-size:.8rem;
  cursor:pointer;padding:0;text-decoration:underline;
}
#<?php echo esc_attr( $uid ); ?> .pg-grid{display:grid;gap:var(--gap)}
#<?php echo esc_attr( $uid ); ?> .pg-item{
  margin:0;border-radius:8px;overflow:hidden;
  background:#f8f9fa;
}
#<?php echo esc_attr( $uid ); ?> .pg-item img{
  width:100%;height:220px;object-fit:cover;display:block;
  transition:transform .25s ease;
}
#<?php echo esc_attr( $uid ); ?> .pg-item:hover img{transform:scale(1.04)}
#<?php echo esc_attr( $uid ); ?> .pg-item figcaption{
  font-size:.72rem;color:#555;padding:.35rem .5rem;
  line-height:1.35;background:#fff;
}
#<?php echo esc_attr( $uid ); ?> .pg-noresults{
  text-align:center;color:#aaa;padding:3rem 0;font-style:italic;
}
@media(max-width:600px){
  #<?php echo esc_attr( $uid ); ?> .pg-grid{grid-template-columns:repeat(2,1fr)!important}
}
</style>

<script>
(function () {
  var root   = document.getElementById(<?php echo json_encode( $uid ); ?>);
  var search = root.querySelector('.pg-search');
  var chips  = root.querySelectorAll('.pg-chip');
  var items  = root.querySelectorAll('.pg-item');
  var count  = root.querySelector('.pg-count');
  var clear  = root.querySelector('.pg-clear');
  var noRes  = root.querySelector('.pg-noresults');
  var total  = items.length;
  var active = new Set();

  function filter() {
    var q = search.value.trim().toLowerCase();
    var n = 0;
    items.forEach(function (el) {
      var tags  = el.dataset.tags ? el.dataset.tags.split('|') : [];
      var title = el.dataset.title || '';
      var ok = (active.size === 0 || [...active].every(function (t) { return tags.indexOf(t) !== -1; }))
            && (!q || title.indexOf(q) !== -1 || tags.some(function (t) { return t.indexOf(q) !== -1; }));
      el.hidden = !ok;
      if (ok) n++;
    });
    count.textContent = n + ' of ' + total + (total === 1 ? ' image' : ' images');
    noRes.hidden = n > 0;
    clear.hidden = active.size === 0 && !search.value;
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var tag = chip.dataset.tag;
      if (active.has(tag)) {
        active.delete(tag);
        chip.setAttribute('aria-pressed', 'false');
      } else {
        active.add(tag);
        chip.setAttribute('aria-pressed', 'true');
      }
      filter();
    });
  });

  var timer;
  search.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(filter, 150);
  });

  clear.addEventListener('click', function () {
    active.clear();
    search.value = '';
    chips.forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
    filter();
  });

  filter(); // initialise count on load
}());
</script>
    <?php
    return ob_get_clean();
} );
