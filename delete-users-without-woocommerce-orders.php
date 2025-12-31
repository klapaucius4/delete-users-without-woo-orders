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
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addCleanupCustomersPage']);
        add_action('admin_enqueue_scripts', [$this, 'loadStyle']);
        add_filter('plugin_action_links', [$this, 'addPluginActionLinks'], 10, 2);
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

        echo '<div class="wrap"><h1>' . esc_html__('Delete Users Without WooCommerce Orders', 'duwwo') . '</h1></div>';
        echo '<p>' . esc_html__('This page lists customers with 0 orders in small batches to prevent performance issues.', 'duwwo') . '</p>';

        $perPage = 100;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $perPage;

        $customerQuery = new WP_User_Query([
            'role' => 'customer',
            'fields' => ['ID', 'user_login', 'user_email'],
            'number' => $perPage,
            'offset' => $offset,
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

        echo '<h2>' . sprintf(esc_html__('Customers with No Orders (Batch %d)', 'duwwo'), absint($paged)) . '</h2>';
        echo '<p>Below is a list of WP users who have 0 WooCommerce orders.</p>';

        if (!empty($noOrdersCustomers)) {
            echo '<div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px; border: 1px solid #ccd0d4; padding: 10px; border-radius: 4px; background: #fff;">';

            echo '<table class="widefat"><thead><tr><th>ID</th><th>Login</th><th>Email</th></tr></thead><tbody>';

            foreach ($noOrdersCustomers as $user) {
                echo '<tr>
                <td>'.esc_html($user->ID).'</td>
                <td>'.esc_html($user->user_login).'</td>
                <td>'.esc_html($user->user_email).'</td>
                </tr>';
            }

            echo '</tbody></table>';
            echo '</div>';

            echo '<form method="post" style="margin-top: 20px;">';
            wp_nonce_field('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

            echo '<p><input type="submit" class="button button-primary" name="duwwo_delete_batch" value="Delete This Batch" onclick="return confirm(\'Are you sure you want to delete all customers in this batch?\');"></p>';

            echo '</form>';

            if (isset($_POST['duwwo_delete_batch'])) {
                check_admin_referer('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

                foreach ($noOrdersCustomers as $user) {
                    wp_delete_user($user->ID);
                }

                echo '<div class="notice notice-success"><p>Batch deleted successfully!</p></div>';
            }

        } else {
            echo '<p>No zero-order customers in this batch.</p>';
        }
    }

    public function loadStyle(): void
    {
        wp_enqueue_style('duwwo-style', plugin_dir_url(__FILE__) . 'assets/style.css', false, '1.0.0');
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
}
