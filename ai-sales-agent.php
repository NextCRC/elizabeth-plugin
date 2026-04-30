<?php
/**
 * Plugin Name: Elizabeth - Customer Service
 * Plugin URI: https://nextcrc.com/elizabeth
 * Description: Elizabeth es una Agente de Ventas Inteligente impulsada por IA.
 * Version: 1.0.1
 * Author: NextCRC
 * License: GPL-2.0+
 */

namespace Andana\Elizabeth;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define Plugin Constants
define( 'AI_SALES_AGENT_VERSION', '1.0.1' );
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

        // Initialize Admin
        if ( is_admin() ) {
            $admin = new Admin\Setup();
            $admin->init();
        }

        // Frontend hooks
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_footer', [ $this, 'render_chat_widget' ] );
        
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
            'userId'         => $user_id,
            'licenseKey'     => $license_key,
            'apiUrl'         => 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/chat',
            'siteUrl'        => get_site_url(),
            'inventoryUrl'   => rest_url( 'elizabeth/v1/inventory' ),
            'currentProduct' => $current_product,
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

        wp_remote_post( 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/sync-inventory', [
            'method'      => 'POST',
            'timeout'     => 5,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => false,
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

