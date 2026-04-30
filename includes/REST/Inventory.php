<?php
namespace Andana\Elizabeth\REST;

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Inventory {
    
    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'elizabeth/v1', '/inventory', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_inventory' ],
            'permission_callback' => [ $this, 'validate_request' ],
            'show_in_index'       => false,
        ]);
    }

    public function validate_request( $request ) {
        $stored_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $stored_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Plugin no configurado.', 'elizabeth' ),
                [ 'status' => 403 ]
            );
        }

        $header_key = $request->get_header( 'X-Elizabeth-License' );

        if ( empty( $header_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Se requiere el header X-Elizabeth-License.', 'elizabeth' ),
                [ 'status' => 401 ]
            );
        }

        // Comparación en tiempo constante para evitar timing attacks.
        if ( ! hash_equals( $stored_key, $header_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'License key inválida.', 'elizabeth' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    public function get_inventory() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $products       = wc_get_products( [ 'status' => 'publish', 'limit' => 50 ] );
        $inventory_data = [];

        foreach ( $products as $product ) {
            $cats = get_the_terms( $product->get_id(), 'product_cat' );
            $tags = get_the_terms( $product->get_id(), 'product_tag' );

            // Precio: los productos variables exponen un rango min–max.
            // Si el precio está vacío se manda null para que el AI no lo invente.
            if ( $product->is_type( 'variable' ) ) {
                $min = $product->get_variation_price( 'min' );
                $max = $product->get_variation_price( 'max' );
                $price_display = ( $min && $max )
                    ? ( $min === $max ? $min : $min . ' - ' . $max )
                    : null;
            } else {
                $raw = $product->get_price();
                $price_display = ( $raw !== '' && $raw !== null ) ? $raw : null;
            }

            $inventory_data[] = [
                'name'              => $product->get_name(),
                'sku'               => $product->get_sku() ?: null,
                'price'             => $price_display,
                'short_description' => wp_strip_all_tags( wp_trim_words( $product->get_short_description(), 20 ) ) ?: null,
                'stock'             => $product->get_stock_quantity() !== null
                    ? $product->get_stock_quantity()
                    : ( $product->is_in_stock() ? 'Disponible' : 'Agotado' ),
                'categories'        => is_array( $cats ) ? wp_list_pluck( $cats, 'name' ) : [],
                'tags'              => is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : [],
                'permalink'         => $product->get_permalink(),
            ];
        }

        return rest_ensure_response( $inventory_data );
    }
}
