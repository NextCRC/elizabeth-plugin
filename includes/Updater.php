<?php
/**
 * Maneja las actualizaciones automáticas del plugin desde el servidor propio.
 */

namespace Andana\Elizabeth;

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Updater {

    private const UPDATE_ENDPOINT   = 'https://mvzapxphslinrmqcsavp.supabase.co/functions/v1/plugin-update-check';
    private const SUPABASE_HOST     = 'mvzapxphslinrmqcsavp.supabase.co';
    private const PLUGIN_SLUG     = 'elizabeth-customer-service';
    private const PLUGIN_FILE     = 'elizabeth-customer-service/ai-sales-agent.php';
    private const CACHE_KEY       = 'elizabeth_update_info';
    private const HASH_KEY        = 'elizabeth_update_hash';
    private const CACHE_TTL       = 43200; // 12 horas

    public function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'purge_cache' ], 10, 2 );
        add_filter( 'upgrader_pre_download', [ $this, 'verify_package_integrity' ], 10, 3 );
    }

    /**
     * Descarga el paquete, verifica su hash SHA-256 y devuelve la ruta al ZIP temporal.
     * Si el hash no coincide, aborta con WP_Error antes de que WordPress instale nada.
     */
    public function verify_package_integrity( $reply, $package, $upgrader ) {
        $expected_hash = get_transient( self::HASH_KEY );

        // Solo interceptar nuestro paquete: hash almacenado Y URL del proyecto específico.
        if ( ! $expected_hash || false === strpos( $package, self::SUPABASE_HOST ) ) {
            return $reply;
        }

        $tmp_file = download_url( $package );
        if ( is_wp_error( $tmp_file ) ) {
            return $tmp_file;
        }

        $actual_hash = hash_file( 'sha256', $tmp_file );
        if ( ! hash_equals( $expected_hash, $actual_hash ) ) {
            @unlink( $tmp_file );
            return new \WP_Error(
                'elizabeth_integrity_check_failed',
                __( 'La verificación de integridad del paquete de actualización falló. La actualización fue cancelada por seguridad.', 'elizabeth-customer-service' )
            );
        }

        return $tmp_file;
    }

    /**
     * Inyecta los datos de actualización en el transient de WordPress.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote || ! isset( $remote->version ) ) {
            return $transient;
        }

        if ( version_compare( AI_SALES_AGENT_VERSION, $remote->version, '<' ) ) {
            $transient->response[ self::PLUGIN_FILE ] = (object) [
                'slug'         => self::PLUGIN_SLUG,
                'plugin'       => self::PLUGIN_FILE,
                'new_version'  => $remote->version,
                'url'          => 'https://elizabeth.nextcrc.com',
                'package'      => $remote->download_url,
                'requires'     => $remote->requires     ?? '6.0',
                'tested'       => $remote->tested       ?? '6.7',
                'requires_php' => $remote->requires_php ?? '7.4',
            ];
        } else {
            $transient->no_update[ self::PLUGIN_FILE ] = (object) [
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => self::PLUGIN_FILE,
                'new_version' => $remote->version,
                'url'         => 'https://elizabeth.nextcrc.com',
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Provee la información del plugin para el popup "Ver detalles" de WordPress.
     */
    public function plugin_info( $response, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $response;
        }
        if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $response;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote ) {
            return $response;
        }

        return (object) [
            'name'          => 'Elizabeth - Customer Service',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $remote->version,
            'author'        => '<a href="https://nextcrc.com">NextCRC</a>',
            'homepage'      => 'https://elizabeth.nextcrc.com',
            'requires'      => $remote->requires     ?? '6.0',
            'tested'        => $remote->tested       ?? '6.7',
            'requires_php'  => $remote->requires_php ?? '7.4',
            'download_link' => $remote->download_url,
            'sections'      => [
                'changelog' => nl2br( esc_html( $remote->changelog ?? '' ) ),
            ],
        ];
    }

    /**
     * Limpia el caché después de una actualización exitosa.
     */
    public function purge_cache( $upgrader, $options ): void {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( self::CACHE_KEY );
            delete_transient( self::HASH_KEY );
        }
    }

    /**
     * Obtiene la información de la versión remota (con caché de 12h).
     */
    private function get_remote_info(): ?object {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $license_key = get_option( 'ai_sales_agent_license_key', '' );
        $user_id     = get_option( 'ai_sales_agent_user_id', '' );

        if ( empty( $license_key ) || empty( $user_id ) ) {
            return null;
        }

        $response = wp_remote_post( self::UPDATE_ENDPOINT, [
            'timeout'   => 10,
            'sslverify' => false,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'license_key' => $license_key,
                'user_id'     => $user_id,
            ] ),
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $data || ! isset( $data->version ) ) {
            return null;
        }

        if ( isset( $data->download_hash ) && preg_match( '/^[a-f0-9]{64}$/', $data->download_hash ) ) {
            set_transient( self::HASH_KEY, $data->download_hash, self::CACHE_TTL );
        }

        // Validar tipos de los campos críticos antes de cachear.
        if ( ! isset( $data->version ) || ! is_string( $data->version ) ) {
            return null;
        }
        if ( isset( $data->download_url ) && ! filter_var( $data->download_url, FILTER_VALIDATE_URL ) ) {
            return null;
        }
        if ( isset( $data->requires ) && ! is_string( $data->requires ) ) {
            $data->requires = '6.0';
        }
        if ( isset( $data->tested ) && ! is_string( $data->tested ) ) {
            $data->tested = '6.7';
        }

        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }
}
