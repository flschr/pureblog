# Bundled plugins

Pure Blog Plus ships an internal plugin contract for reviewed providers used by the owning
deployment. It may change together with fischr.org and is not a public compatibility promise.
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

Each managed image or video may also retain one editable default description in the operational
media record. It is not a second copy of editorial content. When saved Markdown, HTML, or a
structured Micropub photo contains an empty alt text, the description is copied into that
occurrence and remains ordinary editable post content. An authored alt text is never replaced.
For a managed video without an authored `video.alt`, the description becomes an assistive
`aria-label` and is not rendered as a visible caption. The editor can edit or clear the reusable default.

## Optional providers

`s3-media` uploads the same originals and variants to an administrator-configured,
S3-compatible HTTPS endpoint. It requires a bucket, region, access key, secret key, and
public base URL. Requests are signed locally with Signature Version 4, do not use an
environment proxy, pin the request to a public DNS result, and stream upload bodies from disk. The plugin is inactive until
it is selected and completely configured.

`openai-alt-text` is an optional editor aid. When selected, the smallest public responsive image and
up to 2,500 characters of editorial context are sent to the configured OpenAI API account.
The returned text is stored as the editable media description and inserted as alt text only
when the relevant occurrence is empty. Micropub media uploads generate the same reusable
description; create and update requests copy it into empty Markdown, HTML, or structured photo
alt text. Micropub clients can provide their own alt text without this plugin, and that value
always wins. The standalone CMS never requires an AI service. Generation failures never fail
the media upload or post save.

`personal-photo-gallery` is an optional derived view over managed images that occur in published
posts and in the connected owner's public or unlisted original Social statuses. Social authorship
is verified against the immutable account ID stored for that connection; boosts, foreign authors,
private statuses, quoted media, and link previews are never gallery sources. It never copies
editorial content and never changes a post, status, or media file. The public route
defaults to `/gallery` and renders a server-side proportional-row layout with intrinsic dimensions,
responsive sources, cursor pagination, progressive enhancement, and the shared lightbox. The first
public version is deliberately one chronological stream without filters. Add the configured route to
the site's custom navigation when it should appear in the public menu.

The gallery includes only images that are confidently classified as camera photographs or photographs
of a physical work in a real scene. Direct covers, film posters, album art, screenshots, illustrations,
documents, and uncertain images stay out. New uploads retain only two privacy-minimised capture signals
before metadata is stripped: whether camera metadata and an original capture time were present. No GPS
or full EXIF data is stored. Existing managed images can be classified by selecting a provider for
`media.photo_classification`; the bundled OpenAI provider sends the smallest responsive image plus up
to 2,500 characters of editorial context and does not request API-side storage. A provider failure
leaves the image undecided and is retried by the background job runner.

The private gallery admin page supports search and the states visible, hidden, automatically excluded,
and undecided. A manual show or hide is a separate override that survives rebuilds, reclassification,
and provider changes. Removing the override restores automatic selection. Hiding an image affects only
the gallery and its lightbox sequence; the source post or status and media URL remain published.
The projection is rebuilt in background jobs after activation and updated after later post saves,
deletions, or successful Social reconciliations. The same captured image is shown only once when it
occurs in both sources, and its manual visibility override applies everywhere.

Managed deletion is durable: the database marks an asset as deleting before invoking its
provider. If provider or local cleanup fails, the record remains retryable and a later attempt
continues the same idempotent deletion.

Plugin secrets are saved in the existing private PHP configuration file. They are never
rendered back into the settings form. Disabling an optional plugin leaves Markdown, media
records, and already published URLs unchanged.

## API contract

Every bundled manifest declares a stable identifier, semantic version, plugin API major,
settings schema, and callable capabilities. Core validation rejects malformed identifiers,
unknown API majors, invalid settings schemas, duplicate registrations, disabled providers,
and providers that do not implement the selected capability. The initial API major is `1`.
Public-route plugins may pair `site.route` with a side-effect-free `site.route_match` capability;
the front controller can then check only content front matter for collisions before dispatching,
instead of parsing every post and page body on a cold plugin-route request.

Verification:

```sh
php tests/plus-plugins-media.php
```
