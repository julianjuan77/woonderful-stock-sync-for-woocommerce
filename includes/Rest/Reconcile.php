<?php
/**
 * El endpoint de la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Books;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wss/v1/reconcile/digest`: contesta las preguntas de la reconciliación que lanzó la
 * otra tienda (ADR-009 §1).
 *
 * Cada pregunta viaja en `step`. Cada tanda es independiente: dice qué tramo cubre, y no
 * depende de la anterior.
 */
final class Reconcile {

	/**
	 * "¿Qué tenés de lo mío?": esta tienda lista lo que tiene de la otra.
	 */
	public const THEIRS = 'theirs';

	/**
	 * "Esto tengo de lo tuyo": esta tienda compara lo suyo contra esa lista.
	 */
	public const MINE = 'mine';

	/**
	 * "Estos son mis vínculos": la maestra se los pasa a la secundaria (ADR-009 §8).
	 */
	public const LINKS = 'links';

	/**
	 * "Hacé tu fase 2": esta tienda compara su stock con su libro, y contesta sus
	 * discrepancias abiertas (ADR-010 §7).
	 */
	public const AUDIT = 'audit';

	/**
	 * Engancha el registro de la ruta.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public static function register_routes(): void {
		register_rest_route(
			Movements::NAMESPACE,
			'/reconcile/digest',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'answer' ),
				'permission_callback' => array( Movements::class, 'authenticate' ),
			)
		);
	}

	/**
	 * Contesta una pregunta.
	 *
	 * Si esta tienda está reconciliando —tiene el candado—, contesta que está ocupada: hay una
	 * reconciliación por vez para el par (ADR-009 §4).
	 *
	 * @param \WP_REST_Request $request El request, ya autenticado.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function answer( \WP_REST_Request $request ) {
		if ( Lock::is_taken() ) {
			return new \WP_Error( 'wss_reconciling', __( 'Esta tienda está en medio de una reconciliación.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$payload = json_decode( $request->get_body(), true );
		$store   = (string) $request->get_header( 'X-WSS-Store' );

		if ( ! is_array( $payload ) ) {
			return self::bad_request();
		}

		switch ( $payload['step'] ?? null ) {
			case self::THEIRS:
				return self::theirs( $payload, $store );
			case self::MINE:
				return self::mine( $payload, $store );
			case self::LINKS:
				return self::links( $payload, $store );
			case self::AUDIT:
				return self::audit( $payload, $store );
		}

		return self::bad_request();
	}

	/**
	 * La primera pregunta: lo que esta tienda tiene de la otra, en un tramo.
	 *
	 * La lista se lee **antes** que la marca de purga. La purga borra filas y sube la marca en
	 * la misma transacción: leída después, la marca nunca queda por debajo de lo que falta en
	 * la lista, y la otra no reenvía algo que esta tienda tuvo y borró (ADR-009 §2).
	 *
	 * @param array<mixed, mixed> $payload El cuerpo.
	 * @param string              $store   La tienda que pregunta.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function theirs( array $payload, string $store ) {
		$after = $payload['after'] ?? null;
		$until = $payload['until'] ?? null;

		if ( ! is_int( $after ) || ! is_int( $until ) || $after < 0 || $until < $after ) {
			return self::bad_request();
		}

		$page = Books::held_from( $store, $after, $until );

		return new \WP_REST_Response(
			array(
				'seqs'           => $page['seqs'],
				'covered_to'     => $page['covered_to'],
				'more'           => $page['more'],
				'purged_through' => Peers::purged_through( $store ),
				'ceiling'        => Books::ceiling(),
			),
			200
		);
	}

	/**
	 * La segunda pregunta: lo que la otra tiene de esta tienda, en un tramo. Esta compara lo
	 * suyo, y anota lo que hizo en su resultado.
	 *
	 * @param array<mixed, mixed> $payload El cuerpo.
	 * @param string              $store   La tienda que pregunta.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function mine( array $payload, string $store ) {
		$after   = $payload['after'] ?? null;
		$until   = $payload['until'] ?? null;
		$seqs    = $payload['seqs'] ?? null;
		$mark    = $payload['purged_through'] ?? null;
		$session = $payload['session'] ?? null;

		if ( ! is_int( $after ) || ! is_int( $until ) || $after < 0 || $until < $after
			|| ! is_int( $mark ) || $mark < 0
			|| ! is_string( $session ) || '' === $session || strlen( $session ) > 64
			|| ! is_array( $seqs ) || ! array_is_list( $seqs ) || count( $seqs ) > Books::PAGE
		) {
			return self::bad_request();
		}

		foreach ( $seqs as $seq ) {
			if ( ! is_int( $seq ) || $seq < 1 ) {
				return self::bad_request();
			}
		}

		$done = Books::compare_own( $after, $until, $seqs, $mark );

		self::remember( $session, $store, $done );

		return new \WP_REST_Response( $done, 200 );
	}

	/**
	 * Los vínculos de la maestra: esta tienda —la secundaria— compara los suyos, borra los que
	 * no tienen respaldo e informa el resto.
	 *
	 * Sólo si quien pregunta es la maestra: borrar vínculos porque lo pide la secundaria
	 * dejaría a cualquiera de las dos desarmar a la otra.
	 *
	 * @param array<mixed, mixed> $payload El cuerpo.
	 * @param string              $store   La tienda que pregunta.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function links( array $payload, string $store ) {
		if ( ! Peers::is_master( $store ) ) {
			return new \WP_Error( 'wss_not_master', __( 'Los vínculos los compara la tienda maestra.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$links   = $payload['links'] ?? null;
		$ceiling = $payload['ceiling'] ?? null;

		if ( ! is_int( $ceiling ) || $ceiling < 0 || ! is_array( $links ) || ! array_is_list( $links ) ) {
			return self::bad_request();
		}

		$theirs = array();

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				return self::bad_request();
			}

			$local      = $link['local'] ?? null;
			$remote     = $link['remote'] ?? null;
			$generation = $link['generation'] ?? null;

			if ( ! is_int( $local ) || ! is_int( $remote ) || ! is_int( $generation ) || $local < 1 || $remote < 1 || $generation < 0 || $generation > $ceiling ) {
				return self::bad_request();
			}

			$theirs[] = array(
				'local'      => $local,
				'remote'     => $remote,
				'generation' => $generation,
			);
		}

		return new \WP_REST_Response( Books::compare_links( $store, $theirs, $ceiling ), 200 );
	}

	/**
	 * La tercera pregunta: la fase 2 de esta tienda, y sus discrepancias abiertas.
	 *
	 * Contesta las que ya estaban abiertas: las nuevas se confirman 2 minutos después, así que
	 * la otra las ve en la reconciliación siguiente (ADR-010 §7).
	 *
	 * @param array<mixed, mixed> $payload El cuerpo.
	 * @param string              $store   La tienda que pregunta.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function audit( array $payload, string $store ) {
		$session = $payload['session'] ?? null;

		if ( ! is_string( $session ) || '' === $session || strlen( $session ) > 64 ) {
			return self::bad_request();
		}

		$summary = Audit::run();

		// La tercera pregunta es la última: acá termina, del lado de esta tienda.
		Events::record(
			Events::RECONCILED,
			Events::INFO,
			null,
			array(
				'trigger' => Result::PEER,
				'outcome' => Result::DONE,
				'by'      => $store,
			)
		);

		self::remember(
			$session,
			$store,
			array(
				'reopened'  => 0,
				'confirmed' => 0,
			),
			$summary
		);

		return new \WP_REST_Response(
			array(
				'audit' => $summary,
				'open'  => Audit::open(),
			),
			200
		);
	}

	/**
	 * Suma lo que hizo esta tanda al resultado de esta tienda.
	 *
	 * Una reconciliación son varias tandas; esta tienda no sabe cuál es la última. Las de la
	 * misma reconciliación —el mismo `session`— se acumulan en un solo resultado.
	 *
	 * @param string                                                $session La reconciliación.
	 * @param string                                                $store   La tienda que la lanzó.
	 * @param array{reopened: int, confirmed: int}                  $done    Lo que hizo esta tanda.
	 * @param array{checked: int, closed: int, suspected: int}|null $audit   La fase 2, si es eso.
	 */
	private static function remember( string $session, string $store, array $done, ?array $audit = null ): void {
		$last    = Result::last();
		$details = array(
			'session'   => $session,
			'by'        => $store,
			'reopened'  => 0,
			'confirmed' => 0,
		);

		$same = null !== $last && Result::PEER === $last['trigger'] && ( $last['details']['session'] ?? null ) === $session;

		if ( $same ) {
			$details = array_merge( $details, $last['details'] );
		}

		$details['reopened']  = (int) $details['reopened'] + $done['reopened'];
		$details['confirmed'] = (int) $details['confirmed'] + $done['confirmed'];

		if ( null !== $audit ) {
			$details['audit'] = $audit;
		}

		Result::save( Result::PEER, Result::DONE, $details );
	}

	/**
	 * Un cuerpo que no se entiende.
	 */
	private static function bad_request(): \WP_Error {
		return new \WP_Error( 'wss_bad_request', __( 'El cuerpo no es una pregunta de reconciliación válida.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 400 ) );
	}
}
