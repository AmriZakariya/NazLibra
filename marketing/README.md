# Castl-it-POS — Marketing assets

Everything for promoting **Castl-it-POS** lives here: brand assets, ready-to-post
images, and the copy for each post/campaign.

```
marketing/
├── logo/            Editable logo masters (.svg) — scale to any size, no quality loss
├── banners/         Editable banner masters (.svg)
├── exports/         Ready-to-use .png renders (post these directly)
├── posts/           One markdown file per post/campaign (copy + which assets to use)
└── render-png.php   Regenerates every PNG in exports/ from the brand definition
```

## Brand kit

**Colours**

| Role            | Hex        | Use                                   |
|-----------------|------------|---------------------------------------|
| Indigo (brand)  | `#3157D5`  | Primary — logo mark, buttons, accents |
| Indigo gradient | `#4064E8` → `#2841A2` | Logo mark fill (SVG)       |
| Amber (accent)  | `#F59E0B`  | The "receipt dot", highlights         |
| Ink             | `#0E1330`  | Dark backgrounds, body text on light  |
| Muted           | `#BECAEB`  | Secondary text on dark                |
| White           | `#FFFFFF`  | Text/mark on dark                     |

**Type** — Inter (weights 500 / 800). The PNG exports use DejaVu Sans (bundled)
as a close stand-in since Inter isn't installed on the server; the SVG masters
reference Inter, so open them in a tool that has Inter for pixel-perfect type.

**The mark** — an indigo squircle holding a white "C" with an amber dot (the
"C" for Castl-it, the dot for the receipt/point-of-sale). Keep clear space of at
least half the mark's height around the logo; never recolour or stretch it.

## Asset index (in `exports/`)

| File                          | Size        | Use for                                  |
|-------------------------------|-------------|------------------------------------------|
| `castlit-icon-512.png`        | 512×512     | App icon, favicon source                 |
| `castlit-icon-1024.png`       | 1024×1024   | Store listings, high-res icon            |
| `castlit-facebook-1024.png`   | 1024×1024   | Facebook/social **profile picture** (full-bleed, crops to a circle) |
| `castlit-logo-light.png`      | 1307×300    | Logo on **light** backgrounds (transparent) |
| `castlit-logo-dark.png`       | 1307×300    | Logo on **dark** backgrounds (transparent)  |
| `banner-social-1200x630.png`  | 1200×630    | Facebook/LinkedIn share, Open Graph, posts |
| `banner-wide-1500x500.png`    | 1500×500    | X/Twitter header, wide cover             |

The matching `.svg` masters are in `logo/` and `banners/` — edit those, then
re-export.

## Post images (square 1080×1080, in `exports/posts/`)

One ready-to-post image per campaign — ideal for the Facebook/Instagram feed:

| File | Theme |
|------|-------|
| `post-lancement.png`      | Launch / overview |
| `post-hors-ligne.png`     | Works offline |
| `post-gestion-stock.png`  | Real-time stock |
| `post-multi-terminaux.png`| Web + mobile |
| `post-essai.png`          | Free trial |
| `post-secteurs.png`       | Per-sector fit |
| `post-tiroir-caisse.png`  | Cash-drawer support (illustrated) |
| `post-code-barres.png`    | Barcode scanner (illustrated) |
| `post-terminal.png`       | POS terminal (illustrated) |

## Regenerate the PNGs

After changing colours/text in the renderers (or the brand):

```bash
php marketing/render-png.php     # logo, icons, banners, Facebook profile → exports/
php marketing/render-posts.php   # square post images → exports/posts/
```

## Adding a post

Copy `posts/TEMPLATE.md` to `posts/YYYY-MM-DD-slug.md`, write the copy, and note
which export(s) it uses. Keeps every campaign's text and imagery together.
