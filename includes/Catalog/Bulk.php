<?php
/**
 * La vinculación masiva.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Catalog;

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Linking\State;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Transport\Catalog;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * Vincula los pares sin ambigüedad —los "con sugerencia"—, en tandas de 20, revalidando cada
 * uno en el momento (ADR-014 §6).
 */
final class Bulk {

	/**
	 * Pares por tanda.
	 */
	public const BATCH = 20;

	/**
	 * Lo que la masiva va a vincular: los pares con sugerencia, sin descartar. Los ambiguos
	 * —SKU duplicado— quedan aparte, para resolver de a uno.
	 *
	 * @return array{pairs: array<int, array{local: int, remote: int}>, ambiguous: int}
	 */
	public static function preview(): array {
		$pairs     = array();
		$ambiguous = 0;

		foreach ( Rows::all() as $row ) {
			if ( Rows::SUGGESTION === $row['motive'] ) {
				$pairs[] = array(
					'local'  => (int) $row['id'],
					'remote' => (int) $row['suggestion']['id'],
				);
			} elseif ( Rows::DUPLICATE_SKU === $row['motive'] ) {
				++$ambiguous;
			}
		}

		return array(
			'pairs'     => $pairs,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * Una tanda. Cada par se vuelve a validar con los dos lados frescos —los de allá pedidos
	 * con `include`—, y se vincula por separado: uno que falla no corta la tanda. Reintentar no
	 * duplica: uno ya vinculado vuelve como omitido.
	 *
	 * @param array<int, array{local: int, remote: int}> $pairs Hasta 20.
	 *
	 * @return array{linked: array<int, array<string, mixed>>, omitted: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function link( array $pairs ) {
		if ( count( $pairs ) > self::BATCH ) {
			return new \WP_Error( 'wss_bad_request', __( 'Se vinculan hasta 20 pares por tanda.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$fresh = Catalog::fetch( 1, Catalog::MAX_INCLUDE, array_column( $pairs, 'remote' ) );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$remote = array_column( $fresh['products'], null, 'id' );
		$rows   = array_column( Rows::all(), null, 'id' );
		$result = array(
			'linked'  => array(),
			'omitted' => array(),
		);

		foreach ( $pairs as $pair ) {
			$local  = (int) $pair['local'];
			$other  = (int) $pair['remote'];
			$reason = self::refusal( $rows[ $local ] ?? null, $remote[ $other ] ?? null );
			$linked = null;

			if ( null === $reason ) {
				$linked = Linker::link( $local, $other, Detector::stock_in_db( $local ), (int) $remote[ $other ]['stock'], __( 'vinculación masiva', 'woonderful-stock-sync-for-woocommerce' ) );
				$reason = is_wp_error( $linked ) ? (string) $linked->get_error_code() : null;
			}

			if ( null === $reason ) {
				$result['linked'][] = array(
					'local'  => $local,
					'remote' => $other,
					'result' => $linked,
				);

				continue;
			}

			$result['omitted'][] = array(
				'local'  => $local,
				'remote' => $other,
				'reason' => $reason,
				'text'   => Events::reason_text( $reason ),
			);
		}

		return $result;
	}

	/**
	 * Por qué no se vincula un par, mirado en el momento; null si se puede.
	 *
	 * @param array<string, mixed>|null $row   La fila de acá, recalculada.
	 * @param array<string, mixed>|null $other El de allá, recién pedido.
	 */
	private static function refusal( ?array $row, ?array $other ): ?string {
		if ( null === $row ) {
			return 'product_missing';
		}

		if ( State::UNLINKED !== $row['state'] ) {
			return 'already_linked';
		}

		if ( ! empty( $row['unlinking'] ) ) {
			return 'peer_unlinking';
		}

		if ( in_array( $row['motive'], array( Rows::DISCARDED, Rows::DUPLICATE_SKU ), true ) ) {
			return $row['motive'];
		}

		if ( null === $other ) {
			return 'target_missing';
		}

		if ( 'publish' !== $row['status'] || 'publish' !== $other['status'] ) {
			return 'not_published';
		}

		if ( null === $other['stock'] || 'variable' === ( $other['type'] ?? '' ) ) {
			return 'target_not_managing_stock';
		}

		if ( $other['linked'] ) {
			$peer = Peers::only();

			// Allá vinculado y acá con nada que lo apunte: un desvincular que todavía viaja.
			return null !== $peer && null === Links::for_remote_product( $peer['store'], (int) $other['id'] ) ? 'peer_unlinking' : 'already_linked';
		}

		if ( Rows::normalize( (string) $row['sku'] ) !== Rows::normalize( (string) $other['sku'] ) || '' === Rows::normalize( (string) $row['sku'] ) ) {
			return 'sku_changed';
		}

		return null;
	}
}
