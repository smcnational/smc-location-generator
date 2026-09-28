<?php
/**
 * Export and import of a site's location data as one JSON file.
 *
 * Sections:
 *   locations  every location_category term with all its details (address, phone, hours,
 *              social, map, form, email...). Matched by slug on import, so an existing
 *              location keeps its ID and anything pointing at it keeps working.
 *   reviews    every published review with its locations (by slug). Duplicates are merged.
 *   brand      logo, mobile logo, favicon (image files included), Elementor global colors
 *              and fonts. The previous brand is kept under Brand > Restore.
 *   settings   Locations > Settings display options (hours format, map and form heights...).
 *
 * Pages, templates and menus are not included: those move with the site itself (or come
 * from Add Location).
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Transfer {

	const FORMAT   = 1;
	const TAX      = 'location_category';
	const SECTIONS = [ 'locations', 'team', 'reviews', 'brand', 'settings' ];

	/* ========== Export ========== */

	public static function export( array $sections = self::SECTIONS ) {
		$sections = array_intersect( self::SECTIONS, $sections );
		$out      = [
			'format'   => self::FORMAT,
			'plugin'   => class_exists( 'SMC_Location_Updater' ) ? SMC_Location_Updater::current_version() : '',
			'site'     => home_url( '/' ),
			'name'     => get_bloginfo( 'name' ),
			'exported' => gmdate( 'c' ),
			'sections' => [],
		];

		if ( in_array( 'locations', $sections, true ) && taxonomy_exists( self::TAX ) ) {
			$rows  = [];
			$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false ] );
			foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
				$meta = [];
				foreach ( get_term_meta( $t->term_id ) as $k => $vals ) {
					$meta[ $k ] = maybe_unserialize( $vals[0] );
				}
				$parent = $t->parent ? get_term( $t->parent, self::TAX ) : null;
				$rows[] = [
					'slug'        => $t->slug,
					'name'        => $t->name,
					'description' => $t->description,
					'parent'      => $parent && ! is_wp_error( $parent ) ? $parent->slug : '',
					'meta'        => $meta,
				];
			}
			$out['sections']['locations'] = $rows;
		}

		if ( in_array( 'reviews', $sections, true ) && post_type_exists( 'smc_review' ) ) {
			$rows = [];
			foreach ( get_posts( [ 'post_type' => 'smc_review', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'ASC' ] ) as $p ) {
				$slugs  = wp_get_object_terms( $p->ID, self::TAX, [ 'fields' => 'slugs' ] );
				$rows[] = [
					'name'      => $p->post_title,
					'text'      => $p->post_content,
					'rating'    => (int) get_post_meta( $p->ID, 'rating', true ),
					'date'      => $p->post_date,
					'source'    => (string) get_post_meta( $p->ID, 'source', true ),
					'link'      => (string) get_post_meta( $p->ID, 'review_link', true ),
					'locations' => is_wp_error( $slugs ) ? [] : $slugs,
				];
			}
			$out['sections']['reviews'] = $rows;
		}

		if ( in_array( 'team', $sections, true ) && post_type_exists( 'smc_team' ) ) {
			$rows = [];
			foreach ( get_posts( [ 'post_type' => 'smc_team', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] ) as $p ) {
				$slugs  = wp_get_object_terms( $p->ID, self::TAX, [ 'fields' => 'slugs' ] );
				$rows[] = [
					'name'        => $p->post_title,
					'bio'         => $p->post_content,
					'short_bio'   => (string) get_post_meta( $p->ID, 'short_bio', true ),
					'type'        => (string) get_post_meta( $p->ID, 'team_type', true ),
					'title'       => (string) get_post_meta( $p->ID, 'job_title', true ),
					'credentials' => (string) get_post_meta( $p->ID, 'credentials', true ),
					'anchor'      => (string) get_post_meta( $p->ID, 'anchor', true ),
					'order'       => (int) $p->menu_order,
					'photo'       => self::image_out( get_post_thumbnail_id( $p ) ),
					'locations'   => is_wp_error( $slugs ) ? [] : $slugs,
				];
			}
			$out['sections']['team'] = $rows;
		}

		if ( in_array( 'brand', $sections, true ) && class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id() ) {
			$b                          = SMC_Location_Brand::snapshot();
			$out['sections']['brand'] = [
				'logo'              => self::image_out( $b['logo'] ),
				'mobile_logo'       => self::image_out( $b['mobile_logo'] ),
				'icon'              => self::image_out( $b['icon'] ),
				'mobile_logo_on'    => $b['mobile_logo_on'],
				'system_colors'     => $b['system_colors'],
				'custom_colors'     => $b['custom_colors'],
				'system_typography' => $b['system_typography'],
				'custom_typography' => $b['custom_typography'],
			];
		}

		if ( in_array( 'settings', $sections, true ) && class_exists( 'SMC_Location_Settings' ) ) {
			$out['sections']['settings'] = (array) get_option( SMC_Location_Settings::OPTION, [] );
		}

		return $out;
	}

	public static function filename() {
		$host = preg_replace( '/[^a-z0-9.-]+/i', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'smc-locations-' . $host . '-' . wp_date( 'Y-m-d' ) . '.json';
	}

	/** An image as filename, type, alt text and base64 data (so the file is self-contained). */
	private static function image_out( $id ) {
		$id   = (int) $id;
		$path = $id ? get_attached_file( $id ) : '';
		if ( ! $path || ! is_readable( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) {
			return null;
		}
		return [
			'filename' => basename( $path ),
			'mime'     => get_post_mime_type( $id ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'data'     => base64_encode( file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
		];
	}

	/* ========== Reading a file ========== */

	/** Parses and checks an export file. Returns the data array or a WP_Error. */
	public static function parse( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! isset( $data['format'], $data['sections'] ) || ! is_array( $data['sections'] ) ) {
			return new WP_Error( 'smc_import', 'That isn\'t an SMC Locations export file.' );
		}
		if ( (int) $data['format'] > self::FORMAT ) {
			return new WP_Error( 'smc_import', 'This file comes from a newer version of the plugin. Update SMC Locations on this site first.' );
		}
		return $data;
	}

	/** What an import would do, per section. */
	public static function preview( array $data ) {
		$s   = $data['sections'];
		$out = [];

		if ( isset( $s['locations'] ) ) {
			$new = [];
			$upd = [];
			foreach ( (array) $s['locations'] as $l ) {
				if ( get_term_by( 'slug', $l['slug'] ?? '', self::TAX ) ) {
					$upd[] = $l['name'];
				} else {
					$new[] = $l['name'];
				}
			}
			$out['locations'] = [ 'new' => $new, 'existing' => $upd, 'available' => taxonomy_exists( self::TAX ) ];
		}

		if ( isset( $s['reviews'] ) ) {
			$have = [];
			foreach ( get_posts( [ 'post_type' => 'smc_review', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
				$have[ (string) get_post_meta( $id, '_smc_review_hash', true ) ] = true;
			}
			$new = 0;
			$dup = 0;
			foreach ( (array) $s['reviews'] as $r ) {
				$h = SMC_Location_Reviews::hash( SMC_Location_Reviews::clean_name( $r['name'] ?? '' ) ?: 'Anonymous', SMC_Location_Reviews::clean_text( $r['text'] ?? '' ) );
				if ( isset( $have[ $h ] ) ) {
					$dup++;
				} else {
					$new++;
					$have[ $h ] = true;
				}
			}
			$out['reviews'] = [ 'new' => $new, 'existing' => $dup ];
		}

		if ( isset( $s['team'] ) ) {
			$new = [];
			$old = [];
			foreach ( (array) $s['team'] as $m ) {
				$exists = get_posts( [ 'post_type' => 'smc_team', 'post_status' => 'any', 'title' => (string) ( $m['name'] ?? '' ), 'fields' => 'ids', 'numberposts' => 1 ] );
				if ( $exists ) {
					$old[] = $m['name'];
				} else {
					$new[] = $m['name'];
				}
			}
			$out['team'] = [ 'new' => $new, 'existing' => $old ];
		}

		if ( isset( $s['brand'] ) ) {
			$b              = $s['brand'];
			$out['brand'] = [
				'colors'    => array_merge( (array) ( $b['system_colors'] ?? [] ), (array) ( $b['custom_colors'] ?? [] ) ),
				'fonts'     => array_values( array_filter( array_map( fn( $t ) => $t['typography_font_family'] ?? '', array_merge( (array) ( $b['system_typography'] ?? [] ), (array) ( $b['custom_typography'] ?? [] ) ) ) ) ),
				'images'    => array_filter( [ 'Logo' => $b['logo']['filename'] ?? '', 'Mobile logo' => $b['mobile_logo']['filename'] ?? '', 'Favicon' => $b['icon']['filename'] ?? '' ] ),
				'available' => class_exists( 'SMC_Location_Brand' ) && SMC_Location_Brand::kit_id(),
			];
		}

		if ( isset( $s['settings'] ) ) {
			$out['settings'] = [ 'count' => count( (array) $s['settings'] ) ];
		}
		return $out;
	}

	/* ========== Import ========== */

	/**
	 * @param array $data     Parsed export.
	 * @param array $sections Sections to import.
	 * @param bool  $update   Update existing locations with the file's details (false = leave them).
	 * @return array [ 'done' => [ lines ], 'warnings' => [ lines ] ]
	 */
	public static function import( array $data, array $sections, $update = true ) {
		$s    = $data['sections'];
		$done = [];
		$warn = [];

		if ( in_array( 'locations', $sections, true ) && isset( $s['locations'] ) ) {
			if ( ! taxonomy_exists( self::TAX ) ) {
				$warn[] = 'Locations skipped: this site doesn\'t have the SMC location system (location_category).';
			} else {
				$made    = 0;
				$changed = 0;
				$parents = [];
				foreach ( (array) $s['locations'] as $l ) {
					$slug = sanitize_title( $l['slug'] ?? '' );
					if ( '' === $slug ) {
						continue;
					}
					$term = get_term_by( 'slug', $slug, self::TAX );
					if ( $term && ! $update ) {
						continue;
					}
					if ( $term ) {
						wp_update_term( $term->term_id, self::TAX, [ 'name' => sanitize_text_field( $l['name'] ), 'description' => wp_kses_post( $l['description'] ?? '' ) ] );
						$tid = (int) $term->term_id;
						$changed++;
					} else {
						$res = wp_insert_term( sanitize_text_field( $l['name'] ), self::TAX, [ 'slug' => $slug, 'description' => wp_kses_post( $l['description'] ?? '' ) ] );
						if ( is_wp_error( $res ) ) {
							$warn[] = "Location {$l['name']}: " . $res->get_error_message();
							continue;
						}
						$tid = (int) $res['term_id'];
						$made++;
					}
					foreach ( (array) ( $l['meta'] ?? [] ) as $k => $v ) {
						$k = (string) $k;
						// ACF's "_field" references point at this site's own field keys; keep them if set.
						if ( '_' === substr( $k, 0, 1 ) && '' !== (string) get_term_meta( $tid, $k, true ) ) {
							continue;
						}
						update_term_meta( $tid, $k, wp_slash( $v ) );
					}
					if ( ! empty( $l['parent'] ) ) {
						$parents[ $tid ] = sanitize_title( $l['parent'] );
					}
				}
				foreach ( $parents as $tid => $pslug ) {
					$p = get_term_by( 'slug', $pslug, self::TAX );
					if ( $p ) {
						wp_update_term( $tid, self::TAX, [ 'parent' => (int) $p->term_id ] );
					}
				}
				$done[] = "Locations: $made added, $changed updated.";
			}
		}

		if ( in_array( 'reviews', $sections, true ) && isset( $s['reviews'] ) ) {
			$made    = 0;
			$dup     = 0;
			$missing = [];
			foreach ( (array) $s['reviews'] as $r ) {
				$ids = [];
				foreach ( (array) ( $r['locations'] ?? [] ) as $slug ) {
					$t = get_term_by( 'slug', $slug, self::TAX );
					if ( $t ) {
						$ids[] = (int) $t->term_id;
					} else {
						$missing[ $slug ] = true;
					}
				}
				$res = SMC_Location_Reviews::create( $r, $ids );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				$res ? $made++ : $dup++;
			}
			$done[] = "Reviews: $made added" . ( $dup ? ", $dup already here (their locations were combined)" : '' ) . '.';
			if ( $missing ) {
				$warn[] = 'Some reviews belong to locations this site doesn\'t have (' . implode( ', ', array_keys( $missing ) ) . '). They were imported without those locations; import Locations too, or assign them under Reviews.';
			}
		}

		if ( in_array( 'team', $sections, true ) && isset( $s['team'] ) && class_exists( 'SMC_Location_Team' ) ) {
			$made = 0;
			$have = 0;
			foreach ( (array) $s['team'] as $m ) {
				$ids = [];
				foreach ( (array) ( $m['locations'] ?? [] ) as $slug ) {
					$t = get_term_by( 'slug', $slug, self::TAX );
					if ( $t ) {
						$ids[] = (int) $t->term_id;
					}
				}
				$photo = 0;
				if ( ! empty( $m['photo'] ) ) {
					$pid = self::image_in( $m['photo'] );
					if ( is_wp_error( $pid ) ) {
						$warn[] = "Photo for {$m['name']} not imported: " . $pid->get_error_message();
					} else {
						$photo = $pid;
					}
				}
				$res = SMC_Location_Team::create( $m, $ids, $photo );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				$res ? $made++ : $have++;
			}
			$done[] = "Team: $made added" . ( $have ? ", $have already here (their locations were combined)" : '' ) . '.';
		}

		if ( in_array( 'brand', $sections, true ) && isset( $s['brand'] ) ) {
			if ( ! class_exists( 'SMC_Location_Brand' ) || ! SMC_Location_Brand::kit_id() ) {
				$warn[] = 'Brand skipped: Elementor\'s Site Settings weren\'t found on this site.';
			} else {
				$b   = $s['brand'];
				$old = SMC_Location_Brand::snapshot();
				$new = $old;
				foreach ( [ 'logo' => 'Logo', 'mobile_logo' => 'Mobile logo', 'icon' => 'Favicon' ] as $k => $label ) {
					if ( empty( $b[ $k ] ) ) {
						$new[ $k ] = 0;
						continue;
					}
					$id = self::image_in( $b[ $k ] );
					if ( is_wp_error( $id ) ) {
						$warn[] = "$label not imported: " . $id->get_error_message() . ' Set it under Locations > Brand.';
						continue;
					}
					$new[ $k ] = $id;
				}
				$new['mobile_logo_on'] = 'tablet' === ( $b['mobile_logo_on'] ?? '' ) ? 'tablet' : 'mobile';
				foreach ( [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ] as $k ) {
					if ( isset( $b[ $k ] ) && is_array( $b[ $k ] ) ) {
						$new[ $k ] = $b[ $k ];
					}
				}
				SMC_Location_Brand::push_history( $old );
				SMC_Location_Brand::apply( $new );
				$done[] = 'Brand: logo, favicon, colors and fonts replaced. The previous brand is under Locations > Brand > Restore.';
			}
		}

		if ( in_array( 'settings', $sections, true ) && isset( $s['settings'] ) && class_exists( 'SMC_Location_Settings' ) ) {
			update_option( SMC_Location_Settings::OPTION, array_merge( SMC_Location_Settings::defaults(), (array) $s['settings'] ) );
			$done[] = 'Settings: display settings replaced.';
		}

		if ( class_exists( 'SMC_Location_Cloner' ) ) {
			SMC_Location_Cloner::refresh_caches();
		}
		return [ 'done' => $done, 'warnings' => $warn ];
	}

	/** Adds an exported image to the Media Library (or reuses one imported before). */
	private static function image_in( array $img ) {
		$bytes = base64_decode( (string) ( $img['data'] ?? '' ), true );
		if ( ! $bytes ) {
			return new WP_Error( 'smc_import', 'the image data is missing.' );
		}
		$hash  = md5( $bytes );
		$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_smc_import_hash', 'meta_value' => $hash, 'fields' => 'ids', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return (int) $found[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name = sanitize_file_name( (string) ( $img['filename'] ?? 'logo.png' ) );
		$tmp  = wp_tempnam( $name );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], 0, sanitize_text_field( $img['alt'] ?? '' ) );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $id;
		}
		if ( ! empty( $img['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
		}
		update_post_meta( $id, '_smc_import_hash', $hash );
		return (int) $id;
	}
}
