<?php
/**
 * Template: Portfolio Canvas (full-screen, standalone — no theme header/footer)
 * Used by the Portfolio Canvas plugin.
 */

defined( 'ABSPATH' ) || exit;

// Stuur UTF-8 header vóór alle output
header( 'Content-Type: text/html; charset=UTF-8' );

/* ── Collect portfolio items ─────────────────────── */

$raw_posts = get_posts( [
    'post_type'      => 'portfolio_item',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'menu_order date',
    'order'          => 'ASC',
] );

$items = [];

foreach ( $raw_posts as $post ) {

    // Featured image — 'large' for card, 'full' for overlay
    $img_card = '';
    $img_full = '';
    $img_w    = 0;
    $img_h    = 0;

    if ( has_post_thumbnail( $post->ID ) ) {
        $thumb_id = get_post_thumbnail_id( $post->ID );

        $card_src = wp_get_attachment_image_src( $thumb_id, 'large' );
        if ( $card_src ) {
            $img_card = $card_src[0];
            $img_w    = (int) $card_src[1];
            $img_h    = (int) $card_src[2];
        }

        $full_src = wp_get_attachment_image_src( $thumb_id, 'full' );
        if ( $full_src ) {
            $img_full = $full_src[0];
        }
    }

    // Auto-thumbnail uit video als er geen featured image is
    $video = get_post_meta( $post->ID, 'portfolio_video', true ) ?: '';
    if ( ! $img_card && $video ) {
        $auto = portfolio_canvas_video_thumbnail( $video );
        if ( $auto ) {
            $img_card = $auto;
            $img_full = $auto;
            $img_w    = 480;
            $img_h    = 360;
        }
    }

    // Category (first term)
    $terms = get_the_terms( $post->ID, 'portfolio_cat' );
    $cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Work';

    // Year
    $year = get_post_meta( $post->ID, 'portfolio_year', true );
    if ( ! $year ) {
        $year = get_the_date( 'Y', $post );
    }

    // Description (excerpt)
    $desc = '';
    if ( $post->post_excerpt ) {
        $desc = wp_strip_all_tags( $post->post_excerpt );
    }

    // Galerij-afbeeldingen (extra)
    $gallery_raw  = get_post_meta( $post->ID, 'portfolio_gallery', true );
    $gallery_ids  = $gallery_raw ? array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) : [];
    $gallery_urls = [];
    foreach ( $gallery_ids as $gid ) {
        $gsrc = wp_get_attachment_image_src( $gid, 'full' );
        if ( $gsrc ) {
            $gallery_urls[] = $gsrc[0];
        }
    }

    // Decodeer HTML-entities (bijv. &#8217; → ') zodat de JSON
    // gewone Unicode-tekens bevat in plaats van escaped entities.
    $decode = function ( $str ) {
        return html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    };

    $items[] = [
        'id'      => $post->ID,
        'title'   => $decode( get_the_title( $post ) ),
        'desc'    => $decode( $desc ),
        'cat'     => $decode( $cat ),
        'year'    => $year,
        'img'     => $img_card,
        'imgFull' => $img_full ?: $img_card,
        'imgW'    => $img_w,
        'imgH'    => $img_h,
        'video'   => $video,
        'gallery' => $gallery_urls,
    ];
}

$items_json = wp_json_encode( $items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
$page_title = get_the_title( get_queried_object_id() );
$site_name  = get_bloginfo( 'name' );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( $page_title . ' — ' . $site_name ); ?></title>
  <?php wp_head(); ?>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --bg: #0b0b0b;
      --card: #161616;
      --card-hover: #1d1d1d;
      --border: rgba(255,255,255,0.07);
      --border-hover: rgba(255,255,255,0.14);
      --text1: #e0e0e0;
      --text3: #444;
      --radius: 10px;
    }

    html, body {
      height: 100%; width: 100%;
      overflow: hidden;
      background: var(--bg);
      font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;
      color: var(--text1);
    }

    #viewport {
      position: fixed; inset: 0;
      overflow: hidden;
      cursor: grab;
      -webkit-user-select: none;
      user-select: none;
    }
    #viewport.grabbing { cursor: grabbing; }
    #viewport::after {
      content: '';
      position: fixed; inset: 0;
      pointer-events: none;
      background: radial-gradient(ellipse at center,
        transparent 55%,
        rgba(255,255,255,0.04) 78%,
        rgba(255,255,255,0.10) 100%
      );
      z-index: 400;
    }

    #canvas {
      position: absolute; top: 0; left: 0;
      will-change: transform;
      transform-origin: 0 0;
    }

    /* ── Cards ── */
    .card {
      position: absolute;
      border-radius: var(--radius);
      overflow: hidden;
      background: var(--card);
      border: 1px solid var(--border);
      opacity: 0;
      contain: layout style paint;
      transform: rotate(var(--r, 0deg));
      transition: opacity 0.5s ease,
                  transform 0.18s ease,
                  box-shadow 0.18s ease,
                  border-color 0.18s ease;
    }
    .card.visible { opacity: 1; }
    .card:hover {
      transform: translateY(-3px) scale(1.015) rotate(var(--r, 0deg));
      box-shadow: 0 20px 60px rgba(0,0,0,0.75);
      border-color: var(--border-hover);
      z-index: 99 !important;
      cursor: pointer;
    }
    .card-img img, .card-img video {
      display: block; width: 100%;
      background: #1e1e1e;
      pointer-events: none;
      -webkit-user-drag: none;
      object-fit: cover;
    }
    .card-direct-video video {
      object-fit: contain;
    }
    .card-info {
      padding: 11px 14px 14px;
      display: flex; flex-direction: column; gap: 3px;
    }
    .meta-row {
      display: flex; justify-content: space-between; align-items: center;
    }
    .cat {
      font-size: 10px; font-weight: 600;
      letter-spacing: 0.1em; text-transform: uppercase;
      color: var(--text3);
    }
    .year { font-size: 10px; color: var(--text3); }
    .title {
      font-size: 13px; font-weight: 500;
      color: var(--text1); line-height: 1.45;
    }
    .card-colour .colour-body {
      padding: 22px 18px; height: 100%;
      display: flex; flex-direction: column; justify-content: space-between;
    }
    .card-colour .colour-cat {
      font-size: 10px; font-weight: 600;
      letter-spacing: 0.1em; text-transform: uppercase;
      color: rgba(255,255,255,0.28);
    }
    .card-colour .colour-title {
      font-size: 19px; font-weight: 600;
      color: rgba(255,255,255,0.82);
      line-height: 1.3; letter-spacing: -0.02em;
    }

    /* ── Instagram-kaart ── */
    .card-instagram .ig-body {
      height: 220px;
      background: linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
      display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 10px;
    }
    .card-instagram .ig-icon {
      width: 38px; height: 38px; opacity: 0.9;
    }
    .card-instagram .ig-label {
      font-size: 12px; font-weight: 600;
      color: rgba(255,255,255,0.85); letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    /* ── Play-knop op kaart ── */
    .card-play {
      position: absolute;
      top: 10px; right: 10px;
      width: 34px; height: 34px;
      background: rgba(0,0,0,0.55);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: rgba(255,255,255,0.85);
      font-size: 11px;
      pointer-events: none;
      transition: background 0.2s, transform 0.2s;
    }
    .card:hover .card-play {
      background: rgba(255,255,255,0.18);
      transform: scale(1.12);
    }

    /* ── Video in overlay ── */
    #overlay-video {
      display: none;
      width: 100%;
      aspect-ratio: 16 / 9;
      background: #000;
    }
    #overlay-video iframe,
    #overlay-video video {
      width: 100%; height: 100%;
      display: block; border: none;
    }

    /* ── Galerij in overlay ── */
    #overlay-gallery {
      display: none;
      position: relative;
    }
    #overlay-gallery-img {
      display: block; width: 100%; max-height: 80vh;
      object-fit: contain; background: #1c1c1c;
    }
    .gallery-nav {
      position: absolute; top: 50%; transform: translateY(-50%);
      width: 36px; height: 36px;
      background: rgba(0,0,0,0.55);
      backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
      border: none; border-radius: 50%;
      color: rgba(255,255,255,0.8); font-size: 20px;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.2s, transform 0.2s;
      z-index: 2; line-height: 1;
    }
    .gallery-nav:hover { background: rgba(255,255,255,0.15); transform: translateY(-50%) scale(1.1); }
    #overlay-prev { left: 10px; }
    #overlay-next { right: 10px; }
    #overlay-gallery-counter {
      position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
      font-size: 11px; color: rgba(255,255,255,0.45);
      background: rgba(0,0,0,0.45);
      padding: 3px 10px; border-radius: 100px;
      pointer-events: none;
    }

    /* ── Overlay ── */
    #overlay {
      position: fixed; inset: 0; z-index: 800;
      display: flex; align-items: center; justify-content: center;
      padding: 32px 20px;
      background: rgba(0,0,0,0);
      backdrop-filter: blur(0px);
      -webkit-backdrop-filter: blur(0px);
      transition: background 0.28s ease, backdrop-filter 0.28s ease;
      pointer-events: none;
    }
    #overlay.open {
      background: rgba(0,0,0,0.74);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      pointer-events: all;
      cursor: default;
    }
    #overlay-panel {
      position: relative;
      max-width: 620px; width: 100%;
      border-radius: 14px; overflow: hidden;
      background: #131313;
      border: 1px solid rgba(255,255,255,0.09);
      box-shadow: 0 48px 120px rgba(0,0,0,0.9);
      transform: scale(0.93) translateY(16px); opacity: 0;
      transition: transform 0.32s cubic-bezier(0.34, 1.4, 0.64, 1),
                  opacity 0.22s ease;
    }
    #overlay.open #overlay-panel { transform: scale(1) translateY(0); opacity: 1; }
    #overlay-img {
      display: block; width: 100%; max-height: 80vh;
      object-fit: contain; background: #1c1c1c;
    }
    #overlay-colour-block {
      width: 100%; height: 260px;
      display: none; align-items: flex-end; padding: 28px;
    }
    #overlay-colour-block .block-title {
      font-size: 26px; font-weight: 600;
      color: rgba(255,255,255,0.78);
      letter-spacing: -0.025em; line-height: 1.3;
    }
    #overlay-info { padding: 18px 22px 24px; border-top: 1px solid rgba(255,255,255,0.06); }
    #overlay-meta {
      display: flex; justify-content: space-between;
      align-items: center; margin-bottom: 7px;
    }
    #overlay-cat { font-size: 10px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: #505050; }
    #overlay-year { font-size: 11px; color: #444; }
    #overlay-title { font-size: 17px; font-weight: 600; color: #e4e4e4; letter-spacing: -0.02em; line-height: 1.35; margin-bottom: 9px; }
    #overlay-desc { font-size: 13px; line-height: 1.65; color: #5a5a5a; }
    #overlay-close {
      position: absolute; top: 18px; right: 22px;
      width: 30px; height: 30px;
      display: flex; align-items: center; justify-content: center;
      background: none; border: none;
      color: rgba(255,255,255,0.22);
      font-size: 16px; line-height: 1; cursor: pointer;
      border-radius: 50%;
      opacity: 0;
      transition: opacity 0.25s ease, color 0.2s ease, background 0.2s ease;
    }
    #overlay.open #overlay-close { opacity: 1; }
    #overlay-close:hover { color: rgba(255,255,255,0.65); background: rgba(255,255,255,0.07); }

    /* ── Header ── */
    #header {
      position: fixed; top: 0; left: 0; right: 0;
      padding: 22px 26px;
      display: flex; justify-content: space-between; align-items: flex-start;
      z-index: 500; pointer-events: none;
    }
    .logo { font-size: 14px; font-weight: 600; letter-spacing: -0.025em; color: rgba(255,255,255,0.88); }
    .subline { font-size: 11px; color: rgba(255,255,255,0.28); margin-top: 3px; }
    .nav { display: flex; gap: 18px; pointer-events: all; align-items: center; }
    .nav a { font-size: 12px; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s; }
    .nav a:hover { color: rgba(255,255,255,0.75); }
    .nav a:first-child { font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.75); letter-spacing: -0.01em; }
    .nav a:first-child:hover { color: rgba(255,255,255,1); }
    .logo-link {
      text-decoration: none;
      color: inherit;
      transition: opacity 0.2s;
    }
    .logo-link:hover { opacity: 0.6; }

    /* ── Hint ── */
    #hint {
      position: fixed; bottom: 24px; left: 50%;
      transform: translateX(-50%); z-index: 500;
      background: rgba(255,255,255,0.07);
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255,255,255,0.1); border-radius: 100px;
      padding: 8px 20px; font-size: 12px; color: rgba(255,255,255,0.38);
      pointer-events: none; white-space: nowrap;
      transition: opacity 0.6s ease;
    }
    #hint.hidden { opacity: 0; }

    /* ── Empty state ── */
    #empty-state {
      position: fixed; inset: 0;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 12px; text-align: center;
      color: rgba(255,255,255,0.3);
      font-size: 14px; line-height: 1.6;
    }
    #empty-state strong { color: rgba(255,255,255,0.55); }
  </style>
</head>
<body>

  <div id="header">
    <div>
      <div class="logo"><?php echo esc_html( $page_title ); ?></div>
      <a class="subline logo-link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $site_name ); ?></a>
    </div>
    <nav class="nav">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
      <?php if ( has_nav_menu( 'portfolio_nav' ) ) : ?>
        <?php wp_nav_menu( [ 'theme_location' => 'portfolio_nav', 'container' => false, 'items_wrap' => '%3$s' ] ); ?>
      <?php endif; ?>
    </nav>
  </div>

  <div id="viewport">
    <div id="canvas"></div>
  </div>

  <div id="overlay">
    <button id="overlay-close" aria-label="Close">✕</button>
    <div id="overlay-panel">
      <div id="overlay-video"></div>
      <div id="overlay-gallery">
        <img id="overlay-gallery-img" src="" alt="">
        <button id="overlay-prev" class="gallery-nav" aria-label="Vorige">&#8249;</button>
        <button id="overlay-next" class="gallery-nav" aria-label="Volgende">&#8250;</button>
        <div id="overlay-gallery-counter"></div>
      </div>
      <img id="overlay-img" src="" alt="">
      <div id="overlay-colour-block"><span class="block-title"></span></div>
      <div id="overlay-info">
        <div id="overlay-meta">
          <span id="overlay-cat"></span>
          <span id="overlay-year"></span>
        </div>
        <div id="overlay-title"></div>
        <div id="overlay-desc"></div>
      </div>
    </div>
  </div>

  <div id="hint">Scroll or drag to explore</div>

<?php if ( empty( $items ) ) : ?>
  <div id="empty-state">
    <p>No portfolio items yet.</p>
    <p>Go to <strong>Portfolio → Add New</strong> in the WordPress admin to add your first item.</p>
  </div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  const WP_ITEMS = <?php echo $items_json; // phpcs:ignore WordPress.Security.EscapeOutput ?>;

  if ( ! WP_ITEMS || ! WP_ITEMS.length ) return;

  const canvas   = document.getElementById('canvas');
  const viewport = document.getElementById('viewport');
  const hint     = document.getElementById('hint');

  /* ── Helpers ── */
  function isDirectVideo(url) {
    return url && /\.(mp4|webm|ogg|mov)(\?|#|$)/i.test(url);
  }

/* ── Config ── */
  const W        = 260;
  const GAP      = 15;
  const STRIDE   = W + GAP;
  const INFO_H   = 62;
  const FRICTION = 0.91;
  const SPRING   = 0.12;
  const R        = STRIDE + 25;    // base ring radius ≈ 300px — tighter, more uniform spacing

  let canvasW = 0, canvasH = 0;

  const ACCENTS = [
    '#111827','#1a1a2e','#16213e','#0f3460',
    '#1b1a2e','#2d132c','#0a1628','#0d2136',
    '#1a2416','#1e1a0a','#221010','#101822',
  ];

  /* ── HTML-escaper ── */
  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
  }

  function cardImgH(item) {
    if ( ! item.imgW || ! item.imgH ) return 200;
    return Math.max( Math.round( W * item.imgH / item.imgW ), 150 );
  }

  function totalCardH(item) {
    if (item.img) return cardImgH(item) + INFO_H;
    if (!item.img && isDirectVideo(item.video)) return 200 + INFO_H;
    return 160;
  }

  /* ── Capture first frame of a local/same-origin video as a data URL ── */
  function captureVideoFirstFrame(videoUrl, callback) {
    const v = document.createElement('video');
    v.muted       = true;
    v.playsInline = true;
    v.preload     = 'metadata';
    v.addEventListener('loadeddata', function onData() {
      v.removeEventListener('loadeddata', onData);
      v.currentTime = 0.25;
    });
    v.addEventListener('seeked', function onSeeked() {
      v.removeEventListener('seeked', onSeeked);
      try {
        const c   = document.createElement('canvas');
        c.width   = v.videoWidth  || 480;
        c.height  = v.videoHeight || 360;
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        callback(c.toDataURL('image/jpeg', 0.75), c.width, c.height);
      } catch (e) {
        callback(null, 0, 0); // cross-origin or other error — silently skip
      }
    });
    v.addEventListener('error', function() { callback(null); });
    v.src = videoUrl;
  }

  /* ── Build card elements ── */
  function buildCard(item) {
    const el   = document.createElement('div');
    const imgH = cardImgH(item);
    el.dataset.itemId = item.id;

    const isInstagram = item.video && /instagram\.com/.test(item.video);

    if (item.img) {
      el.className = 'card card-img';
      // Directe videobestanden: autoplay muted op de kaart
      const mediaEl = isDirectVideo(item.video)
        ? `<video src="${esc(item.video)}" poster="${esc(item.img)}"
                  style="height:${imgH}px" autoplay muted loop playsinline
                  draggable="false"></video>`
        : `<img src="${esc(item.img)}" style="height:${imgH}px" loading="lazy" draggable="false">`;
      el.innerHTML =
        `${mediaEl}
         <div class="card-info">
           <div class="meta-row">
             <span class="cat">${esc(item.cat)}</span>
             <span class="year">${esc(item.year)}</span>
           </div>
           <div class="title">${esc(item.title)}</div>
         </div>`;
    } else if (isDirectVideo(item.video)) {
      el.className = 'card card-img card-direct-video';
      el.innerHTML =
        `<video src="${esc(item.video)}" style="height:200px" autoplay muted loop playsinline draggable="false"></video>
         <div class="card-info">
           <div class="meta-row">
             <span class="cat">${esc(item.cat)}</span>
             <span class="year">${esc(item.year)}</span>
           </div>
           <div class="title">${esc(item.title)}</div>
         </div>`;
    } else if (isInstagram) {
      el.className = 'card card-instagram';
      el.innerHTML =
        `<div class="ig-body">
           <svg class="ig-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
             <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="white" stroke-width="1.8" fill="none"/>
             <circle cx="12" cy="12" r="4.5" stroke="white" stroke-width="1.8" fill="none"/>
             <circle cx="17.5" cy="6.5" r="1" fill="white"/>
           </svg>
           <span class="ig-label">Instagram</span>
         </div>
         <div class="card-info">
           <div class="meta-row">
             <span class="cat">${esc(item.cat)}</span>
             <span class="year">${esc(item.year)}</span>
           </div>
           <div class="title">${esc(item.title)}</div>
         </div>`;
    } else {
      const accent = ACCENTS[ item.id % ACCENTS.length ];
      el.className = 'card card-colour';
      el.style.background = accent;
      el.innerHTML =
        `<div class="colour-body">
           <div class="colour-cat">${esc(item.cat)}</div>
           <div class="colour-title">${esc(item.title)}</div>
         </div>`;
    }

    // Voeg play-indicator toe als het item een video heeft
    if (item.video) {
      const play = document.createElement('div');
      play.className = 'card-play';
      play.textContent = '▶';
      el.appendChild(play);
    }

    el._cardData = {
      id:      item.id,
      type:    item.img ? 'img' : 'colour',
      title:   item.title,
      desc:    item.desc,
      cat:     item.cat,
      year:    item.year,
      imgFull: item.imgFull || item.img,
      accent:  ACCENTS[ item.id % ACCENTS.length ],
      video:   item.video || '',
      gallery: item.gallery || [],
    };

    return el;
  }

  /* ── Transform ── */
  let ox = 0, oy = 0;

  function applyTransform() {
    canvas.style.transform = `translate(${ox}px,${oy}px)`;
  }

  function playCardVideo(el) {
    const vid = el.querySelector('video');
    if (vid) vid.play().catch(function () {});
  }

  /* ── Layout: organic scatter cluster ── */
  function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  }

  function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }

  function layoutItems() {
    const PAD   = 200;
    const items = shuffle([...WP_ITEMS]);
    const n     = items.length;

    // Build ring slot positions (ring 0 = centre, ring k has k*6 slots at radius k*R)
    const slots = [{ x: 0, y: 0 }];
    for (let ring = 1; slots.length < n; ring++) {
      const radius    = ring * R;
      const slotCount = ring * 6;
      for (let s = 0; s < slotCount && slots.length < n; s++) {
        const angle = (2 * Math.PI * s) / slotCount;
        slots.push({ x: Math.cos(angle) * radius, y: Math.sin(angle) * radius });
      }
    }

    // Apply jitter (±14px organic variation) and rotation (±2°)
    const jitter = 14;
    const placed = [];

    for (let i = 0; i < n; i++) {
      const item = items[i];
      const sl   = slots[i];
      const x    = sl.x + (Math.random() * 2 - 1) * jitter;
      const y    = sl.y + (Math.random() * 2 - 1) * jitter;
      const rot  = (Math.random() * 2 - 1) * 2;
      const h    = totalCardH(item);
      placed.push({ item, x, y, rot, h });
    }

    // Collision resolution: iteratively push overlapping cards apart (min 20px gap)
    const MIN_GAP = 20;
    for (let iter = 0; iter < 200; iter++) {
      let moved = false;
      for (let a = 0; a < n; a++) {
        for (let b = a + 1; b < n; b++) {
          const pa = placed[a], pb = placed[b];
          const rawOx = Math.min(pa.x + W, pb.x + W) - Math.max(pa.x, pb.x);
          const rawOy = Math.min(pa.y + pa.h, pb.y + pb.h) - Math.max(pa.y, pb.y);
          const pushX = rawOx + MIN_GAP;
          const pushY = rawOy + MIN_GAP;
          if (pushX > 0 && pushY > 0) {
            moved = true;
            if (pushX <= pushY) {
              const half = pushX / 2;
              if (pa.x <= pb.x) { pa.x -= half; pb.x += half; }
              else               { pa.x += half; pb.x -= half; }
            } else {
              const half = pushY / 2;
              if (pa.y <= pb.y) { pa.y -= half; pb.y += half; }
              else               { pa.y += half; pb.y -= half; }
            }
          }
        }
      }
      if (!moved) break;
    }

    // Compute bounding box after resolution
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const { x, y, h } of placed) {
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x + W);
      maxY = Math.max(maxY, y + h);
    }

    canvasW = Math.ceil(maxX - minX) + PAD * 2;
    canvasH = Math.ceil(maxY - minY) + PAD * 2;
    canvas.style.width  = canvasW + 'px';
    canvas.style.height = canvasH + 'px';

    placed.forEach(({ item, x, y, rot }, i) => {
      const el = buildCard(item);
      const cx = Math.round(x - minX + PAD);
      const cy = Math.round(y - minY + PAD);
      el.style.cssText = `left:${cx}px;top:${cy}px;width:${W}px;--r:${rot.toFixed(2)}deg;`;
      canvas.appendChild(el);
      playCardVideo(el);
      // Auto-thumbnail for direct-video cards without a featured image
      if (!item.img && isDirectVideo(item.video)) {
        captureVideoFirstFrame(item.video, function(dataUrl, vw, vh) {
          if (!dataUrl) return;
          const vid = el.querySelector('video');
          if (vid) {
            const imgH = (vw && vh) ? Math.max(Math.round(W * vh / vw), 150) : 200;
            vid.style.height = imgH + 'px';
            vid.poster = dataUrl;
            el.classList.remove('card-direct-video');
          }
          if (!el._cardData.imgFull) el._cardData.imgFull = dataUrl;
        });
      }
      setTimeout(() => el.classList.add('visible'), Math.min(i * 8, 300));
    });

    ox = Math.round((viewport.clientWidth  - canvasW) / 2);
    oy = Math.round((viewport.clientHeight - canvasH) / 2);
    applyTransform();
  }

  /* ── Overlay ── */
  const overlayEl      = document.getElementById('overlay');
  const overlayVideo   = document.getElementById('overlay-video');
  const overlayImg     = document.getElementById('overlay-img');
  const overlayGallery = document.getElementById('overlay-gallery');
  const overlayGallImg = document.getElementById('overlay-gallery-img');
  const overlayCounter = document.getElementById('overlay-gallery-counter');
  const overlayPrev    = document.getElementById('overlay-prev');
  const overlayNext    = document.getElementById('overlay-next');
  const overlayColour  = document.getElementById('overlay-colour-block');
  const overlayCat     = document.getElementById('overlay-cat');
  const overlayYear    = document.getElementById('overlay-year');
  const overlayTitle   = document.getElementById('overlay-title');
  const overlayDesc    = document.getElementById('overlay-desc');

  let galleryImages = [];
  let galleryIdx    = 0;

  function showGallerySlide(idx) {
    galleryIdx = ((idx % galleryImages.length) + galleryImages.length) % galleryImages.length;
    overlayGallImg.src       = galleryImages[galleryIdx];
    overlayCounter.textContent = (galleryIdx + 1) + ' / ' + galleryImages.length;
  }
  overlayPrev.addEventListener('click', e => { e.stopPropagation(); showGallerySlide(galleryIdx - 1); });
  overlayNext.addEventListener('click', e => { e.stopPropagation(); showGallerySlide(galleryIdx + 1); });

  // Zet een video-URL om naar een embed-HTML-string
  function videoEmbedHtml(url) {
    const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
    if (yt) {
      return `<iframe src="https://www.youtube.com/embed/${yt[1]}?autoplay=1&rel=0&modestbranding=1"
                      allow="autoplay; fullscreen" allowfullscreen></iframe>`;
    }
    const vimeo = url.match(/vimeo\.com\/(\d+)/);
    if (vimeo) {
      return `<iframe src="https://player.vimeo.com/video/${vimeo[1]}?autoplay=1&title=0&byline=0"
                      allow="autoplay; fullscreen" allowfullscreen></iframe>`;
    }
    const ig = url.match(/instagram\.com\/(?:p|reel|tv)\/([a-zA-Z0-9_-]+)/);
    if (ig) {
      return `<iframe src="https://www.instagram.com/p/${ig[1]}/embed/"
                      scrolling="no" allowtransparency="true"
                      style="width:100%;height:100%;border:none"></iframe>`;
    }
    // Directe videobestand (mp4, webm, etc.)
    return `<video src="${esc(url)}" controls autoplay playsinline></video>`;
  }

  // Bepaal of een URL een Instagram-post is
  function isInstagramUrl(url) {
    return url && /instagram\.com\/(?:p|reel|tv)\//.test(url);
  }

  function openOverlay(d) {
    // Alles verbergen
    overlayVideo.style.display   = 'none';
    overlayImg.style.display     = 'none';
    overlayGallery.style.display = 'none';
    overlayColour.style.display  = 'none';
    galleryImages = [];

    if (d.video) {
      // Video (YouTube / Vimeo / Instagram / directe mp4)
      overlayVideo.innerHTML = videoEmbedHtml(d.video);
      overlayVideo.style.aspectRatio = isInstagramUrl(d.video) ? 'unset' : '16 / 9';
      overlayVideo.style.minHeight   = isInstagramUrl(d.video) ? '540px'  : 'unset';
      overlayVideo.style.display     = 'block';
    } else {
      // Bouw lijst van alle afbeeldingen: featured + galerij
      const allImgs = [];
      if (d.imgFull) allImgs.push(d.imgFull);
      (d.gallery || []).forEach(u => allImgs.push(u));

      if (allImgs.length > 1) {
        // Galerij-modus
        galleryImages = allImgs;
        overlayGallery.style.display = 'block';
        showGallerySlide(0);
      } else if (d.type === 'img' && d.imgFull) {
        overlayImg.src           = d.imgFull;
        overlayImg.style.display = 'block';
      } else {
        overlayColour.style.display = 'flex';
        overlayColour.style.background = d.accent || '#161616';
        overlayColour.querySelector('.block-title').textContent = d.title;
      }
    }

    overlayCat.textContent   = d.cat;
    overlayYear.textContent  = d.year;
    overlayTitle.textContent = d.title;
    overlayDesc.textContent  = d.desc;
    overlayEl.classList.add('open');
  }

  function closeOverlay() {
    overlayEl.classList.remove('open');
    overlayVideo.innerHTML = '';
    galleryImages = [];
  }

  overlayEl.addEventListener('click', e => {
    if ( ! e.target.closest('#overlay-panel') || e.target.id === 'overlay-close' ) {
      closeOverlay();
    }
  });

  /* ── Pan (mouse) ── */
  let dragging  = false;
  let dragMoved = false;
  let mx0, my0, ox0, oy0;
  let vx = 0, vy = 0;
  let lmx, lmy, lt;
  let raf;

  viewport.addEventListener('mousedown', e => {
    if (e.button !== 0) return;
    dragging  = true;
    dragMoved = false;
    cancelAnimationFrame(raf);
    mx0 = e.clientX; my0 = e.clientY;
    ox0 = ox;        oy0 = oy;
    lmx = e.clientX; lmy = e.clientY; lt = Date.now();
    vx = 0; vy = 0;
    viewport.classList.add('grabbing');
    dismissHint();
  });

  window.addEventListener('mousemove', e => {
    if ( ! dragging ) return;
    if ( Math.abs(e.clientX - mx0) > 4 || Math.abs(e.clientY - my0) > 4 ) dragMoved = true;
    const now = Date.now();
    const dt  = Math.max(now - lt, 1);
    vx = (e.clientX - lmx) / dt * 16;
    vy = (e.clientY - lmy) / dt * 16;
    lmx = e.clientX; lmy = e.clientY; lt = now;
    ox = ox0 + (e.clientX - mx0);
    oy = oy0 + (e.clientY - my0);
    applyTransform();
  });

  window.addEventListener('mouseup', e => {
    if ( ! dragging ) return;
    dragging = false;
    viewport.classList.remove('grabbing');
    if ( ! dragMoved ) {
      const card = e.target.closest('.card');
      if ( card && card._cardData ) { openOverlay(card._cardData); return; }
    }
    momentum();
  });

  /* ── Pan (touch) ── */
  let touch0 = null;

  viewport.addEventListener('touchstart', e => {
    touch0 = e.touches[0];
    ox0 = ox; oy0 = oy;
    lmx = touch0.clientX; lmy = touch0.clientY; lt = Date.now();
    vx = 0; vy = 0;
    cancelAnimationFrame(raf);
    dismissHint();
  }, { passive: true });

  viewport.addEventListener('touchmove', e => {
    if ( ! touch0 ) return;
    const t   = e.touches[0];
    const now = Date.now();
    const dt  = Math.max(now - lt, 1);
    vx = (t.clientX - lmx) / dt * 16;
    vy = (t.clientY - lmy) / dt * 16;
    lmx = t.clientX; lmy = t.clientY; lt = now;
    ox = ox0 + (t.clientX - touch0.clientX);
    oy = oy0 + (t.clientY - touch0.clientY);
    applyTransform();
  }, { passive: true });

  viewport.addEventListener('touchend', () => {
    touch0 = null;
    momentum();
  });

  /* ── Wheel / Trackpad ── */
  let wheelRaf = 0;
  viewport.addEventListener('wheel', e => {
    e.preventDefault();
    let dx = e.deltaX;
    let dy = e.deltaY;
    if (e.deltaMode === 1) { dx *= 20; dy *= 20; }
    if (e.deltaMode === 2) { dx *= window.innerHeight; dy *= window.innerHeight; }
    ox -= dx;
    oy -= dy;
    applyTransform();
    dismissHint();
    // Trigger spring correction after scroll settles
    if ( ! wheelRaf ) {
      wheelRaf = requestAnimationFrame(() => { wheelRaf = 0; vx = 0; vy = 0; momentum(); });
    }
  }, { passive: false });

  /* ── Momentum + spring bounce ── */
  function momentum() {
    cancelAnimationFrame(raf);
    (function step() {
      vx *= FRICTION; vy *= FRICTION;
      ox += vx; oy += vy;

      const bMinX = viewport.clientWidth  - canvasW;
      const bMinY = viewport.clientHeight - canvasH;
      const tx = clamp(ox, bMinX, 0);
      const ty = clamp(oy, bMinY, 0);
      ox += (tx - ox) * SPRING;
      oy += (ty - oy) * SPRING;

      applyTransform();

      const atRest = Math.abs(vx) < 0.08 && Math.abs(vy) < 0.08
                  && Math.abs(ox - tx) < 0.5 && Math.abs(oy - ty) < 0.5;
      if (!atRest) raf = requestAnimationFrame(step);
    })();
  }

  /* ── Keyboard ── */
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeOverlay(); return; }
    // Galerij-navigatie als de overlay open is met meerdere afbeeldingen
    if (overlayEl.classList.contains('open') && galleryImages.length > 1) {
      if (e.key === 'ArrowLeft')  { e.preventDefault(); showGallerySlide(galleryIdx - 1); return; }
      if (e.key === 'ArrowRight') { e.preventDefault(); showGallerySlide(galleryIdx + 1); return; }
    }
    const S = 260;
    const moves = { ArrowLeft:[S,0], ArrowRight:[-S,0], ArrowUp:[0,S], ArrowDown:[0,-S] };
    if ( ! moves[e.key] ) return;
    e.preventDefault();
    cancelAnimationFrame(raf);
    [vx, vy] = moves[e.key].map(v => v / 14);
    ox += moves[e.key][0];
    oy += moves[e.key][1];
    applyTransform();
    momentum();
    dismissHint();
  });

  /* ── Hint ── */
  let hintDismissed = false;
  function dismissHint() {
    if (hintDismissed) return;
    hintDismissed = true;
    hint.classList.add('hidden');
  }
  setTimeout(dismissHint, 6000);

  /* ── Init ── */
  layoutItems();

  /* ── Deep-link: open overlay for #item-{id} ── */
  (function () {
    const m = window.location.hash.match(/^#item-(\d+)$/);
    if ( ! m ) return;
    const targetId = parseInt(m[1], 10);
    const item = WP_ITEMS.find(function (i) { return i.id === targetId; });
    if ( ! item ) return;
    openOverlay({
      id:      item.id,
      type:    item.img ? 'img' : 'colour',
      title:   item.title,
      desc:    item.desc,
      cat:     item.cat,
      year:    item.year,
      imgFull: item.imgFull || item.img,
      accent:  ACCENTS[ item.id % ACCENTS.length ],
      video:   item.video || '',
      gallery: item.gallery || [],
    });
  })();


})();
</script>
<?php wp_footer(); ?>
</body>
</html>
