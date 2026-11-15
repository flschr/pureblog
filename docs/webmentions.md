# Webmention receiver

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

This component receives Webmentions only. Sending Webmentions for links published by the
blog is a separate capability so both directions remain independently testable.

## Verification

Run the isolated receiver contract with:

```sh
php tests/plus-webmentions.php
```

The test covers lazy storage, URL and private-network rejection, verification, moderation,
safe rendering, link removal, idempotent jobs, and request rate limits.
