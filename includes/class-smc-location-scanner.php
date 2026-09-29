<?php
/**
 * Finds location details that are typed into Elementor templates and pages instead of
 * coming from the location shortcodes: phone numbers, addresses, hours, social links,
 * Google Maps and booking links.
 *
 * Settings that an Elementor dynamic tag overrides are skipped, since the page doesn't
 * show them. Anything already using a [location...] shortcode is skipped too.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Scanner {

	const FIXES = [
		'Phone'        => 'Text: [location field="phone_label"]. Link: [location field="phone_link"]',
		'Address'      => '[location field="address"]',
		'Hours'        => '[location_hours]',
		'Social link'  => 'Social Icons link: [location field="facebook_url"] (etc.) or [location_social]',
		'Map'          => '[location_map]',
		'Booking link' => 'Link: [location field="booking_link"]',
		'Email'        => 'Text: [location field="email"]. Link: [location field="email_link"]',
		'Form'         => '[location_form]',
		'Team'         => 'Add the person under Locations > Team, then use [location_team] or a Loop Grid with Query ID location_doctors / location_staff',
		'Reviews'      => 'Import them (Locations > Import Reviews), then use [location_reviews] or a Loop Carousel with Query ID location_reviews',
	];

	private $phones   = []; // regex => location name
	private $needles  = []; // [ kind, text, location name ]
	private $results  = [];

	/**
	 * @param array $opts [ 'templates' => bool, 'pages' => bool ]
	 * @return array List of posts with findings.
	 */
	public static function run( array $opts = [] ) {
		$opts = array_merge( [ 'templates' => true, 'pages' => true ], $opts );
		$s    = new self();
		$s->build_needles();

		$types = [];
		if ( $opts['templates'] ) {
			$types[] = 'elementor_library';
		}
		if ( $opts['pages'] ) {
			$types[] = 'page';
		}
		if ( ! $types ) {
			return [];
		}

		$ids = get_posts(
			[
				'post_type'   => $types,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'type title',
				'order'       => 'ASC',
			]
		);
		foreach ( $ids as $id ) {
			$s->scan_post( get_post( $id ) );
		}

		// Templates first, then pages.
		usort( $s->results, fn( $a, $b ) => [ $a['is_template'] ? 0 : 1, $a['title'] ] <=> [ $b['is_template'] ? 0 : 1, $b['title'] ] );
		return $s->results;
	}

	/* ========== Needles from each location's saved details ========== */

	private function build_needles() {
		if ( ! class_exists( 'SMC_Location_Manager' ) ) {
			require_once __DIR__ . '/class-smc-location-manager.php';
		}
		if ( post_type_exists( 'smc_team' ) ) {
			foreach ( get_posts( [ 'post_type' => 'smc_team', 'post_status' => 'publish', 'numberposts' => -1 ] ) as $m ) {
				$name = trim( preg_replace( '/^(dr\.?|doctor)\s+/i', '', $m->post_title ) );
				if ( strlen( $name ) >= 5 && false !== strpos( $name, ' ' ) ) {
					$this->team_names[ $name ] = $m->post_title;
				}
			}
		}
		foreach ( SMC_Location_Manager::locations() as $t ) {
			$tid = $t->term_id;

			$digits = preg_replace( '/\D/', '', (string) get_term_meta( $tid, 'phone_label', true ) );
			if ( 10 === strlen( $digits ) ) {
				foreach ( SMC_Location_Cloner::phone_variants( $digits ) as $v ) {
					$this->phones[ '/(?<!\d)' . preg_quote( $v, '/' ) . '(?!\d)/' ] = $t->name;
				}
				// URL-encoded tel links, e.g. tel:(555)%20555-0100
				$this->phones[ '/\(' . substr( $digits, 0, 3 ) . '\)%20' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 ) . '/' ] = $t->name;
			}

			$addr = preg_split( '#\s*<br\s*/?>\s*#i', (string) get_term_meta( $tid, 'address', true ) );
			if ( ! empty( $addr[0] ) && strlen( trim( $addr[0] ) ) >= 6 ) {
				$this->needles[] = [ 'Address', trim( $addr[0] ), $t->name ];
			}

			$email = trim( (string) get_term_meta( $tid, 'email', true ) );
			if ( '' !== $email ) {
				$this->needles[] = [ 'Email', $email, $t->name ];
			}

			$booking = trim( (string) get_term_meta( $tid, 'booking_link', true ) );
			if ( strlen( $booking ) > 12 ) {
				$this->needles[] = [ 'Booking link', $booking, $t->name ];
			}
		}
	}

	/** Names of saved team members, to spot profiles typed into widgets. */
	private $team_names = [];

	/* ========== Scanning ========== */

	private function scan_post( $post ) {
		if ( ! $post ) {
			return;
		}
		$is_tpl = 'elementor_library' === $post->post_type;
		$type   = $is_tpl ? (string) get_post_meta( $post->ID, '_elementor_template_type', true ) : 'page';
		if ( in_array( $type, [ 'kit', 'popup' ], true ) ) {
			return;
		}

		$findings = [];
		$raw      = get_post_meta( $post->ID, '_elementor_data', true );
		$data     = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		if ( is_array( $data ) ) {
			$this->walk( $data, $findings );
		} elseif ( '' !== trim( $post->post_content ) ) {
			$this->check_string( $post->post_content, 'content', $findings );
		}
		if ( ! $findings ) {
			return;
		}

		$this->results[] = [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'is_template' => $is_tpl,
			'type'        => $is_tpl ? ucwords( str_replace( '-', ' ', $type ?: 'template' ) ) : 'Page',
			'status'      => $post->post_status,
			'location'    => $this->location_label( $post, $is_tpl ),
			'edit'        => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
			'findings'    => $findings,
		];
	}

	/** Walks Elementor elements, scanning each element's settings. */
	private function walk( array $elements, array &$findings ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$widget = $el['widgetType'] ?? ( $el['elType'] ?? '' );
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$settings = $el['settings'];
				if ( 'google_maps' === $widget && ! empty( $settings['address'] ) && empty( $settings['__dynamic__']['address'] ) ) {
					$this->add( $findings, 'Map', 'Elementor Google Maps widget: ' . $settings['address'], $widget );
					unset( $settings['address'] );
				}
				if ( $this->team_names && in_array( $widget, [ 'heading', 'image-box', 'icon-box', 'call-to-action', 'flip-box', 'price-list' ], true ) ) {
					$flat = wp_strip_all_tags( implode( ' ', array_filter( (array) $settings, 'is_string' ) ) );
					foreach ( $this->team_names as $needle => $full ) {
						if ( false !== stripos( $flat, $needle ) ) {
							$this->add( $findings, 'Team', "Typed-in profile: $full", $widget );
						}
					}
				}
				if ( in_array( $widget, [ 'testimonial', 'testimonial-carousel', 'reviews' ], true ) ) {
					// Count real reviews only; carousels of [elementor-template] slides aren't reviews.
					$texts = 'testimonial' === $widget ? [ $settings['testimonial_content'] ?? '' ] : array_column( (array) ( $settings['slides'] ?? [] ), 'content' );
					$n     = count( array_filter( $texts, fn( $t ) => ! SMC_Location_Reviews::is_empty_text( (string) $t ) ) );
					if ( $n ) {
						$this->add( $findings, 'Reviews', "Elementor $widget widget with $n typed-in review" . ( 1 === $n ? '' : 's' ), $widget );
					}
				} elseif ( 'video' !== $widget ) {
					$this->scan_settings( $settings, $widget, $findings );
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk( $el['elements'], $findings );
			}
		}
	}

	/** Scans settings (and repeater items), skipping keys a dynamic tag overrides. */
	private function scan_settings( array $settings, $widget, array &$findings ) {
		$dynamic = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? array_keys( $settings['__dynamic__'] ) : [];
		foreach ( $settings as $k => $v ) {
			if ( '__dynamic__' === $k || in_array( $k, $dynamic, true ) || ( is_string( $k ) && '_' === $k[0] ) ) {
				continue;
			}
			if ( is_string( $v ) ) {
				$this->check_string( $v, $widget, $findings );
			} elseif ( is_array( $v ) ) {
				$this->scan_settings( $v, $widget, $findings );
			}
		}
	}

	private function check_string( $s, $widget, array &$findings ) {
		// Shortcodes are already connected; check whatever text is around them.
		$s = preg_replace( '/\[location[^\]]*\]/i', '', $s );
		if ( strlen( trim( $s ) ) < 5 ) {
			return;
		}

		foreach ( $this->phones as $re => $loc ) {
			if ( preg_match( $re, $s, $m, PREG_OFFSET_CAPTURE ) ) {
				$this->add( $findings, 'Phone', $this->snip( $s, $m[0][1], strlen( $m[0][0] ) ), $widget, $loc );
				break;
			}
		}
		foreach ( $this->needles as list( $kind, $text, $loc ) ) {
			$pos = stripos( $s, $text );
			if ( false !== $pos ) {
				$this->add( $findings, $kind, $this->snip( $s, $pos, strlen( $text ) ), $widget, $loc );
			}
		}

		// Hours: a time range, or a day name with a time.
		$time = '\d{1,2}(?::\d{2})?\s*[ap]\.?\s*m\.?';
		if ( preg_match( "/$time\s*(?:-|–|—|to)\s*$time/i", $s, $m, PREG_OFFSET_CAPTURE )
			|| ( preg_match( '/\b(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?\b/i', $s ) && preg_match( "/$time/i", $s, $m, PREG_OFFSET_CAPTURE ) ) ) {
			$this->add( $findings, 'Hours', $this->snip( $s, $m[0][1], strlen( $m[0][0] ) ), $widget );
		}

		// Embedded JotForms (iframe or script embeds, not plain links on buttons).
		if ( preg_match( '#<(?:iframe|script)\b[^>]*\bsrc=["\']?(https?://[a-z0-9.-]*jotform\.(?:com|eu|me)/[^"\'\s>]*)#i', $s, $m ) ) {
			$this->add( $findings, 'Form', 'Embedded JotForm: ' . $m[1], $widget );
		}

		// Any mailto: link not already reported as a saved location email.
		if ( preg_match( '/mailto:([^\s"\'<>?]+)/i', $s, $m ) && ! isset( $findings[ 'Email|' . $m[1] ] ) ) {
			$known = false;
			foreach ( $findings as $f ) {
				$known = $known || ( 'Email' === $f['kind'] && false !== stripos( $f['snippet'], $m[1] ) );
			}
			if ( ! $known ) {
				$this->add( $findings, 'Email', 'mailto:' . $m[1], $widget );
			}
		}

		if ( preg_match( '#https?://[^\s"\'<>]*google\.[a-z.]+/maps/embed[^\s"\'<>]*#i', $s, $m ) ) {
			$this->add( $findings, 'Map', 'Embedded map: ' . ( strlen( $m[0] ) > 90 ? substr( $m[0], 0, 87 ) . '...' : $m[0] ), $widget );
		}

		$social = '#https?://(?:www\.|m\.)?(?:facebook\.com|fb\.com|instagram\.com|tiktok\.com|youtube\.com/(?:@|c/|channel/|user/)|g\.page|business\.google\.com|google\.com/maps/place|maps\.app\.goo\.gl|search\.google\.com/local)[^\s"\'<>]*#i';
		if ( preg_match_all( $social, $s, $all, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all[0] as $hit ) {
				$this->add( $findings, 'Social link', $hit[0], $widget );
			}
		}
	}

	private function add( array &$findings, $kind, $snippet, $widget, $loc = '' ) {
		$key = $kind . '|' . $snippet;
		if ( isset( $findings[ $key ] ) ) {
			$findings[ $key ]['count']++;
			return;
		}
		$findings[ $key ] = [
			'kind'     => $kind,
			'snippet'  => $snippet,
			'widget'   => $widget,
			'location' => $loc,
			'fix'      => self::FIXES[ $kind ] ?? '',
			'count'    => 1,
		];
	}

	private function snip( $s, $pos, $len ) {
		$start = max( 0, $pos - 40 );
		$text  = substr( $s, $start, $len + 80 );
		$text  = preg_replace( '#<br\s*/?>#i', ' ', $text );
		$text  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return ( $start > 0 ? '...' : '' ) . $text . ( $start + $len + 80 < strlen( $s ) ? '...' : '' );
	}

	/** Which location a page or template belongs to. */
	private function location_label( $post, $is_tpl ) {
		if ( ! $is_tpl ) {
			$names = wp_get_post_terms( $post->ID, SMC_Location_Cloner::LOC_TAX, [ 'fields' => 'names' ] );
			return is_wp_error( $names ) || ! $names ? '-' : implode( ', ', $names );
		}
		$conds  = array_filter( (array) get_post_meta( $post->ID, '_elementor_conditions', true ) );
		$labels = [];
		foreach ( $conds as $c ) {
			$parts = explode( '/', $c );
			if ( 'include/general' === $c ) {
				$labels[] = 'Whole site';
			} elseif ( count( $parts ) >= 4 && 0 === strpos( $parts[2], 'in_' ) && ctype_digit( end( $parts ) ) ) {
				$term     = get_term( (int) end( $parts ), substr( $parts[2], 3 ) );
				$labels[] = $term && ! is_wp_error( $term ) ? $term->name : $c;
			} elseif ( 0 === strpos( $c, 'include/' ) ) {
				$labels[] = $c;
			}
		}
		return $labels ? implode( ', ', array_unique( $labels ) ) : 'Embedded (no display conditions)';
	}
}
