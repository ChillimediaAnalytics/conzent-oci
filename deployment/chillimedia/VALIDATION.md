# Deployment verification — 19 October 2026

Live service: https://consent.chillimedia.se/ on the existing Oderland Cloud host.
Upstream baseline: Conzent OCI 2.7.4, commit 9631bef571676b96f1c7644ed29c8450745e1b61.
PHP app image: `chilli-conzent-app:5198b10`, image ID `sha256:540da3482ad6c545d3d5d46163497cfcbb0f8dc383de62cc6d997d1a7df07004`.
Scanner image ID: `sha256:99d5cbf432bdd5955ceaa911d29d1222b58698bb200de60871da50cb91a5a916`.
The later runtime nginx configuration follows app-container DNS changes. Production credentials are excluded from Git.

## Verified

- 81 PHP regression tests / 383 assertions: ownership, webhook authentication, bounded pinned URL fetching, configured-origin request protection and fresh-install schema compatibility.
- 8 scanner security tests, including real Chromium fixtures; 9 additional deployed API checks. Private/metadata/loopback addresses and untrusted callbacks are rejected. Production Chromium reports ready.
- npm root/scanner audits and locked Composer audit reported no known advisories after dependency updates.
- 34 initial cloud integration checks: login, company/site creation, Swedish default plus English, core dashboard pages, generated scripts, preview, access denials, consent receipts and proof pages.
- A one-page scan of the agency homepage completed through queue, Chromium and authenticated callback. Two detected cookies/categories were persisted. The admin-host self-scan remains intentionally blocked.
- Real WordPress 7.1.3, official WP Consent API 2.1.0 and connector 2.2.4.2 tested in an isolated scratch database/container. Strict live key verification passed. Connector checks: 33 PHP fixture assertions and 11 JavaScript tests.
- Browser tests confirmed initial denial of optional WP consent, reject, accept, saved choices on a return visit, preference modal and withdrawal. A one-hour first-party analytics test cookie was removed within one second of withdrawal. The real WP Consent API synthetic consumer checks passed reject/allow/revoke; necessary functional consent remains allowed.
- Login, dashboard and banner editor were checked in the browser. Existing upstream UI retained. Dashboard libraries/fonts are local; default external vendor support chat is disabled.
- HTTPS responses on the consent host, including scripts, errors and robots.txt, carry `X-Robots-Tag: noindex, nofollow`. Main chillimedia.se remains HTTP 200 with index/follow and no host noindex header. ChilliGPT remains HTTP 200.
- The previous c15t app is stopped. The public WordPress QA route was removed after testing; the scratch container, volume, database/user and password files were removed.
- New-app backup archive hash, format-2 restore inspection, manifest, 91 table definitions/data and generated scripts were verified. Production was not restored. Daily local backups run at 03:00 UTC and retain seven archives. Protected host environment is saved separately.

## Operating limits

This is a pilot deployment with targeted security repairs and functional checks, not an exhaustive security certification or a client-volume load test. Client installations need their own tag/cache/consent migration checks. No customer WordPress site was modified.

SMTP/password-recovery delivery and app-managed offsite backup destinations are unconfigured. TCF is disabled (`CMP_ID=0`); this fork does not claim upstream CMP certification. Cloud-only agency billing/portal features are not included.

Unused BuildKit cache cleanup reached other projects' unused build caches as well. No images, containers or volumes were pruned. Their next builds may need to recreate caches. The host had approximately 19 GiB free after cleanup; running services remained healthy.

## Branding release — 20 October 2026

Application image: `chilli-conzent-app:ff59e59`, image ID
`sha256:4e8ceec285578d3d4a0f141be0c2786961fcfe6645fd0ae49de7673a67618e30`.
The scanner image is unchanged and also tagged `ff59e59` so the shared release
variable remains deployable. No schema or consent-runtime changes.

The real Chillimedia SVG wordmark, C favicon/mobile mark and a separate brand
stylesheet replace the pilot logo and green administration theme. Provenance
and palette decisions are in [BRANDING.md](BRANDING.md).

Verified: Twig syntax for the three changed templates, CSS parsing, exact original
SVG bytes/path provenance, light sign-in accessibility checks (contrast, labels,
image alternatives and button names), real production login, dashboard and banner
editor, light/dark logo switching, mobile layout and compact navigation.
White button text on `#7038ac` has a contrast ratio of 7.37:1. Real browser checks
reported no page JavaScript errors; sign-in responses retain `noindex, nofollow`.
Database/Redis health checks pass and both the main website and ChilliGPT return
HTTP 200. The earlier operating limits still apply.
