# WP-CLI commands

All commands live under `wp r2offload`. They drive the same engine as the admin
**R2 Migration** page, but run in the foreground — better for large libraries and
scripted/CI use. A CLI run and the background (admin) migration are mutually
exclusive: the CLI refuses to start while the admin runner holds its lock.

```bash
wp r2offload test     # validate R2 credentials end to end
wp r2offload sync     # copy the existing library into R2
wp r2offload pull     # download R2 objects back to local
wp r2offload reset    # clear offload registration (no downloads)
```

> On containerised hosts, prefix with the deployment exec, e.g.
> `kubectl exec deploy/<name> -- wp r2offload sync --allow-root`.

---

## `wp r2offload test`

End-to-end validation of your R2 configuration — a SigV4 round-trip that connects,
uploads a tiny object under `r2offload-test/`, HEADs it, lists it, and deletes it.
Run this first whenever credentials change.

```bash
wp r2offload test
```

No options. Exits non-zero on the first failed step with the underlying error.

---

## `wp r2offload sync`

Walk the `attachment` post type in batches and copy each attachment's original plus
every registered intermediate size into R2. Reads from a local copy when available,
otherwise fetches from the current public URL (whatever offloader is in place today),
so it works whether your media is local, on GCS, on S3, or already on R2. The R2 key
matches each file's `_wp_attached_file` relative path.

| Option | Default | Description |
|---|---|---|
| `--batch=<n>` | `100` | Attachments processed per batch. |
| `--dry-run` | — | Report counts + total bytes; upload nothing. |
| `--verify` | — | HEAD-check expected keys in R2 and report any missing. Read-only. |
| `--force` | — | Re-upload (replace) objects already in R2 instead of adopting them. |
| `--timeout=<seconds>` | `300` | Per-file download timeout for remote fetches. |

```bash
wp r2offload sync --dry-run          # preview counts + total size, uploads nothing
wp r2offload sync --batch=250        # larger batches
wp r2offload sync --verify           # audit: every expected object exists in R2
wp r2offload sync --force            # repair a stale/wrong bucket
wp r2offload sync --timeout=900      # libraries with large video
```

Outcome counters (Uploaded / Updated / Adopted / Skipped) and the adoption logic are
explained in [MIGRATION.md](MIGRATION.md). `--verify` is the read-only pre-gate to
run before decommissioning any old storage backend — it must report **0 missing**.

---

## `wp r2offload pull`

Download R2 objects back to the local uploads dir and clear each attachment's offload
registration — the reverse of Stateless mode. Each attachment's postmeta is cleared
only after **all** its files download successfully, so a partial failure leaves the
R2 copy serving that attachment (images stay live) while the rest continue.

| Option | Default | Description |
|---|---|---|
| `--batch=<n>` | `50` | Attachments processed per batch. |
| `--dry-run` | — | Report what would be downloaded; write nothing. |
| `--yes` | — | Skip the confirmation prompt. |

```bash
wp r2offload pull --dry-run
wp r2offload pull --yes
wp r2offload pull --batch=25
```

---

## `wp r2offload reset`

Clear all R2 offload registration from the media library **without downloading
files**. Use when switching to a different offload plugin that has already taken
ownership of the files, or to force a clean re-migration.

> ⚠️ In **Stateless mode** (local copies deleted) this makes images 404 immediately.
> Run `pull` instead unless the new provider is already serving the files.

| Option | Default | Description |
|---|---|---|
| `--dry-run` | — | Report how many attachments would be reset; change nothing. |
| `--yes` | — | Skip the confirmation prompt. |

```bash
wp r2offload reset --dry-run
wp r2offload reset --yes
```
