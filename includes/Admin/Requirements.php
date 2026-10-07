<?php
/**
 * Lo que el plugin necesita para funcionar.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Admin;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Express;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Pass;

defined( 'ABSPATH' ) || exit;

/**
 * Los requisitos del primer paso de la configuración inicial, cada uno con su resultado, si es
 * crítico y cómo arreglarlo (ADR-013 §3). Los críticos no dejan avanzar.
 */
final class Requirements {

	/**
	 * La versión mínima de WooCommerce (SPEC §5).
	 */
	public const WOOCOMMERCE = '9.1';

	/**
	 * Hace cuánto tiene que haber corrido la pasada, en segundos: corre cada minuto.
	 */
	public const PASS_RECENT = 600;

	/**
	 * Todos, en orden.
	 *
	 * @return array<int, array{id: string, label: string, critical: bool, pass: bool|null, value: string|null, fix: string|null}>
	 */
	public static function check(): array {
		$wc = function_exists( 'WC' ) ? (string) WC()->version : null;

		$unhealthy = Installer::unhealthy_tables();
		$rest      = self::rest_answers();
		$ran       = self::pass_ran_at();
		$finisher  = Express::finisher();

		return array(
			self::item(
				'versions',
				__( 'PHP 8.1 o más, y WooCommerce 9.1 o más activo', 'woonderful-stock-sync-for-woocommerce' ),
				true,
				// El de PHP no se compara: con un PHP menor, WordPress no activa el plugin (`Requires PHP`).
				null !== $wc && self::at_least( $wc, self::WOOCOMMERCE ),
				'PHP ' . PHP_VERSION . ' · WooCommerce ' . ( $wc ?? '—' ),
				__( 'Actualizá PHP desde el panel del hosting, o WooCommerce desde Plugins.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'tables',
				__( 'Las tablas del plugin creadas y en InnoDB', 'woonderful-stock-sync-for-woocommerce' ),
				true,
				array() === $unhealthy,
				array() === $unhealthy ? null : implode( ', ', $unhealthy ),
				__( 'Desactivá y volvé a activar el plugin. Si siguen sin InnoDB, pedile al hosting que lo habilite: sin transacciones, el stock queda mal contado.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'not_a_copy',
				__( 'No es una copia de otro sitio', 'woonderful-stock-sync-for-woocommerce' ),
				true,
				! Store::is_cloned_site(),
				null,
				__( 'Este sitio es una copia de la tienda emparejada —una restauración, un staging—: no sincroniza. Si es la tienda real que cambió de dirección, emparejala de nuevo.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'https',
				__( 'HTTPS, salvo en un entorno local', 'woonderful-stock-sync-for-woocommerce' ),
				true,
				Pairing::is_secure( (string) get_option( 'home' ) ),
				(string) get_option( 'home' ),
				__( 'Instalá un certificado y pasá la tienda a https antes de emparejar: pasar después suspendería la sincronización.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'rest',
				__( 'La API REST responde desde afuera', 'woonderful-stock-sync-for-woocommerce' ),
				true,
				null === $rest,
				$rest,
				__( 'Algo bloquea la API REST: un plugin de seguridad, el firewall del hosting o una regla del servidor. La otra tienda le habla a /wp-json/wss/v1/: dejala pasar.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'pass',
				__( 'La pasada corrió en los últimos minutos', 'woonderful-stock-sync-for-woocommerce' ),
				false,
				null !== $ran && time() - $ran <= self::PASS_RECENT,
				null === $ran ? __( 'nunca', 'woonderful-stock-sync-for-woocommerce' ) : (string) wp_date( 'Y-m-d H:i', $ran ),
				__( 'Sin visitas, WP-Cron no la dispara: configurá un cron real del sistema que llame a wp-cron.php cada minuto.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'finisher',
				__( 'PHP-FPM o LiteSpeed', 'woonderful-stock-sync-for-woocommerce' ),
				false,
				null !== $finisher,
				$finisher,
				__( 'Sin eso, una venta espera a la pasada —hasta un minuto— en vez de salir al terminar la compra. Funciona igual; con PHP-FPM es más rápido.', 'woonderful-stock-sync-for-woocommerce' )
			),
			self::item(
				'mail',
				__( 'El correo sale', 'woonderful-stock-sync-for-woocommerce' ),
				false,
				null,
				null,
				__( 'No se puede saber sin probar: mandá un email de prueba. Si no llega, el hosting no manda correo y no vas a recibir avisos: configurá un SMTP.', 'woonderful-stock-sync-for-woocommerce' )
			),
		);
	}

	/**
	 * Por qué la API REST no responde, o null si responde.
	 *
	 * Se le pide a la propia tienda `GET /health` sin firma: un `wss_` en la respuesta es este
	 * plugin rechazando, o sea que el pedido llegó. Cualquier otra cosa —un HTML, un 403 de
	 * otro plugin, un error de red— es que algo en el medio lo bloquea.
	 */
	private static function rest_answers(): ?string {
		$response = wp_remote_get(
			rest_url( 'wss/v1/health' ),
			array(
				'timeout'     => Pairing::CALLBACK_TIMEOUT,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code    = is_array( $payload ) && is_string( $payload['code'] ?? null ) ? $payload['code'] : '';

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) || str_starts_with( $code, 'wss_' ) ) {
			return null;
		}

		return trim( 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ' ' . $code );
	}

	/**
	 * Cuándo corrió la pasada por última vez, según Action Scheduler, o null si nunca.
	 */
	private static function pass_ran_at(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX( last_attempt_gmt ) FROM {$table} WHERE hook = %s AND status = 'complete'", Pass::HOOK ) );

		return is_string( $last ) && '' !== $last && '0000-00-00 00:00:00' !== $last ? (int) strtotime( $last . ' UTC' ) : null;
	}

	/**
	 * Si una versión llega al mínimo. Aparte, para que el análisis estático no la evalúe con la
	 * versión fija de sus stubs.
	 *
	 * @param string $version La versión.
	 * @param string $minimum El mínimo.
	 */
	private static function at_least( string $version, string $minimum ): bool {
		return version_compare( $version, $minimum, '>=' );
	}

	/**
	 * Un requisito.
	 *
	 * @param string      $id       El identificador.
	 * @param string      $label    Qué se pide.
	 * @param bool        $critical Si no deja avanzar.
	 * @param bool|null   $pass     Si se cumple; null si no se puede saber sin probar.
	 * @param string|null $value    Lo que se encontró.
	 * @param string      $fix      Cómo arreglarlo.
	 *
	 * @return array{id: string, label: string, critical: bool, pass: bool|null, value: string|null, fix: string|null}
	 */
	private static function item( string $id, string $label, bool $critical, ?bool $pass, ?string $value, string $fix ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'critical' => $critical,
			'pass'     => $pass,
			'value'    => $value,
			'fix'      => true === $pass ? null : $fix,
		);
	}
}
