# Micropub server

Pure Blog Plus advertises a local Micropub endpoint from every public page. Compatible
clients use the built-in IndieAuth server to obtain bearer tokens, then manage posts and
media without a separate publishing service.

The endpoint supports:

- form-encoded and Microformats2 JSON `h-entry` creation
- named articles and untitled notes
- configuration and source queries
- `replace`, `add`, and `delete` updates
- post deletion
- image, audio, and video uploads through the same selected media provider as the admin editor

Creating, updating, deleting, and uploading require the matching `create`, `update`,
`delete`, or `media` scope. Tokens are accepted in the Authorization header and, for
form-encoded requests, in the `access_token` field.

Posts remain ordinary files in `content/posts`. Native title, content, date, status, tags,
and description fields use the existing Pure Blog representation. Supported Micropub
relationships and media properties use reserved `micropub_*` front-matter keys so the
admin editor can preserve them. SQLite contains credentials and operational state, never a
second copy of editorial content.

Media Endpoint uploads use the bundled plugin contract. The standalone default stores them
under `content/media/YYYY/MM` with random names. The server determines the actual MIME type,
accepts a bounded set of image, audio, and video formats, rejects files above 100 MB, and
never downloads media supplied by external URLs. Image sources are retained privately;
when GD or Imagick is available the pipeline creates responsive WebP variants. The returned
URL belongs to the selected provider, so an explicitly configured S3-compatible plugin can
return a custom media-domain URL without changing Micropub behavior.

Syndication targets are empty until an optional social connector is configured. Unknown
post properties are ignored as required by Micropub rather than being written into front
matter.

## Verification

Run the isolated mapping and authorization contract with:

```sh
php tests/plus-micropub.php
php tests/plus-plugins-media.php
```

The test covers form and JSON parsing, reserved commands, articles, notes, source
properties, token authentication, and scope boundaries.
