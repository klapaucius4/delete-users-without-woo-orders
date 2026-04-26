<?php

/*
 * Plugin Name:       Delete Users Without Woo Orders
 * Plugin URI:        https://wordpress.org/plugins/delete-users-without-woo-orders/
 * Description:       Simple and free plugin that allows you to delete WooCommerce users with no orders.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Michał Kowalik
 * Author URI:        https://michalkowalik.pl
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       delete-users-without-woo-orders
 */

if (! defined('ABSPATH')) {
    exit;
}

DeleteUsersWithoutWooCommerceOrders::getInstance();

class DeleteUsersWithoutWooCommerceOrders
{
    private static ?self $instance = null;
    private bool $deactivatedDueToWooCommerce = false;

    private function __construct()
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

        if (isset($_POST['duwwo_delete'])) {
            check_admin_referer('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

            if (! current_user_can('delete_users')) {
                wp_die(esc_html__('You do not have permission to delete users.', 'duwwo'));
            }

            $customerQuery = new WP_User_Query($this->getCustomerUserQueryArgs());

            $customers = $customerQuery->get_results();
            $deletedCount = 0;

            foreach ($customers as $user) {
                if ($this->userHasNoOrders($user->ID)) {
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

        echo '<div class="wrap"><h1>' . esc_html__('Delete Users Without Woo Orders', 'duwwo') . '</h1></div>';
        echo '<p>' . esc_html__('This page lists customers who have no WooCommerce orders. You can delete all listed users after confirming.', 'duwwo') . '</p>';

        if (isset($_GET['deleted']) && intval($_GET['deleted']) > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Successfully deleted %d customer(s)!', 'duwwo'), absint($_GET['deleted'])) . '</p></div>';
        }

        $customerQuery = new WP_User_Query($this->getCustomerUserQueryArgs());

        $customers = $customerQuery->get_results();
        $noOrdersCustomers = [];

        foreach ($customers as $user) {
            if ($this->userHasNoOrders($user->ID)) {
                $noOrdersCustomers[] = $user;
            }
        }

        echo '<h2>' . esc_html__('Customers with No Orders', 'duwwo') . '</h2>';
        echo '<p>' . esc_html__('Below is a list of WP users who have 0 WooCommerce orders.', 'duwwo') . '</p>';

        $key = 1;

        if (! empty($noOrdersCustomers)) {
            echo '<div class="duwwo-customers-container">';

            echo '<table class="widefat"><thead><tr><th>' . esc_html__('Lp.', 'duwwo') . '</th><th>' . esc_html__('User ID', 'duwwo') . '</th><th>' . esc_html__('Login', 'duwwo') . '</th><th>' . esc_html__('Email', 'duwwo') . '</th><th>' . esc_html__('Register date', 'duwwo') . '</th><th>' . esc_html__('Role', 'duwwo') . '</th></tr></thead><tbody>';

            foreach ($noOrdersCustomers as $user) {
                $userData = get_userdata($user->ID);
                $roleSlug = $userData->roles[0] ?? '';
                $role = $roleSlug ? wp_roles()->get_role($roleSlug) : null;
                $roleLabel = $role ? $role->name : $roleSlug;
                $registered = $userData->user_registered
                    ? mysql2date(get_option('date_format'), $userData->user_registered)
                    : '';

                echo '<tr>
                <td>' . esc_html($key++) . '</td>
                <td>' . esc_html($user->ID). '</td>
                <td>' . esc_html($user->user_login) . '</td>
                <td>' . esc_html($user->user_email) . '</td>
                <td>' . esc_html($registered) . '</td>
                <td>' . esc_html($roleLabel) . '</td>
                </tr>';
            }

            echo '</tbody></table>';
            echo '</div>';

            echo '<form method="post" class="duwwo-delete-form">';

            wp_nonce_field('duwwo_cleanup_action', 'duwwo_cleanup_nonce');

            $confirmDelete = esc_js(__('Are you sure you want to permanently delete all customers listed below? This cannot be undone.', 'duwwo'));
            echo '<p><input type="submit" class="button button-primary" name="duwwo_delete" value="' . esc_attr__('Delete all listed customers', 'duwwo') . '" onclick="return confirm(\'' . $confirmDelete . '\');"></p>';

            echo '</form>';

        } else {
            echo '<p>' . esc_html__('No customers without WooCommerce orders were found.', 'duwwo') . '</p>';
        }
    }

    public function loadStyle(string $hookSuffix): void
    {
        if ($hookSuffix !== 'users_page_cleanup-customers') {
            return;
        }

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

        echo '<div class="notice notice-warning"><p>' . esc_html__('Delete Users Without Woo Orders was deactivated because WooCommerce was deactivated.', 'duwwo') . '</p></div>';
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

        echo '<div class="notice notice-error"><p>' . esc_html__('Delete Users Without Woo Orders requires WooCommerce to be active.', 'duwwo') . '</p></div>';
    }

    private function getCustomerUserQueryArgs(): array
    {
        return [
            'role' => 'customer',
            'fields' => ['ID', 'user_login', 'user_email'],
        ];
    }

    private function userHasNoOrders(int $userId): bool
    {
        $orders = wc_get_orders([
            'customer_id' => $userId,
            'limit' => 1
        ]);

        return empty($orders);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __clone()
    {
        // Prevent cloning
    }

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
