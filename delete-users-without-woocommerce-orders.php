<?php

/*
 * Plugin Name:       Delete Users Without WooCommerce Orders
 * Plugin URI:        https://wordpress.org/plugins/delete-users-without-woocommerce-orders/
 * Description:       -
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
    }

    public function addCleanupCustomersPage(): void
    {
        add_users_page(
            'Cleanup Customers',
            'Cleanup Customers',
            'manage_options',
            'cleanup-customers',
            [$this, 'cleanupCustomersPage']
        );
    }

    public function cleanupCustomersPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        echo '<div class="wrap"><h1>Cleanup Customers Page</h1></div>';
        echo '<p>This page lists customers with 0 orders in small batches to prevent performance issues.</p>';

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

        echo '<h2>Customers with No Orders (Batch ' . $paged . ')</h2>';

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
        wp_enqueue_style('duwwo_style', plugin_dir_url(__FILE__) . 'assets/style.css', false, '1.0.0');
    }
}
