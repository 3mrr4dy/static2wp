<?php
/**
 * Frontend layer: serve the assigned landing HTML on its page (full takeover).
 *
 * @package Static2WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class S2WP_Renderer
 */
class S2WP_Renderer {

	/**
	 * Hook in.
	 */
	public static function init() {
		// Priority 11: after redirect_canonical (10) so canonical URLs win.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 11 );
		add_action( 'wp_head', array( __CLASS__, 'echo_head_code' ), 99 );
		add_action( 'wp_body_open', array( __CLASS__, 'echo_body_code' ), 99 );
	}

	/**
	 * Print the global head code on regular pages (landing takeovers get it
	 * injected into the served HTML instead, since the theme never loads).
	 */
	public static function echo_head_code() {
		$settings = S2WP_Store::settings();
		if ( ! empty( $settings['inject_all'] ) && '' !== trim( $settings['head_code'] ) ) {
			echo $settings['head_code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-provided tracking code, by design.
		}
	}

	/**
	 * Print the global body code (e.g. GTM <noscript>) on regular pages.
	 */
	public static function echo_body_code() {
		$settings = S2WP_Store::settings();
		if ( ! empty( $settings['inject_all'] ) && '' !== trim( $settings['body_code'] ) ) {
			echo $settings['body_code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-provided tracking code, by design.
		}
	}

	/**
	 * When the current page has an active landing, output its HTML and stop.
	 */
	public static function maybe_serve() {
		if ( is_feed() || is_embed() || is_robots() || is_favicon() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! is_page() && ! is_front_page() ) {
			return;
		}

		$page_id = (int) get_queried_object_id();
		if ( ! $page_id ) {
			return;
		}

		$landing = S2WP_Store::find_active_by_page( $page_id );
		if ( ! $landing ) {
			return;
		}

		// Respect WordPress password protection on the assigned page.
		$post = get_post( $page_id );
		if ( $post && post_password_required( $post ) ) {
			return;
		}

		$entry_path = S2WP_Store::current_entry_path( $landing );
		if ( '' === $entry_path || ! is_file( $entry_path ) ) {
			return; // Files were removed externally — fall back to the normal page.
		}

		$html = file_get_contents( $entry_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $html ) {
			return;
		}

		$base_url = trailingslashit( S2WP_Store::current_base_url( $landing ) );
		$entry    = S2WP_Store::current_entry( $landing );
		$base_rel = trailingslashit( dirname( $entry ) );
		$base_rel = ( './' === $base_rel ) ? '' : $base_rel;

		$html = self::rewrite( $html, $base_url, $base_rel );

		/*
		 * Inherit everything WordPress applies to the page (SEO plugins,
		 * tracking codes, site icons, …) while keeping the landing's design
		 * fully independent:
		 *  1. strip the landing's own SEO tags (the page inherits WP's)
		 *  2. capture wp_head / wp_body_open / wp_footer output
		 *  3. remove design assets (stylesheets / scripts) from the capture
		 *  4. merge the rest into the landing HTML
		 *
		 * The admin bar has no CSS in a takeover document — if left on it
		 * dumps an unstyled link list over the landing (WPCode, profile, …).
		 */
		show_admin_bar( false );
		add_filter( 'show_admin_bar', '__return_false', 999 );
		remove_action( 'wp_body_open', 'wp_admin_bar_render', 0 );
		remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );

		$html = self::strip_landing_seo( $html );

		$assets = self::capture_wp_assets();
		$html   = self::inject_after_body_open( $html, $assets['body_open'] );
		$html   = self::inject_before_head_end( $html, $assets['head'] );
		$html   = self::inject_before_body_end( $html, $assets['footer'] );

		$settings = S2WP_Store::settings();
		if ( ! empty( $settings['seo_meta'] ) ) {
			$html = self::inject_seo( $html, $post ); // Fallback only — fills gaps WP didn't provide.
		}
		$html = self::inject_codes( $html, $settings ); // Global tracking codes (removed from the capture above).

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		nocache_headers(); // Page caches must not pin takeover HTML after deactivate/rollback.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- full admin-provided HTML document, by design.
		exit;
	}

	/**
	 * Capture the WordPress output a regular page would receive: wp_head,
	 * wp_body_open and wp_footer. Design assets (stylesheets/scripts) are
	 * stripped afterwards so the landing's own design stays untouched.
	 *
	 * @return array{head:string,body_open:string,footer:string}
	 */
	private static function capture_wp_assets() {
		// Remove our own hooks first: global codes are injected manually
		// later, so they must not appear twice in the capture.
		remove_action( 'wp_head', array( __CLASS__, 'echo_head_code' ), 99 );
		remove_action( 'wp_body_open', array( __CLASS__, 'echo_body_code' ), 99 );

		$head = self::ob_capture( 'wp_head' );
		$body = self::ob_capture( 'wp_body_open' );
		$foot = self::ob_capture( 'wp_footer' );

		return array(
			'head'      => self::strip_admin_chrome( self::strip_design_assets( $head ) ),
			'body_open' => self::strip_admin_chrome( self::strip_design_assets( $body ) ),
			'footer'    => self::strip_admin_chrome( self::strip_design_assets( $foot ) ),
		);
	}

	/**
	 * Run an action inside an output buffer and return whatever it printed.
	 *
	 * @param string $hook Action name.
	 * @return string
	 */
	private static function ob_capture( $hook ) {
		ob_start();
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookFound -- capturing core hooks by design.
		return (string) ob_get_clean();
	}

	/**
	 * Drop leftover admin-bar markup that some plugins still print after
	 * show_admin_bar(false).
	 *
	 * @param string $chunk Captured HTML.
	 * @return string
	 */
	private static function strip_admin_chrome( $chunk ) {
		if ( '' === trim( $chunk ) ) {
			return $chunk;
		}

		$chunk = preg_replace( '#<div[^>]*\bid=(["\'])wpadminbar\1[^>]*>.*?</div>#is', '', $chunk );
		$chunk = preg_replace( '#<div[^>]*\bid=(["\'])wp-toolbar\1[^>]*>.*?</div>#is', '', $chunk );

		return $chunk;
	}

	/**
	 * Remove design assets (stylesheets, style blocks, external scripts)
	 * from a captured WordPress chunk, keeping meta tags, inline tracking
	 * scripts, noscript fallbacks and links such as canonical / rest api.
	 *
	 * @param string $chunk Captured HTML.
	 * @return string
	 */
	private static function strip_design_assets( $chunk ) {
		if ( '' === trim( $chunk ) ) {
			return $chunk;
		}

		// Stylesheets and preloads for styles/scripts.
		$chunk = preg_replace( '#<link[^>]+rel=[\'"](?:stylesheet|preload)[\'"][^>]*>#i', '', $chunk );
		// Inline style blocks (theme CSS, admin-bar bump, …).
		$chunk = preg_replace( '#<style[^>]*>.*?</style>#is', '', $chunk );
		// External scripts (theme/plugin JS files — the landing ships its own).
		$chunk = preg_replace( '#<script[^>]*\bsrc=[^>]*>.*?</script>#is', '', $chunk );
		$chunk = preg_replace( '#<script[^>]*\bsrc=[^>]*>\s*</script>#i', '', $chunk );

		return $chunk;
	}

	/**
	 * Strip the landing's own SEO tags so the page inherits WordPress'
	 * (SEO plugin output, core title, canonical, …) instead.
	 *
	 * @param string $html Landing HTML.
	 * @return string
	 */
	private static function strip_landing_seo( $html ) {
		$html = preg_replace( '#<title[^>]*>.*?</title>#is', '', $html );
		$html = preg_replace( '#<meta[^>]+name=[\'"]description[\'"][^>]*>#i', '', $html );
		$html = preg_replace( '#<meta[^>]+name=[\'"]keywords[\'"][^>]*>#i', '', $html );
		$html = preg_replace( '#<link[^>]+rel=[\'"]canonical[\'"][^>]*>#i', '', $html );
		$html = preg_replace( '#<meta[^>]+(?:property|name)=[\'"](?:og:|twitter:|article:)[^>]*>#i', '', $html );
		return $html;
	}

	/**
	 * Insert markup right after the opening <body> tag.
	 *
	 * @param string $html    Document.
	 * @param string $markup  Markup to insert.
	 * @return string
	 */
	private static function inject_after_body_open( $html, $markup ) {
		if ( '' === trim( $markup ) ) {
			return $html;
		}
		return preg_replace(
			'/(<body[^>]*>)/i',
			'$1' . "\n" . $markup,
			$html,
			1
		);
	}

	/**
	 * Insert markup right before </head>.
	 *
	 * @param string $html   Document.
	 * @param string $markup Markup to insert.
	 * @return string
	 */
	private static function inject_before_head_end( $html, $markup ) {
		if ( '' === trim( $markup ) || false === stripos( $html, '</head' ) ) {
			return $html;
		}
		// First occurrence only — later </head> strings may sit in scripts.
		return preg_replace( '#</head\s*>#i', "\n" . $markup . "\n</head>", $html, 1 );
	}

	/**
	 * Insert markup right before </body>.
	 *
	 * @param string $html   Document.
	 * @param string $markup Markup to insert.
	 * @return string
	 */
	private static function inject_before_body_end( $html, $markup ) {
		if ( '' === trim( $markup ) || false === stripos( $html, '</body' ) ) {
			return $html;
		}
		return preg_replace( '#</body\s*>#i', "\n" . $markup . "\n</body>", $html, 1 );
	}

	/**
	 * Inject SEO/social meta derived from the WordPress page into the landing
	 * HTML — only tags the HTML does not already define.
	 *
	 * @param string  $html Landing HTML.
	 * @param WP_Post $post The assigned page.
	 * @return string
	 */
	private static function inject_seo( $html, $post ) {
		if ( false === stripos( $html, '</head' ) ) {
			return $html;
		}

		$tags      = array();
		$title     = get_the_title( $post );
		$permalink = get_permalink( $post );
		$excerpt   = trim( wp_strip_all_tags( $post->post_excerpt ) );

		if ( false === stripos( $html, '<title' ) && '' !== $title ) {
			$tags[] = '<title>' . esc_html( $title ) . '</title>';
		}
		if ( '' !== $excerpt && ! self::has_meta( $html, 'name', 'description' ) ) {
			$tags[] = '<meta name="description" content="' . esc_attr( $excerpt ) . '">';
		}
		if ( false === stripos( $html, 'rel="canonical"' ) && false === stripos( $html, "rel='canonical'" ) ) {
			$tags[] = '<link rel="canonical" href="' . esc_url( $permalink ) . '">';
		}
		if ( ! self::has_meta( $html, 'property', 'og:title' ) && '' !== $title ) {
			$tags[] = '<meta property="og:title" content="' . esc_attr( $title ) . '">';
		}
		if ( ! self::has_meta( $html, 'property', 'og:description' ) && '' !== $excerpt ) {
			$tags[] = '<meta property="og:description" content="' . esc_attr( $excerpt ) . '">';
		}
		if ( ! self::has_meta( $html, 'property', 'og:url' ) ) {
			$tags[] = '<meta property="og:url" content="' . esc_url( $permalink ) . '">';
		}
		if ( ! self::has_meta( $html, 'property', 'og:type' ) ) {
			$tags[] = '<meta property="og:type" content="website">';
		}

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( $image && ! self::has_meta( $html, 'property', 'og:image' ) ) {
			$tags[] = '<meta property="og:image" content="' . esc_url( $image ) . '">';
		}
		if ( ! self::has_meta( $html, 'name', 'twitter:card' ) ) {
			$tags[] = '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '">';
		}

		if ( empty( $tags ) ) {
			return $html;
		}

		return preg_replace( '#</head\s*>#i', "\n" . implode( "\n", $tags ) . "\n</head>", $html, 1 );
	}

	/**
	 * Whether the HTML already contains a given meta tag (quote-style agnostic).
	 *
	 * @param string $html  HTML.
	 * @param string $attr  Attribute name (name|property).
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function has_meta( $html, $attr, $value ) {
		return false !== stripos( $html, $attr . '="' . $value . '"' )
			|| false !== stripos( $html, $attr . "='" . $value . "'" );
	}

	/**
	 * Inject the global head/body codes into the landing HTML.
	 *
	 * @param string $html     Landing HTML.
	 * @param array  $settings Global settings.
	 * @return string
	 */
	private static function inject_codes( $html, $settings ) {
		if ( '' !== trim( $settings['head_code'] ) && false !== stripos( $html, '</head' ) ) {
			$html = preg_replace( '#</head\s*>#i', "\n" . $settings['head_code'] . "\n</head>", $html, 1 );
		}
		if ( '' !== trim( $settings['body_code'] ) ) {
			$code = $settings['body_code'];
			$html = preg_replace_callback(
				'/<body(\s[^>]*)?>/i',
				function ( $m ) use ( $code ) {
					return $m[0] . "\n" . $code;
				},
				$html,
				1
			);
		}
		return $html;
	}

	/**
	 * Rewrite relative URLs in an HTML document to absolute URLs pointing at
	 * the landing directory.
	 *
	 * @param string $html     Raw HTML.
	 * @param string $base_url Public URL of the landing directory.
	 * @param string $base_rel Entry-file directory relative to the landing dir ('' for root).
	 * @return string
	 */
	private static function rewrite( $html, $base_url, $base_rel ) {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		$doctype = '';
		if ( preg_match( '/^\s*(<!DOCTYPE[^>]*>)/i', $html, $m ) ) {
			$doctype = $m[1];
		}

		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		// Drop the encoding-hint processing instruction so it never reaches output.
		foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
				break;
			}
		}

		$xpath = new DOMXPath( $dom );

		foreach ( $xpath->query( '//*[@src]' ) as $el ) {
			$el->setAttribute( 'src', self::rewrite_url( $el->getAttribute( 'src' ), $base_url, $base_rel ) );
		}
		foreach ( $xpath->query( '//*[@href]' ) as $el ) {
			$el->setAttribute( 'href', self::rewrite_url( $el->getAttribute( 'href' ), $base_url, $base_rel ) );
		}
		foreach ( $xpath->query( '//*[@srcset]' ) as $el ) {
			$parts = array();
			foreach ( explode( ',', $el->getAttribute( 'srcset' ) ) as $candidate ) {
				$bits = preg_split( '/\s+/', trim( $candidate ), 2 );
				if ( '' !== $bits[0] ) {
					$bits[0] = self::rewrite_url( $bits[0], $base_url, $base_rel );
				}
				$parts[] = implode( ' ', $bits );
			}
			$el->setAttribute( 'srcset', implode( ', ', $parts ) );
		}
		foreach ( $xpath->query( '//*[@poster]' ) as $el ) {
			$el->setAttribute( 'poster', self::rewrite_url( $el->getAttribute( 'poster' ), $base_url, $base_rel ) );
		}
		// Common lazy-load attributes used by exported landing templates.
		foreach ( array( 'data-src', 'data-background', 'data-background-image' ) as $attr ) {
			foreach ( $xpath->query( '//*[@' . $attr . ']' ) as $el ) {
				$value = trim( $el->getAttribute( $attr ) );
				if ( '' === $value || '{' === $value[0] ) {
					continue; // JSON-valued attributes (e.g. data-bg='{"src": ...}') are left alone.
				}
				$el->setAttribute( $attr, self::rewrite_url( $value, $base_url, $base_rel ) );
			}
		}
		foreach ( $xpath->query( '//*[@style]' ) as $el ) {
			$el->setAttribute( 'style', self::rewrite_inline_css( $el->getAttribute( 'style' ), $base_url, $base_rel ) );
		}
		foreach ( $xpath->query( '//style' ) as $el ) {
			foreach ( iterator_to_array( $el->childNodes ) as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
					$child->nodeValue = self::rewrite_inline_css( $child->nodeValue, $base_url, $base_rel );
				}
			}
		}

		$out = $dom->saveHTML();
		if ( $doctype && false === stripos( $out, '<!DOCTYPE' ) ) {
			$out = $doctype . "\n" . ltrim( $out );
		}

		return $out;
	}

	/**
	 * Rewrite one URL value when it is relative.
	 *
	 * @param string $url      Original URL.
	 * @param string $base_url Public URL of the landing directory.
	 * @param string $base_rel Entry-file directory relative to the landing dir.
	 * @return string
	 */
	private static function rewrite_url( $url, $base_url, $base_rel ) {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || '#' === $url[0] ) {
			return $url;
		}
		if ( preg_match( '#^(https?:)?//#i', $url )
			|| preg_match( '/^(data|mailto|tel|javascript|sms|skype):/i', $url ) ) {
			return $url;
		}

		$path = preg_replace( '#^\./#', '', $url );
		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			return $url;
		}

		return trailingslashit( $base_url ) . self::normalize_path( $base_rel . $path );
	}

	/**
	 * Rewrite url(...) references inside inline CSS.
	 *
	 * @param string $css      CSS fragment.
	 * @param string $base_url Public URL of the landing directory.
	 * @param string $base_rel Entry-file directory relative to the landing dir.
	 * @return string
	 */
	private static function rewrite_inline_css( $css, $base_url, $base_rel ) {
		return preg_replace_callback(
			'/url\(\s*([\'"]?)([^)\'"]+)\1\s*\)/i',
			function ( $m ) use ( $base_url, $base_rel ) {
				$target = trim( $m[2] );
				if ( '' === $target
					|| '#' === $target[0]
					|| preg_match( '#^(https?:)?//#i', $target )
					|| preg_match( '/^data:/i', $target ) ) {
					return $m[0];
				}
				return 'url(' . self::rewrite_url( $target, $base_url, $base_rel ) . ')';
			},
			$css
		);
	}

	/**
	 * Collapse "." and ".." segments from a relative path (never above root).
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}
}
