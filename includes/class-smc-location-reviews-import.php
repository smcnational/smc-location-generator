<?php
/**
 * Locations > Import Reviews
 *
 * 1. CSV upload.
 * 2. Pull reviews out of existing Elementor Testimonial, Testimonial Carousel and Reviews
 *    widgets, so typed-in reviews become location reviews without retyping.
 * Duplicates (same reviewer and text) are skipped, so either can be run more than once.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Reviews_Import {

	const SLUG    = 'smc-review-import';
	const CAP     = 'manage_options';
	const WIDGETS = [ 'testimonial', 'testimonial-carousel', 'reviews' ];

	private $notice = '';
	private $error  = '';
	private $found  = null;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 12 );
	}

	public function menu() {
		$hook = add_submenu_page( SMC_Location_Manager::SLUG, 'Import Reviews', 'Import Reviews', self::CAP, self::SLUG, [ $this, 'page' ] );
		add_action( "load-$hook", [ $this, 'handle' ] );
	}

	/* ========== Actions ========== */

	public function handle() {
		if ( isset( $_GET['template'] ) && current_user_can( self::CAP ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'smc_reviews_template' );
			self::send_template();
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['smc_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$action = sanitize_key( $_POST['smc_action'] );

		if ( 'csv' === $action ) {
			check_admin_referer( 'smc_reviews_csv' );
			$this->import_csv();
		} elseif ( 'find' === $action ) {
			check_admin_referer( 'smc_reviews_find' );
			$this->found = self::find_widgets();
		} elseif ( 'cleanup' === $action ) {
			check_admin_referer( 'smc_reviews_cleanup' );
			$c            = SMC_Location_Reviews::cleanup( false );
			$this->notice = sprintf( 'Cleaned up: %d non-review entries and %d duplicates moved to the Trash (duplicates\' locations were combined), %d reviews tidied.', $c['not_reviews'], $c['duplicates'], $c['tidied'] );
		} elseif ( 'import_widgets' === $action ) {
			check_admin_referer( 'smc_reviews_widgets' );
			$this->import_widgets();
		}
	}

	/**
	 * Downloads a CSV template with the right columns and two example rows that use this
	 * site's own location slugs, so it can be filled in and imported as is (delete the examples).
	 */
	private static function send_template() {
		$locs  = SMC_Location_Manager::locations();
		$first = $locs[0]->slug ?? 'springfield';
		$both  = implode( '|', array_filter( [ $locs[0]->slug ?? 'springfield', $locs[1]->slug ?? '' ] ) );
		$rows  = [
			[ 'name', 'rating', 'review', 'date', 'source', 'link', 'location' ],
			[ 'Jane D.', '5', 'Everyone was so friendly and my cleaning was painless.', wp_date( 'Y-m-d' ), 'Google', '', $first ],
			[ 'Mark R.', '4', 'Great care and easy scheduling. Delete these example rows before importing.', wp_date( 'Y-m-d', strtotime( '-1 month' ) ), 'Facebook', '', $both ],
		];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=reviews-import-template.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // So Excel opens it as UTF-8.
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		exit;
	}

	private function import_csv() {
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$this->error = 'Choose a CSV file to upload.';
			return;
		}
		$fh = fopen( $_FILES['csv']['tmp_name'], 'r' );
		if ( ! $fh ) {
			$this->error = 'Could not read that file.';
			return;
		}
		$default = array_filter( [ absint( $_POST['default_location'] ?? 0 ) ] );

		// Header row: match columns by name, in any order.
		$header = fgetcsv( $fh );
		if ( ! $header ) {
			$this->error = 'The file is empty.';
			return;
		}
		$header = array_map( fn( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
		$col    = function ( $names ) use ( $header ) {
			foreach ( (array) $names as $n ) {
				$i = array_search( $n, $header, true );
				if ( false !== $i ) {
					return $i;
				}
			}
			return null;
		};
		$map = [
			'name'     => $col( [ 'name', 'reviewer', 'author', 'reviewer name' ] ),
			'text'     => $col( [ 'review', 'text', 'content', 'comment', 'review text' ] ),
			'rating'   => $col( [ 'rating', 'stars', 'star rating' ] ),
			'date'     => $col( [ 'date', 'review date' ] ),
			'source'   => $col( [ 'source', 'site', 'platform' ] ),
			'link'     => $col( [ 'link', 'url', 'review link' ] ),
			'location' => $col( [ 'location', 'locations', 'office' ] ),
		];
		if ( null === $map['text'] ) {
			$this->error = 'The CSV needs a "review" column (the review text). See the example below.';
			fclose( $fh );
			return;
		}

		$made = 0;
		$dupe = 0;
		$bad  = [];
		$row  = 1;
		while ( ( $cells = fgetcsv( $fh ) ) !== false ) {
			$row++;
			if ( ! array_filter( $cells, 'strlen' ) ) {
				continue;
			}
			$get = fn( $k ) => null === $map[ $k ] ? '' : trim( (string) ( $cells[ $map[ $k ] ] ?? '' ) );
			$loc = $default;
			if ( '' !== $get( 'location' ) ) {
				$loc = [];
				foreach ( preg_split( '/\s*[|;]\s*/', $get( 'location' ) ) as $l ) {
					$t = get_term_by( 'slug', sanitize_title( $l ), SMC_Location_Reviews::TAX ) ?: get_term_by( 'name', $l, SMC_Location_Reviews::TAX );
					if ( $t ) {
						$loc[] = (int) $t->term_id;
					} else {
						$bad[] = "Row $row: no location named \"$l\"";
					}
				}
			}
			$res = SMC_Location_Reviews::create(
				[
					'name'   => $get( 'name' ),
					'text'   => $get( 'text' ),
					'rating' => $get( 'rating' ) ?: 5,
					'date'   => $get( 'date' ),
					'source' => $get( 'source' ),
					'link'   => $get( 'link' ),
				],
				$loc
			);
			if ( is_wp_error( $res ) ) {
				$bad[] = "Row $row: " . $res->get_error_message();
			} elseif ( $res ) {
				$made++;
			} else {
				$dupe++;
			}
		}
		fclose( $fh );

		$this->notice = "Imported $made review(s)." . ( $dupe ? " Skipped $dupe already imported." : '' );
		if ( $bad ) {
			$this->error = 'Some rows need attention: ' . implode( '; ', array_slice( $bad, 0, 10 ) ) . ( count( $bad ) > 10 ? '; and ' . ( count( $bad ) - 10 ) . ' more.' : '.' );
		}
	}

	private function import_widgets() {
		$chosen = (array) ( $_POST['src'] ?? [] );
		$made   = 0;
		$dupe   = 0;
		foreach ( $chosen as $key => $on ) {
			if ( ! $on ) {
				continue;
			}
			list( $post_id, $el_id ) = array_pad( explode( '|', sanitize_text_field( $key ) ), 2, '' );
			$loc   = sanitize_text_field( wp_unslash( $_POST['loc'][ $key ] ?? '' ) );
			$terms = 'all' === $loc ? wp_list_pluck( SMC_Location_Manager::locations(), 'term_id' ) : array_filter( [ absint( $loc ) ] );
			foreach ( self::widget_items( (int) $post_id, $el_id ) as $item ) {
				$res = SMC_Location_Reviews::create( $item, $terms );
				if ( $res && ! is_wp_error( $res ) ) {
					$made++;
				} elseif ( 0 === $res ) {
					$dupe++;
				}
			}
		}
		$this->notice = "Imported $made review(s)." . ( $dupe ? " $dupe were already in the list; their locations were added to the existing review." : '' ) . ' The original widgets are unchanged; swap them for [location_reviews] or a Loop Carousel when ready.';
	}

	/* ========== Finding reviews in Elementor ========== */

	/** Every testimonial/review widget on the site, with a best guess at its location. */
	public static function find_widgets() {
		$out = [];
		$ids = get_posts(
			[
				'post_type'   => [ 'page', 'elementor_library' ],
				'post_status' => [ 'publish', 'draft', 'private' ],
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $ids as $id ) {
			$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( self::widgets_in( $data ) as $w ) {
				$items = self::items_from( $w );
				if ( ! $items ) {
					continue;
				}
				$out[] = [
					'post_id'  => $id,
					'title'    => get_the_title( $id ),
					'type'     => 'elementor_library' === get_post_type( $id ) ? 'Template' : 'Page',
					'widget'   => $w['widgetType'],
					'el_id'    => $w['id'],
					'count'    => count( $items ),
					'sample'   => $items[0]['name'] . ': ' . wp_trim_words( $items[0]['text'], 12 ),
					'location' => self::guess_location( $id ),
				];
			}
		}
		return $out;
	}

	private static function widgets_in( array $elements ) {
		$found = [];
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( in_array( $el['widgetType'] ?? '', self::WIDGETS, true ) ) {
				$found[] = $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = array_merge( $found, self::widgets_in( $el['elements'] ) );
			}
		}
		return $found;
	}

	/** Reviews inside one widget, normalized to name/text/rating/source. */
	private static function items_from( array $w ) {
		$s     = (array) ( $w['settings'] ?? [] );
		$items = [];
		if ( 'testimonial' === $w['widgetType'] ) {
			$items[] = [ 'name' => $s['testimonial_name'] ?? '', 'text' => $s['testimonial_content'] ?? '', 'rating' => 5, 'source' => '' ];
		} else {
			foreach ( (array) ( $s['slides'] ?? [] ) as $slide ) {
				$icon    = strtolower( (string) ( $slide['selected_social_icon']['value'] ?? '' ) );
				$source  = '';
				foreach ( SMC_Location_Reviews::SOURCES as $src ) {
					if ( false !== strpos( $icon, strtolower( $src ) ) ) {
						$source = $src;
					}
				}
				$items[] = [
					'name'   => $slide['name'] ?? '',
					'text'   => $slide['content'] ?? '',
					'rating' => $slide['rating'] ?? 5,
					'source' => $source,
					'link'   => $slide['link']['url'] ?? '',
				];
			}
		}
		foreach ( $items as $i => $it ) {
			// Slides that only embed a template or other shortcode aren't reviews.
			if ( SMC_Location_Reviews::is_empty_text( (string) $it['text'] ) ) {
				unset( $items[ $i ] );
				continue;
			}
			$items[ $i ]['name'] = SMC_Location_Reviews::clean_name( wp_strip_all_tags( (string) $it['name'] ) );
			$items[ $i ]['text'] = SMC_Location_Reviews::clean_text( wp_strip_all_tags( SMC_Location_Reviews::strip_shortcodes_text( (string) $it['text'] ) ) );
		}
		return array_values( $items );
	}

	private static function widget_items( $post_id, $el_id ) {
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			return [];
		}
		foreach ( self::widgets_in( $data ) as $w ) {
			if ( ( $w['id'] ?? '' ) === $el_id ) {
				return self::items_from( $w );
			}
		}
		return [];
	}

	/** Location term ID for a page (its term) or template (its display conditions), or ''. */
	private static function guess_location( $post_id ) {
		$tax = SMC_Location_Reviews::TAX;
		if ( 'elementor_library' !== get_post_type( $post_id ) ) {
			$ids = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $ids ) && $ids ) {
				$locs = wp_list_pluck( SMC_Location_Manager::locations(), 'term_id' );
				foreach ( $ids as $id ) {
					if ( in_array( (int) $id, array_map( 'intval', $locs ), true ) ) {
						return (int) $id;
					}
				}
				return 'all'; // Corporate or shared page.
			}
			return '';
		}
		foreach ( (array) get_post_meta( $post_id, '_elementor_conditions', true ) as $c ) {
			if ( preg_match( '#/in_' . preg_quote( $tax, '#' ) . '/(\d+)$#', (string) $c, $m ) ) {
				return (int) $m[1];
			}
		}
		return '';
	}

	/* ========== Page ========== */

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$locations = SMC_Location_Manager::locations();
		?>
		<div class="wrap">
			<h1>Import Reviews</h1>
			<?php if ( $this->error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $this->error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $this->notice ); ?> <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . SMC_Location_Reviews::TYPE ) ); ?>">View reviews</a></p></div>
			<?php endif; ?>
			<p>Reviews that are already in the list (same reviewer and text) aren't added twice; instead, the new location is added to the existing review. So it's safe to import the same file or widget again, and a review that appears on several location pages ends up assigned to all of them.</p>
			<?php
			$check = SMC_Location_Reviews::cleanup( true );
			if ( array_sum( $check ) ) :
				?>
				<div class="notice notice-warning inline" style="margin:1em 0">
					<p><strong>Your reviews need a cleanup:</strong>
					<?php
					$parts = [];
					if ( $check['not_reviews'] ) {
						$parts[] = $check['not_reviews'] . ' entries aren\'t reviews (just a shortcode, like [elementor-template])';
					}
					if ( $check['duplicates'] ) {
						$parts[] = $check['duplicates'] . ' are duplicates of another review';
					}
					if ( $check['tidied'] ) {
						$parts[] = $check['tidied'] . ' have quote marks around the text or an all-caps name';
					}
					echo esc_html( implode( '; ', $parts ) . '.' );
					?>
					</p>
					<form method="post" style="margin-bottom:10px">
						<?php wp_nonce_field( 'smc_reviews_cleanup' ); ?>
						<input type="hidden" name="smc_action" value="cleanup">
						<?php submit_button( 'Clean up reviews', 'primary', 'submit', false ); ?>
						<span class="description">&nbsp;Non-reviews and duplicates go to the Trash; a duplicate's locations are added to the review that's kept.</span>
					</form>
				</div>
			<?php endif; ?>

			<h2>From existing Elementor widgets</h2>
			<p>Copies reviews out of the Testimonial, Testimonial Carousel and Reviews widgets already on the site. The widgets themselves aren't changed.</p>
			<?php if ( null === $this->found ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'smc_reviews_find' ); ?>
					<input type="hidden" name="smc_action" value="find">
					<?php submit_button( 'Find reviews on this site', 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( ! $this->found ) : ?>
				<p><em>No Testimonial, Testimonial Carousel or Reviews widgets with reviews were found.</em></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'smc_reviews_widgets' ); ?>
					<input type="hidden" name="smc_action" value="import_widgets">
					<table class="widefat striped" style="max-width:1200px">
						<thead><tr><th style="width:30px"></th><th>Found on</th><th>Widget</th><th>Reviews</th><th>First review</th><th>Assign to</th></tr></thead>
						<tbody>
						<?php
						foreach ( $this->found as $f ) :
							$key = $f['post_id'] . '|' . $f['el_id'];
							?>
							<tr>
								<td><input type="checkbox" name="src[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( '' !== $f['location'] ); ?>></td>
								<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $f['post_id'] . '&action=elementor' ) ); ?>" target="_blank"><?php echo esc_html( $f['title'] ); ?></a><br><span class="description"><?php echo esc_html( $f['type'] ); ?></span></td>
								<td><code><?php echo esc_html( $f['widget'] ); ?></code></td>
								<td><?php echo (int) $f['count']; ?></td>
								<td><?php echo esc_html( $f['sample'] ); ?></td>
								<td>
									<select name="loc[<?php echo esc_attr( $key ); ?>]">
										<option value="">Choose...</option>
										<option value="all" <?php selected( $f['location'], 'all' ); ?>>All locations</option>
										<?php foreach ( $locations as $t ) : ?>
											<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $f['location'], (int) $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">Checked rows are the ones where the location could be worked out from the page or template. Check the rest and pick a location to import them too. The same reviews often appear on several pages; duplicates are skipped.</p>
					<?php submit_button( 'Import selected' ); ?>
				</form>
			<?php endif; ?>

			<h2>From a CSV file</h2>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'smc_reviews_csv' ); ?>
				<input type="hidden" name="smc_action" value="csv">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="csv">CSV file</label></th><td><input type="file" name="csv" id="csv" accept=".csv,text/csv" required></td></tr>
					<tr><th scope="row"><label for="default_location">Location for rows without one</label></th>
						<td><select name="default_location" id="default_location"><option value="">None</option>
							<?php foreach ( $locations as $t ) : ?>
								<option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
				</table>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::SLUG, 'template' => 1 ], admin_url( 'admin.php' ) ), 'smc_reviews_template' ) ); ?>"><span class="dashicons dashicons-download" style="vertical-align:text-bottom"></span> Download CSV template</a>
					<span class="description">Opens in Excel, Numbers or Google Sheets. It has every column and two example rows using this site's locations; delete the examples, add your reviews, save as CSV and import it here.</span></p>
				<p>The first row must be column names. Only <code>review</code> is required; columns can be in any order:</p>
				<pre style="background:#fff;border:1px solid #dcdcde;padding:10px 14px;max-width:900px;overflow:auto">name,rating,review,date,source,location
Jane D.,5,"Everyone was so friendly and my cleaning was painless.",2026-08-14,Google,springfield
Mark R.,5,"Best dental office in town.",2026-07-02,Facebook,springfield|shelbyville</pre>
				<p class="description"><code>location</code> is the location's name or slug; separate several with <code>|</code>. <code>rating</code> is 1 to 5 (default 5). <code>source</code> is Google, Facebook, Yelp, Healthgrades, Zocdoc, Website or Other. A <code>link</code> column with the review's URL is optional.</p>
				<?php submit_button( 'Import CSV' ); ?>
			</form>
		</div>
		<?php
	}
}
