<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LP_Migrator_LinkCentral extends LP_Migrator {

	public static function is_available() {
		return self::get_source_count() > 0;
	}

	public static function get_source_name() {
		return 'LinkCentral';
	}

	public static function get_source_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'linkcentral_link'
			 AND post_status IN ('publish', 'draft', 'private')"
		);
	}

	public function run() {
		$this->migrate_categories();
		$this->migrate_links();
		$this->migrate_clicks();
		return $this->results;
	}

	private function migrate_categories() {
		$terms = get_terms( array(
			'taxonomy'   => 'linkcentral_category',
			'hide_empty' => false,
			'orderby'    => 'parent',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$term_id_map = array();

		foreach ( $terms as $term ) {
			$parent_id = 0;
			if ( $term->parent && isset( $term_id_map[ $term->parent ] ) ) {
				$parent_id = $term_id_map[ $term->parent ];
			}

			$new_term_id = $this->map_category( $term->name, $parent_id );
			if ( $new_term_id ) {
				$term_id_map[ $term->term_id ] = $new_term_id;
			}
		}

		return $term_id_map;
	}

	private function migrate_links() {
		$category_map = $this->build_category_map();

		$paged = 1;

		do {
			$query = new WP_Query( array(
				'post_type'      => 'linkcentral_link',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'paged'          => $paged,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			) );

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					continue;
				}

				$redirect_type = get_post_meta( $post_id, '_linkcentral_redirection_type', true );
				$nofollow      = get_post_meta( $post_id, '_linkcentral_nofollow', true );
				$new_tab       = get_post_meta( $post_id, '_linkcentral_new_tab', true );
				$sponsored     = get_post_meta( $post_id, '_linkcentral_sponsored', true );
				$pass_query    = get_post_meta( $post_id, '_linkcentral_parameter_forwarding', true );
				$css_classes   = get_post_meta( $post_id, '_linkcentral_custom_css_classes', true );
				$dest_url      = get_post_meta( $post_id, '_linkcentral_destination_url', true );

				$new_link_id = $this->create_link( array(
					'old_id'          => $post_id,
					'title'           => $post->post_title,
					'slug'            => $post->post_name,
					'destination_url' => $dest_url,
					'redirect_type'   => $redirect_type ? $redirect_type : 'default',
					'nofollow'        => $this->normalize_bool( $nofollow ),
					'sponsored'       => $this->normalize_bool( $sponsored ),
					'new_window'      => $this->normalize_bool( $new_tab ),
					'css_classes'     => $css_classes ? $css_classes : '',
					'pass_query_str'  => $this->normalize_bool( $pass_query ),
				) );

				if ( ! $new_link_id ) {
					continue;
				}

				$terms = wp_get_object_terms( $post_id, 'linkcentral_category', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$lp_term_ids = array();
					foreach ( $terms as $term_id ) {
						if ( isset( $category_map[ $term_id ] ) ) {
							$lp_term_ids[] = $category_map[ $term_id ];
						}
					}
					if ( $lp_term_ids ) {
						wp_set_object_terms( $new_link_id, $lp_term_ids, 'lp_category' );
					}
				}
			}

			$paged++;
		} while ( $paged <= $query->max_num_pages );
	}

	private function migrate_clicks() {
		global $wpdb;

		$table = $wpdb->prefix . 'linkcentral_stats';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$offset     = 0;
		$batch_size = 500;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY id ASC LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! isset( $this->id_map[ $row->link_id ] ) ) {
					continue;
				}

				$new_link_id = $this->id_map[ $row->link_id ];

				$this->import_click( $new_link_id, array(
					'clicked_at'      => $row->click_date,
					'referrer'        => isset( $row->referring_url ) ? $row->referring_url : '',
					'country_code'    => isset( $row->country ) ? $row->country : '',
					'user_agent_hash' => isset( $row->user_agent ) ? md5( $row->user_agent ) : '',
					'is_bot'          => 0,
				) );
			}

			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );
	}

	private function build_category_map() {
		$terms = get_terms( array(
			'taxonomy'   => 'linkcentral_category',
			'hide_empty' => false,
			'orderby'    => 'parent',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$old_to_new  = array();
		$term_id_map = array();

		foreach ( $terms as $term ) {
			$parent_id = 0;
			if ( $term->parent && isset( $old_to_new[ $term->parent ] ) ) {
				$parent_id = $old_to_new[ $term->parent ];
			}

			$lp_term = get_term_by( 'name', $term->name, 'lp_category' );
			if ( $lp_term && (int) $lp_term->parent === (int) $parent_id ) {
				$old_to_new[ $term->term_id ]  = $lp_term->term_id;
				$term_id_map[ $term->term_id ] = $lp_term->term_id;
			}
		}

		return $term_id_map;
	}
}
