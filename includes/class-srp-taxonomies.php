<?php
if (!defined('ABSPATH')) { exit; }

class SRP_Taxonomies {
    public static function init(): void {
        add_action('init', [__CLASS__, 'register_brand_taxonomy'], 9);
    }

    public static function register_brand_taxonomy(): void {
        $labels = [
            'name'              => __('Brands', 'srp'),
            'singular_name'     => __('Brand', 'srp'),
            'search_items'      => __('Search Brands', 'srp'),
            'all_items'         => __('All Brands', 'srp'),
            'edit_item'         => __('Edit Brand', 'srp'),
            'update_item'       => __('Update Brand', 'srp'),
            'add_new_item'      => __('Add New Brand', 'srp'),
            'new_item_name'     => __('New Brand Name', 'srp'),
            'menu_name'         => __('Brands', 'srp'),
        ];

        $args = [
            'labels'            => $labels,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            'hierarchical'      => false,
            'rewrite'           => ['slug' => 'brand'],
            'show_in_rest'      => true,
        ];

        register_taxonomy('srp_brand', ['product'], $args);
    }
}
