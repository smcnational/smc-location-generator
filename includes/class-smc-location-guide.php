<?php
/**
 * Locations > Guide: how to use the plugin, from a template to a launched client site.
 * Static help, with a search box, a table of contents and click-to-copy shortcodes.
 */

defined( 'ABSPATH' ) || exit;

class SMC_Location_Guide {

	const SLUG = 'smc-location-guide';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 30 );
		add_filter( 'plugin_action_links_' . plugin_basename( SMC_LOCATION_FILE ), [ __CLASS__, 'plugin_link' ] );
	}

	public static function menu() {
		add_submenu_page( SMC_Location_Manager::SLUG, 'Guide', 'Guide', self::CAP, self::SLUG, [ __CLASS__, 'page' ] );
	}

	public static function plugin_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Guide</a>' );
		return $links;
	}

	public static function url( $section = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG ) . ( $section ? '#' . $section : '' );
	}

	/** A link to another Locations screen. */
	private static function a( $page, $label ) {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** Click-to-copy code. */
	private static function c( $code ) {
		return '<code class="smc-g-copy" title="Click to copy">' . esc_html( $code ) . '</code>';
	}

	/** A reference table: rows of [ code, what it does ]. */
	private static function table( array $rows, $head = 'Shortcode' ) {
		$out = '<table class="widefat striped smc-g-table"><thead><tr><th>' . esc_html( $head ) . '</th><th>What it shows</th></tr></thead><tbody>';
		foreach ( $rows as [ $code, $what ] ) {
			$out .= '<tr><td>' . self::c( $code ) . '</td><td>' . wp_kses_post( $what ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function sections() {
		$c = [ __CLASS__, 'c' ];
		$a = [ __CLASS__, 'a' ];
		return [
			'start'     => [
				'Start here',
				'<p>SMC Locations turns a template site into a client\'s site, and keeps every office\'s details (address, phone, hours, map, forms, doctors, reviews) in one place. Pages show them through shortcodes, so a change on a location\'s edit screen shows up everywhere: pages, headers, footers, Yoast titles and Google\'s schema.</p>
				<p><strong>Which path?</strong></p>
				<ul class="ul-disc">
					<li><strong>New client, one office:</strong> clone a template site, then run ' . $a( 'smc-location-build', 'New Build' ) . '. See <a href="#single">Single-location site</a>.</li>
					<li><strong>New office for an existing client:</strong> ' . $a( 'smc-add-location', 'Add Location' ) . ' copies an existing office\'s pages. See <a href="#add">Adding a location</a>.</li>
					<li><strong>Before any site goes live:</strong> ' . $a( 'smc-location-scan', 'Scan' ) . ' and the launch checklist. See <a href="#launch">Launching</a>.</li>
					<li><strong>Building or refreshing a template:</strong> see <a href="#template">Building a template</a>.</li>
				</ul>',
			],
			'single'    => [
				'Single-location site (New Build)',
				'<ol>
					<li>Clone the template to the client\'s staging site in Plesk.</li>
					<li>Open ' . $a( 'smc-location-build', 'Locations > New Build' ) . ' and fill in the practice name, domain, doctors (one per line, like ' . $c( 'Dr. Jane Lee, DDS' ) . '), city, state, phone, address, email, booking link, form, map, hours and social links. Blank fields keep the template\'s value for now.</li>
					<li><strong>Services:</strong> untick the services the practice doesn\'t offer. They\'re set to draft and taken out of the menus.</li>
					<li><strong>SEO:</strong> click <em>Fill in the examples</em> or write your own patterns, like ' . $c( '{service} in {city}, {state} {sep} {practice}' ) . '.</li>
					<li>Click <strong>Preview</strong>. Nothing changes yet. Read the summary and warnings.</li>
					<li>Click <strong>Build the site</strong>. Every page, template, header, footer and menu gets the practice\'s details, the location is renamed, the site title changes and the doctors are added.</li>
					<li>Fix anything listed under <em>Still showing demo text</em>, then go to ' . $a( 'smc-brand', 'Brand' ) . ' for the logo, colors and fonts.</li>
					<li>Run ' . $a( 'smc-location-scan', 'Scan' ) . ' (see <a href="#launch">Launching</a>).</li>
				</ol>
				<p><strong>Undo the build</strong> on the New Build screen puts the whole site back to the template. Only one build runs per site; undo to run it again. A JSON file with the same fields can fill the form (<em>Load from a file</em>).</p>
				<p>On a site with one location, pages without a location (services, blog, about) use that location for shortcodes, Yoast variables and schema.</p>',
			],
			'add'       => [
				'Adding a location',
				'<ol>
					<li>Open ' . $a( 'smc-add-location', 'Add Location' ) . ' and pick the existing office most like the new one.</li>
					<li>Fill in the new office\'s city, state, phone, address, booking link, email, form, hours, social links and map. Blank fields show the copied office\'s value in grey and keep it for now.</li>
					<li>Use <strong>Extra replacements</strong> for other text typed into the copied pages, like ' . $c( 'Downtown Oldtown => Downtown Springfield' ) . '.</li>
					<li>Click <strong>Preview</strong>, check it, then create the location. Its pages are drafts by default.</li>
					<li>Add its doctors under <strong>Locations > Team</strong> and its reviews under ' . $a( 'smc-review-import', 'Import Reviews' ) . '.</li>
					<li>Work through its launch checklist (below), then click <strong>Publish location</strong>.</li>
				</ol>
				<p><strong>Undo</strong> next to it under <em>Previous clones</em> on the Add Location screen deletes everything the clone created.</p>',
			],
			'launch'    => [
				'Launching',
				'<p><strong>Launch checklist:</strong> the top of each location\'s edit screen (Locations > All Locations > Edit). Red items block publishing, amber items are worth a look. When nothing is red, <strong>Publish location</strong> publishes all its draft pages and templates at once. <em>Undo publish</em> works for 14 days.</p>
				<p><strong>' . $a( 'smc-location-scan', 'Scan' ) . '</strong> has two parts:</p>
				<ul class="ul-disc">
					<li><strong>Launch audit:</strong> template leftovers (demo practice name, city, phone, doctors, photos, logo, favicon, forms, staging links, lorem ipsum and other placeholders) and launch checks (search engines blocked, noindex, tracking, privacy and accessibility pages, meta descriptions, H1s, image alt text and size). Sorted into <em>Launch blockers</em>, <em>Should fix</em> and <em>Optional</em>. <em>Ignore</em> hides one that\'s intended.</li>
					<li><strong>Typed-in details:</strong> phone numbers, addresses, emails, links, the site name and copyright years typed into pages instead of coming from shortcodes. <strong>Apply</strong> (or <strong>Apply all</strong>) fixes most of them without opening Elementor, and <strong>Undo all fixes</strong> puts them back.</li>
				</ul>
				<p><strong>Before the domain goes live:</strong> clear the launch blockers, untick <em>Discourage search engines</em> under Settings > Reading, and check the site\'s tracking.</p>',
			],
			'template'  => [
				'Building a template',
				'<ul class="ul-disc">
					<li><strong>One demo location</strong> under Locations > All Locations, with fictional details that are easy to spot: Springfield, ST, 555-555-0100, 123 Main St, a demo practice name as the site title, and demo doctors under Team.</li>
					<li><strong>Use shortcodes, not typed details,</strong> in headers, footers and pages (see <a href="#shortcodes">Shortcodes</a>). Run ' . $a( 'smc-location-scan', 'Scan' ) . ' with <em>Apply all</em> to convert what\'s typed in.</li>
					<li><strong>Services</strong> go under a page with the slug ' . $c( 'services' ) . ' so New Build can list them.</li>
					<li><strong>Yoast titles</strong> use the location variables (see <a href="#yoast">Yoast variables</a>).</li>
					<li><strong>Save the template baseline</strong> on the Scan screen once the template is finished. It records the demo details, logo, favicon, colors, forms and photos, so every clone can find what\'s left of the template.</li>
					<li><strong>Keep for clients:</strong> tick it on generic images (icons, patterns) in the Media Library so clones don\'t flag them.</li>
				</ul>',
			],
			'shortcodes' => [
				'Shortcodes',
				'<p>Every shortcode uses the page\'s location. Add ' . $c( 'location="springfield"' ) . ' (the location\'s slug) to show a different one. In a Heading or Button, use Elementor\'s <strong>Shortcode</strong> dynamic tag; Text Editor widgets run shortcodes as typed.</p>
				<h3>Location details</h3>' .
				self::table(
					[
						[ '[location field="city_state"]', 'Springfield, ST' ],
						[ '[location field="address"]', 'The address: two lines on its own, one line inside a sentence' ],
						[ '[location field="phone_label"]', 'The phone number as text' ],
						[ '[location field="phone_link"]', 'tel: link, for a button\'s Link' ],
						[ '[location field="email"]', 'The email address' ],
						[ '[location field="email_link"]', 'mailto: link, for a button\'s Link' ],
						[ '[location field="booking_link"]', 'The booking form link, for a button\'s Link' ],
						[ '[location field="google_business_url"]', 'Google Business Profile link (also facebook_url, instagram_url, youtube_url, tiktok_url)' ],
						[ '[location_url]', 'The location\'s main page URL' ],
						[ '[current_slug]', 'The current page\'s slug' ],
					]
				) .
				'<h3>Blocks</h3>' .
				self::table(
					[
						[ '[location_hours]', 'The hours table' ],
						[ '[location_map]', 'The Google Map' ],
						[ '[location_social]', 'Social icons (empty ones are hidden)' ],
						[ '[location_form]', 'The embedded JotForm (the booking form if no separate form is set)' ],
						[ '[location_team type="doctors"]', 'Doctor cards. type="team" for the rest of the team; button="Read Bio" adds a button to each profile' ],
						[ '[location_reviews]', 'Review cards. limit="6", min_rating="5"' ],
						[ '[location_list]', 'Every location with a map, search and "near me", for an Our Locations page' ],
						[ '[location_closure_notice]', 'A notice in the days before a holiday closure (styled under Holidays)' ],
						[ '[location_holidays]', 'A list of upcoming closures' ],
					]
				) .
				'<h3>Site</h3>' .
				self::table(
					[
						[ '[site_name]', 'The Site Title' ],
						[ '[brand_name]', 'The organization name in Yoast (or the Site Title)' ],
						[ '[brand_logo]', 'The logo, with the mobile logo on small screens' ],
						[ '[current_year]', 'This year, for copyright lines: © [current_year] [brand_name]' ],
					]
				) .
				'<h3>Inside an Elementor Loop</h3>' .
				self::table(
					[
						[ '[team field="name_credentials"]', 'Dr. Jane Lee, DDS (the comma only when there are credentials)' ],
						[ '[team field="short_bio"]', 'The short bio, or the start of the full bio' ],
						[ '[team field="profile_url"]', 'Link to the person\'s profile on Meet the Doctors, for a Read Bio button' ],
						[ '[team field="anchor_id"]', 'The anchor, for the Menu Anchor widget\'s ID' ],
						[ '[team field="locations" link="yes"]', 'The offices they work at' ],
						[ '[review field="name"]', 'Reviewer name (also rating, stars, text, date, source, location)' ],
					]
				) .
				'<p><strong>Loop Query IDs:</strong> ' . $c( 'location_doctors' ) . ' ' . $c( 'location_staff' ) . ' ' . $c( 'location_team' ) . ' ' . $c( 'location_reviews' ) . ' ' . $c( 'location_reviews_5star' ) . ' ' . $c( 'location_reviews_random' ) . '. On Corporate pages they show every location.</p>',
			],
			'yoast'     => [
				'Yoast variables and schema',
				'<p>In Yoast titles and descriptions, these show the page\'s location: ' . $c( '%%location_city%%' ) . ' ' . $c( '%%location_state%%' ) . ' ' . $c( '%%location_city_state%%' ) . ' ' . $c( '%%location_name%%' ) . ' ' . $c( '%%location_phone%%' ) . ' ' . $c( '%%location_address%%' ) . ' ' . $c( '%%location_street%%' ) . ' ' . $c( '%%location_zip%%' ) . ' ' . $c( '%%location_email%%' ) . '</p>
				<p>Example: ' . $c( 'Dentist in %%location_city_state%% | %%sitename%%' ) . '. ' . $c( 'wp smc location yoast-vars' ) . ' converts typed-in details in existing titles.</p>
				<p><strong>Schema:</strong> every location page tells Google it\'s a Dentist, with the address, phone, hours (holidays included), map position, Google Business Profile, social links and doctors. Nothing to fill in. Settings are under ' . $a( 'smc-location-settings', 'Settings > Schema' ) . ', and each location\'s edit screen shows its schema with a link to Google\'s Rich Results Test.</p>',
			],
			'content'   => [
				'Team, reviews, holidays, brand and redirects',
				'<ul class="ul-disc">
					<li><strong>Team:</strong> Locations > Team. Each person has a type (doctor or team), locations, job title, credentials, photo, short bio and full bio, and an automatic anchor for linking to their profile.</li>
					<li><strong>Reviews:</strong> add them under Locations > Reviews or ' . $a( 'smc-review-import', 'Import Reviews' ) . ' (CSV template there). Rating and Source can be changed with Quick Edit and Bulk Edit.</li>
					<li><strong>Holidays:</strong> ' . $a( 'smc-location-holidays', 'Locations > Holidays' ) . '. <em>Add common holidays</em> fills in a year. The notice\'s style and wording are set there too. Put ' . $c( '[location_closure_notice]' ) . ' in each location\'s header below the navigation.</li>
					<li><strong>Brand:</strong> ' . $a( 'smc-brand', 'Locations > Brand' ) . ' sets the logo, mobile logo, favicon, colors, fonts, text styles and buttons, saved to Elementor\'s Site Settings. Every save can be restored.</li>
					<li><strong>Redirects:</strong> ' . $a( 'smc-location-redirects', 'Locations > Redirects' ) . '. Deleting a location or changing a location page\'s URL adds a 301 automatically.</li>
					<li><strong>Export / Import:</strong> moves locations, team (with photos), reviews, brand, holidays and settings between sites in one file.</li>
				</ul>',
			],
			'cli'       => [
				'WP-CLI',
				'<p>Over SSH, from the site\'s WordPress folder:</p>' .
				self::table(
					[
						[ 'wp smc location build client.json', 'New Build from a JSON file (preview, then asks)' ],
						[ 'wp smc location build-undo', 'Undo the build' ],
						[ 'wp smc location sources', 'List the locations that can be copied' ],
						[ 'wp smc location init springfield Shelbyville', 'Write a clone config to fill in' ],
						[ 'wp smc location clone shelbyville.json', 'Add a location from the config' ],
						[ 'wp smc location undo shelbyville', 'Delete what a clone created' ],
						[ 'wp smc location checklist springfield', 'The launch checklist' ],
						[ 'wp smc location publish springfield', 'Publish location' ],
						[ 'wp smc location scan', 'Typed-in details' ],
						[ 'wp smc location yoast-vars', 'Convert typed-in details in Yoast fields (add --apply to save)' ],
						[ 'wp smc location redirects', 'List, add or remove redirects' ],
						[ 'wp smc location export / import', 'Export / Import' ],
					],
					'Command'
				),
			],
			'updates'   => [
				'Updates',
				'<p>The plugin updates itself from GitHub like any other plugin (Dashboard > Updates). Staging sites can get test versions first: set <strong>Updates</strong> to <em>Beta</em> under ' . $a( 'smc-location-settings', 'Settings' ) . '. Releasing a new version is described in the repository\'s RELEASING.md.</p>',
			],
		];
	}

	public static function page() {
		$sections = self::sections();
		?>
		<div class="wrap smc-guide">
			<h1>Guide</h1>
			<p class="smc-g-search"><input type="search" id="smc-g-q" class="regular-text" placeholder="Search the guide, e.g. hours, Read Bio, redirect"> <span class="description" id="smc-g-none" hidden>Nothing found.</span></p>
			<div class="smc-g-layout">
				<nav class="smc-g-toc">
					<?php foreach ( $sections as $id => [ $title ] ) : ?>
						<a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="smc-g-body">
					<?php foreach ( $sections as $id => [ $title, $html ] ) : ?>
						<section class="smc-g-section" id="<?php echo esc_attr( $id ); ?>">
							<h2><?php echo esc_html( $title ); ?></h2>
							<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<style>
			.smc-guide .smc-g-layout { display: flex; gap: 28px; align-items: flex-start; max-width: 1200px; }
			.smc-guide .smc-g-toc { position: sticky; top: 46px; flex: 0 0 210px; background: #fff; border: 1px solid #dcdcde; padding: 10px 0; }
			.smc-guide .smc-g-toc a { display: block; padding: 5px 14px; text-decoration: none; }
			.smc-guide .smc-g-toc a:hover { background: #f0f6fc; }
			.smc-guide .smc-g-body { flex: 1; min-width: 0; }
			.smc-guide .smc-g-section { background: #fff; border: 1px solid #dcdcde; padding: 4px 22px 16px; margin-bottom: 18px; scroll-margin-top: 46px; }
			.smc-guide .smc-g-section h3 { margin: 18px 0 6px; }
			.smc-guide .smc-g-section li { margin-bottom: 6px; }
			.smc-guide .smc-g-table td:first-child { width: 42%; }
			.smc-guide .smc-g-copy { cursor: copy; }
			.smc-guide .smc-g-copy.is-copied { background: #d1e7dd; }
			.smc-guide .smc-g-search input { min-height: 32px; }
			@media (max-width: 900px) { .smc-guide .smc-g-layout { display: block; } .smc-guide .smc-g-toc { position: static; margin-bottom: 16px; } }
		</style>
		<script>
		( function () {
			document.querySelectorAll( '.smc-g-copy' ).forEach( function ( el ) {
				el.addEventListener( 'click', function () {
					if ( navigator.clipboard ) { navigator.clipboard.writeText( el.textContent ); }
					el.classList.add( 'is-copied' );
					setTimeout( function () { el.classList.remove( 'is-copied' ); }, 900 );
				} );
			} );
			var q = document.getElementById( 'smc-g-q' ), none = document.getElementById( 'smc-g-none' );
			q.addEventListener( 'input', function () {
				var t = q.value.trim().toLowerCase(), any = false;
				document.querySelectorAll( '.smc-g-section' ).forEach( function ( s ) {
					var show = ! t || s.textContent.toLowerCase().indexOf( t ) > -1;
					s.hidden = ! show; any = any || show;
					s.querySelectorAll( 'li, tr' ).forEach( function ( r ) {
						r.style.background = t && r.textContent.toLowerCase().indexOf( t ) > -1 ? '#fcf9e8' : '';
					} );
				} );
				none.hidden = any;
			} );
		} )();
		</script>
		<?php
	}
}
