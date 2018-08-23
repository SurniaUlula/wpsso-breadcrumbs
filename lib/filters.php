<?php
/**
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl.txt
 * Copyright 2017-2018 Jean-Sebastien Morisset (https://wpsso.com/)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'These aren\'t the droids you\'re looking for...' );
}

if ( ! class_exists( 'WpssoBcFilters' ) ) {

	class WpssoBcFilters {

		protected $p;

		public static $cf = array(
			'opt' => array(				// options
				'defaults' => array(
					'bc_list_for_ptn_attachment' => 'none',
					'bc_list_for_ptn_page'       => 'ancestors',
					'bc_list_for_ptn_post'       => 'categories',
				),
			),
		);

		public function __construct( &$plugin ) {
			$this->p =& $plugin;

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			$this->p->util->add_plugin_filters( $this, array( 
				'get_defaults'                              => 1,
				'json_array_schema_page_type_ids'           => 2,
				'json_data_https_schema_org_breadcrumblist' => 5,
			) );
		}

		public function filter_get_defaults( $def_opts ) {

			$def_opts = array_merge( $def_opts, self::$cf['opt']['defaults'] );

			/**
			 * Add options using a key prefix array and post type names.
			 */
			$def_opts = $this->p->util->add_ptns_to_opts( $def_opts, array(
				'bc_list_for_ptn' => 'categories',	// breacrumb list for post type name
			) );

			return $def_opts;
		}

		public function filter_json_array_schema_page_type_ids( $page_type_ids, $mod ) {

			$page_type_ids['breadcrumb.list'] = true;

			return $page_type_ids;
		}

		public function filter_json_data_https_schema_org_breadcrumblist( $json_data, $mod, $mt_og, $page_type_id, $is_main ) {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
				$this->p->debug->log( 'page_type_id is ' . $page_type_id);
			}

			/**
			 * Clear all properties inherited by previous filters except for the 'url' property.
			 */
			$json_data    = array( 'url' => $json_data['url'] );
			$scripts_data = array();
			$scripts_max  = SucomUtil::get_const( 'WPSSO_SCHEMA_BREADCRUMB_SCRIPTS_MAX', 5 );

			if ( $mod['is_post'] ) {

				$opt_key = 'bc_list_for_ptn_'.$mod['post_type'];

				/**
				 * The default for any undefined post type is 'categories'.
				 */
				$opt_val = isset( $this->p->options[$opt_key] ) ? $this->p->options[$opt_key] : 'categories';

				if ( $this->p->debug->enabled ) {
					$this->p->debug->log( $opt_key.' is '.$opt_val );
				}

				/**
				 * Breacrumbs are not required for the home page. The Google testing tool also gives
				 * an error if an item in the breadcrumbs list is a Schema WebSite type.
				 */
				if ( $mod['is_home'] ) {
				
					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'exiting early: breadcrumbs not required for home page' );
					}

					return array();	// Stop here.
				}

				switch ( $opt_val ) {

					case 'none':		// Nothing to do.

						return array();	// Stop here.

					case 'ancestors':	// Get post/page parents, grand-parents, etc.
				
						$mods     = array();
						$post_ids = get_post_ancestors( $mod['id'] ); 
						
						if ( is_array( $post_ids ) ) {
							$post_ids   = array_reverse( $post_ids );
							$post_ids[] = $mod['id'];
						} else {
							$post_ids = array( $mod['id'] );
						}

						if ( $this->p->debug->enabled ) {
							$this->p->debug->log_arr( '$post_ids', $post_ids );
						}

						foreach ( $post_ids as $mod_id ) {
							$mods[] = $this->p->m['util']['post']->get_mod( $mod_id );
						}

						WpssoBcBreadcrumb::add_itemlist_data( $json_data, $mods, $page_type_id );

						return $json_data;	// Stop here.

					case 'categories':

						$tax_slug = 'category';

						if ( $mod['post_type'] === 'product' ) {
							if ( ! empty( $this->p->avail['ecom']['woocommerce'] ) ) {
								$tax_slug = 'product_cat';
							}
						}

						$post_terms  = wp_get_post_terms( $mod['id'], $tax_slug );
						$scripts_num = 0;

						if ( $this->p->debug->enabled ) {
							$this->p->debug->log( 'taxonomy slug = ' . $tax_slug );
							$this->p->debug->log_arr( '$post_terms', $post_terms );
						}

						foreach ( $post_terms as $post_term ) {

							$mods     = array();
							$term_ids = get_ancestors( $post_term->term_id, $tax_slug, 'taxonomy' );

							if ( is_array( $term_ids ) ) {
								$term_ids   = array_reverse( $term_ids );
								$term_ids[] = $post_term->term_id;	// Add parent term last.
							} else {
								$term_ids = array( $post_term->term_id );
							}

							foreach ( $term_ids as $mod_id ) {
								$mods[] = $this->p->m['util']['term']->get_mod( $mod_id );
							}

							$mods[] = $this->p->m['util']['post']->get_mod( $mod['id'] );

							/**
							 * Create a unique @id for the breadcrumbs of each top-level post term.
							 */
							$term_data = array(
								'@id' => $json_data['url'] . '#id/' . $page_type_id . '/' . $post_term->slug,
							);

							WpssoBcBreadcrumb::add_itemlist_data( $term_data, $mods, $page_type_id );

							/**
							 * Multiple breadcrumbs list - merge $json_data and save to $scripts_data array.
							 */
							$scripts_data[] = WpssoSchema::return_data_from_filter( $json_data, $term_data, $is_main );

							$scripts_num++;

							if ( $scripts_num >= $scripts_max ) {	// Default max is 5.
								break;
							}
						}

						/**
						 * If the post does not have any categories, then add itself as the only item.
						 */
						if ( empty( $scripts_data ) ) {

							$mods = array( $this->p->m['util']['post']->get_mod( $mod['id'] ) );

							WpssoBcBreadcrumb::add_itemlist_data( $json_data, $mods, $page_type_id );
						
							return $json_data;
						}
						
						return $scripts_data;
				}
			}
		}
	}
}
