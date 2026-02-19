<?php
if (!defined('ABSPATH')) { exit; }

class SRP_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_notices', [__CLASS__, 'conflict_notice']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_srp_search_users', [__CLASS__, 'ajax_search_users']);
        add_action('wp_ajax_srp_search_products', [__CLASS__, 'ajax_search_products']);
        
        // Drag and Drop AJAX Actions
        add_action('wp_ajax_srp_update_type_order', [__CLASS__, 'ajax_update_type_order']);
        add_action('wp_ajax_srp_update_rule_order', [__CLASS__, 'ajax_update_rule_order']);
    }

    public static function enqueue_assets(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'woocommerce_page_srp-smart-b2b-pricing') === false) return;

        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script('jquery-ui-sortable'); // Added for Drag and Drop

        wp_enqueue_style('srp-admin', SRP_PLUGIN_URL . 'assets/admin.css', [], SRP_VERSION);
        wp_enqueue_script('srp-admin', SRP_PLUGIN_URL . 'assets/admin.js', ['jquery', 'jquery-ui-dialog', 'jquery-ui-autocomplete', 'jquery-ui-sortable'], SRP_VERSION, true);

        wp_localize_script('srp-admin', 'SRP_Admin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('srp_admin_nonce'),
            'productSearchNonce' => wp_create_nonce('search-products'),
            'i18n' => [
                'manageUser' => __('Manage User', 'srp'),
                'editRule'   => __('Edit Rule', 'srp'),
            ],
        ]);
    }

    // --- AJAX For Drag and Drop Order Save ---
    public static function ajax_update_type_order(): void {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer('srp_admin_nonce', 'nonce');
        $order = isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [];
        global $wpdb;
        $t = SRP_DB::tables()['customer_types'];
        foreach ($order as $index => $id) {
            $wpdb->update($t, ['menu_order' => (int)$index], ['id' => (int)$id]);
        }
        wp_send_json_success();
    }

    public static function ajax_update_rule_order(): void {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer('srp_admin_nonce', 'nonce');
        $order = isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : [];
        global $wpdb;
        $t = SRP_DB::tables()['rules'];
        foreach ($order as $index => $id) {
            $wpdb->update($t, ['menu_order' => (int)$index], ['id' => (int)$id]);
        }
        wp_send_json_success();
    }
    // -----------------------------------------

    public static function ajax_search_users(): void {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer('srp_admin_nonce', 'nonce');
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $term = trim($term);
        if ($term === '') wp_send_json([]);

        $q = new WP_User_Query([
            'number' => 20,
            'search' => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'fields' => ['ID', 'user_email', 'display_name'],
        ]);
        $out = [];
        foreach ((array) $q->get_results() as $u) {
            $text = ($u->display_name ?: ('User #' . $u->ID)) . ' — ' . $u->user_email;
            $out[] = ['id' => (int) $u->ID, 'text' => $text];
        }
        wp_send_json($out);
    }

    public static function ajax_search_products(): void {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer('srp_admin_nonce', 'nonce');
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $term = trim($term);
        if ($term === '') wp_send_json([]);

        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $term,
            'posts_per_page' => 20,
            'fields'         => 'ids',
        ]);

        $out = [];
        foreach ((array) $q->posts as $pid) {
            $title = get_the_title($pid);
            if (!$title) $title = 'Product #' . $pid;
            $out[] = ['id' => (int) $pid, 'label' => $title, 'value' => $title];
        }
        wp_send_json($out);
    }

    private static function get_type_name(int $type_id): string {
        $types = SRP_User::get_customer_types(false);
        foreach ($types as $t) {
            if ((int)$t['id'] === $type_id) return (string)$t['name'];
        }
        return (string) $type_id;
    }

    private static function get_user_label(int $user_id): string {
        $u = get_user_by('id', $user_id);
        if (!$u) return 'User #' . $user_id;
        $name = $u->display_name ?: ('User #' . $u->ID);
        return $name . ' — ' . $u->user_email;
    }

    private static function get_product_label(int $product_id): string {
        $title = get_the_title($product_id);
        return $title ? $title : ('Product #' . $product_id);
    }

    private static function get_term_label(string $taxonomy, int $term_id): string {
        $t = get_term($term_id, $taxonomy);
        if (is_wp_error($t) || !$t) return (string)$term_id;
        return (string) $t->name;
    }

    private static function get_wc_countries(): array {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            return (array) WC()->countries->get_countries();
        }
        return [];
    }

    private static function country_label($code_or_name): string {
        $countries = self::get_wc_countries();
        $v = (string)$code_or_name;
        if ($v === '') return '';
        if (isset($countries[$v])) return (string)$countries[$v];
        return $v;
    }

    private static function render_status_badge(string $status): string {
        $st = sanitize_key($status);
        $label = ucfirst($st);
        $class = 'srp-badge srp-badge-' . $st;
        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Smart B2B Pricing', 'srp'),
            __('Smart B2B Pricing', 'srp'),
            'manage_woocommerce',
            'srp-smart-b2b-pricing',
            [__CLASS__, 'render']
        );
    }

    public static function conflict_notice(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if (strpos($screen->id, 'woocommerce_page_srp-smart-b2b-pricing') === false) return;

        $conflicts = self::detect_conflicting_pricing_plugins();
        if (empty($conflicts)) return;

        echo '<div class="notice notice-warning"><p><strong>Smart B2B Pricing:</strong> ' . esc_html__('A conflicting pricing/discount plugin is active. Disable it to avoid double discounts and incorrect totals:', 'srp') . '</p><ul style="margin-left:20px;">';
        foreach ($conflicts as $name) {
            echo '<li>' . esc_html($name) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function detect_conflicting_pricing_plugins(): array {
        $active = (array) get_option('active_plugins', []);
        $hits = [];
        foreach ($active as $plugin_file) {
            $pf = strtolower($plugin_file);
            if (strpos($pf, 'woo-discount-rules') !== false ||
                strpos($pf, 'discount-rules-for-woocommerce') !== false ||
                strpos($pf, 'dynamic-pricing') !== false ||
                strpos($pf, 'advanced-dynamic-pricing') !== false ||
                strpos($pf, 'role-based-pricing') !== false ||
                strpos($pf, 'wholesale') !== false
            ) {
                $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
                $hits[] = $data['Name'] ?? $plugin_file;
            }
        }
        return array_values(array_unique($hits));
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'srp'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $tabs = [
            'dashboard' => __('Dashboard', 'srp'),
            'types'     => __('Customer Types', 'srp'),
            'users'     => __('Business Users', 'srp'),
            'rules'     => __('Pricing Rules', 'srp'),
            'registration' => __('Registration & Approval', 'srp'),
        ];

        echo '<div class="wrap"><h1>' . esc_html__('Smart B2B Pricing', 'srp') . '</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            $class = ($tab === $k) ? ' nav-tab nav-tab-active' : ' nav-tab';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=srp-smart-b2b-pricing&tab=' . $k)) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        switch ($tab) {
            case 'types': self::render_types(); break;
            case 'users': self::render_users(); break;
            case 'rules': self::render_rules(); break;
            case 'registration': self::render_registration(); break;
            default: self::render_dashboard();
        }

        echo '</div>';
    }

    private static function render_dashboard(): void {
        global $wpdb;
        $tables = SRP_DB::tables();
        $types = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['customer_types']}");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['users']} WHERE status='pending'");
        $approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['users']} WHERE status='approved'");
        $rules = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['rules']} WHERE status='active'");

        echo '<p>' . esc_html__('Overview of your B2B pricing setup.', 'srp') . '</p>';
        echo '<ul style="list-style:disc;margin-left:18px;">'
            . '<li>' . esc_html__('Customer Types:', 'srp') . ' <strong>' . esc_html($types) . '</strong></li>'
            . '<li>' . esc_html__('Pending Business Users:', 'srp') . ' <strong>' . esc_html($pending) . '</strong></li>'
            . '<li>' . esc_html__('Approved Business Users:', 'srp') . ' <strong>' . esc_html($approved) . '</strong></li>'
            . '<li>' . esc_html__('Active Pricing Rules:', 'srp') . ' <strong>' . esc_html($rules) . '</strong></li>'
            . '</ul>';
    }

    private static function render_types(): void {
        global $wpdb;
        $t = SRP_DB::tables()['customer_types'];

        if (isset($_POST['srp_action']) && check_admin_referer('srp_types_action')) {
            $action = sanitize_key($_POST['srp_action']);
            if ($action === 'add') {
                $name = sanitize_text_field($_POST['name'] ?? '');
                if ($name) {
                    // Max order Find for New Sortning
                    $max_order = (int) $wpdb->get_var("SELECT MAX(menu_order) FROM $t") + 1;
                    $wpdb->insert($t, ['name' => $name, 'status' => 'active', 'menu_order' => $max_order]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Type added.', 'srp') . '</p></div>';
                }
            }
            if ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                $name = sanitize_text_field($_POST['name'] ?? '');
                $status = sanitize_key($_POST['status'] ?? 'active');
                if ($id && $name) {
                    $wpdb->update($t, ['name' => $name, 'status' => in_array($status, ['active','disabled'], true) ? $status : 'active'], ['id' => $id]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Type updated.', 'srp') . '</p></div>';
                }
            }
            if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                $users_tbl = SRP_DB::tables()['users'];
                $cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(u.user_id) FROM $users_tbl u INNER JOIN {$wpdb->users} wu ON u.user_id = wu.ID WHERE u.type_id=%d", $id));
                
                if ($cnt > 0) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Cannot delete: users are assigned to this type. Reassign them first.', 'srp') . '</p></div>';
                } else {
                    $wpdb->delete($t, ['id' => $id]);
                    $wpdb->query($wpdb->prepare("DELETE FROM $users_tbl WHERE type_id=%d", $id));
                    echo '<div class="notice notice-success"><p>' . esc_html__('Type deleted.', 'srp') . '</p></div>';
                }
            }
        }
        }

        // Ordered by menu_order
        $rows = (array) $wpdb->get_results("SELECT * FROM $t ORDER BY menu_order ASC, name ASC", ARRAY_A);

        echo '<h3>' . esc_html__('Add Customer Type', 'srp') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field('srp_types_action');
        echo '<input type="hidden" name="srp_action" value="add" />';
        echo '<table class="form-table"><tr><th><label>' . esc_html__('Name', 'srp') . '</label></th><td><input type="text" name="name" class="regular-text" required placeholder="' . esc_attr__('Enter Customer Type & Hit Add Type', 'srp') . '"></td></tr></table>';
        submit_button(__('Add Type', 'srp'));
        echo '</form>';

        echo '<hr><h3>' . esc_html__('Existing Types (Drag to reorder)', 'srp') . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th style="width:30px;"></th><th>' . esc_html__('Name', 'srp') . '</th><th>' . esc_html__('Status', 'srp') . '</th><th>' . esc_html__('Actions', 'srp') . '</th></tr></thead>';
        // srp-sortable-types class added
        echo '<tbody class="srp-sortable-types">';
        foreach ($rows as $r) {
            // data-id added for drag logic
            echo '<tr data-id="' . esc_attr((int)$r['id']) . '">';
            echo '<td style="cursor:move; text-align:center;"><span class="dashicons dashicons-menu" style="color:#aaa;"></span></td>';
            echo '<td>' . esc_html($r['name']) . '</td>';
            echo '<td>' . self::render_status_badge((string) $r['status']) . '</td>';
            echo '<td>';
            echo '<button type="button" class="srp-edit-habib button srp-open-type-modal" '
                . 'data-id="' . esc_attr((int)$r['id']) . '" '
                . 'data-name="' . esc_attr($r['name']) . '" '
                . 'data-status="' . esc_attr($r['status']) . '">' . esc_html__('Edit', 'srp') . '</button>';

            echo '<form method="post" style="display:inline-block;margin-left:10px;">';
            wp_nonce_field('srp_types_action');
            echo '<input type="hidden" name="srp_action" value="delete" />';
            echo '<input type="hidden" name="id" value="' . esc_attr((int)$r['id']) . '" />';
            submit_button(__('Delete', 'srp'), 'srp-delete-habib delete small', 'submit', false, ['onclick' => "return confirm('Delete this type?');"]);
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<div id="srp-type-modal" title="' . esc_attr__('Edit Customer Type', 'srp') . '" style="display:none;">';
        echo '<form method="post" id="srp-type-modal-form">';
        wp_nonce_field('srp_types_action');
        echo '<input type="hidden" name="srp_action" value="update" />';
        echo '<input type="hidden" name="id" id="srp_type_id" value="" />';
        echo '<p><label><strong>' . esc_html__('Name', 'srp') . '</strong></label><br>';
        echo '<input type="text" name="name" id="srp_type_name" class="regular-text" style="width:100%;" required /></p>';
        echo '<p><label><strong>' . esc_html__('Status', 'srp') . '</strong></label><br>';
        echo '<select name="status" id="srp_type_status" style="width:100%;"><option value="active">' . esc_html__('Active', 'srp') . '</option><option value="disabled">' . esc_html__('Disabled', 'srp') . '</option></select></p>';
        submit_button(__('Save', 'srp'), 'primary', 'submit', false);
        echo '</form></div>';
    }

    private static function render_users(): void {
        global $wpdb;
        $users_tbl = SRP_DB::tables()['users'];
        $types = SRP_User::get_customer_types(false);

        if (isset($_POST['srp_user_action']) && check_admin_referer('srp_users_action')) {
            $action = sanitize_key($_POST['srp_user_action']);
            $user_id = (int) ($_POST['user_id'] ?? 0);

            // Business User Edit Logic
            if ($user_id && $action === 'update') {
                $type_id = isset($_POST['type_id']) ? (int) $_POST['type_id'] : null;

                $status = sanitize_key($_POST['status'] ?? 'pending');
                if (!in_array($status, ['pending','approved','rejected'], true)) $status = 'pending';

                $wpdb->update($users_tbl, ['status' => $status, 'type_id' => $type_id], ['user_id' => $user_id]);

                echo '<div class="notice notice-success"><p>' . esc_html__('User updated.', 'srp') . '</p></div>';
            }

            // Business User Delete Logic
            if ($user_id && $action === 'delete') {
                if (current_user_can('delete_users')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    if (wp_delete_user($user_id)) {
                        echo '<div class="notice notice-success"><p>' . esc_html__('User completely deleted.', 'srp') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Failed to delete user.', 'srp') . '</p></div>';
                    }
                }
            }
        }

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $type_filter = isset($_GET['type_id']) ? (int) $_GET['type_id'] : 0;
        $q = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $where = "WHERE u.registered_via='business_form'";
        $params = [];
        if ($status && in_array($status, ['pending','approved','rejected'], true)) {
            $where .= " AND u.status=%s";
            $params[] = $status;
        }
        if ($type_filter) {
            $where .= " AND u.type_id=%d";
            $params[] = $type_filter;
        }
        if ($q) {
            $where .= " AND (wu.user_email LIKE %s OR wu.display_name LIKE %s)";
            $like = '%' . $wpdb->esc_like($q) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT u.*, wu.user_email, wu.display_name FROM $users_tbl u JOIN {$wpdb->users} wu ON wu.ID=u.user_id $where ORDER BY u.created_at DESC LIMIT 200";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        echo '<h3>' . esc_html__('Business Users', 'srp') . '</h3>';

        echo '<form method="get" style="margin:10px 0;">';
        echo '<input type="hidden" name="page" value="srp-smart-b2b-pricing" />';
        echo '<input type="hidden" name="tab" value="users" />';
        echo '<select name="status"><option value="">' . esc_html__('All Statuses', 'srp') . '</option>';
        foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $k=>$label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($status, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        echo '<select name="type_id"><option value="0">' . esc_html__('All Types', 'srp') . '</option>';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr((int)$t['id']) . '" ' . selected($type_filter, (int)$t['id'], false) . '>' . esc_html($t['name']) . '</option>';
        }
        echo '</select> ';
        echo '<input type="search" name="s" value="' . esc_attr($q) . '" placeholder="Search email/name" /> ';
        submit_button(__('Filter', 'srp'), 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Name', 'srp') . '</th>'
            . '<th>' . esc_html__('Email', 'srp') . '</th>'
            . '<th>' . esc_html__('Company', 'srp') . '</th>'
            . '<th>' . esc_html__('Phone', 'srp') . '</th>'
            . '<th>' . esc_html__('Country', 'srp') . '</th>'
            . '<th>' . esc_html__('Type', 'srp') . '</th>'
            . '<th>' . esc_html__('Status', 'srp') . '</th>'
            . '<th>' . esc_html__('VAT', 'srp') . '</th>'
            . '<th>' . esc_html__('Actions', 'srp') . '</th>'
            . '</tr></thead><tbody>';

        foreach ((array)$rows as $r) {
            $uid = (int)$r['user_id'];
            $company = (string) get_user_meta($uid, 'srp_company', true);
            $phone   = (string) get_user_meta($uid, 'srp_phone', true);
            $country = (string) get_user_meta($uid, 'srp_country', true);

            $display_name = (string) ($r['display_name'] ?: ('#' . $uid));
            $email = (string) ($r['user_email'] ?? '');
            $label_for_modal = trim($display_name . ($email ? ' — ' . $email : ''));

            echo '<tr>';
            echo '<td>' . esc_html($display_name) . '</td>';
            echo '<td>' . esc_html($email) . '</td>';
            echo '<td>' . esc_html($company) . '</td>';
            echo '<td>' . esc_html($phone) . '</td>';
            echo '<td>' . esc_html(self::country_label($country)) . '</td>';
            echo '<td>' . esc_html(self::type_name($types, (int)$r['type_id'])) . '</td>';
            echo '<td>' . self::render_status_badge((string) $r['status']) . '</td>';
            echo '<td>' . esc_html($r['vat_number'] ?? '') . '</td>';
            echo '<td>';
            
            // Manage Business User
            echo '<div style="display: flex; align-items: center; gap: 8px;">';

            echo '<button type="button" class="button srp-open-user-modal srp-edit-habib"'
                . ' data-user-id="' . esc_attr($uid) . '"'
                . ' data-type-id="' . esc_attr((int)$r['type_id']) . '"'
                . ' data-status="' . esc_attr($r['status']) . '"'
                . ' data-name="' . esc_attr($label_for_modal) . '"'
                . '>' . esc_html__('Manage', 'srp') . '</button>';

            // Delete Business User
            echo '<form method="post" style="margin: 0; padding: 0; display: flex;">';
            wp_nonce_field('srp_users_action');
            echo '<input type="hidden" name="srp_user_action" value="delete" />';
            echo '<input type="hidden" name="user_id" value="' . esc_attr($uid) . '" />';
            submit_button(__('Delete', 'srp'), 'srp-delete-habib delete button-small', 'submit', false, ['style' => 'margin: 0;', 'onclick' => "return confirm('Are you sure you want to completely delete this user?');"]);
            echo '</form>';

            echo '</div>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div id="srp-user-modal" title="' . esc_attr__('Manage User', 'srp') . '" style="display:none;">';
        echo '<form method="post" id="srp-user-modal-form">';
        wp_nonce_field('srp_users_action');
        echo '<input type="hidden" name="user_id" id="srp_user_id" value="" />';

        echo '<p><label><strong>' . esc_html__('User', 'srp') . '</strong></label><br>';
        echo '<input type="text" id="srp_user_name" style="width:100%;" disabled /></p>';

        echo '<p><label><strong>' . esc_html__('Type', 'srp') . '</strong></label><br>';
        echo '<select name="type_id" id="srp_user_type" style="width:100%;">';
        echo '<option value="0">—</option>';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr((int)$t['id']) . '">' . esc_html($t['name']) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label><strong>' . esc_html__('Status', 'srp') . '</strong></label><br>';
        echo '<select name="status" id="srp_user_status" style="width:100%;">';
        foreach (['pending','approved','rejected'] as $st) {
            echo '<option value="' . esc_attr($st) . '">' . esc_html(ucfirst($st)) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="srp-modal-actions">'
            . '<button class="button button-primary" name="srp_user_action" value="update">' . esc_html__('Save', 'srp') . '</button>'
            . '</p>';

        echo '</form></div>';
    }

    private static function type_name(array $types, int $id): string {
        foreach ($types as $t) {
            if ((int)$t['id'] === $id) return (string)$t['name'];
        }
        return '';
    }

    private static function render_rules(): void {
        global $wpdb;
        $rules_tbl = SRP_DB::tables()['rules'];
        $types = SRP_User::get_customer_types(false);

        if (isset($_POST['srp_rule_action']) && check_admin_referer('srp_rules_action')) {
            $action = sanitize_key($_POST['srp_rule_action']);
            if ($action === 'add') {
                $scope = sanitize_key($_POST['scope'] ?? 'global');
                $rule_type = sanitize_key($_POST['rule_type'] ?? 'percent');
                $value = (float) ($_POST['value'] ?? 0);
                $type_id = (int) ($_POST['type_id'] ?? 0);
                $user_id = (int) ($_POST['user_id'] ?? 0);
                $object_id = (int) ($_POST['object_id'] ?? 0);
                if ($object_id <= 0) {
                    $object_id = (int) ($_POST['category_id'] ?? $_POST['brand_id'] ?? $_POST['product_id'] ?? 0);
                }

                $allowed_scopes = ['global','category','brand','product','user'];
                $allowed_types = ['percent','fixed'];
                if (!in_array($scope, $allowed_scopes, true)) $scope = 'global';
                if (!in_array($rule_type, $allowed_types, true)) $rule_type = 'percent';

                if ($scope === 'global') $object_id = 0;

                if ($scope === 'user') {
                    $type_id = $user_id;
                }

                if ($type_id > 0 && $value >= 0) {
                    // Find Max Order
                    $max_order = (int) $wpdb->get_var("SELECT MAX(menu_order) FROM $rules_tbl") + 1;
                    $wpdb->insert($rules_tbl, [
                        'type_id' => $type_id,
                        'scope' => $scope,
                        'object_id' => $object_id,
                        'rule_type' => $rule_type,
                        'value' => $value,
                        'status' => 'active',
                        'menu_order' => $max_order
                    ]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Rule added.', 'srp') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Please select a Type and enter a valid value.', 'srp') . '</p></div>';
                }
            }

            if ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                $scope = sanitize_key($_POST['scope'] ?? 'global');
                $rule_type = sanitize_key($_POST['rule_type'] ?? 'percent');
                $value = (float) ($_POST['value'] ?? 0);
                $type_id = (int) ($_POST['type_id'] ?? 0);
                $user_id = (int) ($_POST['user_id'] ?? 0);
                $object_id = (int) ($_POST['object_id'] ?? 0);
                if ($object_id <= 0) {
                    $object_id = (int) ($_POST['category_id'] ?? $_POST['brand_id'] ?? $_POST['product_id'] ?? 0);
                }

                $allowed_scopes = ['global','category','brand','product','user'];
                $allowed_types = ['percent','fixed'];
                if (!in_array($scope, $allowed_scopes, true)) $scope = 'global';
                if (!in_array($rule_type, $allowed_types, true)) $rule_type = 'percent';
                if ($scope === 'global') $object_id = 0;
                if ($scope === 'user') $type_id = $user_id;

                if ($id && $type_id > 0 && $value >= 0) {
                    $wpdb->update($rules_tbl, [
                        'type_id' => $type_id,
                        'scope' => $scope,
                        'object_id' => $object_id,
                        'rule_type' => $rule_type,
                        'value' => $value,
                        'status' => 'active'
                    ], ['id' => $id]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Rule updated.', 'srp') . '</p></div>';
                }
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id) {
                    $wpdb->delete($rules_tbl, ['id' => $id]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Rule deleted.', 'srp') . '</p></div>';
                }
            }
        }

        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $brands = get_terms(['taxonomy' => 'srp_brand', 'hide_empty' => false]);

        echo '<p>' . esc_html__('Rules are evaluated from Regular Price only (sale ignored). Lowest final price always wins.', 'srp') . '</p>';

        echo '<h3>' . esc_html__('Add Rule', 'srp') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field('srp_rules_action');
        echo '<input type="hidden" name="srp_rule_action" value="add" />';

        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Scope', 'srp') . '</th><td><select name="scope" id="srp_rule_scope">'
            . '<option value="global">Global (Type)</option>'
            . '<option value="category">Category</option>'
            . '<option value="brand">Brand</option>'
            . '<option value="product">Product (Type override)</option>'
            . '<option value="user">User (Individual override)</option>'
            . '</select></td></tr>';

        echo '<tr class="srp-owner srp-owner-type"><th>' . esc_html__('Customer Type', 'srp') . '</th><td><select name="type_id" id="srp_rule_type_id" required style="width:320px;"><option value="">—</option>';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr((int)$t['id']) . '">' . esc_html($t['name']) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr class="srp-owner srp-owner-user" style="display:none;"><th>' . esc_html__('User', 'srp') . '</th><td>'
            . '<input type="text" id="srp_rule_user_search" class="srp-ac-user" style="width:320px;" placeholder="' . esc_attr__('Search user by name or email…', 'srp') . '" autocomplete="off" />'
            . '<input type="hidden" name="user_id" id="srp_rule_user_id" value="0" />'
            . '</td></tr>';

        echo '<tr class="srp-target srp-target-category" style="display:none;"><th>' . esc_html__('Category', 'srp') . '</th><td><select name="category_id" id="srp_rule_category_id" style="width:320px;">';
        echo '<option value="">' . esc_html__('Select category…', 'srp') . '</option>';
        foreach ((array)$categories as $c) {
            echo '<option value="' . esc_attr((int)$c->term_id) . '">' . esc_html($c->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr class="srp-target srp-target-brand" style="display:none;"><th>' . esc_html__('Brand', 'srp') . '</th><td><select name="brand_id" id="srp_rule_brand_id" style="width:320px;">';
        echo '<option value="">' . esc_html__('Select brand…', 'srp') . '</option>';
        foreach ((array)$brands as $b) {
            echo '<option value="' . esc_attr((int)$b->term_id) . '">' . esc_html($b->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr class="srp-target srp-target-product" style="display:none;"><th>' . esc_html__('Product', 'srp') . '</th><td>'
            . '<input type="text" id="srp_rule_product_search" class="srp-ac-product" style="width:320px;" placeholder="' . esc_attr__('Search product…', 'srp') . '" autocomplete="off" />'
            . '<input type="hidden" name="product_id" id="srp_rule_product_id" value="0" />'
            . '</td></tr>';

        echo '<input type="hidden" name="object_id" id="srp_rule_object_id" value="0" />';

        echo '<tr><th>' . esc_html__('Rule Type', 'srp') . '</th><td><select name="rule_type"><option value="percent">Percent %</option><option value="fixed">Fixed Price</option></select></td></tr>';
        echo '<tr><th>' . esc_html__('Value', 'srp') . '</th><td><input type="number" name="value" min="0" step="0.01" required placeholder="' . esc_attr__('e.g. 40 or 59.99', 'srp') . '"></td></tr>';
        echo '</table>';

        submit_button(__('Add Rule', 'srp'));
        echo '</form>';

        // Ordered by menu_order
        $rows = (array) $wpdb->get_results("SELECT * FROM $rules_tbl ORDER BY menu_order ASC, id DESC LIMIT 200", ARRAY_A);
        echo '<hr><h3>' . esc_html__('Existing Rules (Drag to reorder)', 'srp') . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th style="width:30px;"></th><th>ID</th><th>Type/User</th><th>Scope</th><th>Target</th><th>Rule</th><th>Status</th><th>Action</th></tr></thead>';
        // srp-sortable-rules class added
        echo '<tbody class="srp-sortable-rules">';
        foreach ($rows as $r) {
            $scope = (string) $r['scope'];
            $type_or_user_label = '';
            $target_label = '';
            $data_user_name = '';
            $data_product_name = '';

            if ($scope === 'user') {
                $user_id = (int) $r['type_id'];
                $product_id = (int) $r['object_id'];
                $type_or_user_label = self::get_user_label($user_id);
                $target_label = self::get_product_label($product_id);
                $data_user_name = $type_or_user_label;
                $data_product_name = $target_label;
            } else {
                $type_or_user_label = self::get_type_name((int)$r['type_id']);
                if ($scope === 'global') {
                    $target_label = '—';
                } elseif ($scope === 'category') {
                    $target_label = self::get_term_label('product_cat', (int)$r['object_id']);
                } elseif ($scope === 'brand') {
                    $target_label = self::get_term_label('srp_brand', (int)$r['object_id']);
                } elseif ($scope === 'product') {
                    $target_label = self::get_product_label((int)$r['object_id']);
                    $data_product_name = $target_label;
                }
            }

            // data-id added for drag logic
            echo '<tr data-id="' . esc_attr((int)$r['id']) . '">';
            echo '<td style="cursor:move; text-align:center;"><span class="dashicons dashicons-menu" style="color:#aaa;"></span></td>';
            echo '<td>' . esc_html($r['id']) . '</td>';
            echo '<td>' . esc_html($type_or_user_label) . '</td>';
            echo '<td>' . esc_html($scope) . '</td>';
            echo '<td>' . esc_html($target_label) . '</td>';
            echo '<td>' . esc_html($r['rule_type']) . ': ' . esc_html($r['value']) . '</td>';
            echo '<td>' . self::render_status_badge((string) $r['status']) . '</td>';
            echo '<td>';
            echo '<button type="button" class="button srp-open-rule-modal srp-edit-habib"'
                . ' data-id="' . esc_attr((int)$r['id']) . '"'
                . ' data-type-id="' . esc_attr((int)$r['type_id']) . '"'
                . ' data-scope="' . esc_attr($scope) . '"'
                . ' data-object-id="' . esc_attr((int)$r['object_id']) . '"'
                . ' data-rule-type="' . esc_attr($r['rule_type']) . '"'
                . ' data-value="' . esc_attr($r['value']) . '"'
                . ' data-user-name="' . esc_attr($data_user_name) . '"'
                . ' data-product-name="' . esc_attr($data_product_name) . '"'
                . '>' . esc_html__('Edit', 'srp') . '</button> ';

            echo '<form method="post" style="display:inline-block;margin-left:6px;">';
            wp_nonce_field('srp_rules_action');
            echo '<input type="hidden" name="srp_rule_action" value="delete" />';
            echo '<input type="hidden" name="id" value="' . esc_attr((int)$r['id']) . '" />';
            submit_button(__('Delete', 'srp'), 'srp-delete-habib delete small', 'submit', false, ['onclick' => "return confirm('Delete this rule?');"]);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<div id="srp-rule-modal" title="' . esc_attr__('Edit Rule', 'srp') . '" style="display:none;">';
        echo '<form method="post" id="srp-rule-modal-form">';
        wp_nonce_field('srp_rules_action');
        echo '<input type="hidden" name="srp_rule_action" value="update" />';
        echo '<input type="hidden" name="id" id="srp_rule_id" value="" />';
        echo '<input type="hidden" name="object_id" id="srp_rule_object_id_edit" value="0" />';

        echo '<p><label><strong>' . esc_html__('Scope', 'srp') . '</strong></label><br>';
        echo '<select name="scope" id="srp_rule_scope_edit" style="width:100%;">'
            . '<option value="global">Global (Type)</option>'
            . '<option value="category">Category</option>'
            . '<option value="brand">Brand</option>'
            . '<option value="product">Product (Type override)</option>'
            . '<option value="user">User (Individual override)</option>'
            . '</select></p>';

        echo '<p class="srp-edit-owner srp-edit-owner-type"><label><strong>' . esc_html__('Customer Type', 'srp') . '</strong></label><br>';
        echo '<select name="type_id" id="srp_rule_type_id_edit" style="width:100%;">';
        echo '<option value="">—</option>';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr((int)$t['id']) . '">' . esc_html($t['name']) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="srp-edit-owner srp-edit-owner-user" style="display:none;"><label><strong>' . esc_html__('User', 'srp') . '</strong></label><br>'
            . '<input type="text" id="srp_rule_user_search_edit" class="srp-ac-user" style="width:100%;" placeholder="' . esc_attr__('Search user by name or email…', 'srp') . '" autocomplete="off" />'
            . '<input type="hidden" name="user_id" id="srp_rule_user_id_edit" value="0" />'
            . '</p>';

        echo '<p class="srp-edit-target srp-edit-target-category" style="display:none;"><label><strong>' . esc_html__('Category', 'srp') . '</strong></label><br>';
        echo '<select id="srp_rule_category_id_edit" style="width:100%;">';
        echo '<option value="">' . esc_html__('Select category…', 'srp') . '</option>';
        foreach ((array)$categories as $c) {
            echo '<option value="' . esc_attr((int)$c->term_id) . '">' . esc_html($c->name) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="srp-edit-target srp-edit-target-brand" style="display:none;"><label><strong>' . esc_html__('Brand', 'srp') . '</strong></label><br>';
        echo '<select id="srp_rule_brand_id_edit" style="width:100%;">';
        echo '<option value="">' . esc_html__('Select brand…', 'srp') . '</option>';
        foreach ((array)$brands as $b) {
            echo '<option value="' . esc_attr((int)$b->term_id) . '">' . esc_html($b->name) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="srp-edit-target srp-edit-target-product" style="display:none;"><label><strong>' . esc_html__('Product', 'srp') . '</strong></label><br>'
            . '<input type="text" id="srp_rule_product_search_edit" class="srp-ac-product" style="width:100%;" placeholder="' . esc_attr__('Search product…', 'srp') . '" autocomplete="off" />'
            . '<input type="hidden" name="product_id" id="srp_rule_product_id_edit" value="0" />'
            . '</p>';

        echo '<p><label><strong>' . esc_html__('Rule Type', 'srp') . '</strong></label><br>';
        echo '<select name="rule_type" id="srp_rule_type_edit" style="width:100%;"><option value="percent">Percent %</option><option value="fixed">Fixed Price</option></select></p>';
        echo '<p><label><strong>' . esc_html__('Value', 'srp') . '</strong></label><br>';
        echo '<input type="number" name="value" id="srp_rule_value_edit" min="0" step="0.01" style="width:100%;" /></p>';

        echo '<p class="srp-modal-actions"><button class="button button-primary" type="submit">' . esc_html__('Save', 'srp') . '</button></p>';
        echo '</form></div>';
    }

    private static function render_registration(): void {
        if (isset($_POST['srp_reg_action']) && check_admin_referer('srp_reg_action')) {
            $replace = isset($_POST['pending_replace_add_to_cart']) ? 'yes' : 'no';
            $msg = sanitize_text_field($_POST['pending_add_to_cart_message'] ?? '');
            $banner = sanitize_text_field($_POST['pending_banner_message'] ?? '');
            update_option('srp_pending_replace_add_to_cart', $replace);
            update_option('srp_pending_add_to_cart_message', $msg);
            update_option('srp_pending_banner_message', $banner);
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'srp') . '</p></div>';
        }

        $replace = get_option('srp_pending_replace_add_to_cart', 'no') === 'yes';
        $msg = (string) get_option('srp_pending_add_to_cart_message', __('Waiting for approval to see wholesale prices', 'srp'));
        $banner = (string) get_option('srp_pending_banner_message', __('Your business account is pending approval. You will see wholesale prices after approval.', 'srp'));

        echo '<h3>' . esc_html__('Registration & Approval', 'srp') . '</h3>';
        echo '<p>' . esc_html__('Use shortcode:', 'srp') . ' <code>[srp_business_register]</code></p>';

        echo '<form method="post" class="srp-settings-form">';
        wp_nonce_field('srp_reg_action');
        echo '<input type="hidden" name="srp_reg_action" value="save" />';

        echo '<table class="form-table">';

        echo '<tr><th>' . esc_html__('Pending approval replaces Add to Cart', 'srp') . '</th><td>';
        echo '<label><input type="checkbox" name="pending_replace_add_to_cart" value="1" ' . checked($replace, true, false) . ' /> ' . esc_html__('Enable', 'srp') . '</label>';
        echo '<p class="description">' . esc_html__('If enabled, pending business users cannot add to cart and will see your custom message instead of the Add to Cart button.', 'srp') . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Pending button message', 'srp') . '</th><td>';
        echo '<input type="text" name="pending_add_to_cart_message" value="' . esc_attr($msg) . '" class="regular-text" placeholder="Waiting for approval…" />';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Pending banner message', 'srp') . '</th><td>';
        echo '<input type="text" name="pending_banner_message" value="' . esc_attr($banner) . '" class="regular-text" placeholder="Your account is pending approval…" />';
        echo '</td></tr>';

        echo '</table>';
        submit_button(__('Save', 'srp'));
        echo '</form>';
    }
}