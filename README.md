# Pure Blog Plus

Pure Blog Plus is the public CMS component maintained for
[fischr.org](https://fischr.org). It is a fork of
[Pure Blog](https://github.com/kevquirk/pureblog), created by
[Kev Quirk](https://kevquirk.com), and retains Pure Blog's license, attribution,
Markdown-first foundation, and mergeable upstream history.

The current Plus line is based on Pure Blog 3.9.4 and extends it with the IndieWeb,
media, gallery, and optional GoToSocial capabilities required by fischr.org. The
repository stays public so its fork history and component changes remain reviewable.
It is not a separately supported distribution: there is no compatibility promise for
unknown installations, no general support channel, and no in-app updater. Changes are
driven by the real fischr.org product path; a second deployment is generalized only
when it actually exists.

See [the Plus architecture](docs/plus-architecture.md) for the agreed product boundaries
and [the upstream workflow](docs/upstream.md) for how this fork follows Pure Blog. The
[Plus runtime](docs/plus-runtime.md) documents lazy SQLite migrations, capabilities, and
the local background-job queue used by optional protocols and connectors.
The [IndieAuth server](docs/indieauth.md) lets compatible applications obtain narrowly
scoped credentials through the existing admin login and MFA flow. The local
[Micropub server](docs/micropub.md) uses those credentials to create, read, update, and
delete Markdown posts and to upload media without a hosted publishing service. The local
[Webmention support](docs/webmentions.md) sends reactions and verifies incoming ones in the
private admin inbox without an external Webmention service. Public comments and reactions
remain private moderation data and are not rendered as public comments.
The [bundled plugin contract](docs/plugins.md) keeps local media as the standalone default
and lets an owner explicitly select optional storage and alt-text providers.
The optional [social connector](docs/social-connector.md) connects one Mastodon-compatible
account through OAuth, keeps a local read-only microblog projection, and can announce selected
blog posts without making either product depend on the other.

## Pure Blog foundation

Pure Blog is a simple, flat‑file blogging platform with a Markdown‑first editor and a lightweight admin area. It stores posts and pages as Markdown files on disk—no database required.

## Features
- Flat-file content using Markdown and front matter.
- A clean, distraction-free admin dashboard for writing and organising posts/pages.
- Draft previews so you can check your work before publishing.
- Optional tags and tag archives for grouping related posts.
- Automatic pagination when your post list grows long.
- An RSS feed so readers can follow along however they like.
- Built-in search that helps readers find exactly what they’re looking for.
- A settings page that allows you to customise and configure your blog.

## Requirements

- PHP 8.1 or newer
- A standard web server (Apache or Nginx)
- **Required PHP extensions:** `mbstring`, `xml`
- **Recommended PHP extensions:** `curl`, `zip`, and `gd` or `imagick` for responsive media variants
- **Required for Plus protocols and connectors:** `pdo_sqlite`, `fileinfo`
- **Required for cross-origin IndieAuth clients:** `curl`
- Write access to `/config`, `/content`, and `/data`

> **Nginx users:** configure PHP-FPM and include the deny rules from
> [`docs/nginx-security.conf.example`](docs/nginx-security.conf.example) in the site `server`
> block. They prevent direct downloads of the private SQLite database, credentials, drafts,
> and Markdown sources. Apache installations receive equivalent rules from `.htaccess`.

## Development use

The component keeps a local PHP/SQLite/filesystem mode because it is the smallest
reproducible development and recovery environment for fischr.org. It is not an
independently supported installation path. Production updates are assembled, verified,
backed up, and rolled back by the owning deployment; the CMS never downloads or installs
a release itself.

## Content
Posts live in `content/posts` and pages live in `content/pages`. New admin and Micropub
uploads share the selected media provider. The default stores public files under
`content/media/YYYY/MM`; older `content/images/[post/page-slug]` URLs remain compatible.
The post and page editors accept images plus MOV, MP4, and WebM videos. Managed videos
referenced through Micropub appear in the same editor media list as direct admin uploads.

## Notes
- Pure Blog is intentionally minimal and designed for personal sites.
- HTML in Markdown is supported.
- Pure Blog Plus preserves the original Pure Blog license and attribution. See
  [LICENSE.md](LICENSE.md).
