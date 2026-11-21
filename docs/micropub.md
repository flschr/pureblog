# Micropub server

Pure Blog Plus advertises a local Micropub endpoint from every public page. Compatible
clients use the built-in IndieAuth server to obtain bearer tokens, then manage posts and
media without a separate publishing service.

The endpoint supports:

- form-encoded and Microformats2 JSON `h-entry` creation
- named articles and untitled notes
- configuration and source queries
- the post-list extension through `q=source` without a URL, including drafts
- the category-list extension through `q=category`
- advertised article, note, media, interaction, bookmark, and RSVP post types
- `replace`, `add`, and `delete` updates
- post deletion
- image, audio, and video uploads through the same selected media provider as the admin editor

Creating, updating, and deleting require the matching `create`, `update`, or `delete`
scope. The Media Endpoint accepts either `create` or the narrower `media` scope so that
standard Micropub clients can use the same posting token while installations may still
issue a dedicated upload token. Tokens are accepted in the Authorization header and, for
form-encoded requests, in the `access_token` field.

The post-list query returns up to 30 newest posts by default and accepts bounded `limit`
and `offset` parameters. It includes published, scheduled, and draft posts because the
authenticated list is an editing surface, not a public feed. Category queries include
categories used by any of those posts and accept `filter`, `limit`, and `offset`.

Posts remain ordinary files in `content/posts`. Native title, content, date, status, tags,
and description fields use the existing Pure Blog representation. Supported Micropub
relationships and media properties use reserved `micropub_*` front-matter keys so the
admin editor can preserve them. This includes replies, likes, reposts, bookmarks, RSVP
values, syndication URLs, and image/audio/video properties. SQLite contains credentials
and operational state, never a second copy of editorial content.

Media Endpoint uploads use the bundled plugin contract. The standalone default stores them
under `content/media/YYYY/MM` with random names. The server determines the actual MIME type,
accepts a bounded set of image, audio, and video formats, rejects files above 100 MB, and
never downloads media supplied by external URLs. Image sources are retained privately;
when GD or Imagick is available the pipeline creates responsive WebP variants. The returned
URL belongs to the selected provider, so an explicitly configured S3-compatible plugin can
return a custom media-domain URL without changing Micropub behavior.

If an optional alt-text provider is active, an image upload may also create an editable default
media description. A client-supplied Markdown, HTML, or structured `photo.alt` value always
remains authoritative and is never regenerated or replaced. When the client leaves the alt
text empty, Pure Blog inserts the stored description while creating or updating the post. If
there is no stored description yet, it tries to generate one at that point. The resulting alt
text is ordinary post content, is returned by a later source query, and can be edited normally.
The Media Endpoint itself still returns the standard `Location` response.

Managed videos referenced through the structured `video` property are also discoverable in
the admin editor. A client-supplied `video.alt` remains authoritative. When it is absent, an
editable stored video description is used as the accessible label without creating a visible
caption. The same MOV, MP4, and WebM formats can be selected directly in the admin editor.

Syndication targets are empty until an optional social connector is configured. Unknown
post properties are ignored as required by Micropub rather than being written into front
matter.

## Verification

Run the isolated mapping and authorization contract with:

```sh
php tests/plus-micropub.php
php tests/plus-plugins-media.php
```

The test covers form and JSON parsing, reserved commands, articles, notes, OutPost-style
draft/bookmark/RSVP payloads, list and category queries, source properties, token
authentication, Media Endpoint compatibility, and scope boundaries.
