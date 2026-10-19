# Chilli Consent on Oderland

Agency-operated deployment of the Conzent OCI fork. The existing upstream dashboard is retained; the login/nav branding is Chilli Consent. The Apache license/NOTICE remain. Cloud-only agency portal, billing, A/B tests and revenue features are not added. TCF is disabled (`CMP_ID=0`); no upstream CMP certification is claimed.

## Services and boundaries

Compose project `chilli-conzent` runs on the existing Oderland Cloud host. Its only host listener is `127.0.0.1:3091`, behind the dedicated `consent.chillimedia.se` host proxy. MariaDB, Redis and scanner have no published ports. Scan concurrency is one. Container memory/CPU limits suit a small pilot, not an untested client-volume promise. ChilliGPT/Blikk services and main `chillimedia.se` routes are independent.

The host proxy in `nginx-host.conf` preserves Let's Encrypt renewal and sets `X-Robots-Tag: noindex, nofollow` on every response. Login/dashboard also have robots meta tags. No main-site SEO configuration changes. Legacy `/admin/login` redirects to Conzent's `/login`.

Public signup is disabled in the dedicated nginx configuration; this is operated by the agency. Email, third-party OAuth and external support chat are not configured. Operators provision users through the dashboard/CLI. The SMTP-dependent password-reset link is omitted until mail delivery is configured.

## Security changes in this fork

- Check site ownership on analytics, consent proof, script generation and installation checker routes.
- Require the configured same origin for authenticated dashboard changes; retain existing form-token checks.
- Require scan webhook authentication and configured secret; dispatch fails closed.
- Scanner API requires a strong key; callbacks require the configured server key and exact webhook allowlist.
- Validate public scan destinations and DNS at the actual connection. A bundled pinned-egress proxy covers Chromium redirects/resources; private/reserved IPs and nonstandard ports are blocked.
- Keep scanner callbacks on the private network; the public host blocks that endpoint.
- Update vulnerable npm/Composer dependencies and pin scanner runtime/browser versions.

These are targeted repairs with regression tests, not an exhaustive security certification. Re-test upstream changes before merging them.

## Deploy

1. Check out the `chillimedia` branch of `ChillimediaAnalytics/conzent-oci` at `/home/ubuntu/chilli-conzent`.
2. Create `.env` next to this file from `.env.example`, permissions `0600`. Generate all secrets; callback key must equal PHP webhook secret. Preserve signing/TOTP keys and DB credentials across releases. Never push the real file.
3. `RELEASE=<commit> docker compose build app scanner`
4. `RELEASE=<commit> docker compose up -d mariadb redis app`
5. `docker compose exec -T app php bin/oci migrations:migrate`
6. Seed bundled content: `banners:seed-translations --insert`, `banners:seed-translations --categories`, `cookies:sync` and `cookies:translate --insert`.
7. First install only: create the operator with `php bin/oci setup`. Prefer passing credentials inside a protected process environment rather than shell history/command arguments.
8. `docker compose exec -T app php bin/oci scanner:register`
9. `RELEASE=<commit> docker compose up -d --wait`
10. Verify login, site creation, generated banner, consent save, WordPress rendering, scanning, ownership and noindex before switching the dedicated host proxy.

`RELEASE` is retained in the protected environment file. Deployment is manual, not automatic on GitHub push. Record built image digests with the release.

## Backup and rollback

Before cutover, the previous c15t app's SQLite online backup and existing `.env`/volumes are preserved. Its checkout remains `/home/ubuntu/chilli-consent`. The previous nginx host file is saved under the protected host backups directory. A rollback starts the prior app, restores that dedicated nginx file, validates `nginx -t` and reloads nginx. Never delete prior volumes during cutover.

For Conzent, use `docker compose exec -T app php bin/oci backup:run` and verify the archive. Back up its `.env`, database and `sites` volume securely. The `backups` named volume survives container rebuilds but is not an off-host disaster-recovery copy. Do not use `down -v`. Arrange protected off-host retention before broad client rollout.

## WordPress

The `wordpress/` directory contains the agency connector fork and its provenance/security notes. Pin it to our server before activation. Existing clients' Cookiebot plugins/scripts are not removed automatically; each client migration needs pre-choice, reject, accept and withdrawal checks plus cache/plugin integration verification.
