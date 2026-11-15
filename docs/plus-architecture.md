# Pure Blog Plus architecture

## Product outcome

Pure Blog Plus is a small personal publishing CMS for one author. It works independently
as a conventional blog and, over time, will add local-first IndieWeb publishing features.
It can optionally connect to one personal social account without turning either product
into a dependency of the other.

When connected to GoToSocial Plus, Pure Blog Plus owns the customizable combined website.
GoToSocial Plus retains its own public profile and remains the sole owner of ActivityPub,
statuses, replies, follows, and federation.

## Canonical ownership

| Concern | Owner |
| --- | --- |
| Articles, pages, blog media, themes, search, and feeds | Pure Blog Plus |
| Micropub, IndieAuth, and Webmentions | Pure Blog Plus |
| ActivityPub identities, statuses, replies, follows, and federation | GoToSocial Plus |
| Combined public website and presentation | Pure Blog Plus |
| Connected social account and its canonical status identifiers | GoToSocial Plus |
| Read-only social projection used by the combined website | Pure Blog Plus |

There is one author identity per Pure Blog Plus installation and at most one connected
social account. Neither product reads the other's database or filesystem.

## Connector contract

The optional connector uses versioned HTTPS APIs and OAuth. Its portable baseline is the
standard Mastodon-compatible API; GoToSocial Plus may expose optional capabilities for
faster event delivery, but polling remains a compatible fallback.

Pure Blog Plus keeps a durable, read-only local projection of public connected-account
content and copies public media needed by that projection. While connected, source edits
and deletions are reconciled. After disconnection, the last synchronized projection stays
available as an immutable local archive until the owner explicitly removes it.

Blog articles remain canonical in Pure Blog Plus. Per-article syndication can create a
link announcement in the connected social account, and the returned remote status ID is
stored so retries, edits, and deletions are deterministic.

Social replies remain part of social threads. Webmentions to blog articles are a separate,
moderated reaction type; the two are not collapsed into a second universal comment model.

## Local-first deployment

The complete standalone CMS must work with PHP, SQLite, the local filesystem, and a local
background-job mechanism. CDN, object storage, external backup providers, and hosted
identity services are optional adapters rather than requirements.

Installation topology is deliberately independent: Pure Blog Plus and GoToSocial Plus can
run on different hosts and be upgraded or disconnected separately. Unknown connector
capabilities fail closed without preventing either standalone product from starting.

## Extension boundary

General product behavior belongs in this repository. Site-specific themes, navigation,
content imports, and compatibility migrations belong to the site using the product.
fischr.org is the first reference installation, not a source of hard-coded product rules.

Existing fischr.org URLs and metadata will be handled by a bounded importer with an explicit
removal condition. Permanent dual reads, dual writes, and Eleventy compatibility branches
are not part of the product architecture.

## Non-goals

- Implement ActivityPub a second time in Pure Blog Plus.
- Turn GoToSocial Plus into a long-form blog CMS.
- Require GoToSocial Plus, Cloudflare, R2, GitHub Actions, or another hosted service.
- Share credentials, sessions, a database, or a filesystem between the two products.
- Add multi-author or multiple-connected-account behavior to the first product version.
- Move fischr.org-specific rendering or migration rules into the generic CMS core.

## Delivery order

1. Establish the maintained fork, upstream update workflow, and unchanged baseline.
2. Define module, migration, job, and capability boundaries inside Pure Blog Plus.
3. Add local IndieWeb foundations: authentication, Micropub, Webmentions, and media.
4. Add the optional social connector and durable read-only projection.
5. Migrate fischr.org through an explicit importer and remove superseded external paths.

Each stage must leave standalone Pure Blog Plus usable and must delete any superseded path
in the same migration that replaces it.
