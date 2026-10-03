<?php
/**
 * Launch audit: is this site ready to go live for its client?
 *
 * Template leftovers: the template's demo text, placeholder text, links to the template or
 * another staging site, the template's photos, and WordPress's defaults.
 * Launch checks: search engines, noindex, logo, favicon, colors, forms, tracking, privacy
 * and accessibility pages, SEO descriptions, H1s, image alt text and size.
 *
 * Leftovers are found by comparing with the template baseline: a snapshot of the template's
 * demo details, branding, forms and media, saved on the template (Scan > Save as template
 * baseline). It's an option, so every site cloned from the template carries it. New Build
 * saves one automatically if the site has none.
 *
 * Findings: [ key, severity blocker|should|optional, check, detail, where [ [title, url] ], fix, link, item ]
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Audit {

	const BASELINE = 'smc_template_baseline';
	const IGNORED  = 'smc_audit_ignored';
	const KEEP     = '_smc_keep_for_clients';
	const TAX      = 'location_category';
	const TYPES    = [ 'page', 'post', 'elementor_library' ];

	const PLACEHOLDERS = [
		'/\blorem ipsum\b|\bdolor sit amet\b|\bconsectetur adipiscing\b/i'                         => [ 'blocker', 'Lorem ipsum' ],
		'/\b(?:Dr\.?|Doctor) (?:Name|Lastname|Last Name|Firstname)\b/i'                           => [ 'blocker', 'Placeholder doctor name' ],
		'/\b(?:Practice|Company|Business|Office) Name\b|\bYour (?:Practice|City|Company)\b/i' => [ 'blocker', 'Placeholder name' ],
		'/\b(?:XXX|xxx)[-. ](?:XXX|xxx)[-. ](?:XXXX|xxxx)\b|\(XXX\)|\b123[-. ]456[-. ]7890\b/'      => [ 'blocker', 'Placeholder phone number' ],
		'/\[\s*(?:insert|add|enter|placeholder|tbd|todo)\b[^\]]*\]|\bTBD\b|\bTODO\b/i'             => [ 'blocker', 'Unfinished text' ],
		'/\b(?:info|hello|email)@(?:example|yourdomain|domain|yourwebsite)\.com\b/i'              => [ 'blocker', 'Placeholder email' ],
		'/\bcoming soon\b|\bplaceholder\b/i'                                                     => [ 'should', 'Placeholder wording' ],
	];

	private $items    = []; // Published content: [ post, raw text, plain text ]
	private $findings = [];
	private $base;

	public static function init() {
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'keep_field' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ __CLASS__, 'keep_save' ], 10, 2 );
	}

	/** "Keep for clients" on images: generic template images every client can keep. */
	public static function keep_field( $fields, $post ) {
		if ( 0 === strpos( (string) $post->post_mime_type, 'image' ) && self::baseline() ) {
			$fields['smc_keep'] = [
				'label' => 'Keep for clients',
				'input' => 'html',
				'html'  => '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][smc_keep]" value="1" ' . checked( (bool) get_post_meta( $post->ID, self::KEEP, true ), true, false ) . '> Generic image (icon, pattern...) that doesn\'t need replacing on client sites</label>',
			];
		}
		return $fields;
	}

	public static function keep_save( $post, $att ) {
		if ( self::baseline() && isset( $_POST['attachments'][ $post['ID'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! empty( $att['smc_keep'] ) ) {
				update_post_meta( $post['ID'], self::KEEP, 1 );
			} else {
				delete_post_meta( $post['ID'], self::KEEP );
			}
		}
		return $post;
	}

	/* ========== Baseline ========== */

	public static function baseline() {
		$b = get_option( self::BASELINE );
		return is_array( $b ) && ! empty( $b['saved'] ) ? $b : null;
	}

	/** True on the template itself (the site the baseline was saved on, unchanged). */
	public static function is_template( $b = null ) {
		$b = $b ?: self::baseline();
		return $b && strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === ( $b['host'] ?? '' ) && html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) === ( $b['practice'] ?? '' );
	}

	/** Snapshot of the template: demo details, branding, forms and media. */
	public static function save_baseline( $extra = null ) {
		$locs = SMC_Location_Manager::locations();
		$t    = $locs[0] ?? null;
		$m    = fn( $k ) => $t ? trim( (string) get_term_meta( $t->term_id, $k, true ) ) : '';
		$addr = preg_split( '#\s*<br\s*/?>\s*#i', $m( 'address' ) );
		$kit  = class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id() ? (array) get_post_meta( SMC_Location_Brand::kit_id(), '_elementor_page_settings', true ) : [];
		$old  = self::baseline();
		$b    = [
			'saved'    => time(),
			'user'     => get_current_user_id(),
			'host'     => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'practice' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'city'     => $t ? $t->name : '',
			'phone'    => $m( 'phone_label' ),
			'street'   => trim( $addr[0] ?? '' ),
			'csz'      => trim( $addr[1] ?? '' ),
			'email'    => $m( 'email' ),
			'domain'   => $t ? SMC_Location_Builder::guess_domain( $t ) : '',
			'team'     => post_type_exists( 'smc_team' ) ? wp_list_pluck( get_posts( [ 'post_type' => 'smc_team', 'post_status' => 'publish', 'numberposts' => -1 ] ), 'post_title' ) : [],
			'logo'     => (int) get_theme_mod( 'custom_logo' ),
			'mlogo'    => class_exists( 'SMC_Location_Brand' ) ? (int) SMC_Location_Brand::options()['mobile_logo'] : 0,
			'icon'     => (int) get_option( 'site_icon' ),
			'colors'   => wp_list_pluck( (array) ( $kit['system_colors'] ?? [] ), 'color', '_id' ),
			'forms'    => self::jotform_ids( $locs ),
			'media'    => get_posts( [ 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids' ] ),
			'extra'    => null !== $extra ? $extra : ( $old['extra'] ?? [] ),
		];
		update_option( self::BASELINE, $b, false );
		return $b;
	}

	/** JotForm IDs in the locations' booking and form fields, and in published content. */
	private static function jotform_ids( array $locs ) {
		global $wpdb;
		$ids = [];
		foreach ( $locs as $t ) {
			foreach ( [ 'booking_link', 'form_embed' ] as $k ) {
				if ( preg_match_all( '#jotform\.(?:com|eu|me)/(?:jsform/|form/)?(\d{10,})#i', (string) get_term_meta( $t->term_id, $k, true ), $m ) ) {
					$ids = array_merge( $ids, $m[1] );
				}
			}
		}
		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%jotform%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			if ( preg_match_all( '#jotform\.(?:com|eu|me)\\\\?/(?:jsform\\\\?/|form\\\\?/)?(\d{10,})#i', $r, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/* ========== Ignoring ========== */

	public static function ignored() {
		$i = get_option( self::IGNORED, [] );
		return is_array( $i ) ? $i : [];
	}

	public static function ignore( $key, $label ) {
		$i         = self::ignored();
		$i[ $key ] = (string) $label;
		update_option( self::IGNORED, $i, false );
	}

	public static function unignore_all() {
		delete_option( self::IGNORED );
	}

	/* ========== Run ========== */

	/** @return array [ blocker => [], should => [], optional => [], ignored => n ] */
	public static function run() {
		$a       = new self();
		$a->base = self::baseline();
		$a->load();
		if ( $a->base && ! self::is_template( $a->base ) ) {
			$a->demo_text();
			$a->demo_images();
			$a->branding();
			$a->forms();
		}
		$a->placeholders();
		$a->urls();
		$a->defaults();
		$a->search_engines();
		$a->tracking();
		$a->legal_pages();
		$a->seo();
		$a->headings_and_images();

		$out  = [ 'blocker' => [], 'should' => [], 'optional' => [], 'ignored' => 0 ];
		$skip = self::ignored();
		foreach ( $a->findings as $f ) {
			if ( isset( $skip[ $f['key'] ] ) ) {
				$out['ignored']++;
				continue;
			}
			$out[ $f['severity'] ][] = $f;
		}
		return $out;
	}

	private function add( $severity, $check, $detail, array $where = [], $fix = '', $link = '', $key = '', $item = 0 ) {
		$this->findings[] = [
			'key'      => $key ?: md5( $check . '|' . $detail ),
			'severity' => $severity,
			'check'    => $check,
			'detail'   => $detail,
			'where'    => array_slice( $where, 0, 30 ),
			'more'     => max( 0, count( $where ) - 30 ),
			'fix'      => $fix,
			'link'     => $link,
			'item'     => (int) $item,
		];
	}

	/** Every published page, post, template and menu item, with its text. */
	private function load() {
		$posts = get_posts( [ 'post_type' => array_merge( self::TYPES, [ 'nav_menu_item' ] ), 'post_status' => 'publish', 'numberposts' => -1 ] );
		foreach ( $posts as $p ) {
			if ( 'elementor_library' === $p->post_type && in_array( get_post_meta( $p->ID, '_elementor_template_type', true ), [ 'kit' ], true ) ) {
				continue;
			}
			$parts = [ $p->post_title, $p->post_excerpt ];
			$data  = json_decode( (string) get_post_meta( $p->ID, '_elementor_data', true ), true );
			if ( is_array( $data ) ) {
				array_walk_recursive(
					$data,
					function ( $v, $k ) use ( &$parts ) {
						if ( is_string( $v ) && ! ( is_string( $k ) && preg_match( '/^_(?:id|element_id|css_classes)$|css|^id$|^widgetType$|^elType$/', $k ) ) ) {
							$parts[] = $v;
						}
					}
				);
			} else {
				$parts[] = $p->post_content;
			}
			if ( 'nav_menu_item' === $p->post_type ) {
				$parts[] = (string) get_post_meta( $p->ID, '_menu_item_url', true );
			}
			foreach ( [ 'title', 'metadesc', 'opengraph-title', 'opengraph-description' ] as $y ) {
				$parts[] = (string) get_post_meta( $p->ID, "_yoast_wpseo_$y", true );
			}
			$raw           = implode( "\n", array_filter( $parts, 'strlen' ) );
			$plain         = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $raw ) ), ENT_QUOTES, 'UTF-8' ) ) ) );
			$this->items[] = [ $p, $raw, $plain ];
		}
	}

	private function where( $p ) {
		if ( 'nav_menu_item' === $p->post_type ) {
			return [ 'Menu: ' . ( $p->post_title ?: get_the_title( (int) get_post_meta( $p->ID, '_menu_item_object_id', true ) ) ), admin_url( 'nav-menus.php' ) ];
		}
		$label = ( 'elementor_library' === $p->post_type ? 'Template: ' : '' ) . ( $p->post_title ?: '#' . $p->ID );
		return [ $label, admin_url( 'post.php?post=' . $p->ID . '&action=elementor' ) ];
	}

	/** Items whose text matches $re (plain text by default), as where-links. */
	private function find( $re, $raw = false ) {
		$out = [];
		foreach ( $this->items as [ $p, $r, $plain ] ) {
			if ( preg_match( $re, $raw ? $r : $plain ) ) {
				$out[] = $this->where( $p );
			}
		}
		return $out;
	}

	/* ========== 1. Template leftovers ========== */

	private function demo_text() {
		$b      = $this->base;
		$now    = SMC_Location_Manager::locations()[0] ?? null;
		$cur    = $now ? [ $now->name, (string) get_term_meta( $now->term_id, 'phone_label', true ), (string) get_term_meta( $now->term_id, 'email', true ) ] : [];
		$values = [
			'Demo practice name' => [ $b['practice'] ],
			'Demo city'          => [ $b['city'] ],
			'Demo street'        => [ $b['street'] ],
			'Demo email'         => [ $b['email'] ],
			'Demo domain'        => [ $b['domain'] ],
			'Demo doctor'        => (array) $b['team'],
			'Template text'      => (array) ( $b['extra'] ?? [] ),
		];
		foreach ( $values as $check => $list ) {
			foreach ( array_unique( array_filter( array_map( 'trim', $list ) ) ) as $v ) {
				if ( strlen( $v ) < 4 || in_array( strtolower( $v ), array_map( 'strtolower', $cur ), true ) || 0 === strcasecmp( $v, html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ) {
					continue; // The client's own value (e.g. same city).
				}
				$name  = preg_replace( '/^(?:Dr\.?|Doctor)\s+/i', '', $v );
				$re    = '/(?<![\w@.\/-])' . preg_quote( 'Demo doctor' === $check ? $name : $v, '/' ) . '(?![\w-])/iu';
				$where = $this->find( $re );
				$where = array_merge( $where, $this->alt_text( $re ) );
				if ( $where ) {
					$this->add( 'blocker', $check, "\"$v\" is still on the site.", $where, 'Replace it with the client\'s, or use the matching shortcode. If it\'s meant to be there, ignore this.', '', 'demo|' . md5( $v ) );
				}
			}
		}
		$digits = preg_replace( '/\D/', '', (string) $b['phone'] );
		if ( 10 === strlen( $digits ) && ( ! $cur || preg_replace( '/\D/', '', $cur[1] ) !== $digits ) ) {
			$re    = '/(?<!\d)(?:' . implode( '|', array_map( fn( $x ) => preg_quote( $x, '/' ), SMC_Location_Cloner::phone_variants( $digits ) ) ) . ')(?!\d)/';
			$where = $this->find( $re, true );
			if ( $where ) {
				$this->add( 'blocker', 'Demo phone number', "\"{$b['phone']}\" is still on the site.", $where, 'Replace it with [location field="phone_label"] (Scan below can do it for you once it\'s the location\'s number).', '', 'demo|phone' );
			}
		}
	}

	/** Images whose alt text matches. */
	private function alt_text( $re ) {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND meta_value <> ''" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( preg_match( $re, $row->meta_value ) ) {
				$out[] = [ 'Image alt text: ' . get_the_title( $row->post_id ), admin_url( 'post.php?post=' . (int) $row->post_id . '&action=edit' ) ];
			}
		}
		return $out;
	}

	private function demo_images() {
		$media = array_map( 'intval', (array) ( $this->base['media'] ?? [] ) );
		if ( ! $media ) {
			return;
		}
		$skip = array_filter( [ (int) $this->base['logo'], (int) $this->base['mlogo'], (int) $this->base['icon'] ] ); // Checked under branding.
		foreach ( $media as $id ) {
			if ( in_array( $id, $skip, true ) || get_post_meta( $id, self::KEEP, true ) || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( '' === $file ) {
				continue;
			}
			$stem  = preg_replace( '/(?:-scaled)?\.[a-z0-9]+$/i', '', $file ); // 2024/05/office (any size: office-300x200.jpg)
			$re    = '#uploads(?:\\\\?/)' . str_replace( '/', '\\\\?/', preg_quote( $stem, '#' ) ) . '(?:-\d+x\d+|-scaled)?\.[a-z0-9]+#i';
			$where = [];
			foreach ( $this->items as [ $p, $raw ] ) {
				if ( preg_match( $re, $raw ) || (int) get_post_thumbnail_id( $p ) === $id ) {
					$where[] = $this->where( $p );
				}
			}
			if ( $where ) {
				$this->add( 'should', 'Template photo', get_the_title( $id ) . ' (' . basename( $file ) . ') is from the template.', $where, 'Swap it for one of the client\'s photos. If it\'s a generic image every client can keep (an icon, a pattern), click Keep for clients.', admin_url( 'post.php?post=' . $id . '&action=edit' ), 'img|' . $id, $id );
			}
		}
	}

	private function branding() {
		$b    = $this->base;
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo && $logo === (int) $b['logo'] ) {
			$this->add( 'blocker', 'Logo', 'The site logo is still the template\'s.', [], 'Set the client\'s logo.', admin_url( 'admin.php?page=smc-brand' ), 'brand|logo' );
		}
		$ml = class_exists( 'SMC_Location_Brand' ) ? (int) SMC_Location_Brand::options()['mobile_logo'] : 0;
		if ( $ml && $ml === (int) $b['mlogo'] ) {
			$this->add( 'blocker', 'Mobile logo', 'The mobile logo is still the template\'s.', [], 'Set the client\'s mobile logo, or remove it.', admin_url( 'admin.php?page=smc-brand' ), 'brand|mlogo' );
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon && $icon === (int) $b['icon'] ) {
			$this->add( 'blocker', 'Favicon', 'The favicon is still the template\'s.', [], 'Set the client\'s favicon.', admin_url( 'admin.php?page=smc-brand' ), 'brand|icon' );
		}
		$kit = class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id() ? (array) get_post_meta( SMC_Location_Brand::kit_id(), '_elementor_page_settings', true ) : [];
		$now = wp_list_pluck( (array) ( $kit['system_colors'] ?? [] ), 'color', '_id' );
		if ( $now && ! empty( $b['colors'] ) && array_map( 'strtolower', $now ) == array_map( 'strtolower', (array) $b['colors'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			$this->add( 'should', 'Brand colors', 'The global colors are the same as the template\'s.', [], 'Set the client\'s colors, unless the template\'s are what they chose.', admin_url( 'admin.php?page=smc-brand' ), 'brand|colors' );
		}
	}

	private function forms() {
		$old = (array) ( $this->base['forms'] ?? [] );
		if ( ! $old ) {
			return;
		}
		$re = '#jotform\.(?:com|eu|me)\\\\?/(?:jsform\\\\?/|form\\\\?/)?(' . implode( '|', array_map( 'preg_quote', $old ) ) . ')\b#i';
		$where = [];
		foreach ( SMC_Location_Manager::locations() as $t ) {
			foreach ( [ 'booking_link' => 'Booking form link', 'form_embed' => 'Embedded form' ] as $k => $label ) {
				if ( preg_match( $re, (string) get_term_meta( $t->term_id, $k, true ) ) ) {
					$where[] = [ "{$t->name}: $label", SMC_Location_Manager::url( [ 'action' => 'edit', 'term' => $t->term_id ] ) ];
				}
			}
		}
		$where = array_merge( $where, $this->find( $re, true ) );
		if ( $where ) {
			$this->add( 'blocker', 'Template form', 'A JotForm from the template is still in use, so submissions go to the template\'s form.', $where, 'Clone the form in JotForm for this client and use its link.', '', 'forms' );
		}
	}

	private function placeholders() {
		foreach ( self::PLACEHOLDERS as $re => [ $sev, $check ] ) {
			$where = $this->find( $re );
			if ( $where ) {
				$this->add( $sev, $check, 'Placeholder text is on the site.', $where, 'Replace it with the client\'s content.', '', 'ph|' . $check );
			}
		}
		// Fictional 555-01xx phone numbers that aren't the location's own.
		$own = array_map( fn( $t ) => preg_replace( '/\D/', '', (string) get_term_meta( $t->term_id, 'phone_label', true ) ), SMC_Location_Manager::locations() );
		$where = [];
		foreach ( $this->items as [ $p, $raw ] ) {
			if ( preg_match_all( '/(?<!\d)\(?(\d{3})\)?[\s.-]?555[\s.-]?(01\d\d)(?!\d)/', $raw, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $x ) {
					if ( ! in_array( $x[1] . '555' . $x[2], $own, true ) ) {
						$where[] = $this->where( $p );
						break;
					}
				}
			}
		}
		if ( $where ) {
			$this->add( 'blocker', 'Fake phone number', 'A 555-01xx number (only used in examples) is on the site.', $where, 'Replace it with [location field="phone_label"].', '', 'ph|555' );
		}
	}

	private function urls() {
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$staging = (bool) preg_match( '/smcnational\.com$/', $host );
		$tpl     = array_filter( [ $this->base['host'] ?? '', $this->base['domain'] ?? '' ] );
		$other   = [];
		$self    = [];
		foreach ( $this->items as [ $p, $raw ] ) {
			if ( ! preg_match_all( '#https?:(?:\\\\?/){2}(?:www\.)?([a-z0-9.-]+\.[a-z]{2,})#i', $raw, $m ) ) {
				continue;
			}
			foreach ( array_unique( array_map( 'strtolower', $m[1] ) ) as $h ) {
				if ( $h === $host || 'www.' . $h === $host ) {
					if ( $staging ) {
						$self[ $p->ID ] = $this->where( $p );
					}
				} elseif ( preg_match( '/smcnational\.com$/', $h ) || in_array( $h, $tpl, true ) || in_array( 'www.' . $h, $tpl, true ) ) {
					$other[ $h ][] = $this->where( $p );
				}
			}
		}
		foreach ( $other as $h => $where ) {
			$this->add( 'blocker', 'Link to the template or another staging site', "Links or images point at $h.", $where, 'Change them to this site, or to relative links (/contact/). Images: re-pick them from this site\'s Media Library.', '', 'url|' . $h );
		}
		if ( $self ) {
			$this->add( 'optional', 'Links to the staging address', 'Some links and images use the full staging address (' . $host . '). They\'ll need changing when the site moves to its domain.', array_values( $self ), 'Use relative links (/contact/) where you can; the rest is handled when the domain is switched.', '', 'url|self' );
		}
	}

	private function defaults() {
		foreach ( [ [ 'sample-page', 'page', 'The "Sample Page" WordPress adds is published.' ], [ 'hello-world', 'post', 'The "Hello world!" post WordPress adds is published.' ] ] as [ $slug, $type, $msg ] ) {
			$p = get_page_by_path( $slug, OBJECT, $type );
			if ( $p && 'publish' === $p->post_status ) {
				$this->add( 'should', 'WordPress default content', $msg, [ $this->where( $p ) ], 'Delete it.', '', 'default|' . $slug );
			}
		}
		if ( in_array( get_option( 'blogdescription' ), [ 'Just another WordPress site', 'Just another WordPress site.' ], true ) ) {
			$this->add( 'should', 'WordPress default tagline', 'The tagline is still "Just another WordPress site".', [], 'Change or clear it under Settings > General.', admin_url( 'options-general.php' ), 'default|tagline' );
		}
	}

	/* ========== 2. Launch checks ========== */

	private function search_engines() {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$this->add( 'blocker', 'Search engines blocked', '"Discourage search engines from indexing this site" is on. Fine on staging; it has to be off at launch.', [], 'Untick it under Settings > Reading when the site goes live.', admin_url( 'options-reading.php' ), 'seo|blog_public' );
		}
		$front = (int) get_option( 'page_on_front' );
		$where = [];
		$home  = false;
		foreach ( $this->items as [ $p ] ) {
			if ( in_array( $p->post_type, [ 'page', 'post' ], true ) && '1' === (string) get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
				$where[] = $this->where( $p );
				$home    = $home || $p->ID === $front;
			}
		}
		if ( $where ) {
			$this->add( $home ? 'blocker' : 'should', 'Pages hidden from Google', count( $where ) . ' page(s) are set to noindex in Yoast' . ( $home ? ', including the homepage' : '' ) . '.', $where, 'In each page\'s Yoast settings (Advanced), allow search engines to show it, unless hiding it is intended (a thank-you page).', '', 'seo|noindex' );
		}
	}

	private function tracking() {
		global $wpdb;
		$re   = '/googletagmanager\.com|GTM-[A-Z0-9]{4,}|\bG-[A-Z0-9]{6,}\b|gtag\(|google-analytics\.com|cdn\.callrail\.com|calltrk|\bUA-\d{4,}-\d+/';
		$hit  = false;
		$opts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'googlesitekit%' OR option_name LIKE 'gtm4wp%' OR option_name LIKE 'monsterinsights%' OR option_name LIKE 'ihaf_%' OR option_name LIKE '%header_scripts%' OR option_name LIKE '%custom_code%' OR option_name LIKE 'callrail%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $opts as $o ) {
			$hit = $hit || preg_match( $re, (string) $o );
		}
		if ( ! $hit ) {
			$code = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_type IN ('wpcode','elementor_snippet','custom-css-js','wp_block') AND post_status IN ('publish','private')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $code as $c ) {
				$hit = $hit || preg_match( $re, (string) $c );
			}
		}
		if ( ! $hit && $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}snippets'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}snippets WHERE active = 1" ) as $c ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$hit = $hit || preg_match( $re, (string) $c );
			}
		}
		if ( ! $hit ) {
			foreach ( $this->items as [ $p, $raw ] ) {
				$hit = $hit || preg_match( $re, $raw );
			}
		}
		if ( ! $hit ) {
			$this->add( 'should', 'No tracking found', 'No Google Tag Manager, Google Analytics or CallRail code was found (in Site Kit, GTM4WP, MonsterInsights, WPCode, Code Snippets, Elementor custom code or the pages).', [], 'Add the client\'s GTM or GA4, and call tracking if they use it. If it\'s added another way, ignore this.', '', 'tracking' );
		}
	}

	private function legal_pages() {
		$pp = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( ! $pp || 'publish' !== get_post_status( $pp ) ) {
			$this->add( 'should', 'Privacy policy', 'No published privacy policy page is set.', [], 'Publish one and pick it under Settings > Privacy.', admin_url( 'options-privacy.php' ), 'legal|privacy' );
		} elseif ( preg_match( '/Suggested text:|Our website address is: https?:\/\//', (string) get_post_field( 'post_content', $pp ) ) ) {
			$this->add( 'should', 'Privacy policy', 'The privacy policy is still WordPress\'s starter text.', [ $this->where( get_post( $pp ) ) ], 'Replace it with the practice\'s policy.', '', 'legal|privacy_text' );
		}
		$acc = false;
		foreach ( $this->items as [ $p ] ) {
			$acc = $acc || ( 'page' === $p->post_type && ( false !== stripos( $p->post_name, 'accessib' ) || false !== stripos( $p->post_title, 'accessib' ) ) );
		}
		if ( ! $acc ) {
			$this->add( 'should', 'Accessibility statement', 'No published accessibility page was found.', [], 'Publish an accessibility statement and link it in the footer.', '', 'legal|accessibility' );
		}
	}

	private function seo() {
		$titles = get_option( 'wpseo_titles' );
		$def    = is_array( $titles ) ? trim( (string) ( $titles['metadesc-page'] ?? '' ) ) : '';
		$missing = [];
		$seen    = [];
		foreach ( $this->items as [ $p ] ) {
			if ( 'page' !== $p->post_type ) {
				continue;
			}
			if ( '' === $def && '' === trim( (string) get_post_meta( $p->ID, '_yoast_wpseo_metadesc', true ) ) ) {
				$missing[] = $this->where( $p );
			}
			$t = trim( (string) get_post_meta( $p->ID, '_yoast_wpseo_title', true ) );
			if ( '' !== $t && false === strpos( $t, '%%title%%' ) ) {
				$seen[ strtolower( $t ) ][] = $this->where( $p );
			}
		}
		if ( $missing && defined( 'WPSEO_VERSION' ) ) {
			$this->add( 'should', 'Missing meta descriptions', count( $missing ) . ' page(s) have no meta description, and there\'s no default for pages.', $missing, 'Write them, or set a pattern (New Build or Yoast > Settings > Content types > Pages).', '', 'seo|desc' );
		}
		foreach ( $seen as $t => $where ) {
			if ( count( $where ) > 1 ) {
				$this->add( 'optional', 'Duplicate SEO title', count( $where ) . " pages share the SEO title \"$t\".", $where, 'Give each page its own title.', '', 'seo|dup|' . md5( $t ) );
			}
		}
	}

	private function headings_and_images() {
		$none   = [];
		$many   = [];
		$noalt  = [];
		$big    = [];
		foreach ( $this->items as [ $p ] ) {
			if ( 'page' !== $p->post_type ) {
				continue;
			}
			$data = json_decode( (string) get_post_meta( $p->ID, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$h1   = 0;
			$imgs = [];
			$walk = function ( $els ) use ( &$walk, &$h1, &$imgs ) {
				foreach ( (array) $els as $el ) {
					$w = $el['widgetType'] ?? '';
					$s = (array) ( $el['settings'] ?? [] );
					if ( ( 'heading' === $w && 'h1' === ( $s['header_size'] ?? '' ) ) || ( in_array( $w, [ 'theme-post-title', 'theme-page-title' ], true ) && 'h2' !== ( $s['header_size'] ?? 'h1' ) ) ) {
						$h1++;
					}
					if ( 'text-editor' === $w ) {
						$h1 += preg_match_all( '/<h1\b/i', (string) ( $s['editor'] ?? '' ) );
					}
					if ( 'image' === $w && ! empty( $s['image']['id'] ) && empty( $s['__dynamic__']['image'] ) ) {
						$imgs[] = (int) $s['image']['id'];
					}
					$walk( $el['elements'] ?? [] );
				}
			};
			$walk( $data );
			if ( 0 === $h1 ) {
				$none[] = $this->where( $p );
			} elseif ( $h1 > 1 ) {
				$many[] = $this->where( $p );
			}
			foreach ( array_unique( $imgs ) as $id ) {
				if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
					$noalt[ $id ] = [ get_the_title( $id ) . ' (on ' . $p->post_title . ')', admin_url( 'post.php?post=' . $id . '&action=edit' ) ];
				}
				$meta = wp_get_attachment_metadata( $id );
				$size = (int) ( $meta['filesize'] ?? 0 );
				if ( ! $size ) {
					$file = get_attached_file( $id );
					$size = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
				}
				if ( $size > 500 * 1024 ) {
					$big[ $id ] = [ get_the_title( $id ) . ' (' . size_format( $size ) . ', on ' . $p->post_title . ')', admin_url( 'post.php?post=' . $id . '&action=edit' ) ];
				}
			}
		}
		if ( $none ) {
			$this->add( 'optional', 'No H1', count( $none ) . ' page(s) have no H1 heading in Elementor.', $none, 'Set the page\'s main heading to H1 (if the theme shows the page title as the H1, ignore this).', '', 'h1|none' );
		}
		if ( $many ) {
			$this->add( 'optional', 'More than one H1', count( $many ) . ' page(s) have more than one H1.', $many, 'Keep one H1 per page; make the others H2.', '', 'h1|many' );
		}
		if ( $noalt ) {
			$this->add( 'optional', 'Images without alt text', count( $noalt ) . ' image(s) shown on pages have no alt text.', array_values( $noalt ), 'Describe each image in its Alt Text field in the Media Library.', '', 'img|alt' );
		}
		if ( $big ) {
			$this->add( 'optional', 'Large images', count( $big ) . ' image(s) on pages are over 500 KB.', array_values( $big ), 'Compress them (Imagify can do it in bulk) or upload smaller versions.', '', 'img|big' );
		}
	}
}
