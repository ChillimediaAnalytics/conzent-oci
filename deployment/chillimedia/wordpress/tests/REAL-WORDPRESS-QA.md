# Disposable real WordPress QA

The lightest official-container approach needs **one** `wordpress:cli-php8.3`
container, using its PHP built-in server. The official image already includes
PHP, mysqli, mysql-client and WP-CLI. Apache/FPM and a second WordPress image are
unnecessary. Reuse the pilot's MariaDB with a new isolated scratch database/user
instead of starting another database daemon. Budget 256 MiB and 0.5 CPU for the
WordPress container, with its port bound only on host loopback.

Official references checked 2026-10-19:

- [Image Dockerfile](https://github.com/docker-library/wordpress/blob/master/cli/php8.3/alpine/Dockerfile).
- [WP-CLI core install](https://developer.wordpress.org/cli/commands/core/install/).
- [WP-CLI built-in server](https://developer.wordpress.org/cli/commands/server/).
- [Current core version API](https://api.wordpress.org/core/version-check/1.7/): 7.1.3.
- Official WP Consent API 2.1.0 ZIP: `https://downloads.wordpress.org/plugin/wp-consent-api.2.1.0.zip`,
  SHA-256 `f4fb971b3f47134ad67b7efdba8150a32e0bc5ca14328ae3cd2c4aebd85f7892`.

## Preparation owned by the operator

1. In the existing MariaDB container create a unique database named
   `chilli_wp_qa_<unique-id>` and a dedicated user granted privileges on **only
   that database**. Do not give WordPress the production application's account or
   the MariaDB root password. Do not run `wp db clean` or reuse a customer schema.
2. Set `QA_NETWORK` to the pilot's Docker network and `QA_DB_HOST` to its MariaDB
   service/container hostname. Save the scratch database user's password to a
   mode-600 file, `QA_DB_PASSWORD_FILE`. Use a distinct random mode-600
   `QA_ADMIN_PASSWORD_FILE` for the disposable WordPress admin.
3. Set `CHILLI_QA_SITE_KEY` to a disposable site created in the central dashboard.
   The plugin is pinned to the public agency hostname: successful live API
   verification requires that public hostname already route to OCI. Before the
   switch, use `CHILLI_QA_EXPECT_VERIFIED=0` to check pending behaviour instead.
   Its value defaults to `1` below.

## Exact setup

From `deployment/chillimedia/wordpress/`, with the prepared variables exported:

```bash
export QA_PUBLIC_URL=https://consent.chillimedia.se/__wordpress_qa
export QA_BIND_PORT=3092
export CHILLI_QA_EXPECT_VERIFIED=1
bash tests/docker-wordpress-qa.sh
```

The script prints its exact unique container/volume name and image content ID.
It runs as the official image's default non-root `www-data` user. Secrets are
passed through WP-CLI stdin prompts. Those two commands have output suppressed
because WP-CLI echoes entered values and reconstructed command arguments.
Core is pinned to 7.1.3 and checksum-verified; WP Consent API is pinned to 2.1.0.
A failed setup removes only its newly created container/volume. The operator owns
scratch database/user/password-file cleanup in all cases.

For the requested temporary public path, nginx must strip the prefix while
preserving the original request URI and TLS information for WordPress:

```nginx
location ^~ /__wordpress_qa/ {
    proxy_pass http://127.0.0.1:3092/;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Original-URI $request_uri;
    add_header X-Robots-Tag "noindex, nofollow" always;
}
```

The setup inserts a scratch-only proxy adapter in its new `wp-config.php`. This
keeps canonical redirects, asset URLs and TLS detection correct beneath the
prefix. Remove the temporary nginx route after collecting results.

If accessing the cloud fixture from the desktop, create a temporary SSH local
forward to the server's loopback port. Open
`http://127.0.0.1:3092/?chilli-consent-qa=1#chilli-consent-qa-results` in the browser.
The page itself runs a synthetic reject/allow/revoke sequence through the real
connector JavaScript and real WP Consent API. Read the visible JSON or the
`#chilli-consent-qa-results` element: its `data-status` must be `passed`, with
three passing category checks. It deliberately does **not** claim a real banner
UI interaction, scanner result or backend consent-log write was tested.

A valid end-to-end consent test additionally clicks the real banner's controls
on a disposable site, inspects pre-consent third-party requests and verifies the
corresponding consent record in the central dashboard. Do not substitute the
synthetic bridge test for that.

## Cleanup

After collecting output and any screenshots, stop/remove **only** `$QA_NAME`,
remove **only** `$QA_VOLUME`, then drop **only** the scratch database/user created
in preparation. Delete the two scratch password files. Keep no customer data,
credentials or test WordPress installation in GitHub. Do not change the existing
pilot's Compose file or running app/database volumes.
