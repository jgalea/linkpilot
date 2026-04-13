<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LP_Migrator_PrettyLinks extends LP_Migrator {

	public static function is_available() {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $exists !== $table ) {
			return false;
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE link_status = 'enabled'" );
		return $count > 0;
	}

	public static function get_source_name() {
		return 'Pretty Links';
	}

	public static function get_source_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';
return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE link_status = 'enabled'" );
	}

	public function run() {
		global $wpdb;
		$links_table  = $wpdb->prefix . 'prli_links';
		$clicks_table = $wpdb->prefix . 'prli_clicks';

$links = $wpdb->get_results( "SELECT * FROM `{$links_table}` WHERE link_status = 'enabled'", ARRAY_A );

		if ( empty( $links ) ) {
			return $this->results;
		}

		$has_categories = taxonomy_exists( 'pretty-link-category' );

		foreach ( $links as $link ) {
			$redirect_map = array(
				'301' => '301',
				'302' => '302',
				'307' => '307',
			);
			$redirect_type = isset( $redirect_map[ $link['redirect_type'] ] ) ? $redirect_map[ $link['redirect_type'] ] : 'default';

			$nofollow        = $this->normalize_bool( (int) $link['nofollow'] );
			$sponsored       = $this->normalize_bool( (int) $link['sponsored'] );
			$pass_query_str  = ( ! empty( $link['param_forwarding'] ) ) ? 'yes' : 'default';

			$new_id = $this->create_link( array(
				'old_id'          => (int) $link['id'],
				'title'           => $link['name'],
				'slug'            => $link['slug'],
				'destination_url' => $link['url'],
				'redirect_type'   => $redirect_type,
				'nofollow'        => $nofollow,
				'sponsored'       => $sponsored,
				'new_window'      => 'default',
				'css_classes'     => '',
				'pass_query_str'  => $pass_query_str,
				'rel_tags'        => '',
			) );

			if ( ! $new_id ) {
				continue;
			}

			if ( $has_categories && ! empty( $link['link_cpt_id'] ) ) {
				$terms = wp_get_object_terms( (int) $link['link_cpt_id'], 'pretty-link-category', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					$term_ids = array();
					foreach ( $terms as $term_name ) {
						$term_id = $this->map_category( $term_name );
						if ( $term_id ) {
							$term_ids[] = $term_id;
						}
					}
					if ( ! empty( $term_ids ) ) {
						wp_set_object_terms( $new_id, $term_ids, 'lp_category' );
					}
				}
			}

		$clicks = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$clicks_table}` WHERE link_id = %d",
				(int) $link['id']
			), ARRAY_A );

			if ( ! empty( $clicks ) ) {
				foreach ( $clicks as $click ) {
					$this->import_click( $new_id, array(
						'clicked_at'      => isset( $click['created_at'] ) ? $click['created_at'] : current_time( 'mysql', true ),
						'referrer'        => isset( $click['referer'] ) ? $click['referer'] : '',
						'country_code'    => '',
						'user_agent_hash' => '',
						'is_bot'          => isset( $click['robot'] ) ? (int) $click['robot'] : 0,
					) );
				}
			}
		}

		return $this->results;
	}
}
