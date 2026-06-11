# Hooks & extensibility

R2 Stateless Media Offloader exposes a small, deliberate set of **filters** so you
can adapt its behaviour without forking. There are no custom actions — the plugin
hooks into WordPress core events (upload, metadata, delete, attachment URLs) and
exposes the decision points below.

All filters use the `r2offload_` prefix. Add them from a theme `functions.php`, an
mu-plugin, or your own plugin.

---

## `r2offload_offload_on_upload`

Decide whether a freshly uploaded attachment (original + generated sizes) is pushed
to R2. Return `false` to skip it.

```php
apply_filters( 'r2offload_offload_on_upload', bool $offload, int $attachment_id, array $metadata )
```

| Param | Type | Default | Description |
|---|---|---|---|
| `$offload` | `bool` | `true` | Whether to offload this upload. |
| `$attachment_id` | `int` | — | The attachment being saved. |
| `$metadata` | `array` | — | The attachment metadata (`file`, `sizes`, …). |

**Use it to** freeze offload-on-upload during a migration window — e.g. while another
plugin (wp-stateless, a CDN offloader) still owns ingestion — so new uploads aren't
pushed to R2 until this plugin fully takes over.

```php
// Pause offloading new uploads until cutover is complete.
add_filter( 'r2offload_offload_on_upload', '__return_false' );

// Or: skip a specific MIME type.
add_filter( 'r2offload_offload_on_upload', function ( $offload, $id, $meta ) {
	return get_post_mime_type( $id ) === 'application/zip' ? false : $offload;
}, 10, 3 );
```

---

## `r2offload_mirror_deletes`

Decide whether deleting an attachment in WordPress also deletes its objects from R2.
Return `false` to leave the R2 objects in place.

```php
apply_filters( 'r2offload_mirror_deletes', bool $mirror, int $attachment_id )
```

| Param | Type | Default | Description |
|---|---|---|---|
| `$mirror` | `bool` | `true` | Whether to delete the matching R2 objects. |
| `$attachment_id` | `int` | — | The attachment being deleted. |

**Use it to** stop mirroring WordPress deletions to R2 during a migration window, so
R2 and a still-live source can't diverge before cutover is final — or to keep R2 as
an immutable archive that WordPress deletions never touch.

```php
add_filter( 'r2offload_mirror_deletes', '__return_false' );
```

---

## `r2offload_restore_to_uploads`

**Stateless mode only.** When a local copy is needed on demand (e.g. WordPress
regenerates thumbnails or edits an image), the plugin restores the file from R2.
This filter controls *where* it writes: the canonical uploads location (`true`) or
the system temp dir (`false`).

```php
apply_filters( 'r2offload_restore_to_uploads', bool $use_uploads, string $local_path )
```

| Param | Type | Default | Description |
|---|---|---|---|
| `$use_uploads` | `bool` | `true` | Restore into the uploads dir when it's writable and inside the uploads basedir; otherwise the plugin falls back to the temp dir regardless. |
| `$local_path` | `string` | — | The canonical local path WordPress expects. |

**Use it to** force temp-dir restores on read-only or ephemeral uploads dirs (the
plugin already falls back automatically when uploads isn't writable; this lets you
opt out explicitly). The plugin only ever writes inside the uploads basedir — paths
outside it always fall back to temp.

```php
// Never write restored files back to uploads (always use temp).
add_filter( 'r2offload_restore_to_uploads', '__return_false' );
```

---

## `r2offload_max_upload_bytes`

The maximum object size (in bytes) the plugin will PUT to R2. The WordPress HTTP API
has no streaming PUT, so the plugin reads the whole file into memory; to protect
against OOM it refuses files above this cap (default **50 MB**). Chunked multipart
upload for large files (video) is a tracked follow-up.

```php
apply_filters( 'r2offload_max_upload_bytes', int $bytes, string $local_path, string $key )
```

| Param | Type | Default | Description |
|---|---|---|---|
| `$bytes` | `int` | `52428800` (50 MB) | Max size to upload. `0` disables the cap (not recommended until streaming lands). |
| `$local_path` | `string` | — | Local path of the file about to be uploaded. |
| `$key` | `string` | — | The target R2 object key. |

**Use it to** raise the cap on hosts with more PHP heap headroom.

```php
// Allow up to 200 MB on a host with plenty of memory.
add_filter( 'r2offload_max_upload_bytes', function () {
	return 200 * 1024 * 1024;
} );
```

> Files above the cap fail with a `r2offload_file_too_large` `WP_Error` rather than
> silently skipping — so they surface in the migrator's error list for follow-up.

---

## Configuration via constants

Every setting can also be pinned in `wp-config.php` (and then takes precedence over
the admin UI) using `R2OFFLOAD_*` constants — see
[CONFIGURATION.md](CONFIGURATION.md). These aren't filters, but they're the other
half of the programmatic-control story.

## Internal identifiers (not a public API)

Post meta (`_r2offload_synced`, `_r2offload_key`, `_r2offload_objects`,
`_r2offload_synced_at`), the `r2offload_settings` option, and the
`r2offload_migrate_tick` cron hook are **internal** — they may change between
versions. Prefer the filters above and the WP-CLI commands
([CLI.md](CLI.md)) for integration.
