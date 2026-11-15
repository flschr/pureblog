# Pure Blog Plus

Pure Blog Plus is a maintained fork of [Pure Blog](https://github.com/kevquirk/pureblog),
created by [Kev Quirk](https://kevquirk.com). It keeps Pure Blog's small,
Markdown-first foundation and develops optional personal-publishing features around it.

The current `main` branch is behaviorally identical to Pure Blog 3.9.2. The first Plus
release is being designed around local-first IndieWeb publishing and an optional,
loosely-coupled connection to GoToSocial Plus. Pure Blog Plus remains useful on its own;
connecting another service is never required.

See [the Plus architecture](docs/plus-architecture.md) for the agreed product boundaries
and [the upstream workflow](docs/upstream.md) for how this fork follows Pure Blog.

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
- **Recommended PHP extensions:** `curl`, `zip`
- Write access to `/config`, `/content`, and `/data`

> **Nginx users:** you will also need `php-fpm` configured alongside Nginx, as PHP-FPM is required to process PHP when not using Apache.

## Getting started
All you need to run Pure Blog is a host that supports PHP (pretty much all of them do). Once you have that, all you need to do is:

1. Download the Pure Blog package.
2. Extract the zip file and upload the contents to your web server.
3. Visit the URL of your blog and setup will automatically start.
4. Once your site is setup, visit `/admin` and login to your new blog.

## Content
Posts live in `content/posts` and pages live in `content/pages`. When images are uploaded they are stored in `/content/images/[post/page-slug]`.

## Notes
- Pure Blog is intentionally minimal and designed for personal sites.
- HTML in Markdown is supported.
- Pure Blog Plus preserves the original Pure Blog license and attribution. See
  [LICENSE.md](LICENSE.md).
