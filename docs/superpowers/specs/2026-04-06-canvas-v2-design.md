# Portfolio Canvas v2.0 Design Spec

**Date:** 2026-04-06
**Status:** Approved

## Overview

Replace the infinite repeating grid with a finite organic scatter cluster. Every portfolio item appears exactly once. The canvas has a soft glow border that prevents scrolling into the void. The cluster grows outward in all directions as items are added.

---

## 1. Layout Algorithm

### Shuffle
On each page load, the item array is shuffled using `Math.random()` (Fisher-Yates). Layout is non-deterministic — the arrangement is fresh every load.

### Ring placement
Items are assigned to concentric ring slots in order after shuffling:

| Ring | Slot count | Radius             |
|------|-----------|---------------------|
| 0    | 1         | 0 (center)          |
| 1    | 6         | 1 × R               |
| 2    | 12        | 2 × R               |
| N≥1  | N × 6     | N × R               |

Base ring radius `R = STRIDE * 1.5` (where `STRIDE = W + GAP = 275px`), so `R ≈ 412px`. This ensures adjacent ring slots have enough space for cards without overlapping even after jitter is applied. The number of rings used is the smallest `K` such that `1 + 6×(1+2+…+K) ≥ itemCount`.

### Per-item jitter & rotation
Each item receives:
- **Position jitter:** random offset of ±20% of ring spacing in both X and Y axes
- **Rotation:** random value in the range ±2°, applied via CSS `transform: rotate()`

Both values are computed once at layout time and stored on the card element.

### One-time render
`layoutItems()` runs once on load:
1. Shuffle items
2. Compute ring slot positions
3. Apply jitter and rotation to each slot
4. Call `buildCard()` for each item
5. Position cards absolutely on the canvas
6. Set canvas explicit width/height to bounding box + padding
7. Fade cards in with staggered animation (existing behaviour retained)

No virtual scrolling, no DOM culling — all cards are in the DOM at once.

---

## 2. Canvas & Border

### Canvas sizing
After layout, the canvas gets an explicit `width` and `height` equal to the bounding box of all placed cards plus **200px padding on all sides**. The padding accounts for card dimensions, jitter offsets, and rotation overhang, ensuring no card is clipped.

The canvas does **not** have `overflow: hidden` — card elements are never cropped.

### Soft glow border
Implemented as a `::after` pseudo-element on `#viewport` with a radial gradient overlay:

```css
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
```

This creates a subtle rim that brightens slightly at the viewport edges — you feel the boundary rather than see a hard line. As the user pans toward the canvas boundary the glow becomes visually apparent just before the elastic bounce kicks in. No separate border DOM element is needed.

---

## 3. Pan & Elastic Bounce

### Existing behaviour retained
- Pointer drag (mouse + touch)
- Momentum with `FRICTION = 0.91` decay
- `applyTransform()` via `translate(ox, oy)`

### Boundary clamping
Each animation frame, the canvas offset is checked against the canvas bounds. The allowed offset range is:

```
minX = viewport.width  - canvas.width
maxX = 0
minY = viewport.height - canvas.height
maxY = 0
```

### Spring bounce
If the offset is outside the allowed range, a spring force pulls it back:

```js
const SPRING = 0.12;
ox += (clamp(ox, minX, maxX) - ox) * SPRING;
oy += (clamp(oy, minY, maxY) - oy) * SPRING;
```

During active drag, the user can pull slightly past the boundary (rubber-band stretch). On release, the spring force snaps the canvas back into bounds. Feel is equivalent to iOS overscroll.

### Initial viewport position
On load, the canvas is centered in the viewport:

```js
ox = (viewport.width  - canvas.width)  / 2;
oy = (viewport.height - canvas.height) / 2;
```

---

## 4. Removed Systems

The following are deleted entirely:

| Removed | Replaced by |
|---------|-------------|
| `itemAtPos()` — seeded cyclic shuffle | `layoutItems()` one-time random shuffle |
| `cols` Map | — |
| `pushBottom()`, `pushTop()` | — |
| `maybeCull()` — DOM culling | — |
| `fill()` + scroll-triggered fill loop | — |
| `getCol()`, `seededRng()` | — |

### Retained unchanged
- `buildCard()` — card HTML construction
- Overlay system (open, close, gallery, video embed)
- Deep-link support (`#item-{id}`)
- Header, hint, empty state
- Auto-updater (PHP)

---

## 5. Version

This is a breaking visual change. Version bump to **2.0.0**.
