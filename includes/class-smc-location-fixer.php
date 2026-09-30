<?php
/**
 * Applies Scan's suggested fixes without opening Elementor.
 *
 * Fixable: phone numbers, emails, booking links, social links, city and state, the site
 * name, copyright years, and typed-in details in Yoast fields. The rest (addresses, hours,
 * maps, forms, team, reviews, logos, holidays) need a person, so they keep Scan's advice.
 *
 * How a value is replaced depends on where it is:
 *  - Text Editor and Shortcode widgets (and pages without Elementor): the shortcode goes
 *    straight into the text, since those run shortcodes.
 *  - A link (buttons, icons, social icons...): the link gets Elementor's Shortcode dynamic
 *    tag, e.g. [location field="phone_link"].
 *  - Other text (headings, button text, icon lists...): the setting gets the Shortcode
 *    dynamic tag holding the text with the shortcode in it, so "Call 555-555-0100 today"
 *    becomes "Call [location field="phone_label"] today" everywhere Elementor shows it.
 *  - HTML widgets don't run shortcodes, so they're left for a person.
 *
 * The first time an item is changed its original data is saved (meta _smc_scan_backup), and
 * "Undo fixes" on the Scan screen puts every changed item back.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Fixer {

	const LOG    = 'smc_scan_fix_log';
	const BACKUP = '_smc_scan_backup';
	const TAX    = 'location_category';
	const AUTO   = [ 'Phone', 'Email', 'Booking link', 'Social link', 'City and state', 'Site name', 'Year', 'Yoast SEO' ];
	const INLINE = [ 'text-editor', 'shortcode', 'content' ];

	private $post_id;
	private $kind;
	private $value;
	private $loc;
	private $count = 0;

	public static function can_fix( $kind, $widget, $value ) {
		return in_array( $kind, self::AUTO, true ) && '' !== (string) $value && 'html' !== $widget;
	}

	/** Items changed so far (post IDs), for Undo. */
	public static function changed() {
		$l = get_option( self::LOG, [] );
		return is_array( $l ) ? array_values( array_filter( array_map( 'intval', $l ) ) ) : [];
	}

	/**
	 * Fixes every place $value (of this kind) appears in a post. Returns how many were changed.
	 *
	 * @param string $loc The location name the value belongs to (from Scan), if any.
	 */
	public static function fix( $post_id, $kind, $value, $loc = '' ) {
		$f          = new self();
		$f->post_id = (int) $post_id;
		$f->kind    = $kind;
		$f->value   = (string) $value;
		$f->loc     = (string) $loc;
		if ( ! in_array( $kind, self::AUTO, true ) || ! get_post( $post_id ) ) {
			return 0;
		}
		return 'Yoast SEO' === $kind ? $f->fix_yoast() : $f->fix_content();
	}

	/* ========== Content ========== */

	private function fix_content() {
		$raw  = (string) get_post_meta( $this->post_id, '_elementor_data', true );
		$data = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $data ) ) {
			$this->walk( $data );
			if ( ! $this->count ) {
				return 0;
			}
			$this->backup( [ '_elementor_data' ] );
			update_post_meta( $this->post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			delete_post_meta( $this->post_id, '_elementor_css' );
			delete_post_meta( $this->post_id, '_elementor_element_cache' );
		} else {
			$post = get_post( $this->post_id );
			$new  = $this->replace( $post->post_content );
			if ( $new === $post->post_content ) {
				return 0;
			}
			$this->backup( [], true );
			global $wpdb;
			$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => $this->post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->count++;
		}
		clean_post_cache( $this->post_id );
		return $this->count;
	}

	private function walk( array &$elements ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$widget = $el['widgetType'] ?? ( $el['elType'] ?? '' );
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) && 'html' !== $widget ) {
				$this->settings( $el['settings'], $widget );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk( $el['elements'] );
			}
		}
	}

	/** One level of settings (a widget's, or a repeater item's). */
	private function settings( array &$set, $widget ) {
		$dynamic = isset( $set['__dynamic__'] ) && is_array( $set['__dynamic__'] ) ? $set['__dynamic__'] : [];
		// Text already in a Shortcode dynamic tag (e.g. from an earlier fix): fix inside it too.
		foreach ( $dynamic as $k => $tag ) {
			if ( is_string( $tag ) && preg_match( '/^\[elementor-tag id="[^"]*" name="shortcode" settings="([^"]*)"\]$/', $tag, $m ) ) {
				$conf = json_decode( rawurldecode( $m[1] ), true );
				if ( is_array( $conf ) && isset( $conf['shortcode'] ) && is_string( $conf['shortcode'] ) ) {
					$new = $this->replace( $conf['shortcode'] );
					if ( $new !== $conf['shortcode'] ) {
						$set['__dynamic__'][ $k ] = self::tag( $new );
						$this->count++;
					}
				}
			}
		}
		foreach ( $set as $k => &$v ) {
			if ( '__dynamic__' === $k || isset( $dynamic[ $k ] ) || ( is_string( $k ) && '_' === $k[0] ) ) {
				continue;
			}
			if ( is_string( $k ) && preg_match( '/(^|_)(image|images|gallery|alt|css_classes|custom_css|link_attributes)$/', $k ) ) {
				continue;
			}
			if ( is_array( $v ) && isset( $v['url'] ) && is_string( $v['url'] ) ) {
				// A link control: connect the whole link if the URL is exactly the value.
				$sc = $this->link_shortcode( $v['url'] );
				if ( $sc ) {
					$set['__dynamic__'][ $k ] = self::tag( $sc );
					$this->count++;
				}
				continue;
			}
			if ( is_array( $v ) ) {
				foreach ( $v as &$item ) {
					if ( is_array( $item ) ) {
						$this->settings( $item, $widget ); // Repeater items.
					}
				}
				unset( $item );
				continue;
			}
			if ( ! is_string( $v ) || '' === trim( $v ) ) {
				continue;
			}
			$new = $this->replace( $v );
			if ( $new === $v ) {
				continue;
			}
			if ( in_array( $widget, self::INLINE, true ) ) {
				$v = $new;
			} else {
				$set['__dynamic__'][ $k ] = self::tag( $new ); // The typed text stays underneath as a fallback.
			}
			$this->count++;
		}
		unset( $v );
	}

	/** Elementor's Shortcode dynamic tag holding $text. */
	public static function tag( $text ) {
		$id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
		return '[elementor-tag id="' . $id . '" name="shortcode" settings="' . rawurlencode( wp_json_encode( [ 'shortcode' => $text ] ) ) . '"]';
	}

	/* ========== What replaces what ========== */

	/** location="slug" when the value belongs to another location than the page's. */
	private function attr() {
		if ( '' === $this->loc ) {
			return '';
		}
		$t = get_term_by( 'name', $this->loc, self::TAX );
		if ( ! $t || 'elementor_library' === get_post_type( $this->post_id ) ) {
			return ''; // Templates follow the page they're shown on.
		}
		$own = wp_get_post_terms( $this->post_id, self::TAX, [ 'fields' => 'ids' ] );
		$own = is_wp_error( $own ) ? [] : array_map( 'intval', $own );
		if ( ( ! $own && SMC_Location_Fields::only_location_id() === (int) $t->term_id ) || in_array( (int) $t->term_id, $own, true ) ) {
			return '';
		}
		return ' location="' . esc_attr( $t->slug ) . '"';
	}

	private function sc( $field ) {
		return '[location field="' . $field . '"' . $this->attr() . ']';
	}

	private function phone_res() {
		$d = preg_replace( '/\D/', '', $this->value );
		$d = 11 === strlen( $d ) && '1' === $d[0] ? substr( $d, 1 ) : $d;
		if ( 10 !== strlen( $d ) ) {
			return [];
		}
		$v = array_map( fn( $x ) => preg_quote( $x, '/' ), SMC_Location_Cloner::phone_variants( $d ) );
		$v[] = preg_quote( '(' . substr( $d, 0, 3 ) . ')%20' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 ), '/' );
		return '(?<!\d)(?:' . implode( '|', $v ) . ')(?!\d)';
	}

	/** A shortcode for a link whose URL is exactly the value, or ''. */
	private function link_shortcode( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		switch ( $this->kind ) {
			case 'Phone':
				$re = $this->phone_res();
				return $re && preg_match( '/^tel:\s*' . $re . '$/i', $url ) ? $this->sc( 'phone_link' ) : '';
			case 'Email':
				return 0 === strcasecmp( preg_replace( '/^mailto:/i', '', strtok( $url, '?' ) ), $this->value ) ? $this->sc( 'email_link' ) : '';
			case 'Booking link':
				return untrailingslashit( $url ) === untrailingslashit( $this->value ) ? $this->sc( 'booking_link' ) : '';
			case 'Social link':
				return $url === $this->value ? $this->social_sc( $url ) : '';
		}
		return '';
	}

	private function social_sc( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( [ 'facebook' => 'facebook_url', 'fb.com' => 'facebook_url', 'instagram' => 'instagram_url', 'tiktok' => 'tiktok_url', 'youtube' => 'youtube_url', 'google' => 'google_business_url', 'g.page' => 'google_business_url', 'goo.gl' => 'google_business_url' ] as $needle => $field ) {
			if ( false !== strpos( $host, $needle ) ) {
				return $this->sc( $field );
			}
		}
		return '';
	}

	/** The text with the value swapped for its shortcode (links inside HTML included). */
	private function replace( $s ) {
		switch ( $this->kind ) {
			case 'Phone':
				$re = $this->phone_res();
				if ( ! $re ) {
					return $s;
				}
				$s = preg_replace( '/tel:\s*' . $re . '/i', $this->sc( 'phone_link' ), $s );
				return preg_replace( '/' . $re . '/', $this->sc( 'phone_label' ), $s );
			case 'Email':
				$e = preg_quote( $this->value, '/' );
				$s = preg_replace( '/mailto:' . $e . '/i', $this->sc( 'email_link' ), $s );
				return preg_replace( '/(?<![\w.@-])' . $e . '(?![\w.-])/i', $this->sc( 'email' ), $s );
			case 'Booking link':
				return str_replace( $this->value, $this->sc( 'booking_link' ), $s );
			case 'Social link':
				$sc = $this->social_sc( $this->value );
				return $sc ? str_replace( $this->value, $sc, $s ) : $s;
			case 'City and state':
				$parts = array_map( 'trim', explode( ',', $this->value, 2 ) );
				if ( 2 !== count( $parts ) ) {
					return $s;
				}
				$state = [ preg_quote( $parts[1], '/' ) ];
				$abbr  = array_search( strtolower( $parts[1] ), array_map( 'strtolower', SMC_Location_Scanner::STATES ), true );
				if ( $abbr ) {
					$state[] = $abbr;
				} elseif ( isset( SMC_Location_Scanner::STATES[ strtoupper( $parts[1] ) ] ) ) {
					$state[] = preg_quote( SMC_Location_Scanner::STATES[ strtoupper( $parts[1] ) ], '/' );
				}
				// Not followed by a zip (that's an address) and not a neighboring town.
				return preg_replace( '/(?<![\w-])(?<!west )(?<!east )(?<!north )(?<!south )(?<!new )' . preg_quote( $parts[0], '/' ) . ',(?:\s|&nbsp;|\x{00A0})*(?:' . implode( '|', $state ) . ')(?![\w-])(?!(?:\s|&nbsp;)*\d{5})/iu', $this->sc( 'city_state' ), $s );
			case 'Site name':
				$field = 0 === strcasecmp( $this->value, html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ? '[site_name]' : '[brand_name]';
				// Not inside a URL, email or tag attribute.
				return preg_replace( '/(?<![\w@.\/-])' . preg_quote( $this->value, '/' ) . '(?![\w.@-]*\.(?:com|net|org))(?!\w)(?![^<]*>)/i', $field, $s );
			case 'Year':
				return preg_replace( '/((?:©|&copy;|\(c\)|copyright)\s*(?:[^<\d]{0,20})?)(?:19|20)\d{2}\b/i', '$1[current_year]', $s );
		}
		return $s;
	}

	/* ========== Yoast ========== */

	private function fix_yoast() {
		$tid  = SMC_Location_Yoast::term_for( $this->post_id );
		$term = $tid ? get_term( $tid, self::TAX ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}
		$rows = array_values( array_filter( SMC_Location_Yoast::conversions( $term ), fn( $r ) => (int) $r['id'] === $this->post_id ) );
		if ( ! $rows ) {
			return 0;
		}
		$this->backup( array_map( fn( $r ) => '_yoast_wpseo_' . $r['field'], $rows ) );
		return SMC_Location_Yoast::apply( $rows );
	}

	/* ========== Backup and undo ========== */

	private function backup( array $meta_keys, $content = false ) {
		global $wpdb;
		$b = get_post_meta( $this->post_id, self::BACKUP, true );
		$b = is_array( $b ) ? $b : [ 'meta' => [] ];
		foreach ( $meta_keys as $k ) {
			if ( ! array_key_exists( $k, $b['meta'] ) ) {
				$b['meta'][ $k ] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $this->post_id, $k ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( $content && ! array_key_exists( 'content', $b ) ) {
			$b['content'] = get_post_field( 'post_content', $this->post_id, 'raw' );
		}
		update_post_meta( $this->post_id, self::BACKUP, wp_slash( $b ) );
		$log = self::changed();
		if ( ! in_array( $this->post_id, $log, true ) ) {
			$log[] = $this->post_id;
			update_option( self::LOG, $log, false );
		}
	}

	/** Puts every item Scan changed back as it was. Returns how many. */
	public static function undo() {
		global $wpdb;
		$n = 0;
		foreach ( self::changed() as $id ) {
			$b = get_post_meta( $id, self::BACKUP, true );
			if ( ! is_array( $b ) ) {
				continue;
			}
			foreach ( (array) ( $b['meta'] ?? [] ) as $k => $raw ) {
				if ( null === $raw ) {
					delete_post_meta( $id, $k );
				} else {
					$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $raw ], [ 'post_id' => $id, 'meta_key' => $k ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			if ( array_key_exists( 'content', $b ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $b['content'] ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			delete_post_meta( $id, self::BACKUP );
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			wp_cache_delete( $id, 'post_meta' );
			clean_post_cache( $id );
			if ( class_exists( 'SMC_Location_Yoast' ) ) {
				SMC_Location_Yoast::rebuild_indexable( $id );
			}
			$n++;
		}
		delete_option( self::LOG );
		SMC_Location_Cloner::refresh_caches();
		return $n;
	}

	/** Forgets the backups (keeps the fixes). */
	public static function keep() {
		foreach ( self::changed() as $id ) {
			delete_post_meta( $id, self::BACKUP );
		}
		delete_option( self::LOG );
	}
}
