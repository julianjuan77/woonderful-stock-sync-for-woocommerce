<?php
/**
 * La reconciliación, del lado de la tienda que la lanza.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Rest\Reconcile;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * Lanza una reconciliación: toma el candado, le hace las preguntas a la otra tienda y guarda
 * cómo terminó (ADR-009).
 *
 * Los tres disparadores —la noche, la manual y el cierre de una importación— llaman a lo
 * mismo; quién puede lanzarla lo decide cada uno.
 */
final class Reconciler {

	/**
	 * Lanza una reconciliación.
	 *
	 * Con el candado tomado no hace nada ni guarda nada: la manual lo informa, y las
	 * automáticas se saltean sin ruido (ADR-009 §4).
	 *
	 * @param string $trigger Quién la lanza: una de las constantes de `Result`.
	 *
	 * @return array{outcome: string, details: array<string, mixed>}
	 */
	public static function run( string $trigger ): array {
		$peer = Peers::only();

		if ( null === $peer ) {
			return self::finish( $trigger, Result::UNREACHABLE, array( 'error' => 'wss_not_paired' ) );
		}

		$lock = Lock::acquire();

		if ( null === $lock ) {
			return array(
				'outcome' => Result::BUSY,
				'details' => array(),
			);
		}

		try {
			return self::reconcile( $peer, $trigger );
		} finally {
			Lock::release( $lock );
		}
	}

	/**
	 * Las preguntas, en orden.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer    La otra tienda.
	 * @param string                                                               $trigger Quién la lanzó.
	 *
	 * @return array{outcome: string, details: array<string, mixed>}
	 */
	private static function reconcile( array $peer, string $trigger ): array {
		$details = array(
			'session'        => wp_generate_uuid4(),
			'reopened'       => 0,
			'confirmed'      => 0,
			'peer_reopened'  => 0,
			'peer_confirmed' => 0,
		);

		$ceiling      = Books::ceiling();
		$peer_ceiling = self::ask_theirs( $peer, $ceiling, $details );

		// Si la otra no respondió la primera pregunta, no corre ninguna fase en ninguna tienda
		// (ADR-010 §7).
		if ( is_wp_error( $peer_ceiling ) ) {
			return self::failed( $trigger, $peer_ceiling, 'theirs', $details );
		}

		$failure = self::the_rest( $peer, $ceiling, $peer_ceiling, $details );

		// La fase 2 propia corre aunque la otra se haya caído después: la cuenta es local.
		$details['audit'] = Audit::run();

		if ( null !== $failure ) {
			return self::failed( $trigger, $failure[0], $failure[1], $details );
		}

		return self::finish( $trigger, Result::DONE, $details );
	}

	/**
	 * La segunda pregunta y los vínculos.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer         La otra tienda.
	 * @param int                                                                  $ceiling      El techo propio.
	 * @param int                                                                  $peer_ceiling El de la otra.
	 * @param array<string, mixed>                                                 $details      Lo que se va haciendo.
	 *
	 * @return array{0: \WP_Error, 1: string}|null Por qué y dónde se cortó, o null si no.
	 */
	private static function the_rest( array $peer, int $ceiling, int $peer_ceiling, array &$details ): ?array {
		$sent = self::tell_mine( $peer, $peer_ceiling, $details );

		if ( is_wp_error( $sent ) ) {
			return array( $sent, 'mine' );
		}

		// Los vínculos, sólo si lanza la maestra: cuando lanza la secundaria —el cierre de una
		// importación— los cubre la noche (ADR-009 §8).
		if ( Peers::this_is_master() ) {
			$compared = self::compare_links( $peer, $ceiling, $details );

			if ( is_wp_error( $compared ) ) {
				return array( $compared, 'links' );
			}
		}

		return null;
	}

	/**
	 * La primera pregunta: "¿qué tenés de lo mío?", hasta el techo propio. Lo que la otra no
	 * tiene se reencola; lo que tiene y acá no se sabía, se confirma.
	 *
	 * Cada tanda trae la marca de purga de la otra leída junto con su lista, y se compara con
	 * esa: si la otra purga en el medio de la pasada, la tanda siguiente ya trae la marca nueva.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer    La otra tienda.
	 * @param int                                                                  $ceiling El techo propio.
	 * @param array<string, mixed>                                                 $details Lo que se va haciendo.
	 *
	 * @return int|\WP_Error El techo de la otra, o por qué no se pudo.
	 */
	private static function ask_theirs( array $peer, int $ceiling, array &$details ) {
		$after        = 0;
		$peer_ceiling = null;

		do {
			$answer = Remote::ask(
				$peer,
				array(
					'step'  => Reconcile::THEIRS,
					'after' => $after,
					'until' => $ceiling,
				)
			);

			if ( is_wp_error( $answer ) ) {
				return $answer;
			}

			if ( ! self::is_page( $answer, $after, $ceiling ) ) {
				return new \WP_Error( 'wss_bad_answer', 'theirs' );
			}

			$done = Books::compare_own( $after, (int) $answer['covered_to'], $answer['seqs'], (int) $answer['purged_through'] );

			$details['reopened']  += $done['reopened'];
			$details['confirmed'] += $done['confirmed'];
			$peer_ceiling          = $peer_ceiling ?? (int) $answer['ceiling'];
			$after                 = (int) $answer['covered_to'];
		} while ( true === $answer['more'] );

		return $peer_ceiling;
	}

	/**
	 * La segunda pregunta: "esto tengo de lo tuyo", hasta el techo de la otra.
	 *
	 * La marca de purga propia se lee una vez: con el candado tomado, esta tienda no purga
	 * mientras reconcilia (ADR-009 §4).
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer         La otra tienda.
	 * @param int                                                                  $peer_ceiling Su techo.
	 * @param array<string, mixed>                                                 $details      Lo que se va haciendo.
	 *
	 * @return true|\WP_Error
	 */
	private static function tell_mine( array $peer, int $peer_ceiling, array &$details ) {
		$mark  = Peers::purged_through( $peer['store'] );
		$after = 0;

		do {
			$page   = Books::held_from( $peer['store'], $after, $peer_ceiling );
			$answer = Remote::ask(
				$peer,
				array(
					'step'           => Reconcile::MINE,
					'after'          => $after,
					'until'          => $page['covered_to'],
					'seqs'           => $page['seqs'],
					'purged_through' => $mark,
					'session'        => $details['session'],
				)
			);

			if ( is_wp_error( $answer ) ) {
				return $answer;
			}

			$details['peer_reopened']  += (int) ( $answer['reopened'] ?? 0 );
			$details['peer_confirmed'] += (int) ( $answer['confirmed'] ?? 0 );
			$after                      = $page['covered_to'];
		} while ( $page['more'] );

		return true;
	}

	/**
	 * Los vínculos: la maestra le pasa los suyos hasta su techo, y la secundaria compara.
	 *
	 * Lo que falta allá y es de un bootstrap todavía sin resolver no se informa: todavía no
	 * se sabe si la otra lo armó, y eso lo averigua la pregunta de ADR-008 §5.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer    La otra tienda.
	 * @param int                                                                  $ceiling El techo propio.
	 * @param array<string, mixed>                                                 $details Lo que se va haciendo.
	 *
	 * @return true|\WP_Error
	 */
	private static function compare_links( array $peer, int $ceiling, array &$details ) {
		$links  = Books::links_with( $peer['store'], $ceiling );
		$answer = Remote::ask(
			$peer,
			array(
				'step'    => Reconcile::LINKS,
				'ceiling' => $ceiling,
				'links'   => $links,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		// La otra contesta generaciones; el dueño reconoce productos.
		$product_of = array_column( $links, 'local', 'generation' );
		$unresolved = array_flip( Books::unresolved_generations() );
		$missing    = array();

		foreach ( (array) ( $answer['missing'] ?? array() ) as $generation ) {
			$generation = (int) $generation;

			if ( ! isset( $unresolved[ $generation ] ) && isset( $product_of[ $generation ] ) ) {
				$missing[] = (int) $product_of[ $generation ];
			}
		}

		$details['peer_unlinked']   = array_map( 'intval', (array) ( $answer['deleted'] ?? array() ) );
		$details['peer_missing']    = $missing;
		$details['peer_mismatched'] = array_map( 'intval', (array) ( $answer['mismatched'] ?? array() ) );

		return true;
	}

	/**
	 * Si una tanda de la primera pregunta trae lo que tiene que traer, y avanza.
	 *
	 * Una que dijera "hay más" sin avanzar dejaría la pasada dando vueltas para siempre.
	 *
	 * @param array<string, mixed> $answer  La respuesta.
	 * @param int                  $after   Desde dónde se pidió.
	 * @param int                  $ceiling Hasta dónde se pidió.
	 */
	private static function is_page( array $answer, int $after, int $ceiling ): bool {
		if ( ! is_array( $answer['seqs'] ?? null ) || ! array_is_list( $answer['seqs'] )
			|| ! is_int( $answer['covered_to'] ?? null ) || ! is_bool( $answer['more'] ?? null )
			|| ! is_int( $answer['purged_through'] ?? null ) || ! is_int( $answer['ceiling'] ?? null )
		) {
			return false;
		}

		foreach ( $answer['seqs'] as $seq ) {
			if ( ! is_int( $seq ) ) {
				return false;
			}
		}

		$advances = $answer['covered_to'] > $after && $answer['covered_to'] <= $ceiling;
		$empty    = ! $answer['more'] && $answer['covered_to'] === $after;

		return $advances || $empty;
	}

	/**
	 * La pasada se cortó: la otra no respondió, contestó mal o está ocupada.
	 *
	 * Lo que ya se comparó queda: cada tanda es independiente (ADR-009 §6). Si estaba ocupada
	 * desde la primera pregunta, no se hizo nada y no se guarda nada, como con el candado
	 * propio tomado.
	 *
	 * @param string               $trigger Quién la lanzó.
	 * @param \WP_Error            $error   Por qué.
	 * @param string               $stage   En qué pregunta.
	 * @param array<string, mixed> $details Lo que se hizo hasta ahí.
	 *
	 * @return array{outcome: string, details: array<string, mixed>}
	 */
	private static function failed( string $trigger, \WP_Error $error, string $stage, array $details ): array {
		$busy = 'wss_reconciling' === $error->get_error_code();

		$details['stage'] = $stage;
		$details['error'] = (string) $error->get_error_code();

		if ( $busy && 'theirs' === $stage && 0 === $details['reopened'] + $details['confirmed'] ) {
			return array(
				'outcome' => Result::BUSY,
				'details' => $details,
			);
		}

		return self::finish( $trigger, $busy ? Result::BUSY : Result::UNREACHABLE, $details );
	}

	/**
	 * Guarda cómo terminó, y lo devuelve.
	 *
	 * @param string               $trigger Quién la lanzó.
	 * @param string               $outcome Cómo terminó.
	 * @param array<string, mixed> $details Lo que hizo.
	 *
	 * @return array{outcome: string, details: array<string, mixed>}
	 */
	private static function finish( string $trigger, string $outcome, array $details ): array {
		Result::save( $trigger, $outcome, $details );

		return array(
			'outcome' => $outcome,
			'details' => $details,
		);
	}
}
