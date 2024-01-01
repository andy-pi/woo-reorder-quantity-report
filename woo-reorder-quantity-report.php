<?php
/**
 * Plugin Name: Woo Reorder Quantity Report
 * Plugin URI:
 * Version: 1.0
 * Description: Report Showing Sales, Remainging Stock and Reorder Quantity
 * Author: <a href="https://andypi.co.uk">AndyPi</a>
 * Author URI: https://andypi.co.uk
 * Text Domain: andypi_wsr
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class andypi_wsr_WooSalesReport_List_Table extends WP_List_Table {
    // Constructor
    public function __construct() {
        parent::__construct([
            'singular' => 'product',
            'plural'   => 'products',
            'ajax'     => false,
        ]);
    }
    
    // Define columns
    public function get_columns() {
        return [
            'product_name'    => 'Product Name',
            'sku'             => 'SKU',
            'quantity_sold'   => 'Quantity Sold',
            'stock_quantity'  => 'Current Stock',
            'reorder_quantity'=> 'Reorder Quantity',
        ];
    }
    
    // Define sortable columns
    public function get_sortable_columns() {
        return [
            'product_name'    => ['product_name', false],
            'sku'             => ['sku', false],
            'quantity_sold'   => ['quantity_sold', false],
            'stock_quantity'  => ['stock_quantity', false],
       		'reorder_quantity'=> ['reorder_quantity', false],
        ];
    }

    
    // Prepare items for the table

    public function prepare_items() {
        // Retrieve and set your product data here
        global $wpdb;

        // Get the product IDs
        $product_ids = wc_get_products(array('status' => 'publish', 'limit' => -1, 'return' => 'ids'));

        // Retrieve the selected time period from URL parameter, default to 30
        $selected_period = isset($_GET['period']) ? intval($_GET['period']) : 30;

        // Calculate start date based on the selected period
        $start_date = date('Y-m-d', strtotime("-$selected_period days"));
        $end_date = date('Y-m-d');
        $end_date = date('Y-m-d');

        // Initialize an array to store the product data
        $product_data = array();

        foreach ($product_ids as $product_id) {
            // Get product object
            $product = wc_get_product($product_id);

            // Get SKU
            $sku = $product->get_sku();

            // Get total quantity sold in the past 30 days
            $total_quantity_sold = $wpdb->get_var("
                SELECT SUM(order_item_meta.meta_value)
                FROM {$wpdb->prefix}woocommerce_order_items AS order_items
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                INNER JOIN {$wpdb->prefix}posts AS posts ON order_items.order_id = posts.ID
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status IN ( 'wc-completed', 'wc-processing' )
                AND order_item_meta.meta_key = '_qty'
                AND order_item_meta.meta_value > 0
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta AS product_meta
                    WHERE product_meta.order_item_id = order_items.order_item_id
                    AND product_meta.meta_key = '_product_id'
                    AND product_meta.meta_value = $product_id
                )
                AND posts.post_date BETWEEN '$start_date' AND '$end_date'
            ");

            // Get current stock quantity
            $stock_quantity = $product->get_stock_quantity();

            // Calculate reorder quantity (ensure it doesn't go below zero)
            $reorder_quantity = max(0, $total_quantity_sold - $stock_quantity);


            if ($total_quantity_sold || $stock_quantity > 0) {
                $product_data[] = array(
                    'product_name' => $product->get_name(),
                    'sku' => $sku,
                    'quantity_sold' => $total_quantity_sold,
                    'stock_quantity' => $stock_quantity,
                    'reorder_quantity' => $reorder_quantity, 
                );
            }
        }

        $this->items = $product_data;

        // Set columns and sortable headers
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        // Handle sorting
        usort($this->items, array($this, 'usort_reorder'));
    }

    public function usort_reorder($a, $b) {
        // Define your sorting logic here
        $orderby = (!empty($_GET['orderby'])) ? $_GET['orderby'] : 'sku';
        $order = (!empty($_GET['order'])) ? $_GET['order'] : 'asc';
        $result = strnatcmp($a[$orderby], $b[$orderby]);

        return ($order === 'asc') ? $result : -$result;
    }
    
    // Render each column
    public function column_default($item, $column_name) {
        return $item[$column_name];
    }
    
    // Render product name column
    public function column_product_name($item) {
        return '<strong>' . esc_html($item['product_name']) . '</strong>';
    }
}

class andypi_wsr_WooSalesReport_Plugin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_sales_report_menu'));
    }

    public function add_sales_report_menu() {
        add_menu_page(
            'Reordering Report',        // Page title
            'Reordering Report',            // Menu title
            'manage_options',          // Capability
            'woo-reorder-quantity-report',        // Menu slug
            array($this, 'render_sales_report'), // Callback function to render the page
            'dashicons-chart-bar',     // Icon
            25                         // Menu position
        );
    }

    public function render_sales_report() {

        // Check if a time period has been selected, default to 30 days
        $selected_period = isset($_GET['period']) ? intval($_GET['period']) : 30;
        echo '<div class="wrap">';
        echo '<h2>List of Products Sold</h2>';

        // Form for period selection
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="woo-reorder-quantity-report">';
        echo '<select name="period">';
        echo '<option value="30"' . selected($selected_period, 30) . '>Last 30 days</option>';
        echo '<option value="60"' . selected($selected_period, 60) . '>Last 60 days</option>';
        echo '<option value="90"' . selected($selected_period, 90) . '>Last 90 days</option>';
        echo '<option value="180"' . selected($selected_period, 180) . '>Last 180 days</option>';
        echo '<option value="365"' . selected($selected_period, 365) . '>Last 365 days</option>';
        echo '</select>';
        echo '<input type="submit" value="Filter" class="button">';
        echo '</form>';

        // Display table
        $products_table = new andypi_wsr_WooSalesReport_List_Table();
        $products_table->prepare_items();
        $products_table->display();
        echo '</div>';
    }
}

new andypi_wsr_WooSalesReport_Plugin();
