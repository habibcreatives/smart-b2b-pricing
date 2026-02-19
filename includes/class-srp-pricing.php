<?php
if (!defined('ABSPATH')) { exit; }

class SRP_Pricing {
    public static function init(): void {
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_prices'], 9999);

        add_filter('woocommerce_product_get_sale_price', [__CLASS__, 'filter_sale_price'], 9999, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [__CLASS__, 'filter_sale_price'], 9999, 2);

        add_filter('woocommerce_product_get_price', [__CLASS__, 'filter_price'], 9999, 2);
        add_filter('woocommerce_product_variation_get_price', [__CLASS__, 'filter_price'], 9999, 2);

        add_filter('woocommerce_get_price_html', [__CLASS__, 'filter_price_html'], 9999, 2);

        add_action('wp_footer', [__CLASS__, 'maybe_render_pending_banner']);

        add_filter('woocommerce_is_purchasable', [__CLASS__, 'pending_is_purchasable'], 9999, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'pending_loop_add_to_cart_link'], 9999, 2);
        add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'pending_add_to_cart_text'], 9999, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [__CLASS__, 'pending_add_to_cart_text'], 9999, 2);
    }

    private static function current_user_id(): int {
        return get_current_user_id() ?: 0;
    }

    private static function should_apply_b2b_pricing(int $user_id): bool {
        return $user_id > 0 && SRP_User::is_business_user_approved($user_id);
    }

    private static function is_pending(int $user_id): bool {
        return $user_id > 0 && SRP_User::is_business_user_pending($user_id);
    }

    public static function maybe_render_pending_banner(): void {
        $uid = self::current_user_id();
        if (!self::is_pending($uid)) return;
        $banner = (string) get_option('srp_pending_banner_message', __('Your business account is pending approval. You will see wholesale prices after approval.', 'srp'));
        echo '<div style="position:fixed;bottom:0;left:0;right:0;z-index:999999;padding:12px 16px;background:#111;color:#fff;text-align:center;font-size:14px;">'
            . esc_html($banner)
            . '</div>';
    }

    private static function pending_replace_enabled(): bool {
        return get_option('srp_pending_replace_add_to_cart', 'no') === 'yes';
    }

    private static function pending_button_message(): string {
        $msg = (string) get_option('srp_pending_add_to_cart_message', __('Pending approval', 'srp'));
        return trim($msg) !== '' ? $msg : __('Pending approval', 'srp');
    }

    public static function pending_is_purchasable($purchasable, $product) {
        $uid = self::current_user_id();
        if (!self::pending_replace_enabled()) return $purchasable;
        if (!self::is_pending($uid)) return $purchasable;
        return false;
    }

    public static function pending_add_to_cart_text($text, $product) {
        $uid = self::current_user_id();
        if (!self::pending_replace_enabled()) return $text;
        if (!self::is_pending($uid)) return $text;
        return self::pending_button_message();
    }

    public static function pending_loop_add_to_cart_link($html, $product) {
        $uid = self::current_user_id();
        if (!self::pending_replace_enabled()) return $html;
        if (!self::is_pending($uid)) return $html;
        $msg = esc_html(self::pending_button_message());
        return '<span class="button disabled" style="opacity:.65;cursor:not-allowed;">' . $msg . '</span>';
    }

    public static function filter_sale_price($sale_price, $product) {
        $uid = self::current_user_id();
        if (!self::should_apply_b2b_pricing($uid)) {
            return $sale_price;
        }
        return '';
    }

    public static function filter_price($price, $product) {
        $uid = self::current_user_id();
        if (!self::should_apply_b2b_pricing($uid)) {
            return $price;
        }

        $regular = $product->get_regular_price();
        if ($regular === '' || $regular === null) {
            return $price;
        }
        $regular = (float) $regular;
        $computed = self::compute_lowest_price($uid, $product, $regular);
        return $computed !== null ? $computed : $price;
    }

    public static function filter_price_html($price_html, $product) {
        $uid = self::current_user_id();
        if (!self::should_apply_b2b_pricing($uid)) {
            return $price_html;
        }
        $regular = $product->get_regular_price();
        if ($regular === '' || $regular === null) {
            return $price_html;
        }
        $regular_f = (float) $regular;
        $computed = self::compute_lowest_price($uid, $product, $regular_f);
        if ($computed === null) return $price_html;

        $regular_html = wc_price($regular_f);
        $computed_html = wc_price($computed);
        return '<del>' . $regular_html . '</del> <ins>' . $computed_html . '</ins>';
    }

    public static function apply_cart_prices($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) return;
        $uid = self::current_user_id();
        if (!self::should_apply_b2b_pricing($uid)) return;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['data']) || !is_object($cart_item['data'])) continue;
            $product = $cart_item['data'];
            $regular = $product->get_regular_price();
            if ($regular === '' || $regular === null) continue;
            $regular_f = (float) $regular;
            $computed = self::compute_lowest_price($uid, $product, $regular_f);
            if ($computed !== null) {
                $product->set_price($computed);
            }
        }
    }

    private static function compute_lowest_price(int $user_id, $product, float $regular_price): ?float {
        $product_id = (int) $product->get_id();
        $type_id = SRP_User::get_user_type_id($user_id);
        if (!$type_id) {
            return null;
        }

        $candidates = [];

        $candidates = array_merge($candidates, self::prices_from_rules($type_id, 'global', 0, $regular_price));

        $cat_ids = wc_get_product_term_ids($product_id, 'product_cat');
        foreach ($cat_ids as $cat_id) {
            $candidates = array_merge($candidates, self::prices_from_rules($type_id, 'category', (int)$cat_id, $regular_price));
        }

        $brand_ids = wp_get_post_terms($product_id, 'srp_brand', ['fields' => 'ids']);
        if (is_array($brand_ids)) {
            foreach ($brand_ids as $bid) {
                $candidates = array_merge($candidates, self::prices_from_rules($type_id, 'brand', (int)$bid, $regular_price));
            }
        }

        $candidates = array_merge($candidates, self::prices_from_rules($type_id, 'product', $product_id, $regular_price));

        $candidates = array_merge($candidates, self::prices_from_rules($user_id, 'user', $product_id, $regular_price));

        if (empty($candidates)) return null;

        $valid = [];
        foreach ($candidates as $p) {
            $p = (float) $p;
            if ($p < 0) continue;
            if ($p > $regular_price) continue;
            $valid[] = $p;
        }
        if (empty($valid)) return null;
        $lowest = min($valid);
        return round($lowest, wc_get_price_decimals());
    }

    private static function prices_from_rules(int $type_id, string $scope, int $object_id, float $regular_price): array {
        global $wpdb;
        $t = SRP_DB::tables()['rules'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rule_type, value FROM $t WHERE status='active' AND type_id=%d AND scope=%s AND object_id=%d",
                $type_id,
                $scope,
                $object_id
            ),
            ARRAY_A
        );

        $out = [];
        foreach ((array)$rows as $r) {
            $rule_type = $r['rule_type'];
            $value = (float) $r['value'];
            if ($rule_type === 'percent') {
                $out[] = $regular_price * (1.0 - ($value / 100.0));
            } elseif ($rule_type === 'fixed') {
                $out[] = $value;
            }
        }
        return $out;
    }
}
