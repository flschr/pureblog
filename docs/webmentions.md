# Webmention receiver

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
PureComments integration, which is not part of this receiver.

This component receives Webmentions only. Sending Webmentions for links published by the
blog is a separate capability so both directions remain independently testable.

## Verification

Run the isolated receiver contract with:

```sh
php tests/plus-webmentions.php
```

The test covers lazy storage, URL and private-network rejection, verification, private inbox
review, link removal, idempotent jobs, and request rate limits.
