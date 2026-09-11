# Static2WP WP-CLI

Introduced in 1.8.0. Command group: `wp s2wp`.

There is no HTTP upload. WP-CLI either reads a file that already exists on the machine running `wp` (`--file`) or downloads one (`--url`).

In-plugin help:

```
wp help s2wp
wp help s2wp add
```

## `--file` vs `--url`

| Flag | What it does |
| --- | --- |
| `--file=<path>` | Path on **this** machine (absolute, or relative to the current working directory). The source file is copied, not deleted. |
| `--url=<url>` | `http://` or `https://` URL whose path ends in `.html`, `.htm`, or `.zip`. Downloaded with `download_url()` (60s timeout), ingested, then the temp file is deleted. |

Do not pass both. One of them is required for `add` and `version`.

Limits (same as the admin uploader): `.html` / `.htm` / `.zip` only, 100 MB max, HTML entry file 2 MB max. The file is checked with `wp_check_filetype_and_ext()`, not the filename alone.

Typical `--file` flow on a remote host:

```
scp landing.zip user@server:/tmp/landing.zip
wp s2wp add --file=/tmp/landing.zip --name="Summer" --user=admin
```

## `--user`

`list` and `get` run without a WordPress user.

Every other command calls `get_current_user_id()` and exits with:

```
Error: Pass --user=<id|login> so the command runs as a WordPress user.
```

if you omit `--user`.

| Command | Capabilities |
| --- | --- |
| `add` (no `--page`) | `unfiltered_html` and `publish_pages` |
| `add --page=…` | `unfiltered_html` and `edit_post` on that page |
| `version` | `unfiltered_html` |
| `activate`, `deactivate`, `rollback`, `delete-version`, `delete` | `edit_pages` |

## Commands

### `wp s2wp list`

No user required.

```
wp s2wp list
wp s2wp list --format=json
wp s2wp list --format=ids
wp s2wp list --format=count
```

`--format` default: `table`. Other values: `csv`, `json`, `yaml`, `ids`, `count`.

Table columns: `id`, `name`, `page` (slug, or page ID if the page is missing), `page_id`, `status` (`on` / `off`), `type`, `version`.

### `wp s2wp get <id>`

No user required. Prints YAML (no `--format` flag).

```
wp s2wp get s2wp_abc123def456
```

Unknown id:

```
Error: Landing not found.
```

### `wp s2wp add`

Creates a published page when `--page` is omitted. `--page` accepts a page ID, slug (`get_page_by_path`), or URL (`url_to_postid`). `--name` is the landing title; it also becomes the new page title when a page is created. If `--name` is omitted, the file basename (new page) or the existing page title is used.

The landing is saved **active** (visitors see the file).

```
wp s2wp add --file=./dist/index.html --name="Summer" --user=admin
wp s2wp add --file=/tmp/campaign.zip --page=about --user=admin
wp s2wp add --url=https://example.com/site.zip --page=42 --user=admin
```

If that page already has a landing:

```
Error: That page already has a landing. Use `wp s2wp version <id> --file=…` instead.
```

Invalid `--page` (not a page post):

```
Error: Please pass a valid page ID, slug, or URL.
```

### `wp s2wp version <id>`

Stores a new version under `v-N/` and makes it live. Older versions stay on disk.

```
wp s2wp version s2wp_abc123def456 --file=./v2.zip --user=admin
```

### `wp s2wp activate <id>` / `wp s2wp deactivate <id>`

```
wp s2wp activate s2wp_abc123def456 --user=admin
wp s2wp deactivate s2wp_abc123def456 --user=admin
```

Activate: visitors see the file. Deactivate: visitors see the normal WordPress page. Files stay on disk.

### `wp s2wp rollback <id> --v=<n>`

`--v` is required. `0` or a missing version prints `Error: Version not found.`

```
wp s2wp rollback s2wp_abc123def456 --v=1 --user=admin
```

### `wp s2wp delete-version <id> --v=<n>`

Refuses to delete the last remaining version:

```
Error: This is the only version — delete the landing itself instead.
```

If the deleted version was live, the highest remaining version number becomes live.

```
wp s2wp delete-version s2wp_abc123def456 --v=2 --user=admin
```

### `wp s2wp delete <id>`

Deletes the landing record and files. The WordPress page is kept. Prompts unless `--yes` is passed.

```
wp s2wp delete s2wp_abc123def456 --yes --user=admin
```

## Errors from `--file` / `--url`

These strings come from `S2WP_Store::validate_local_file()` / `resolve_source()`:

| Message | Cause |
| --- | --- |
| `Pass either --file or --url, not both.` | Both flags set |
| `Pass --file=/path/to/file.zip (on this machine) or --url=https://…` | Neither flag set |
| `That file does not exist or is not readable.` | `--file` path missing |
| `Only .html and .zip files are supported.` | Wrong extension |
| `That file does not look like a real .html or .zip file.` | `wp_check_filetype_and_ext()` rejected it |
| `File is larger than 100 MB.` / `The HTML file is larger than 2 MB.` | Size caps |
| `URL must start with http:// or https://.` | `--url` scheme |
| `URL must point to a .html or .zip file.` | URL path has no `.html`/`.htm`/`.zip` |
| `Download failed: …` | `download_url()` error |
