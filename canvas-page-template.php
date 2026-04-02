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
      transition: opacity 0.5s ease,
                  transform 0.18s ease,
                  box-shadow 0.18s ease,
                  border-color 0.18s ease;
    }
    .card.visible { opacity: 1; }
    .card:hover {
      transform: translateY(-3px) scale(1.015);
      box-shadow: 0 20px 60px rgba(0,0,0,0.75);
      border-color: var(--border-hover);
      z-index: 99 !important;
      cursor: pointer;
    }
    .card-img img {
      display: block; width: 100%;
      background: #1e1e1e;
      pointer-events: none;
      -webkit-user-drag: none;
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
    .nav { display: flex; gap: 18px; pointer-events: all; }
    .nav a { font-size: 12px; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s; }
    .nav a:hover { color: rgba(255,255,255,0.75); }
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

  /* ── Config ── */
  const W        = 260;
  const GAP      = 15;
  const STRIDE   = W + GAP;
  const INFO_H   = 62;
  const FRICTION = 0.91;
  const BUF_X    = 3;    // extra kolommen buiten viewport
  const BUF_Y    = 700;  // extra pixels boven/onder viewport

  const ACCENTS = [
    '#111827','#1a1a2e','#16213e','#0f3460',
    '#1b1a2e','#2d132c','#0a1628','#0d2136',
    '#1a2416','#1e1a0a','#221010','#101822',
  ];

  /* ── Helpers ── */
  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
  }

  // Bepaal welk WP-item op positie (col, row) hoort — herhaalt cyclisch
  function itemAtPos(col, row) {
    const h = ( Math.abs(col * 73856093) ^ Math.abs(row < 0
      ? (-row) * 19349663 + 99999
      :   row  * 19349663) ) >>> 0;
    return WP_ITEMS[ h % WP_ITEMS.length ];
  }

  // Seeded RNG voor de kolom-stagger
  function seededRng(seed) {
    let s = (seed ^ 0xDEADBEEF) >>> 0;
    return () => { s = Math.imul(1664525, s) + 1013904223 | 0; return (s >>> 0) / 4294967296; };
  }

  function cardImgH(item) {
    if ( ! item.imgW || ! item.imgH ) return 200;
    return Math.max( Math.round( W * item.imgH / item.imgW ), 150 );
  }

  function totalCardH(item) {
    return item.img ? cardImgH(item) + INFO_H : 160;
  }

  /* ── Build card elements ── */
  function buildCard(item) {
    const el   = document.createElement('div');
    const imgH = cardImgH(item);

    if (item.img) {
      el.className = 'card card-img';
      el.innerHTML =
        `<img src="${esc(item.img)}" style="height:${imgH}px" loading="lazy" draggable="false">
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
      type:    item.img ? 'img' : 'colour',
      title:   item.title,
      desc:    item.desc,
      cat:     item.cat,
      year:    item.year,
      imgFull: item.imgFull || item.img,
      accent:  ACCENTS[ item.id % ACCENTS.length ],
      video:   item.video || '',
    };

    return el;
  }

  /* ── Transform ── */
  let ox = 0, oy = 0;

  function applyTransform() {
    canvas.style.transform = `translate(${ox}px,${oy}px)`;
  }

  /* ── Oneindige kolommenstructuur ── */
  // Elke kolom: { x, bottomY, topY, bi (volgende index omlaag), ti (volgende index omhoog) }
  const cols = new Map();
  let cardCounter = 0;

  function getCol(ci) {
    if ( ! cols.has(ci) ) {
      const r       = seededRng(ci * 31337 + 7);
      const stagger = Math.round( (r() * 2 - 1) * 140 );
      cols.set(ci, { x: ci * STRIDE, bottomY: stagger, topY: stagger, bi: 0, ti: -1 });
    }
    return cols.get(ci);
  }

  function pushBottom(ci) {
    const col  = getCol(ci);
    const item = itemAtPos(ci, col.bi);
    const h    = totalCardH(item);
    const el   = buildCard(item);
    el.style.cssText = `left:${col.x}px;top:${col.bottomY}px;width:${W}px;`;
    canvas.appendChild(el);
    setTimeout( () => el.classList.add('visible'), Math.min(cardCounter++ * 8, 300) );
    col.bottomY += h + GAP;
    col.bi++;
  }

  function pushTop(ci) {
    const col  = getCol(ci);
    const item = itemAtPos(ci, col.ti);
    const h    = totalCardH(item);
    const top  = col.topY - h - GAP;
    const el   = buildCard(item);
    el.style.cssText = `left:${col.x}px;top:${top}px;width:${W}px;`;
    canvas.appendChild(el);
    setTimeout( () => el.classList.add('visible'), Math.random() * 200 );
    col.topY = top;
    col.ti--;
  }

  function fill() {
    const vpW    = viewport.clientWidth;
    const vpH    = viewport.clientHeight;
    const left   = -ox - BUF_X * STRIDE;
    const right  = -ox + vpW + BUF_X * STRIDE;
    const top    = -oy - BUF_Y;
    const bottom = -oy + vpH + BUF_Y;
    const ci0    = Math.floor( left  / STRIDE );
    const ci1    = Math.ceil ( right / STRIDE );

    for ( let ci = ci0; ci <= ci1; ci++ ) {
      const col = getCol(ci);
      while ( col.bottomY < bottom ) pushBottom(ci);
      while ( col.topY    > top    ) pushTop(ci);
    }
  }

  /* ── Overlay ── */
  const overlayEl     = document.getElementById('overlay');
  const overlayVideo  = document.getElementById('overlay-video');
  const overlayImg    = document.getElementById('overlay-img');
  const overlayColour = document.getElementById('overlay-colour-block');
  const overlayCat    = document.getElementById('overlay-cat');
  const overlayYear   = document.getElementById('overlay-year');
  const overlayTitle  = document.getElementById('overlay-title');
  const overlayDesc   = document.getElementById('overlay-desc');

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
    // Directe videobestand (mp4, webm, etc.)
    return `<video src="${esc(url)}" controls autoplay playsinline></video>`;
  }

  function openOverlay(d) {
    // Standaard alles verbergen
    overlayVideo.style.display  = 'none';
    overlayImg.style.display    = 'none';
    overlayColour.style.display = 'none';

    if (d.video) {
      overlayVideo.innerHTML    = videoEmbedHtml(d.video);
      overlayVideo.style.display = 'block';
    } else if (d.type === 'img' && d.imgFull) {
      overlayImg.src           = d.imgFull;
      overlayImg.style.display = 'block';
    } else {
      overlayColour.style.display = 'flex';
      overlayColour.style.background = d.accent || '#161616';
      overlayColour.querySelector('.block-title').textContent = d.title;
    }

    overlayCat.textContent   = d.cat;
    overlayYear.textContent  = d.year;
    overlayTitle.textContent = d.title;
    overlayDesc.textContent  = d.desc;
    overlayEl.classList.add('open');
  }

  function closeOverlay() {
    overlayEl.classList.remove('open');
    overlayVideo.innerHTML = ''; // stop video bij sluiten
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
    fill();
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
    fill();
  }, { passive: true });

  viewport.addEventListener('touchend', () => {
    touch0 = null;
    momentum();
  });

  /* ── Wheel / Trackpad ── */
  viewport.addEventListener('wheel', e => {
    e.preventDefault();
    cancelAnimationFrame(raf);
    let dx = e.deltaX;
    let dy = e.deltaY;
    if (e.deltaMode === 1) { dx *= 20; dy *= 20; }
    if (e.deltaMode === 2) { dx *= window.innerHeight; dy *= window.innerHeight; }
    ox -= dx;
    oy -= dy;
    applyTransform();
    fill();
    dismissHint();
  }, { passive: false });

  /* ── Momentum ── */
  function momentum() {
    cancelAnimationFrame(raf);
    (function step() {
      vx *= FRICTION; vy *= FRICTION;
      if ( Math.abs(vx) < 0.08 && Math.abs(vy) < 0.08 ) return;
      ox += vx; oy += vy;
      applyTransform();
      fill();
      raf = requestAnimationFrame(step);
    })();
  }

  /* ── Keyboard ── */
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeOverlay(); return; }
    const S = 260;
    const moves = { ArrowLeft:[S,0], ArrowRight:[-S,0], ArrowUp:[0,S], ArrowDown:[0,-S] };
    if ( ! moves[e.key] ) return;
    e.preventDefault();
    cancelAnimationFrame(raf);
    [vx, vy] = moves[e.key].map(v => v / 14);
    ox += moves[e.key][0];
    oy += moves[e.key][1];
    applyTransform();
    fill();
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
  ox = Math.round( window.innerWidth  / 2 - W / 2 );
  oy = Math.round( window.innerHeight / 2 - 200 );
  applyTransform();
  fill();

  window.addEventListener('resize', fill);

})();
</script>
<?php wp_footer(); ?>
</body>
</html>
