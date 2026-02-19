<?php
if (!defined('ABSPATH')) { exit; }

class SRP_Shortcodes {
    public static function init(): void {
        add_shortcode('srp_business_register', [__CLASS__, 'render_business_register']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void {
        if (!is_singular()) return;
        global $post;
        if (!$post || !has_shortcode((string) $post->post_content, 'srp_business_register')) return;
        wp_enqueue_style('srp-public', SRP_PLUGIN_URL . 'assets/public.css', [], SRP_VERSION);
    }

    private static function get_wc_countries(): array {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            return (array) WC()->countries->get_countries();
        }
        return [];
    }

    public static function render_business_register($atts = []): string {
        if (is_user_logged_in()) {
            return '<p>' . esc_html__('You are already logged in.', 'srp') . '</p>';
        }

        $types = SRP_User::get_customer_types(true);
        if (empty($types)) {
            return '<p>' . esc_html__('No customer types are configured yet. Please contact the site administrator.', 'srp') . '</p>';
        }

        $countries = self::get_wc_countries();

        $errors = [];
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['srp_business_register_nonce'])
            && wp_verify_nonce($_POST['srp_business_register_nonce'], 'srp_business_register')
        ) {
            $first   = sanitize_text_field($_POST['first_name'] ?? '');
            $last    = sanitize_text_field($_POST['last_name'] ?? '');
            $email   = sanitize_email($_POST['email'] ?? '');
            $company = sanitize_text_field($_POST['company'] ?? '');
            $address = sanitize_textarea_field($_POST['address'] ?? '');
            $phone   = sanitize_text_field($_POST['contact_phone'] ?? '');
            $country = sanitize_text_field($_POST['country'] ?? '');

            $vat     = sanitize_text_field($_POST['vat_number'] ?? '');
            $type_id = (int) ($_POST['type_id'] ?? 0);
            $pass    = (string) ($_POST['password'] ?? '');
            $pass2   = (string) ($_POST['password2'] ?? '');

            if ($first === '') $errors[] = __('First name is required.', 'srp');
            if ($last === '') $errors[] = __('Last name is required.', 'srp');
            if (!is_email($email)) $errors[] = __('Valid email is required.', 'srp');

            if ($company === '') $errors[] = __('Company is required.', 'srp');
            if ($address === '') $errors[] = __('Address is required.', 'srp');
            if ($phone === '') $errors[] = __('Contact phone is required.', 'srp');

            if ($country === '') {
                $errors[] = __('Country is required.', 'srp');
            } else {
                if (!empty($countries) && !array_key_exists($country, $countries)) {
                    $errors[] = __('Please select a valid country.', 'srp');
                }
            }

            if ($type_id <= 0) $errors[] = __('Please select a customer type.', 'srp');
            if (strlen($pass) < 6) $errors[] = __('Password must be at least 6 characters.', 'srp');
            if ($pass !== $pass2) $errors[] = __('Passwords do not match.', 'srp');
            if (email_exists($email)) $errors[] = __('This email is already registered.', 'srp');

            $hp = sanitize_text_field($_POST['srp_hp'] ?? '');
            if ($hp !== '') $errors[] = __('Registration failed. Please try again.', 'srp');

            if (empty($errors)) {
                $username = sanitize_user(current(explode('@', $email)), true);
                if (username_exists($username)) {
                    $username .= '_' . wp_generate_password(4, false, false);
                }

                $user_id = wp_create_user($username, $pass, $email);
                if (is_wp_error($user_id)) {
                    $errors[] = $user_id->get_error_message();
                } else {
                    wp_update_user([
                        'ID' => $user_id,
                        'first_name' => $first,
                        'last_name' => $last,
                        'display_name' => trim($first . ' ' . $last),
                    ]);

                    SRP_User::upsert_user_record($user_id, $type_id, 'pending', $vat, 'business_form');

                    update_user_meta($user_id, 'srp_company', $company);
                    update_user_meta($user_id, 'srp_address', $address);
                    update_user_meta($user_id, 'srp_phone', $phone);
                    update_user_meta($user_id, 'srp_country', $country);

                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);

                    $success = true;
                }
            }
        }

        ob_start();

        if ($success) {
            echo '<div class="woocommerce-message">' . esc_html__('Registration complete. Your business account is pending approval. You can browse retail prices now; wholesale prices will appear after approval.', 'srp') . '</div>';
        }

        if (!empty($errors)) {
            echo '<div class="woocommerce-error" role="alert"><ul>';
            foreach ($errors as $e) {
                echo '<li>' . esc_html($e) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="srp-register-wrap">';
        echo '<form method="post" class="srp-register-form">';
        wp_nonce_field('srp_business_register', 'srp_business_register_nonce');

        echo '<p style="display:none;">'
            . '<label>Leave this field empty</label>'
            . '<input type="text" name="srp_hp" value="" autocomplete="off" />'
            . '</p>';

        echo '<div class="srp-grid">';

        echo '<p>'
            . '<label>' . esc_html__('First Name', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="text" name="first_name" autocomplete="given-name" required placeholder="' . esc_attr__('First name', 'srp') . '" />'
            . '</p>';

        echo '<p>'
            . '<label>' . esc_html__('Last Name', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="text" name="last_name" autocomplete="family-name" required placeholder="' . esc_attr__('Last name', 'srp') . '" />'
            . '</p>';

        echo '<p class="srp-full">'
            . '<label>' . esc_html__('Email Address', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="email" name="email" autocomplete="email" required placeholder="hello@company.com" />'
            . '</p>';

        echo '<p class="srp-full">'
            . '<label>' . esc_html__('Company', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="text" name="company" required placeholder="' . esc_attr__('Company name', 'srp') . '" />'
            . '</p>';

        echo '<p class="srp-full">'
            . '<label>' . esc_html__('Address', 'srp') . ' <span class="required">*</span></label>'
            . '<textarea name="address" rows="2" required placeholder="' . esc_attr__('Street, City, State, Zip Code', 'srp') . '"></textarea>'
            . '</p>';

        echo '<p>'
            . '<label>' . esc_html__('Contact Phone', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="text" name="contact_phone" required placeholder="' . esc_attr__('Phone number', 'srp') . '" />'
            . '</p>';

        echo '<p>'
            . '<label>' . esc_html__('Country', 'srp') . ' <span class="required">*</span></label>';
        echo '<select name="country" required>';
        echo '<option value="">' . esc_html__('Select…', 'srp') . '</option>';
        foreach ((array)$countries as $code => $name) {
            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p class="srp-full">'
            . '<label>' . esc_html__('Customer Type', 'srp') . ' <span class="required">*</span></label>'
            . '<select name="type_id" required><option value="">' . esc_html__('Select…', 'srp') . '</option>';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr((int)$t['id']) . '">' . esc_html($t['name']) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="srp-full">'
            . '<label>' . esc_html__('Tax/VAT number', 'srp') . '</label>'
            . '<input type="text" name="vat_number" placeholder="' . esc_attr__('Enter Your Tax/Vat Number', 'srp') . '" />'
            . '</p>';

        echo '<p>'
            . '<label>' . esc_html__('Password', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="password" name="password" autocomplete="new-password" required placeholder="••••••" />'
            . '</p>';
        echo '<p>'
            . '<label>' . esc_html__('Confirm Password', 'srp') . ' <span class="required">*</span></label>'
            . '<input type="password" name="password2" autocomplete="new-password" required placeholder="••••••" />'
            . '</p>';

        echo '</div>';

        echo '<p class="srp-actions">'
            . '<button type="submit" class="button" name="register" value="Register">'
            . esc_html__('Register', 'srp')
            . '</button>'
            . '</p>';

        echo '</form>';
        echo '</div>';

        return ob_get_clean();
    }
}
