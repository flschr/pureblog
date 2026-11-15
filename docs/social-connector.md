# Social connector

Pure Blog Plus can optionally connect one Mastodon-compatible account, including a
single-user GoToSocial or GoToSocial Plus instance. The connection is deliberately an API
boundary: Pure Blog never reads the social server's database, filesystem, session, or
configuration, and the social server does not need access to Pure Blog internals. Either
product remains usable when the other is offline or disconnected.

The owner enters the HTTPS server origin under **Settings → Social** and authorizes Pure
Blog through OAuth. Dynamic app registration and the standard Mastodon-compatible API are
used with the narrow `read:accounts`, `read:statuses`, and `write:statuses` scopes. Client
credentials and the access token are stored in the private Plus SQLite database. Disconnect
attempts to revoke the remote token and always removes the active local connection. If the
server is temporarily unavailable, the revocation credential is isolated in a private retry
record until a background job confirms revocation.

Starting a new authorization does not disturb the active account. Pure Blog verifies the new
token and account before switching. Each connected account has its own archive identity;
previous projections remain frozen, and queued sync or announcement jobs are bound to the
identity that created them so they cannot run against a later account.

## Local microblog projection

The background job runner polls the connected account in bounded pages. Public and unlisted
original statuses are stored as a read-only local projection; replies, boosts, private posts,
and statuses created as Pure Blog announcements are excluded. Remote edits and deletions are
reconciled only after a complete successful poll. Supported image, video, and audio attachments are copied through the
selected Pure Blog media provider, so the public projection does not depend on remote media
URLs. A failed image copy does not discard its status. Copied media that is no longer referenced
after a successful reconciliation is removed through the normal retryable media-deletion path.

Enable the public projection to make it available at `/social`. Navigation remains under the
site owner's control through the normal navigation settings. Disconnecting stops polling but
keeps the projection and its copied media frozen until the same or another account is
authorized. Explicitly deleting local media still follows the normal reference checks.

Run the standard Plus worker regularly, for example every minute:

```sh
php bin/plus-jobs.php 25
```

The connector schedules its next poll in five minutes after a successful synchronization.
Normal job retries handle temporary server or network failures.

## Blog announcements and Micropub

When connected, the post editor can opt an individual published article into a short social
announcement containing its title and canonical URL. Re-saving unchanged content is a no-op;
title or URL changes update the existing remote status, and disabling the option or deleting
the article removes it. Creation uses an idempotency key and the remote status ID is kept only
as operational connector state.

The connected account is also advertised as a Micropub syndication target. A client that sends
that target through `mp-syndicate-to` enables the same announcement behavior; it does not make
the social account the source of the blog post.

## Verification

```sh
php tests/plus-social-connector.php
php tests/plus-runtime.php
php tests/plus-micropub.php
```

The isolated connector contract covers OAuth state and token handling, one-account ownership,
polling, local media isolation, edits and deletions, idempotent announcements, and the frozen
disconnect behavior.
