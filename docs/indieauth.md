# IndieAuth server

Pure Blog Plus includes its own IndieAuth authorization server. It uses the existing
single-author admin account and MFA flow, so no external identity or token service is
required.

Production sites must use HTTPS. An insecure public site does not advertise or serve the
IndieAuth endpoints; plain HTTP is accepted only for local development on loopback hosts.

The public site advertises the authorization-server metadata endpoint in its HTML head.
The metadata document publishes the authorization and token endpoints, PKCE support, and
the scopes understood by the planned Micropub server:

- `profile`
- `create`
- `update`
- `delete`
- `media`

Authorization requests require the authorization-code flow, an exact redirect URI, and
PKCE with `S256`. Cross-origin redirects must be declared in client metadata fetched over
HTTPS. Metadata requests reject private and reserved network addresses, use bounded
responses and timeouts, and do not follow redirects to insecure URLs.

The author signs in through the normal admin page and explicitly approves scopes on a
local consent page. During this flow, the login, MFA, and consent pages send no referrer,
are not cached, cannot be framed, and load no third-party assets.

Authorization codes expire after five minutes and can be used only once. Codes and access
tokens are stored only as SHA-256 hashes in the local Plus SQLite database. Access tokens
do not expire automatically in this first version, but can be listed and revoked through
the server-side token functions. Refresh tokens and dynamic client registration are not
supported.

IndieAuth makes authentication and scoped credentials available before Micropub is added.
The published content model remains Markdown-first, and enabling IndieAuth does not move
posts or media into SQLite.

## Verification

Run the isolated protocol contract with:

```sh
php tests/plus-indieauth.php
```

The test covers discovery registration, redirect validation, client metadata, PKCE,
one-time codes, hashed credentials, scope enforcement, and revocation. It uses a temporary
database and does not require an installed blog.
