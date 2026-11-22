# Webmentions

Pure Blog Plus advertises a local Webmention endpoint from every public page. Other sites
can notify it when they link to a published post or page without involving a hosted
commenting or Webmention service.

The endpoint accepts `source` and `target` form fields. It validates the local target
synchronously, stores the request in the private Plus SQLite database, and returns `202`
before source verification. Verification runs through the local Plus job queue.

Source requests are limited to public HTTP and HTTPS addresses, with DNS pinning, bounded
redirects, time and response-size limits. A source is valid only while the fetched document
contains the exact target URL. Previously verified mentions whose link disappears remain
recorded with a deleted status.

Verified mentions remain private and are collected in **Admin → Webmentions**. The inbox
can ignore an entry or restore it for later use, but Pure Blog Plus never renders incoming
Webmentions on public posts or pages. Public comments and reactions belong to an optional
This component intentionally does not render them as public comments.

Pure Blog Plus also sends Webmentions for external links in published posts and pages.
Saving content only records local delivery jobs; endpoint discovery and network delivery
run through the Plus job queue. Updates notify current targets and targets that accepted an
earlier delivery, so receivers can remove links that no longer exist. Targets that never
accepted a Webmention are not retained solely for later removal updates. Renamed, unpublished,
and deleted sources notify their previously reached targets as well.

The local scheduler runs `php bin/plus-jobs.php` as described in the
[Plus runtime](plus-runtime.md); no hosted queue is involved.

Discovery follows the Webmention order: HTTP `Link` headers first, followed by HTML `link`
and `a` elements. Relative endpoints and endpoint query parameters are preserved. Target
fetches and endpoint posts use the same private-network, redirect, time, and size boundaries
as incoming verification. Discovery asks for HTML while the form POST asks for an API/JSON
response, so content-negotiating receivers do not redirect the background worker to a browser
status page. Every `2xx` endpoint response counts as successful delivery. A `404` or `410`
from the target or its advertised endpoint records the receiver as unsupported without retry;
other non-`2xx` responses remain delivery failures.

The private incoming inbox and outgoing delivery status are available under **Admin →
Webmentions**. Failed, queued, successful, and unsupported deliveries remain local and can
be retried from the admin area. Operational health counts a failed delivery while its target
is still linked or while a previously successful receiver still needs an update. A removed
target that never accepted a Webmention remains in the local history without staying an
actionable failure. Upgrades reconcile this reference state once through the local job queue;
the migration does not perform network delivery.

Micropub `in-reply-to`, `like-of`, and `repost-of` properties render as verifiable
Microformats links on the public source page and participate in outgoing delivery.

## Verification

Run the isolated receiver contract with:

```sh
php tests/plus-webmentions.php
php tests/plus-webmention-sender.php
```

The receiver test covers lazy storage, URL and private-network rejection, verification,
private inbox review, link removal, idempotent jobs, and request rate limits. The
sender test covers link extraction, endpoint precedence, redirects, form delivery, updates,
manual retries, and private-network rejection.
