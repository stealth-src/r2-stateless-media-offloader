# Architecture

A high-level map of the moving parts. For usage see
[CONFIGURATION.md](CONFIGURATION.md) and [MIGRATION.md](MIGRATION.md).

The plugin is **non-destructive**: it stores a little post-meta per attachment and
rewrites URLs at render time. The WordPress database and post content are never
altered, so deactivating cleanly reverts to default local URLs.

---

## Components (`includes/`)

| Class | Role |
|---|---|
| `Settings` | Resolves config (wp-config constant → DB option → default). Encrypts/decrypts the secret. Builds object keys and the public base URL. |
| `R2_Client` | S3-compatible R2 access with native AWS SigV4 over the WordPress HTTP API (no AWS SDK): `upload`, `download`, `head`, `list`, `delete`. |
| `Offloader` | On new uploads, pushes the original + every size to R2 and records the meta; on `delete_attachment`, reaps the attachment's R2 objects via the manifest. |
| `URL_Rewriter` | At render time, rewrites media URLs (attachment URL, `src`, `srcset`, original-image, thumbnail) to the custom domain — only for offloaded attachments. |
| `Local_Fallback` | Stateless read path: restores a file from R2 on demand for image edits / regeneration, and guards attachment metadata against temp-path corruption. |
| `Migrator` + `Migration_Runner` | Bulk-migrate the existing library: per-variant adopt/upload, batched and resumable, driven by WP-Cron with an option-based lock + compare-and-swap state. |
| `Admin_Settings` / `Admin_Migration` | The Settings and Media → Migrate to R2 screens (+ AJAX). |
| `CLI` | `wp r2offload sync` (and friends) for large libraries. |

---

## Per-attachment metadata

| Meta key | Meaning |
|---|---|
| `_r2offload_synced` | `1` once the attachment is fully on R2 (presence = "migrated"). |
| `_r2offload_key` | The original's actual R2 key, captured at offload — so resolution is stable even if `path_prefix` changes later. |
| `_r2offload_objects` | The **ownership manifest**: every R2 key this attachment owns, so deletion reaps exactly those and no more. |
| `_r2offload_synced_at` | First-sync timestamp. |

These are the only persistent traces; uninstall removes them (never the media).

---

## Lifecycle

```
  Upload ──► Offloader ──► R2 bucket  (original + all sizes)
                │
                └─► writes _r2offload_synced / _r2offload_key / _r2offload_objects

  Page render ──► URL_Rewriter ──► https://<custom-domain>/<key>   (offloaded attachments only)

  Stateless image edit / regenerate ──► Local_Fallback restores the file from R2
                                        ──► WordPress edits it ──► Offloader re-offloads

  Delete attachment ──► Offloader.delete() ──► removes the manifest's R2 objects

  Existing library ──► Migrator (CLI or admin) ──► adopt-or-upload each variant ──► register
```

---

## Design choices worth knowing

- **S3 API for bucket operations; custom domain for serving only.** Offload,
  migration, adoption, and deletion all authenticate to the bucket directly
  (`<account>.r2.cloudflarestorage.com`). The custom domain is used purely to build
  browser-facing URLs. (This is why the CDN domain never affects migration.)
- **Keys are stored, not recomputed.** Each attachment's `_r2offload_key` is fixed
  at offload time, so a later `path_prefix` change can't split an attachment from
  where its bytes live.
- **Idempotent migration.** Each variant is independently existence-checked
  (with a size guard), so external copies (Super Slurper), partial runs, and
  stop/restart all converge without re-transferring correct objects.
- **Zero egress by design.** R2 charges nothing for egress; served through a
  Cloudflare custom domain, delivery is free and only storage is billed.
- **Render-time, reversible rewriting.** No database rewrite, no content
  mutation — deactivate and you're back to stock WordPress URLs.
- **Hooks, not a stream wrapper.** The uploads directory stays a real
  directory; the plugin intercepts the standard media hooks
  (`add_attachment`, the metadata filters, `delete_attachment`). A virtual
  `s3://`-style uploads path would force every `$editor->save()` through the
  network mid-generation and break any code that touches files directly
  (`realpath()`, `ZipArchive`, exec'd binaries). Hooks keep full ecosystem
  compatibility and let uploads be batched (below).
- **Uploads are deferred out of the generation window.** Sub-size generation
  is detected from its start (`add_attachment` for new uploads,
  `intermediate_image_sizes_advanced` for resumes); incremental metadata
  saves are skipped and one batched upload runs on the final
  `wp_generate_attachment_metadata` pass, with a shutdown backstop for
  resume paths that never fire it. No R2 I/O between GD resizes.
- **The synced flag is the URL switch, and it never lies.** `_r2offload_synced`
  is only written when the original and every metadata-listed size are
  confirmed in R2 — so the rewriter can never emit a CDN URL whose object
  doesn't exist. Already-synced attachments upload inline per key (the filter
  runs before the metadata write), preserving the same ordering during edits
  and regeneration.
- **Stateless cleanup is evidence-based.** Local copies are deleted at request
  shutdown, only when the attachment ends the request synced; the shutdown
  backstop (which cannot prove generation finished) cleans uploaded size
  files but retains regeneration sources — image and PDF originals — until a
  complete inline pass confirms completion.
