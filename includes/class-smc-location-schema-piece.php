<?php
/**
 * The location's business as a piece of Yoast SEO's schema graph.
 * Loaded only when Yoast is active (see SMC_Location_Schema::yoast_piece()).
 */

defined( 'ABSPATH' ) || exit;

use Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece;

class SMC_Location_Schema_Piece extends Abstract_Schema_Piece {

	public function __construct( $context = null ) {
		if ( $context ) {
			$this->context = $context;
		}
	}

	public function is_needed() {
		return (bool) SMC_Location_Schema::current_term();
	}

	public function generate() {
		$t = SMC_Location_Schema::current_term();
		if ( ! $t ) {
			return false;
		}
		$org = '';
		if ( isset( $this->context->site_represents ) && 'company' === $this->context->site_represents ) {
			$org = trailingslashit( $this->context->site_url ) . '#organization';
		}
		return SMC_Location_Schema::node( $t, $org );
	}
}
