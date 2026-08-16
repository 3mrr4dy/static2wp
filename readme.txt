=== Static2WP ===
Contributors: 3mrr4dy
Tags: landing page, html, static page, page template, upload
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload an HTML or ZIP file and that page shows the file to visitors — not the theme. Create a page from a file, or attach one while editing.

== Description ==

Have a ready-made landing page (an exported HTML file, a ThemeForest landing, an AI-generated page) and want it live on your site without touching your theme? This plugin does exactly that:

1. Go to **Pages → Page file** and drop a single `.html` file, or a `.zip` containing your `index.html` plus `css/`, `js/`, `images/` folders. The plugin creates a new WordPress page for it (or you can attach the file to an existing page).
2. Or open any page in the editor and click **Show a file on this page**.
3. Done — visitors see the file, not the theme and not a builder.

= What visitors see =

* Your uploaded HTML **completely takes over** the chosen page: its own `<head>`, styles and scripts. The theme header, footer and menus are not loaded.
* Relative links inside a ZIP (images, stylesheets, scripts, extra `.html` files, `poster`, lazy-load `data-src`) are rewritten automatically to the uploads folder.
* Deactivate the file anytime and the normal WordPress page is back.

= Page-editor integration =

* **Editor canvas** occupies the content slot in both the block editor and the classic editor.
* **No file yet**: a **Show a file on this page** button sits above the visual editor. The editor stays visible until you click it.
* **File assigned**: a live status panel (view, show file / use normal page, remove file) plus **Edit page text** to reveal the WordPress editor again.
* **Replace file** is behind an explicit confirm — drop or choose a file, then click **Use this file**.
* **Versions row** appears when more than one file has been uploaded: roll back or delete older files.
* **File column** in the Pages list shows On / Off at a glance.

= Landing manager =

* **Pages → Page file** is the central screen: upload a file to create a page, or attach it to an existing unused page.
* Table of pages that already have a file: view, edit, switch visitors between the file and the normal page, or remove the file.
* Live search filter.

= Global tracking codes (GTM & co.) =

* Paste your **head code** (GTM script, analytics, pixels) and **body code** (GTM noscript) once under Pages → Page file → Tracking codes.
* Injected into **landing pages** (which bypass the theme) and — optionally — into **all regular pages/posts** too.

= SEO =

Because full-takeover bypasses the theme, the plugin inherits WordPress (and SEO-plugin) head output for the page, and can fill remaining gaps from the page title, excerpt, permalink and featured image:

* `<title>` from the page title
* `<meta name="description">` from the page excerpt
* canonical link, Open Graph (title, description, URL, type), `og:image` from the featured image, Twitter card

= Management =

* **Show file / Use normal page** with one click.
* **Replace file** uploads a new version while keeping the same page assignment.
* **Remove file** deletes the landing files; the WordPress page is kept.
* One file per page — pages that already have a file are omitted from the attach dropdown.

= Safety =

* Uploads are validated (`.html` / `.zip` only, 100 MB max; HTML entry capped at 2 MB).
* ZIP members are extracted one-by-one onto an allow-list of extensions (no `extractTo()`, no symlinks). SVG is blocked by design.
* Publishing raw HTML requires the `unfiltered_html` capability. Creating a new page requires `publish_pages`; attaching to an existing page requires `edit_post` for that page.
* Takeover responses send `nocache` headers so a page cache cannot pin stale HTML after deactivate or rollback.
* Saving raw tracking code additionally requires `unfiltered_html`.

== Installation ==

1. Upload the `static2wp` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New → Upload).
2. Activate the plugin.
3. Go to **Pages → Page file** to publish a file as a new page — or open any page and click **Show a file on this page**.

== Frequently Asked Questions ==

= Does it change my theme or my page content? =

No. The plugin only intercepts the page on the frontend while the file is active. Your page content in the editor stays exactly as it was. Use **Edit page text** on the canvas if you want to work on that content again.

= What happens to links inside my HTML? =

Relative `src`, `href`, `srcset`, `poster`, common lazy-load attributes and CSS `url(...)` references are rewritten to the landing's uploads folder at serve time. Absolute URLs (https://…), anchors (#…), `mailto:` and `tel:` links are left untouched.

= My ZIP has no index.html =

The first HTML file found is used as the entry page instead.

= Can two landings share one page? =

No — one file per page. Create another WordPress page for the second file.

= Does it work with a static homepage? =

Yes. You can assign a file to the page set as your homepage under Settings → Reading.

= Will my GTM/analytics fire on landing pages? =

Yes — paste it once under Pages → Page file → Tracking codes. It's injected into every landing page, and optionally into all regular pages and posts as well.

= Where do I set the meta description for a landing? =

On the page itself — the plugin uses the page's excerpt as the meta description and its featured image as `og:image`.

= Limitations =

* Uploaded files live under the WordPress uploads directory and are served as static files. Do not use this plugin on pages that require password privacy — anyone who knows (or guesses) the uploads URL can fetch the HTML and assets directly.
* Relative paths built in inline JavaScript are not rewritten.
* SVG uploads are blocked by design (they can execute script when opened directly).

== Changelog ==

= 1.7.1 =
* UI: the admin screen and menu now use the Static2WP name.
* New: automatic one-time migration from the legacy HTML Landing Pages data (hlp_landings / hlp_settings options, _hlp_landing meta, uploads/html-landing-pages) into the Static2WP namespace, deleting the legacy data. Runs on activation and on any load while legacy data exists, so in-place updates migrate too.
* Uninstall now also clears the legacy namespace.
= 1.7.1 =
* Renamed: the plugin is now **Static2WP** (previously HTML Landing Pages). All prefixes, text domain, options and the uploads directory moved to the static2wp / s2wp_ namespace. Uploaded files keep working only if you migrate the old `hlp_landings` option to `s2wp_landings` (fresh installs need nothing).
= 1.7.1 =
* Fix: on the block editor the canvas now mounts into the Gutenberg skeleton. A canvas still sitting in the hidden footer template is no longer treated as mounted, so the panel appears and the visual editor is no longer blank.
* New: **Edit page text** reveal toggle — the classic textarea is kept (TinyMCE stays off so the tab spinner cannot hang); both classic and block editors un-hide when revealed.
* i18n: remaining admin JS strings live in `HLP.strings`; `view_url` is encoded before it is written into an href; `wp_set_script_translations` is registered for the admin script.
* Uninstall now deletes the `_s2wp_landing` page-meta index before removing options and files.
* URL rewrite preserves the original doctype when libxml drops it.
* Docs: readme matches the 1.6 product (Pages → Page file, editor canvas, limitations).

= 1.5.0 =
* Security: ZIP members are extracted via `getFromIndex` + `file_put_contents` onto an allow-list (no `extractTo()`, no symlinks), with realpath containment and a 2 MB HTML cap.
* Security: publishing a landing or a new version requires `unfiltered_html`; creating a page requires `publish_pages`; attaching to an existing page requires `edit_post`. Auto-drafts are rejected.
* Security: GET deactivate handler removed; takeover responses send `nocache` headers; `template_redirect` runs at priority 11 and skips feeds, embeds and robots.
* Hardened uploads directory (`index.php` / `.htaccess`); post-meta index `_s2wp_landing` for the public lookup.
* Admin-bar markup is stripped from takeovers so logged-in viewing no longer dumps an unstyled bar over the landing.
* Tab-spinner fixes for the hidden classic editor.

= 1.4.0 =
* Simplified flow: **Pages → Page file** creates a new page from an uploaded file (or attaches it to an existing page).
* Editor canvas replaces the side meta box — **Show a file on this page**, live status panel, replace behind an explicit confirm, versions row.
* Removed the stats bar, builder warnings, and the editor-notice deactivate link.

= 1.3.6 =
* Editor canvas is a file drop zone for a new version. The live preview iframe is gone.

= 1.3.5 =
* Fix: editor tab spinner never stopped — hidden TinyMCE iframe + preview iframe kept the document "loading".

= 1.3.4 =
* Fix: viewing a live landing while logged in dumped the unstyled admin bar (WPCode, profile, logout) over the page.

= 1.3.3 =
* Editor canvas is now a live preview of the landing, with a single toolbar. Duplicate sidebar controls and warning essays are gone.

= 1.3.2 =
* Fix: the landing canvas now occupies the classic editor area itself (the empty Visual/Code box) instead of staying hidden in the footer until JavaScript moved it.
* Fix: the editor page tab no longer spins forever. A MutationObserver on `document.body` was remounting the canvas on every DOM change.

= 1.3.0 =
* New: WordPress inheritance — landing pages now automatically inherit everything the page would receive: SEO plugin output (Yoast, RankMath, …), tracking codes, site icons and any wp_head / wp_footer injections. Design assets (theme CSS/JS) are stripped from the capture so the landing's design stays fully independent.
* New: builder-style editor canvas — when a page has a landing, the content editor area is replaced by a management panel (works in Gutenberg and the classic editor), with a "show the WordPress editor" toggle. The side meta box appears only for pages without a landing.
* New: version management — every upload creates a new version (v1, v2, …). Upload new versions with one click, roll back to any previous version, delete old versions. Legacy single-upload landings are treated as v1 automatically.
* Landing's own SEO tags (title, description, canonical, OG/Twitter) are stripped so the page inherits WordPress' output instead.

= 1.2.2 =
* Fix: uploading a landing from the meta box of a brand-new, unsaved page (auto-draft) could bind it to a discarded ID — the meta box now asks to save the page first, and the server rejects auto-drafts.
* Fix: potential PHP fatal when AJAX refresh hit a deleted page.

= 1.2.1 =
* Fix: activate/deactivate, replace file and delete failed with a generic browser alert — landing IDs were lowercased during nonce verification, breaking the security check. IDs are now validated case-preserving, and new IDs are always lowercase.
* New: all browser alert() popups replaced with native WordPress admin notices (success/error) that auto-dismiss; real server error messages are now shown instead of a generic string, including for HTTP 400 JSON responses.

= 1.2.0 =
* Fix: users could unknowingly override a page that already had Elementor/block content — now the plugin detects the builder and warns in the editor notice, meta box, page dropdown, landings table, and Pages list column.
* New: prominent warning notice on the page editor while a landing is active, with one-click View / Deactivate.
* New: stats bar (total / active / inactive) in the landing manager.
* New: richer landings table — entry file, page slug, file count + size, last-updated date, "Files missing" health badge, live filter, empty state.
* New: one-click deactivate URL handler (admin-post).

= 1.1.0 =
* New: HTML Landing Page meta box in the page editor — upload, activate, replace, remove without leaving the editor (refreshes in place).
* New: "Landing" status column in the Pages list table.
* New: global head/body tracking codes (GTM etc.) injected into landings and optionally all public pages.
* New: SEO meta injection into landings (title, description from excerpt, canonical, Open Graph, featured image).
* Landing name now defaults to the page title.

= 1.0.0 =
* Initial release: single .html or .zip upload, page assignment, full-takeover serving with automatic URL rewriting, activate/deactivate, replace file, delete.
