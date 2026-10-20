# Chillimedia branding

The administration UI uses Chillimedia's actual vector wordmark, rather than
the provisional checkmark/typed logo from the pilot. Upstream page structure,
forms and consent behaviour are retained.

## Source artwork

- Current website wordmark: https://chillimedia.se/wp-content/uploads/2024/11/Chilli_logo_rgb_red.svg
- Usage reference: https://chillimedia.se/ and https://se.linkedin.com/company/chillimedia
- Retrieved and visually checked on 20 October 2026. The LinkedIn profile uses
  a red C on pale lavender; its cover uses a cream wordmark on lavender.
- `public/media/chillimedia-logo.svg` is the unmodified current website SVG.
- `chillimedia-logo-on-dark.svg` keeps the same paths, with cream fill.
- `chillimedia-mark.svg` uses the first C path from that same SVG, centred on
  a lavender square for the favicon and compact navigation. It is not a new glyph.

Chillimedia artwork and trademarks belong to Chillimedia. Retain upstream
Conzent licensing/NOTICE separately from these deployment assets.

## Colours and typography

Official references: orange-red `#ff3d00`, lavender `#ecdcff`, violet `#9d57ff`,
near-black `#0a0a0a`, and warm white `#faf6f3`/`#f9f6f4`.

`public/assets/css/chilli-brand.css` applies warm neutral surfaces, lavender
selection states and a darker violet (`#7038ac`) for controls with white text.
The original bright red/violet do not have sufficient contrast with normal-size
white text. Green, amber and red semantic status colours retain their meanings.
Both light and dark modes are covered; the C mark replaces the full wordmark on
compact navigation.

The administration retains the existing locally hosted Inter font. Neulis font
files from the main website are not redistributed without a verified licence.

This theme styles the administration and authentication pages. Client consent
banners remain configurable per website through the existing banner editor.
