# Webmentions

Pure Blog Plus advertises a local Webmention endpoint from every public page. Other sites
can notify it when they link to a published post or page without involving a hosted
commenting or Webmention service.

The endpoint accepts `source` and `target` form fields. It validates the local target
synchronously, stores the request in the private Plus SQLite database, and returns `202`
before source verification. Verification runs through the local Plus job queue.

Source requests are limited to public HTTP and HTTPS addresses, with DNS pinning, bounded
redirects, time and response-size limits. A source is valid only while the fetched document
contains the exact target URL. Previously approved mentions whose link disappears are
removed from public display.

Verified mentions remain private until they are approved in **Admin → Webmentions**.
Approved entries render as escaped text below their local post or page. Source links use
`nofollow`, `ugc`, `noopener`, and `noreferrer`; remote HTML and images are never embedded.

Pure Blog Plus also sends Webmentions for external links in published posts and pages.
Saving content only records local delivery jobs; endpoint discovery and network delivery
run through the Plus job queue. Updates notify both current and previously recorded targets,
so receivers can remove links that no longer exist. Renamed, unpublished, and deleted
sources notify their previous targets as well.

The local scheduler runs `php bin/plus-jobs.php` as described in the
[Plus runtime](plus-runtime.md); no hosted queue is involved.

Discovery follows the Webmention order: HTTP `Link` headers first, followed by HTML `link`
and `a` elements. Relative endpoints and endpoint query parameters are preserved. Target
fetches and endpoint posts use the same private-network, redirect, time, and size boundaries
as incoming verification. Every `2xx` endpoint response counts as successful delivery.

Incoming moderation and outgoing delivery status are available under **Admin →
Webmentions**. Failed, queued, successful, and unsupported deliveries remain local and can
be retried from the admin area.

Micropub `in-reply-to`, `like-of`, and `repost-of` properties render as verifiable
Microformats links on the public source page and participate in outgoing delivery.

## Verification

Run the isolated receiver contract with:

```sh
php tests/plus-webmentions.php
php tests/plus-webmention-sender.php
```

The receiver test covers lazy storage, URL and private-network rejection, verification,
moderation, safe rendering, link removal, idempotent jobs, and request rate limits. The
sender test covers link extraction, endpoint precedence, redirects, form delivery, updates,
manual retries, and private-network rejection.
