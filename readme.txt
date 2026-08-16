=== HTML Landing Pages ===
Contributors: amrrady
Tags: landing page, html, static page, page template, upload
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload one HTML file (or a ZIP with assets) and assign it to any WordPress page — that page is served as a full standalone landing page.

== Description ==

Have a ready-made landing page (an exported HTML file, a Themeforest landing, an AI-generated page) and want it live on your site without touching your theme? This plugin does exactly that:

1. Open any WordPress page in the editor — the **HTML Landing Page** box is right there.
2. Drop a single `.html` file, or a `.zip` containing your `index.html` plus `css/`, `js/`, `images/` folders.
3. Done — the landing is active immediately. (You can also manage everything centrally under **Pages → HTML Landing**.)

= What visitors see =

* Your uploaded HTML **completely takes over** the chosen page: its own `<head>`, styles and scripts. The theme header, footer and menus are not loaded.
* Relative links inside a ZIP (images, stylesheets, scripts, even extra .html files) are rewritten automatically to the uploads folder, so everything just works.
* The WordPress page itself is untouched — deactivate the landing anytime and the normal page is back.

= Page-editor integration =

* **Meta box on every page**: upload, activate/deactivate, replace file, or remove the landing — without leaving the editor. The box refreshes in place, no page reload.
* **Landing column** in the Pages list shows status at a glance.
* Landing name defaults to the page title — it feels like part of the page, because it is.

= Content safety warnings =

A landing hides the page's existing content from visitors (never deletes it). The plugin makes this impossible to miss:

* **Editor notice**: a prominent warning on top of the page editor while a landing is active, with one-click *View* and *Deactivate* links.
* **Builder detection**: pages built with **Elementor**, the **block editor**, or the **classic editor** are detected — warnings name the exact builder, and the page dropdown annotates such pages *before* you pick them.
* **Manager flags**: the landings table and Pages list column mark pages whose builder content is currently hidden.

= Landing manager =

* **Stats bar**: totals for all / active / inactive landings at a glance.
* **Rich table**: landing name with entry file, linked page with slug, type, file count + total size, status, last-updated date.
* **Live filter** to find a landing instantly.
* **Health checks**: "Page deleted" and "Files missing" badges surface problems before your visitors do.
* **Empty state** guides first-time users.

= Global tracking codes (GTM & co.) =

* Paste your **head code** (GTM script, analytics, pixels) and **body code** (GTM noscript) once in Pages → HTML Landing → Global settings.
* Injected into **landing pages** (which bypass the theme) and — optionally — into **all regular pages/posts** too, so one code covers the whole public site.

= SEO meta injection =

Because full-takeover bypasses your SEO plugin, the plugin can inject meta derived from the WordPress page into the landing HTML — only tags your HTML doesn't already define:

* `<title>` from the page title
* `<meta name="description">` from the page excerpt
* canonical link, Open Graph (title, description, URL, type), `og:image` from the page's featured image, Twitter card

= Management =

* **Activate / Deactivate** any landing with one click.
* **Replace file** re-uploads the HTML/ZIP while keeping the same page assignment.
* **Delete** removes the landing files; the WordPress page is kept.
* One landing per page — pages that are already used are removed from the dropdown.

= Safety =

* Uploads are validated (.html/.zip only, 100 MB max).
* ZIP extraction blocks unsafe paths (zip-slip) and executable files (.php, .sh, …).
* Password-protected pages keep their password prompt.
* Only users with the `edit_pages` capability can manage landings; saving raw tracking code additionally requires `unfiltered_html`.

== Installation ==

1. Upload the `html-landing-pages` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New → Upload).
2. Activate the plugin.
3. Open any page in the editor and use the **HTML Landing Page** box — or go to **Pages → HTML Landing**.

== Frequently Asked Questions ==

= Does it change my theme or my page content? =

No. The plugin only intercepts the page on the frontend while the landing is active. Your page content in the editor stays exactly as it was.

= What happens to links inside my HTML? =

Relative `src`, `href`, `srcset` and CSS `url(...)` references are rewritten to the landing's uploads folder at serve time. Absolute URLs (https://…), anchors (#…), `mailto:` and `tel:` links are left untouched.

= My ZIP has no index.html =

The first HTML file found is used as the entry page instead.

= Can two landings share one page? =

No — one landing per page. Create another WordPress page for the second landing.

= Does it work with a static homepage? =

Yes. You can assign a landing to the page set as your homepage under Settings → Reading.

= Will my GTM/analytics fire on landing pages? =

Yes — paste it once under Pages → HTML Landing → Global settings. It's injected into every landing page, and optionally into all regular pages and posts as well.

= Where do I set the meta description for a landing? =

On the page itself — the plugin uses the page's excerpt as the meta description and its featured image as `og:image`.

== Changelog ==

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
