<?php
/**
 * El namespace REST del admin.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Admin;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * `wss-admin/v1`: lo que llama la app, en el navegador del dueño (ADR-013 §4).
 *
 * Separado de `wss/v1`, que llama la otra tienda: éste se autentica con la sesión de WordPress
 * y su nonce, aquél con la firma. Cada endpoint pide `manage_woocommerce`, y llama a las mismas
 * clases que WP-CLI: la pantalla no tiene lógica propia.
 */
final class Api {

	/**
	 * El namespace.
	 */
	public const NAMESPACE = 'wss-admin/v1';

	/**
	 * Engancha el registro de las rutas.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registra las rutas.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * El permiso de cada endpoint: `manage_woocommerce`, verificado en el servidor (ADR-013 §5).
	 */
	public static function can_manage(): bool {
		return current_user_can( Page::CAPABILITY );
	}

	/**
	 * Lo que la app necesita al cargar: esta tienda, la otra, y si está emparejada.
	 *
	 * Lo de Resumen se completa en la tarea 8.2.
	 */
	public static function status(): \WP_REST_Response {
		$peer  = Peers::only();
		$state = Peer::current();

		return new \WP_REST_Response(
			array(
				'store'     => array(
					'label'     => Store::label(),
					'master'    => Peers::this_is_master(),
					'suspended' => Plugin::suspension_reason(),
				),
				'paired'    => null !== $peer,
				'peer'      => array(
					'state' => $state['state'],
					'since' => Peer::OK === $state['state'] ? null : Events::local_time( gmdate( 'Y-m-d H:i:s', $state['since'] ) ),
					'error' => $state['error'],
				),
			),
			200
		);
	}
}
