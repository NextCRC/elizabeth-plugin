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

    private function check_rate_limit( string $ip ): bool {
        $key     = 'elizabeth_rl_' . md5( $ip );
        $hits    = (int) get_transient( $key );
        if ( $hits >= 20 ) {
            return false;
        }
        set_transient( $key, $hits + 1, 60 ); // ventana de 60 segundos
        return true;
    }

    private function get_client_ip(): string {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            // CF-Connecting-IP tiene prioridad: Cloudflare lo fija internamente y no puede ser suplantado por el cliente.
            $candidate = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // X-Forwarded-For puede ser inyectado por el cliente; se usa solo como fallback.
            $candidate = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        } else {
            $candidate = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        return filter_var( $candidate, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
    }

    public function validate_request( $request ) {
        $ip = $this->get_client_ip();
        if ( ! $this->check_rate_limit( $ip ) ) {
            return new \WP_Error(
                'rest_too_many_requests',
                __( 'Demasiadas solicitudes. Intenta de nuevo en un momento.', 'elizabeth' ),
                [ 'status' => 429 ]
            );
        }

        // El header X-Elizabeth-License es OBLIGATORIO.
        // Esto asegura que solo nuestra Edge Function (que posee la clave)
        // pueda extraer el catálogo, protegiendo los datos del cliente.
        $header_key = $request->get_header( 'X-Elizabeth-License' );
        $stored_key = get_option( 'ai_sales_agent_license_key', '' );

        if ( empty( $header_key ) || empty( $stored_key ) || ! hash_equals( $stored_key, $header_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Acceso no autorizado: Se requiere una licencia válida.', 'elizabeth' ),
                [ 'status' => 401 ]
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
                'currency'          => get_woocommerce_currency(),
                'currency_symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
                'short_description' => wp_strip_all_tags( wp_trim_words( $product->get_short_description(), 20 ) ) ?: null,
                'stock'             => $product->is_in_stock() ? 'Disponible' : 'Agotado',
                'categories'        => is_array( $cats ) ? wp_list_pluck( $cats, 'name' ) : [],
                'tags'              => is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : [],
                'permalink'         => $product->get_permalink(),
            ];
        }

        return rest_ensure_response( $inventory_data );
    }
}
