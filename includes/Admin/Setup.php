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
        
        // Validation Hook
        add_filter( 'pre_update_option_ai_sales_agent_license_key', [ $this, 'validate_license_key' ], 10, 2 );
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
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_user_id', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_license_key', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting( 'ai_sales_agent_options_group', 'ai_sales_agent_license_status' ); // store status

        add_settings_section(
            'ai_sales_agent_main_section',
            'Configuración de Conexión',
            [ $this, 'main_section_callback' ],
            'elizabeth-ai'
        );

        add_settings_field(
            'ai_sales_agent_user_id',
            'API Base de Conocimiento (User ID)',
            [ $this, 'user_id_callback' ],
            'elizabeth-ai',
            'ai_sales_agent_main_section'
        );

        add_settings_field(
            'ai_sales_agent_license_key',
            'Clave de Licencia (License Key)',
            [ $this, 'license_key_callback' ],
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

        $user_id = isset( $_POST['ai_sales_agent_user_id'] ) ? sanitize_text_field( $_POST['ai_sales_agent_user_id'] ) : get_option( 'ai_sales_agent_user_id' );

        $response = wp_remote_post( 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/validate-license', [
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([
                'user_id'     => $user_id,
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

    public function main_section_callback() {
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 15px; margin-bottom: 25px;">Ingresa las credenciales de tu cuenta SaaS para activar a Elizabeth en esta tienda.</p>';
    }

    public function user_id_callback() {
        $val = get_option( 'ai_sales_agent_user_id', '' );
        echo '<input type="text" id="ai_sales_agent_user_id" name="ai_sales_agent_user_id" value="' . esc_attr( $val ) . '" class="elizabeth-input" placeholder="Ej: a1b2c3d4-e5f6-..." />';
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 12px; margin-top: 5px;">Tu User ID del dashboard SaaS. Encuéntralo en <strong>Sitios → Plugin WordPress & Credenciales → API Base de Conocimiento</strong>.</p>';
    }

    public function license_key_callback() {
        $val = get_option( 'ai_sales_agent_license_key', '' );
        echo '<input type="password" id="ai_sales_agent_license_key" name="ai_sales_agent_license_key" value="' . esc_attr( $val ) . '" class="elizabeth-input" placeholder="••••••••••••••••" />';
        echo '<p style="color: var(--elizabeth-text-muted); font-size: 12px; margin-top: 5px;">La clave de activación vinculada a tu suscripción.</p>';
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
                <a href="https://ejemplo-saas-dashboard.com" target="_blank" style="color: var(--elizabeth-primary); font-weight: 500; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    Ir al Dashboard Externo
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                        <polyline points="15 3 21 3 21 9"></polyline>
                        <line x1="10" y1="14" x2="21" y2="3"></line>
                    </svg>
                </a>
            </div>
        </div>
        <?php
    }
}

