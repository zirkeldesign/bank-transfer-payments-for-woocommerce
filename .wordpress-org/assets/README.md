# WordPress.org plugin directory assets

These are the icon and banner shown on the plugin's WordPress.org listing. They
are **not** part of the installed plugin — `.distignore` keeps the whole
`.wordpress-org/` directory out of the built zip.

## Files

| File | Purpose |
|---|---|
| `icon.svg` | Vector icon source — deployed as-is; WordPress.org prefers the SVG |
| `icon-256x256.png` | High-resolution icon (fallback for clients that ignore SVG) |
| `icon-128x128.png` | Low-resolution icon |
| `banner.svg` | Banner source — **not** deployed, only rasterized |
| `banner-1544x500.png` | Retina banner |
| `banner-772x250.png` | Standard banner |

## Design

Follows the house style of `privacy-captcha-for-cap` and
`regex-validation-for-gravity-forms`: a diagonal two-stop gradient, a white
glyph, and the plugin name set right of a translucent badge.

- **Gradient:** `#0e3a63` → `#0aa2dd`. Deliberately distinct from its siblings
  (navy→orange, teal→green) so the three are told apart in a directory listing,
  and deliberately *not* WooCommerce purple or Stripe indigo — a plugin has no
  business implying either brand's endorsement.
- **Glyph:** a bank façade, which reads at 128 px where a wordmark would not,
  and is understood as *Banküberweisung* across the DACH market.
- **Check badge:** the automatic reconciliation is what the product sells, so it
  gets its own mark rather than being left to the copy.

Both SVGs declare their gradient with `gradientUnits="userSpaceOnUse"` so every
shape samples the same gradient. The icon's badge relies on this: its outer ring
is painted with the gradient and therefore disappears into the background,
cutting a clean gap between the badge and the façade beneath it.

The IBAN in the banner is `DE89 3704 0044 0532 0130 00` — the standard
documentation IBAN, not a real account.

## Regenerating the PNGs

Edit the SVG, then:

```bash
cd .wordpress-org/assets
rsvg-convert -w 256 -h 256 icon.svg -o icon-256x256.png
rsvg-convert -w 128 -h 128 icon.svg -o icon-128x128.png
rsvg-convert -w 772 -h 250 banner.svg -o banner-772x250.png
rsvg-convert -w 1544 -h 500 banner.svg -o banner-1544x500.png
```

`rsvg-convert` comes from `brew install librsvg`.

## Deployment

Pushing a change here to `main` triggers `.github/workflows/deploy-assets.yml`,
which commits the icon and banners to the plugin's SVN `assets/` directory. It
does not touch `trunk/` or `tags/`, so it needs no version bump or release.
