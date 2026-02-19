<?php
if (!defined('ABSPATH')) { exit; }

class SRP_User {
    public static function init(): void {
        // Automatically delete B2B data when a user is deleted from WordPress
        add_action('deleted_user', [__CLASS__, 'on_user_deleted']);
    }

    public static function on_user_deleted(int $user_id): void {
        global $wpdb;
        $t = self::tables()['users'];
        $wpdb->delete($t, ['user_id' => $user_id]);
    }

    public static function tables() {
        return SRP_DB::tables();
    }

    public static function get_customer_types(bool $only_active = true): array {
        global $wpdb;
        $t = self::tables()['customer_types'];
        $sql = "SELECT id, name, status FROM $t";
        if ($only_active) {
            $sql .= " WHERE status='active'";
        }
        $sql .= " ORDER BY name ASC";
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public static function get_user_record(int $user_id): ?array {
        global $wpdb;
        $t = self::tables()['users'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d", $user_id), ARRAY_A);
        return $row ?: null;
    }

    public static function upsert_user_record(int $user_id, ?int $type_id, string $status, ?string $vat, string $registered_via = 'business_form'): void {
        global $wpdb;
        $t = self::tables()['users'];
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d", $user_id));
        $data = [
            'user_id' => $user_id,
            'type_id' => $type_id,
            'status'  => $status,
            'vat_number' => $vat,
            'registered_via' => $registered_via,
        ];
        if ($exists) {
            unset($data['user_id']);
            $wpdb->update($t, $data, ['user_id' => $user_id]);
        } else {
            $wpdb->insert($t, $data);
        }
    }

    public static function is_business_user_pending(int $user_id): bool {
        $row = self::get_user_record($user_id);
        return $row && $row['registered_via'] === 'business_form' && $row['status'] === 'pending';
    }

    public static function is_business_user_approved(int $user_id): bool {
        $row = self::get_user_record($user_id);
        return $row && $row['registered_via'] === 'business_form' && $row['status'] === 'approved';
    }

    public static function get_user_type_id(int $user_id): ?int {
        $row = self::get_user_record($user_id);
        if (!$row) return null;
        return $row['type_id'] ? (int) $row['type_id'] : null;
    }
}
