<?php
/**
 * Admin functionality for the Elizabeth AI Sales Agent plugin.
 */

namespace Andana\Elizabeth\Admin;

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Setup {

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_plugin_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        
        // AJAX Sync Handler
        add_action( 'wp_ajax_elizabeth_sync_all_products', [ $this, 'ajax_sync_all_products' ] );
        
        // Validation Hook
        add_filter( 'pre_update_option_ai_sales_agent_license_key', [ $this, 'validate_license_key' ], 10, 2 );

        // Sincronización Vectorial de Productos (RAG)
        add_action( 'woocommerce_update_product', [ $this, 'sync_product_vector' ], 10, 1 );
    }

    /**
     * Enqueue admin styles and scripts.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_elizabeth-ai' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'elizabeth-admin-style', AI_SALES_AGENT_PLUGIN_URL . 'assets/css/admin.css', [], AI_SALES_AGENT_VERSION, 'all' );
    }

    /**
     * Add a menu page in the WordPress admin dashboard.
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            'Elizabeth - AI Settings', 
            'Elizabeth AI', 
            'manage_options', 
            'elizabeth-ai', 
            [ $this, 'display_plugin_setup_page' ],
            'dashicons-robot',
            80
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_license_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_license_status', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_response_delay', [
            'sanitize_callback' => [ $this, 'sanitize_response_delay' ],
        ]);

        add_settings_section(
            'ai_sales_agent_main_section',
            'Configuración de Conexión',
            [ $this, 'main_section_callback' ],
            'elizabeth-ai'
        );

        add_settings_field(
            'ai_sales_agent_license_key',
            'Clave de Licencia (License Key)',
            [ $this, 'license_key_callback' ],
            'elizabeth-ai',
            'ai_sales_agent_main_section'
        );

        add_settings_field(
            'ai_sales_agent_response_delay',
            'Retardo de respuesta (segundos)',
            [ $this, 'response_delay_callback' ],
            'elizabeth-ai',
            'ai_sales_agent_main_section'
        );
    }

    /**
     * Validate the license key against the SaaS backend.
     */
    public function validate_license_key( $new_value, $old_value ) {
        if ( empty( $new_value ) ) {
            update_option( 'ai_sales_agent_license_status', 'invalid' );
            return $new_value;
        }

        if ( $new_value === $old_value && get_option( 'ai_sales_agent_license_status' ) === 'active' ) {
            return $new_value; // No change, already active
        }

        $response = wp_remote_post( 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/validate-license', [
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([
                'license_key' => $new_value,
                'site_url'    => get_site_url(),
            ])
        ]);

        if ( is_wp_error( $response ) ) {
            add_settings_error( 'ai_sales_agent_license_key', 'license_api_error', 'Error conectando con el servidor de licencias.', 'error' );
            update_option( 'ai_sales_agent_license_status', 'error' );
            return $new_value;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        // La licencia es válida únicamente cuando el backend confirma ambas condiciones:
        // código HTTP 200 Y el campo success === true en el cuerpo JSON.
        $is_valid = ( 200 === $http_code ) && ! empty( $body['success'] );

        if ( $is_valid ) {
            add_settings_error( 'ai_sales_agent_license_key', 'license_valid', 'Licencia activada correctamente.', 'success' );
            update_option( 'ai_sales_agent_license_status', 'active' );
        } else {
            add_settings_error( 'ai_sales_agent_license_key', 'license_invalid', 'La clave de licencia es inválida o no pertenece a este dominio.', 'error' );
            update_option( 'ai_sales_agent_license_status', 'invalid' );
        }

        return $new_value;
    }

    /**
     * AJAX handler para sincronizar todos los productos de una vez.
     */
    public function ajax_sync_all_products() {
        check_ajax_referer( 'elizabeth_sync_nonce', '_ajax_nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permiso denegado' );
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            wp_send_json_error( 'WooCommerce no está activo' );
        }

        $license = get_option( 'ai_sales_agent_license_key' );
        if ( empty( $license ) ) {
            wp_send_json_error( 'No hay una licencia activa' );
        }

        // Obtenemos todos los productos publicados
        $products = wc_get_products( [ 'status' => 'publish', 'limit' => -1 ] );
        $count = 0;

        foreach ( $products as $product ) {
            // Reutilizamos nuestra lógica de sincronización
            $this->sync_product_vector( $product->get_id() );
            $count++;
            
            // Pequeña pausa para no saturar el servidor si hay miles
            if ($count % 50 === 0) {
                usleep(500000); // 0.5 segundos
            }
        }

        wp_send_json_success( [ 'count' => $count ] );
    }

    /**
     * Sincroniza un producto con Supabase para generar su embedding vectorial.
     */
    public function sync_product_vector( $product_id ) {
        $product = wc_get_product( $product_id );
        $license = get_option( 'ai_sales_agent_license_key' );

        if ( empty( $license ) || ! $product ) return;

        $data = [
            'license_key' => $license,
            'site_url'    => get_site_url(),
            'product'     => [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
                'sku'         => $product->get_sku(),
                'price'       => $product->get_price(),
                'permalink'   => $product->get_permalink(),
            ]
        ];

        wp_remote_post( 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/vectorize-product', [
            'method'      => 'POST',
            'timeout'     => 15,
            'blocking'    => false, // No bloqueamos la carga de WP
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $data ),
        ]);
    }

    public function main_section_callback() {
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 15px; margin-bottom: 25px;">Ingresa las credenciales de tu cuenta SaaS para activar a Elizabeth en esta tienda.</p>';
    }

    public function license_key_callback() {
        $val = get_option( 'ai_sales_agent_license_key', '' );
        echo '<input type="password" id="ai_sales_agent_license_key" name="ai_sales_agent_license_key" value="' . esc_attr( $val ) . '" class="elizabeth-input" placeholder="••••••••••••••••" />';
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 12px; margin-top: 5px;">La clave de activación vinculada a tu suscripción.</p>';
    }

    public function response_delay_callback() {
        $val = (int) get_option( 'ai_sales_agent_response_delay', 18 );
        echo '<input type="number" id="ai_sales_agent_response_delay" name="ai_sales_agent_response_delay" value="' . esc_attr( $val ) . '" min="5" max="60" step="1" class="elizabeth-input" style="max-width:120px;" />';
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 12px; margin-top: 5px;">Segundos mínimos antes de mostrar la respuesta (5–60). Se añaden hasta 5 s aleatorios para simular un agente humano.</p>';
    }

    public function sanitize_response_delay( $value ): int {
        return max( 5, min( 60, (int) $value ) );
    }

    /**
     * Display the settings page.
     */
    public function display_plugin_setup_page() {
        $user_id = get_option( 'ai_sales_agent_user_id', '' );
        $license_key = get_option( 'ai_sales_agent_license_key', '' );
        $status = get_option( 'ai_sales_agent_license_status', 'inactive' );
        $is_active = ( $status === 'active' );
        ?>
        <div class="elizabeth-dashboard">
            <?php settings_errors(); ?>
            <!-- Header -->
            <div class="elizabeth-header">
                <img
                    src="<?php echo esc_url( AI_SALES_AGENT_PLUGIN_URL . 'assets/images/logo_blanco.png' ); ?>"
                    alt="Elizabeth"
                    class="elizabeth-header-logo"
                />
                <p>Tu Agente de Ventas Inteligente impulsado por IA de última generación.</p>

                <div class="elizabeth-status-banner">
                    <span class="elizabeth-status-dot <?php echo $is_active ? 'active' : ''; ?>"></span>
                    <span><?php echo $is_active ? 'Elizabeth está conectada y lista' : 'Esperando configuración...'; ?></span>
                </div>
            </div>

            <!-- Settings Card -->
            <div class="elizabeth-card">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    Conexión con el SaaS
                </h2>
                
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'ai_sales_agent_options_group' );
                    do_settings_sections( 'elizabeth-ai' );
                    ?>
                    <div style="margin-top: 30px;">
                        <input type="submit" name="submit" id="submit" class="elizabeth-btn-save" value="Guardar Configuración">
                    </div>
                </form>
            </div>

            <!-- Help/Info Card -->
            <div class="elizabeth-card" style="background: linear-gradient(to right, #f8fafc, #eff6ff);">
                <h3 style="margin-top:0; font-size: 16px; font-weight: 600;">¿Dónde encuentro mis credenciales?</h3>
                <p style="color: var(--elizabeth-text-muted); font-size: 14px; line-height: 1.6;">
                    Puedes gestionar tus licencias, ver métricas de chat y alimentar la base de conocimientos de Elizabeth desde el panel web externo de control.
                </p>
                <a href="https://elizabeth.nextcrc.com" target="_blank" style="color: var(--elizabeth-primary); font-weight: 500; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    Ir al Dashboard Externo
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                        <polyline points="15 3 21 3 21 9"></polyline>
                        <line x1="10" y1="14" x2="21" y2="3"></line>
                    </svg>
                </a>
            </div>

            <!-- Sync Inventory Card -->
            <div class="elizabeth-card" style="margin-top: 20px;">
                <h2 style="font-size: 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 2v6h-6"></path>
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                        <path d="M3 22v-6h6"></path>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                    </svg>
                    Sincronización Masiva de Inventario
                </h2>
                <p style="color: var(--elizabeth-text-muted); font-size: 14px;">
                    Usa esta opción para enviar todo tu catálogo de productos a Elizabeth por primera vez. Esto permitirá que la IA conozca todos tus productos actuales.
                </p>
                <div id="elizabeth-sync-progress" style="display:none; margin: 15px 0;">
                    <div style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="elizabeth-sync-bar" style="background: var(--elizabeth-primary); width: 0%; height: 100%; transition: width 0.3s;"></div>
                    </div>
                    <p id="elizabeth-sync-status" style="font-size: 12px; margin-top: 5px; color: var(--elizabeth-text-muted);"></p>
                </div>
                <button type="button" id="elizabeth-btn-sync-all" class="elizabeth-btn-save" style="background: #1e293b; border-color: #1e293b;">
                    Sincronizar Catálogo Completo
                </button>

                <script>
                jQuery(document).ready(function($) {
                    $('#elizabeth-btn-sync-all').on('click', function() {
                        if (!confirm('¿Deseas iniciar la sincronización de todo el inventario? Esto puede tardar unos minutos.')) return;

                        const btn = $(this);
                        const progress = $('#elizabeth-sync-progress');
                        const bar = $('#elizabeth-sync-bar');
                        const status = $('#elizabeth-sync-status');

                        btn.prop('disabled', true).text('Sincronizando...');
                        progress.show();
                        bar.css('width', '5%');
                        status.text('Obteniendo catálogo...');

                        $.post(ajaxurl, { 
                            action: 'elizabeth_sync_all_products',
                            _ajax_nonce: '<?php echo wp_create_nonce("elizabeth_sync_nonce"); ?>'
                        }, function(response) {
                            if (response.success) {
                                bar.css('width', '100%');
                                status.text('¡Sincronización completada! ' + response.data.count + ' productos procesados.');
                                btn.text('Sincronización Exitosa').css('background', '#10b981');
                            } else {
                                status.text('Error: ' + response.data);
                                btn.prop('disabled', false).text('Reintentar Sincronización');
                            }
                        });
                    });
                });
                </script>
            </div>
        </div>
        <?php
    }
}

