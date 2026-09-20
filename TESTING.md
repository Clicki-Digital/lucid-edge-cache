# Lucid Edge Cache 0.9 test checklist

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

- Activate with no `WP_CACHE` definition and confirm the plugin inserts it above the WordPress bootstrap marker.
- Before onboarding, confirm only the Setup page is available and direct dashboard access redirects to Setup.
- Leave or create an incomplete managed block and confirm the dashboard remains unavailable until all required Spaces settings are loaded.
- Upgrade from 0.4.1 and confirm the dashboard reports version 0.5.0 and the drop-in as installed.
- Complete onboarding and confirm it redirects to the main dashboard.
- Revisit the setup URL and confirm it redirects without displaying fields.
- Confirm no Spaces secret or API token exists in `wp_options`, activity logs, page source or diagnostic output.
- Temporarily enable `LEC_ALLOW_RECONFIGURE`, confirm setup becomes available, then remove the constant.

## Request behaviour

- Select each cache-lifetime preset, save settings and confirm the stored TTL and generated runtime configuration use the corresponding seconds.
- Select Custom, test the 60-second and seven-day limits, and confirm preset selection hides the custom field again.
- Confirm the Cache safety rules panel lists mandatory WordPress and WooCommerce bypasses separately from editable additional exclusions.
- Confirm excluded requests return `X-Lucid-Cache: BYPASS` and a safe `X-Lucid-Cache-Reason` without cookie values or internal paths.
- Request a public page twice while logged out: first origin response is `MISS`, subsequent origin response is `HIT` with `X-Lucid-Cache-Age`.
- Confirm logged-in, preview, POST, query-string, REST, search, feed, 404, password-protected and excluded commerce requests bypass the page cache.
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
- Change Automatic preload to Off and confirm the daily event is removed without affecting manual preloading.
- Disable page caching and confirm automatic preloading is unscheduled; re-enable it and confirm the event returns.
- Inspect cached, expired, absent and protected URLs and confirm the inspector does not request or modify them.
- Download diagnostics and confirm credentials, tokens and cookie values are absent.
- Disable caching with Emergency disable, confirm dynamic WordPress remains available, then re-enable caching.
- Populate both background queues, test Retry pending work, then test Clear queues on staging.
- Confirm Advanced tools and diagnostics is collapsed by default below System health and that every contained form and button still works after opening it.
- Generate preload and purge activity and confirm same-site paths are visible without query strings, external URLs remain hidden, and event/status labels are human-readable.
- Open Cached Pages from the main settings page and confirm fresh and expired files show the correct age, life remaining, size and queue state.
- Confirm the records screen paginates after 100 items and remains restricted to administrators.
- With Spaces enabled, open several generated CDN links and confirm they use the configured prefix and canonical-host namespace; queue an upload retry and confirm it is flagged instead of presented as verified.

## Lifecycle and compatibility

- Deactivate and confirm the Lucid drop-in and scheduled jobs are removed while configuration remains.
- Reactivate and confirm runtime configuration and the drop-in are restored.
- Test PHP 8.1, 8.2, 8.3 and 8.4 with the supported WordPress versions.
- Test Cloudways Varnish and Redis, Apache/Nginx behaviour, Gutenberg, the site's page builder and WooCommerce exclusions where applicable.
- Treat multisite and subdirectory WordPress installations as unsupported until separately certified.
