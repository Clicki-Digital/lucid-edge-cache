# Lucid Edge Cache 0.10.5 test checklist

Use a staging site and keep a backup of `wp-config.php`. Test in a private browser window as well as while signed in.

## GitHub update path

- Manually upload version 0.7.0 over version 0.6.3 on a staging site. This one-time bootstrap is required because 0.6.3 does not contain the GitHub updater.
- Confirm the existing wp-config.php settings remain available and locked after the manual update.
- Publish version 0.7.0 as a stable GitHub release with the workflow-generated `lucid-edge-cache.zip` asset.
- Prepare a 0.7.1 test release by updating the plugin header version, `LEC_VERSION`, `Stable tag` and changelog, then push the matching `v0.7.1` tag.
- Select Dashboard > Updates > Check again and confirm WordPress offers Lucid Edge Cache 0.7.1 without querying WordPress.org.
- Open View version 0.7.1 details and confirm the GitHub release notes appear.
- Run the update and confirm the plugin remains active and its directory remains `lucid-edge-cache`.
- Confirm the cache, Varnish purge, object-cache flush and Spaces upload still work after the update.
- Enable auto-updates on the staging site, publish a later patch release, and confirm WordPress applies it through its normal auto-update process.
- Negative test: rename the release asset or remove its GitHub digest and confirm no update is offered.
- Negative test in an isolated copy: use a ZIP with the wrong root folder and confirm installation is stopped before the existing plugin is replaced.

## Upgrade and onboarding

- Activate with no `WP_CACHE` definition and confirm the plugin inserts exactly one `define('WP_CACHE', true);` above the WordPress bootstrap marker.
- After WP_CACHE is already enabled, complete onboarding and confirm the credentials write changes only the Lucid-managed block and does not reprocess the WP_CACHE definition.
- Repeat onboarding with a valid wp-config.php that starts with a UTF-8 byte-order mark or leading whitespace and confirm temporary-file validation succeeds and the resulting file matches the intended content byte-for-byte.
- Activate with `WP_CACHE` set to `false`, with the older guarded Lucid definition, and with duplicate recognised definitions; confirm each case is replaced by exactly one canonical enabled definition without changing unrelated wp-config.php content.
- Test an unsupported multi-line `WP_CACHE` definition and an unwritable wp-config.php; confirm the plugin leaves the original file intact and displays an administrator error rather than appending a duplicate.
- Protect a wp-config.php file that already contains one enabled `WP_CACHE` definition and confirm activation succeeds without attempting to rewrite it.
- With a writable wp-config.php containing one enabled `WP_CACHE` definition in a non-standard location, confirm activation removes it and inserts exactly one canonical definition above the WordPress bootstrap marker.
- Test separately with an unreadable file, an unwritable file and an unwritable containing directory; confirm setup identifies the exact failure, current file mode where available, and resolved path.
- Confirm the setup page access report accurately shows the resolved wp-config.php file, readability, file writability, directory writability and permissions.
- Before onboarding, confirm only the Setup page is available and direct dashboard access redirects to Setup.
- Leave or create an incomplete managed block and confirm the dashboard remains unavailable until all required Spaces settings are loaded.
- Upgrade from 0.4.1 and confirm the dashboard reports version 0.5.0 and the drop-in as installed.
- Confirm the Cache settings status line displays the currently installed plugin version.
- Complete onboarding and confirm it redirects to the main dashboard.
- Revisit the setup URL and confirm it redirects without displaying fields.
- Confirm no Spaces secret or API token exists in `wp_options`, activity logs, page source or diagnostic output.
- Temporarily enable `LEC_ALLOW_RECONFIGURE`, confirm setup becomes available, then remove the constant.

## Request behaviour

- Select each cache-lifetime preset, save settings and confirm the stored TTL and generated runtime configuration use the corresponding seconds.
- Select Custom, test the 60-second and seven-day limits, and confirm preset selection hides the custom field again.
- Confirm the Cache safety rules panel lists mandatory WordPress and WooCommerce bypasses separately from editable additional exclusions.
- Confirm excluded requests return `X-Lucid-Cache: BYPASS`, a stable `X-Lucid-Cache-Reason` code and a useful plain-English `X-Lucid-Cache-Explanation` without cookie values or internal paths.
- Trigger a `response-cookie` bypass and confirm the explanation identifies only the cookie name, never its value or attributes.
- Confirm HIT, MISS, STALE, ACQUIRED, WAITED and BUSY responses include accurate explanation headers, and that the settings guide explains each status, lock state and bypass code.
- Request a public page twice while logged out: first origin response is `MISS`, subsequent origin response is `HIT` with `X-Lucid-Cache-Age`.
- Confirm logged-in, preview, POST, query-string, REST, search, feed, 404, password-protected and excluded commerce requests bypass the page cache.
- Start a native PHP session and confirm both the initial `Set-Cookie: PHPSESSID` response and later requests carrying that cookie always bypass the cache.
- Confirm a response that sets a cookie, is not HTML, is empty or does not return HTTP 200 is not written.

## Invalidation

- Update a published post and verify the post, home/blog page, author, post-type archive, relevant taxonomy archives and their pagination regenerate.
- Change a slug and verify the old local and Spaces objects are removed and the new URL is generated.
- Trash/delete published content and verify its local and Spaces objects are removed.
- Change a menu, widget, relevant site option, theme or Customiser setting and confirm a global purge.
- Use manual purge and confirm local HTML, Varnish and optional object-cache actions complete.

## Services and resilience

- Run the system test and confirm each enabled layer passes.
- Temporarily make Spaces unavailable, request an uncached page, restore Spaces and confirm the queued upload succeeds through cron.
- Repeat for a queued Spaces deletion.
- Confirm retry items stop after five failed attempts and the failure appears in Recent activity.
- Make the cache directory unwritable and confirm WordPress still serves the dynamic response without a partial cache file.
- Enter a Varnish endpoint for an unrelated host and confirm it is rejected.
- Trigger several simultaneous requests for an expired URL and confirm one response acquires the generation lock while others wait or receive a short-lived stale response.
- Confirm daily cleanup removes expired HTML and abandoned lock files while leaving fresh HTML intact.
- Confirm Daily automatic preload schedules one `lec_daily_preload` event between 04:00 and 05:30 in the WordPress timezone, records its last run, queues published URLs in batches of five, and schedules the following day.
- Arrange the preload groups into at least two different orders and confirm the queue follows the saved group priority rather than post creation ID. Confirm pages use menu order, products use catalogue order, blog posts run newest first and populated taxonomy archives run largest first.
- Confirm product and product-category groups safely add no URLs when WooCommerce is inactive, and confirm duplicate homepage or archive URLs appear only once.
- Change Automatic preload to Off and confirm the daily event is removed without affecting manual preloading.
- Disable page caching and confirm automatic preloading is unscheduled; re-enable it and confirm the event returns.
- Inspect cached, expired, absent and protected URLs and confirm the inspector does not request or modify them.
- Confirm an eligible URL inspection clearly explains the remaining request-dependent checks and gives private-window MISS-to-HIT test instructions.
- Download diagnostics and confirm credentials, tokens and cookie values are absent.
- Disable caching with Emergency disable, confirm dynamic WordPress remains available, then re-enable caching.
- Populate both background queues, test Retry pending work, then test Clear queues on staging.
- Confirm Advanced tools and diagnostics is collapsed by default below System health and that every contained form and button still works after opening it.
- Generate preload and purge activity and confirm same-site paths are visible without query strings, external URLs remain hidden, and event/status labels are human-readable.
- Open Cached Pages from the main settings page and confirm fresh and expired files show the correct age, life remaining, size and queue state.
- Confirm the records screen paginates after 100 items and remains restricted to administrators.
- With Spaces enabled, open several generated CDN links and confirm they use the configured prefix and canonical-host namespace; queue an upload retry and confirm it is flagged instead of presented as verified.
- Use Verify object and confirm a successful Spaces object reports its HTTP status and content type without rendering the HTML; test a missing object and confirm the failure is shown only to the current administrator.

## Lifecycle and compatibility

- Make wp-config.php writable while its containing directory is not writable. Confirm Setup reports "Locked direct update with verification", writes the complete configuration, retains the original permissions and opens the stable Cache settings route.
- Make the containing directory writable while wp-config.php itself is read-only. Confirm Setup uses atomic verified replacement successfully.
- Force direct-write verification to fail in an isolated test and confirm the byte-for-byte original wp-config.php content is restored and the error explains the restoration.
- Allow temporary-file creation but refuse rename over the existing wp-config.php. Confirm Lucid removes the temporary file, falls back to the locked direct update, verifies the result and completes setup without requesting manual configuration.

- Define all required `LEC_*` constants manually without Lucid's BEGIN/END markers and confirm the normal Cache settings page opens without requesting setup again. Define `LEC_LOCK_SETTINGS` as true and confirm infrastructure fields remain locked.
- Remove one required constant from a manual configuration and confirm Setup names only the missing constant without displaying any existing secret value.
- Open the stable top-level Cache URL before and after automatic setup, a failed setup and a manual wp-config.php correction. Confirm the same route displays Setup or Settings as appropriate and never shows WordPress's "not allowed to access this page" response.

- Confirm administrators see a top-level Cache menu with Settings and Cached Pages, while users without `manage_options` do not.
- Confirm the Cache toolbar dropdown offers Clear cache, Clear and preload cache, and Settings only to administrators, and rejects missing or invalid nonces.
- Run Clear and preload cache and confirm local HTML and downstream caches are purged before the home page and all published content are queued.

- Deactivate and confirm the Lucid drop-in and scheduled jobs are removed while configuration remains.
- Reactivate and confirm runtime configuration and the drop-in are restored.
- Place a non-Lucid `advanced-cache.php` in `wp-content`, confirm Lucid reports the detected owner without deleting it, then use Back up and replace file. Confirm the previous file receives a dated `.lec-backup-*` name, Lucid installs its own verified drop-in, and a failed copy restores the original.
- With Lucid's drop-in missing or prevented from loading, request an anonymous public page twice and confirm both responses explain `dropin-not-loaded`, no new cache record is written, and System health distinguishes the installed file from execution. Restore the drop-in and confirm the first request is MISS and the next is HIT without changing the cached file time on the HIT.
- Test PHP 8.1, 8.2, 8.3 and 8.4 with the supported WordPress versions.
- Test Cloudways Varnish and Redis, Apache/Nginx behaviour, Gutenberg, the site's page builder and WooCommerce exclusions where applicable.
- Treat multisite and subdirectory WordPress installations as unsupported until separately certified.
