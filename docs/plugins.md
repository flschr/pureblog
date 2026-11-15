# Bundled plugins

Pure Blog Plus ships a versioned plugin API for reviewed providers included in the release.
The Plugins settings page enables them and selects one provider for each exclusive
capability. This first contract deliberately does not scan arbitrary directories, install
ZIP files, or download executable code.

## Standalone behavior

`local-media` is always available and cannot be disabled. It stores the public original
and any responsive variants under `content/media/YYYY/MM`. Uploads still work when neither
GD nor Imagick is installed; responsive WebP variants require one of those extensions.
Both the admin editor and Micropub Media Endpoint call the same pipeline.

An installation hook named `on_prepare_media_upload` may convert an otherwise unsupported
local upload before the pipeline accepts it. The filter receives `path`, `name`, detected
`mime_type`, `size`, and `temporary`. It must return a local file path; the result is subjected
to the same size, MIME, image-dimension, storage, and database checks as every other upload.
Set `temporary` to `true` only when the returned file may be removed after ingestion. This
boundary is intended for local format adapters such as HEIC conversion, not remote storage.

The pipeline validates the detected MIME type and size before moving a file, retains a
private source under `data/plus/media-originals`, strips supported image metadata, records
operational media metadata in the private Plus SQLite database, and renders intrinsic
dimensions plus `srcset` for known images. Editorial alt text remains in Markdown or the
structured Micropub `photo` property and is never replaced during rendering.

Each managed image may also retain one editable default description in the operational
media record. It is not a second copy of editorial content. When saved Markdown, HTML, or a
structured Micropub photo contains an empty alt text, the description is copied into that
occurrence and remains ordinary editable post content. An authored alt text is never replaced.
The media library can edit or clear the reusable default.

## Optional providers

`s3-media` uploads the same originals and variants to an administrator-configured,
S3-compatible HTTPS endpoint. It requires a bucket, region, access key, secret key, and
public base URL. Requests are signed locally with Signature Version 4, do not use an
environment proxy, and pin the request to a public DNS result. The plugin is inactive until
it is selected and completely configured.

`openai-alt-text` is an optional editor aid. When selected, the private retained image and
up to 2,500 characters of editorial context are sent to the configured OpenAI API account.
The returned text is stored as the editable media description and inserted as alt text only
when the relevant occurrence is empty. Micropub media uploads generate the same reusable
description; create and update requests copy it into empty Markdown, HTML, or structured photo
alt text. Micropub clients can provide their own alt text without this plugin, and that value
always wins. The standalone CMS never requires an AI service. Generation failures never fail
the media upload or post save.

Plugin secrets are saved in the existing private PHP configuration file. They are never
rendered back into the settings form. Disabling an optional plugin leaves Markdown, media
records, and already published URLs unchanged.

## API contract

Every bundled manifest declares a stable identifier, semantic version, plugin API major,
settings schema, and callable capabilities. Core validation rejects malformed identifiers,
unknown API majors, invalid settings schemas, duplicate registrations, disabled providers,
and providers that do not implement the selected capability. The initial API major is `1`.

Verification:

```sh
php tests/plus-plugins-media.php
```
