<?php

/*
 * Plugin Name:       Delete Users Without WooCommerce Orders
 * Plugin URI:        https://wordpress.org/plugins/delete-users-without-woocommerce-orders/
 * Description:       Simple and free plugin that allows you to delete WooCommerce users with no orders.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Michał Kowalik
 * Author URI:        https://michalkowalik.pl
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       delete-users-without-woocommerce-orders
 */

if (! defined('ABSPATH')) {
    exit;
}

new DeleteUsersWithoutWooCommerceOrders();

class DeleteUsersWithoutWooCommerceOrders
{
    private bool $deactivatedDueToWooCommerce = false;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'addCleanupCustomersPage']);
        add_action('admin_enqueue_scripts', [$this, 'loadStyle']);
        add_filter('plugin_action_links', [$this, 'addPluginActionLinks'], 10, 2);
        add_action('deactivated_plugin', [$this, 'maybeDeactivateSelfWhenWooCommerceDeactivated'], 10, 2);
        add_action('admin_init', [$this, 'maybeDeactivateSelfIfWooCommerceMissing']);

        if (! $this->isWooCommerceActive()) {
            add_action('admin_notices', [$this, 'renderWooCommerceMissingNotice']);

            return;
        }
    }

    public function addCleanupCustomersPage(): void
    {
        add_users_page(
            esc_html__('Cleanup customers', 'duwwo'),
            esc_html__('Cleanup customers', 'duwwo'),
            'manage_options',
            'cleanup-customers',
            [$this, 'cleanupCustomersPage']
        );
    }

    public function cleanupCustomersPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied', 'duwwo'));
        }

        if (! $this->isWooCommerceActive()) {
            wp_die(esc_html__('WooCommerce is not active.', 'duwwo'));
        }

        if (isset($_POST['duwwo_delete_batch'])) {
            check_admin_referer('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

            if (! current_user_can('delete_users')) {
                wp_die(esc_html__('You do not have permission to delete users.', 'duwwo'));
            }

            $customerQuery = new WP_User_Query([
                'role' => 'customer',
                'fields' => ['ID', 'user_login', 'user_email']
            ]);

            $customers = $customerQuery->get_results();
            $deletedCount = 0;

            foreach ($customers as $user) {
                $orders = wc_get_orders([
                    'customer_id' => $user->ID,
                    'limit' => 1
                ]);

                if (empty($orders)) {
                    wp_delete_user($user->ID);
                    $deletedCount++;
                }
            }

            $redirectUrl = add_query_arg(
                [
                    'page' => 'cleanup-customers',
                    'deleted' => $deletedCount
                ],
                admin_url('users.php')
            );
            wp_safe_redirect($redirectUrl);
            exit;
        }

        echo '<div class="wrap"><h1>' . esc_html__('Delete Users Without WooCommerce Orders', 'duwwo') . '</h1></div>';
        echo '<p>' . esc_html__('This page lists customers with 0 orders in small batches to prevent performance issues.', 'duwwo') . '</p>';

        if (isset($_GET['deleted']) && intval($_GET['deleted']) > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Successfully deleted %d customer(s)!', 'duwwo'), absint($_GET['deleted'])) .  '</p></div>';
        }

        $customerQuery = new WP_User_Query([
            'role' => 'customer',
            'fields' => ['ID', 'user_login', 'user_email']
        ]);

        $customers = $customerQuery->get_results();
        $noOrdersCustomers = [];

        foreach ($customers as $user) {
            $orders = wc_get_orders([
                'customer_id' => $user->ID,
                'limit' => 1
            ]);

            if (empty($orders)) {
                $noOrdersCustomers[] = $user;
            }
        }

        echo '<h2>' . esc_html__('Customers with No Orders', 'duwwo') . '</h2>';
        echo '<p>' . esc_html__('Below is a list of WP users who have 0 WooCommerce orders.', 'duwwo') . '</p>';

        $key = 1;

        if (! empty($noOrdersCustomers)) {
            echo '<div class="duwwo-customers-container">';

            echo '<table class="widefat"><thead><tr><th>' . esc_html__('Lp.', 'duwwo') . '</th><th>' . esc_html__('User ID', 'duwwo') . '</th><th>' . esc_html__('Login', 'duwwo') . '</th><th>' . esc_html__('Email', 'duwwo') . '</th></tr></thead><tbody>';

            foreach ($noOrdersCustomers as $user) {
                echo '<tr>
                <td>' . esc_html($key++) . '</td>
                <td>' . esc_html($user->ID). '</td>
                <td>' . esc_html($user->user_login) . '</td>
                <td>' . esc_html($user->user_email) . '</td>
                </tr>';
            }

            echo '</tbody></table>';
            echo '</div>';

            echo '<form method="post" class="duwwo-delete-form">';
            wp_nonce_field('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

            echo '<p><input type="submit" class="button button-primary" name="duwwo_delete_batch" value="Delete This Batch" onclick="return confirm(\'Are you sure you want to delete all customers in this batch?\');"></p>';

            echo '</form>';

        } else {
            echo '<p>' . esc_html__('No zero-order customers in this batch.', 'duwwo') . '</p>';
        }
    }

    public function loadStyle(): void
    {
        wp_enqueue_style('duwwo-style', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.0.0');
    }

    public function addPluginActionLinks(array $actions, string $pluginFile): array
    {
        static $plugin;

        if (! isset($plugin)) {
            $plugin = plugin_basename(__FILE__);
        }

        if ($plugin === $pluginFile) {
            $settings = '<a href="' . esc_url(get_admin_url(null, 'users.php?page=cleanup-customers')) . '">' . esc_html__('Cleanup customers', 'duwwo') . '</a>';

            $actions = array_merge(['settings' => $settings], $actions);
        }

        return $actions;
    }

    public function maybeDeactivateSelfWhenWooCommerceDeactivated(string $plugin, bool $networkDeactivating): void
    {
        if ($plugin === plugin_basename(__FILE__)) {
            return;
        }

        if ($plugin !== 'woocommerce/woocommerce.php') {
            return;
        }

        $this->deactivateSelf($networkDeactivating);
    }

    public function maybeDeactivateSelfIfWooCommerceMissing(): void
    {
        if (! is_admin() || wp_doing_ajax()) {
            return;
        }

        if ($this->isWooCommerceActive()) {
            return;
        }

        $this->deactivateSelf(false);
    }

    private function deactivateSelf(bool $networkWide): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        if (! function_exists('deactivate_plugins') || ! function_exists('is_plugin_active')) {
            $pluginPhp = ABSPATH . 'wp-admin/includes/plugin.php';

            if (file_exists($pluginPhp)) {
                require_once $pluginPhp;
            }
        }

        $thisPlugin = plugin_basename(__FILE__);

        $isActive = false;

        if ($networkWide && function_exists('is_plugin_active_for_network') && is_multisite()) {
            $isActive = is_plugin_active_for_network($thisPlugin);
        } elseif (function_exists('is_plugin_active')) {
            $isActive = is_plugin_active($thisPlugin);
        }

        if (! $isActive) {
            return;
        }

        if (function_exists('deactivate_plugins')) {
            deactivate_plugins($thisPlugin, true, $networkWide);
            $this->deactivatedDueToWooCommerce = true;
            add_action('admin_notices', [$this, 'renderSelfDeactivatedNotice']);
        }
    }

    public function renderSelfDeactivatedNotice(): void
    {
        if (! $this->deactivatedDueToWooCommerce) {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html__('Delete Users Without WooCommerce Orders was deactivated because WooCommerce was deactivated.', 'duwwo') . '</p></div>';
    }

    private function isWooCommerceActive(): bool
    {
        if (class_exists('WooCommerce')) {
            return true;
        }

        if (! function_exists('is_plugin_active')) {
            $pluginPhp = ABSPATH . 'wp-admin/includes/plugin.php';

            if (file_exists($pluginPhp)) {
                require_once $pluginPhp;
            }
        }

        if (function_exists('is_plugin_active') && is_plugin_active('woocommerce/woocommerce.php')) {
            return true;
        }

        if (function_exists('is_plugin_active_for_network') && is_multisite() && is_plugin_active_for_network('woocommerce/woocommerce.php')) {
            return true;
        }

        return false;
    }

    public function renderWooCommerceMissingNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__('Delete Users Without WooCommerce Orders requires WooCommerce to be active.', 'duwwo') . '</p></div>';
    }
}
