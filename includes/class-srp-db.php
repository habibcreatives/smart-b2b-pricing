<?php
if (!defined('ABSPATH')) { exit; }

class SRP_DB {
    public static function tables(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            'customer_types' => $p . 'srp_customer_types',
            'users'          => $p . 'srp_users',
            'rules'          => $p . 'srp_rules',
        ];
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $t = self::tables();

        $sql1 = "CREATE TABLE {$t['customer_types']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            menu_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY menu_order (menu_order)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE {$t['users']} (
            user_id BIGINT UNSIGNED NOT NULL,
            type_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            vat_number VARCHAR(64) NULL,
            registered_via VARCHAR(32) NOT NULL DEFAULT 'business_form',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id),
            KEY status (status),
            KEY type_id (type_id)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE {$t['rules']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type_id BIGINT UNSIGNED NULL,
            scope VARCHAR(20) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            rule_type VARCHAR(10) NOT NULL,
            value DECIMAL(18,6) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            menu_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY menu_order (menu_order),
            KEY type_scope (type_id, scope),
            KEY scope_object (scope, object_id)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['customer_types']}");
        if ($count === 0) {
            $wpdb->insert($t['customer_types'], ['name' => 'Distributor', 'status' => 'active', 'menu_order' => 1]);
            $wpdb->insert($t['customer_types'], ['name' => 'Installer', 'status' => 'active', 'menu_order' => 2]);
            $wpdb->insert($t['customer_types'], ['name' => 'Electrician', 'status' => 'active', 'menu_order' => 3]);
            $wpdb->insert($t['customer_types'], ['name' => 'Freelancer', 'status' => 'active', 'menu_order' => 4]);
        }
    }
}