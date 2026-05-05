<?php
/**
 * Plugin Name: Elizabeth - Customer Service
 * Plugin URI: https://nextcrc.com/elizabeth
 * Description: Elizabeth es una Agente de Ventas Inteligente impulsada por IA.
 * Version: 1.0.2
 * Author: NextCRC
 * License: GPL-2.0+
 */

namespace Andana\Elizabeth;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define Plugin Constants
define( 'AI_SALES_AGENT_VERSION', '1.0.2' );
define( 'AI_SALES_AGENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_SALES_AGENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Simple PSR-4 Autoloader
spl_autoload_register( function ( $class ) {
    $prefix = 'Andana\\Elizabeth\\';
    $base_dir = AI_SALES_AGENT_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    // Replace namespace separators with directory separators, append with .php
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Main Plugin Bootstrap Class
 */
class Plugin {

    /**
     * URLs internas de las Edge Functions — nunca se exponen al frontend.
     */
    private const CHAT_ENDPOINT           = 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/chat';
    private const SYNC_INVENTORY_ENDPOINT = 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/sync-inventory';

    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Initialize REST endpoints
        (new \Andana\Elizabeth\REST\Inventory())->init();

        // Auto-updates desde el servidor propio
        ( new Updater() )->init();

        // Initialize Admin
        if ( is_admin() ) {
            $admin = new Admin\Setup();
            $admin->init();
        }

        // Frontend hooks
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_footer', [ $this, 'render_chat_widget' ] );

        // Proxy AJAX — disponible para usuarios logueados y visitantes anónimos
        add_action( 'wp_ajax_elizabeth_chat',        [ $this, 'ajax_chat_proxy' ] );
        add_action( 'wp_ajax_nopriv_elizabeth_chat', [ $this, 'ajax_chat_proxy' ] );
        
        // WooCommerce Hooks
        add_action( 'woocommerce_update_product', [ $this, 'sync_product' ], 10, 2 );
        add_action( 'woocommerce_new_product', [ $this, 'sync_product' ], 10, 2 );
        
        // Plugin Action Links
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_settings_link' ] );
    }

    public function add_plugin_settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=elizabeth-ai">Ajustes</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function enqueue_scripts() {
        $user_id     = get_option( 'ai_sales_agent_user_id', '' );
        $license_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $user_id ) || empty( $license_key ) ) {
            return;
        }

        wp_enqueue_style( 'ai-sales-agent-style', AI_SALES_AGENT_PLUGIN_URL . 'assets/css/frontend.css', [], AI_SALES_AGENT_VERSION, 'all' );
        wp_enqueue_script( 'ai-sales-agent-script', AI_SALES_AGENT_PLUGIN_URL . 'assets/js/frontend.js', [], AI_SALES_AGENT_VERSION, true );

        // Detectar si el visitante está en una página de producto WooCommerce
        $current_product = null;
        if ( function_exists( 'is_product' ) && is_product() ) {
            $wc_product = wc_get_product( get_queried_object_id() );
            if ( $wc_product instanceof \WC_Product ) {
                $cats = get_the_terms( $wc_product->get_id(), 'product_cat' );
                $tags = get_the_terms( $wc_product->get_id(), 'product_tag' );

                // Para productos variables, recopilar atributos visibles (ej. Color: Rojo, Talla: M)
                $attributes = [];
                foreach ( $wc_product->get_attributes() as $attr ) {
                    if ( $attr->get_visible() ) {
                        $label   = wc_attribute_label( $attr->get_name() );
                        $options = $attr->is_taxonomy()
                            ? wc_get_product_terms( $wc_product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                            : $attr->get_options();
                        if ( ! empty( $options ) ) {
                            $attributes[ $label ] = implode( ', ', $options );
                        }
                    }
                }

                $current_product = [
                    'name'              => $wc_product->get_name(),
                    'sku'               => $wc_product->get_sku(),
                    'price'             => $wc_product->get_price(),
                    'regular_price'     => $wc_product->get_regular_price(),
                    'sale_price'        => $wc_product->get_sale_price(),
                    'short_description' => wp_strip_all_tags( $wc_product->get_short_description() ),
                    'stock'             => $wc_product->get_stock_quantity() !== null
                        ? $wc_product->get_stock_quantity()
                        : ( $wc_product->is_in_stock() ? 'Disponible' : 'Agotado' ),
                    'permalink'         => $wc_product->get_permalink(),
                    'categories'        => is_array( $cats ) ? wp_list_pluck( $cats, 'name' ) : [],
                    'tags'              => is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : [],
                    'attributes'        => $attributes,
                ];
            }
        }

        wp_localize_script( 'ai-sales-agent-script', 'aiSalesAgentData', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'elizabeth_chat_nonce' ),
            'siteUrl'        => get_site_url(),
            'inventoryUrl'   => rest_url( 'elizabeth/v1/inventory' ),
            'currentProduct' => $current_product,
            'responseDelay'  => (int) get_option( 'ai_sales_agent_response_delay', 18 ),
        ] );
    }

    public function render_chat_widget() {
        $user_id     = get_option( 'ai_sales_agent_user_id', '' );
        $license_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $user_id ) || empty( $license_key ) ) {
            return;
        }

        include AI_SALES_AGENT_PLUGIN_DIR . 'templates/chat-widget.php';
    }

    /**
     * Proxy server-side para el chat: el frontend nunca conoce la license_key,
     * el user_id ni la URL de Supabase. Corrige hallazgos C-01, C-04 y C-05.
     *
     * @return void  Termina con wp_send_json_success() o wp_send_json_error().
     */
    public function ajax_chat_proxy() {
        // 1. Verificación CSRF — aborta si el nonce no es válido.
        check_ajax_referer( 'elizabeth_chat_nonce', 'nonce' );

        // 2. Credenciales solo server-side.
        $user_id     = get_option( 'ai_sales_agent_user_id', '' );
        $license_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $user_id ) || empty( $license_key ) ) {
            wp_send_json_error( [ 'message' => 'Plugin not configured.' ], 503 );
        }

        // 3. Leer y sanitizar los campos enviados por el frontend.
        $message         = sanitize_text_field( wp_unslash( $_POST['message']         ?? '' ) );
        $session_id      = sanitize_text_field( wp_unslash( $_POST['session_id']      ?? '' ) );
        $customer_name   = sanitize_text_field( wp_unslash( $_POST['customer_name']   ?? '' ) );
        $page_url        = esc_url_raw( wp_unslash( $_POST['page_url']               ?? '' ) );

        // current_product e history llegan como JSON — se decodifican aquí.
        $current_product_raw  = wp_unslash( $_POST['current_product']  ?? 'null' );
        $inventory_raw        = wp_unslash( $_POST['inventory']         ?? '[]' );
        $history_raw          = wp_unslash( $_POST['history']           ?? '[]' );

        $current_product = json_decode( $current_product_raw, true );
        $inventory       = json_decode( $inventory_raw, true );
        $history         = json_decode( $history_raw, true );

        if ( empty( $message ) ) {
            wp_send_json_error( [ 'message' => 'Empty message.' ], 400 );
        }

        // 4. Construir payload para la Edge Function.
        $payload = [
            'message'       => $message,
            'user_id'       => $user_id,
            'license_key'   => $license_key,
            'site_url'      => get_site_url(),
            'page_url'      => $page_url,
            'page_context'  => is_array( $current_product ) ? $current_product : null,
            'inventory'     => is_array( $inventory ) ? $inventory : [],
            'session_id'    => $session_id,
            'customer_name' => $customer_name,
            'history'       => is_array( $history ) ? $history : [],
        ];

        // 5. Llamada server-side a Supabase.
        $response = wp_remote_post( self::CHAT_ENDPOINT, [
            'method'      => 'POST',
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Connection error.' ], 502 );
        }

        $status_code  = wp_remote_retrieve_response_code( $response );
        $body         = wp_remote_retrieve_body( $response );
        $decoded      = json_decode( $body, true );

        if ( $status_code !== 200 || ! is_array( $decoded ) ) {
            $upstream_msg = ( is_array( $decoded ) && isset( $decoded['error'] ) )
                ? $decoded['error']
                : 'Upstream error.';
            wp_send_json_error( [ 'message' => $upstream_msg, 'status' => $status_code ], 502 );
        }

        wp_send_json_success( $decoded );
    }

    public function sync_product( $product_id, $product ) {
        $user_id = get_option( 'ai_sales_agent_user_id', '' );
        $license_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $user_id ) || empty( $license_key ) ) {
            return;
        }

        $product_data = [
            'product_id'   => $product->get_id(),
            'name'         => $product->get_name(),
            'price'        => $product->get_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'permalink'    => $product->get_permalink(),
        ];

        $payload = [
            'user_id'     => $user_id,
            'license_key' => $license_key,
            'site_url'    => get_site_url(),
            'event'       => 'product_update',
            'product'     => $product_data
        ];

        wp_remote_post( self::SYNC_INVENTORY_ENDPOINT, [
            'method'      => 'POST',
            'timeout'     => 5,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => false,
            'sslverify'   => true,
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $payload ),
        ] );
    }
}

// Boot the plugin
function run_elizabeth() {
    Plugin::get_instance();
}

run_elizabeth();

