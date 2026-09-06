<?php
/**
 * TC-01 a TC-07 — el libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

use InvalidArgumentException;
use Woonderful\StockSync\Db\MovementRepository;
use Woonderful\StockSync\Db\Schema;

class MovementRepositoryTest extends TestCase {

	/** TC-01 — el mismo (origin_store, sequence) dos veces se guarda una sola vez. */
	public function test_un_movimiento_repetido_se_registra_una_sola_vez(): void {
		global $wpdb;

		$primero = $this->movements->record( $this->movement( array( 'sequence' => 7 ) ) );
		$segundo = $this->movements->record( $this->movement( array( 'sequence' => 7 ) ) );

		$this->assertGreaterThan( 0, $primero, 'El primero tiene que insertarse.' );
		$this->assertSame( 0, $segundo, 'El duplicado devuelve 0, no lanza.' );

		$table = Schema::movements_table();
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
	}

	/** TC-01b — el índice único es por tienda: otra tienda puede usar la misma secuencia. */
	public function test_la_misma_secuencia_de_otra_tienda_no_choca(): void {
		$a = $this->movements->record( $this->movement( array( 'origin_store' => 'tienda-a', 'sequence' => 7 ) ) );
		$b = $this->movements->record( $this->movement( array( 'origin_store' => 'tienda-b', 'sequence' => 7 ) ) );

		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b );
		$this->assertNotSame( $a, $b );
	}

	/** TC-02 — una razón desconocida se rechaza en el borde y no escribe nada. */
	public function test_una_razon_desconocida_se_rechaza(): void {
		global $wpdb;

		$this->expectException( InvalidArgumentException::class );

		try {
			$this->movements->record( $this->movement( array( 'reason' => 'inventado' ) ) );
		} finally {
			$table = Schema::movements_table();
			$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
		}
	}

	/** TC-03 — dos claims sobre el mismo movimiento: solo uno gana. */
	public function test_dos_claims_sobre_el_mismo_movimiento_solo_uno_gana(): void {
		$id = $this->movements->record( $this->movement() );

		$this->assertTrue( $this->movements->claim( $id ), 'El primero se lo lleva.' );
		$this->assertFalse( $this->movements->claim( $id ), 'El segundo llega tarde.' );
	}

	/** TC-04 — un claim vencido se puede retomar; uno reciente no. */
	public function test_un_claim_vencido_se_puede_retomar(): void {
		$id = $this->movements->record( $this->movement() );
		$this->movements->claim( $id );

		$this->assertFalse(
			$this->movements->claim( $id ),
			'Recién tomado: nadie más lo toca.'
		);

		$this->backdate_claim( $id, MovementRepository::STALE_CLAIM_SECONDS + 60 );

		$this->assertTrue(
			$this->movements->claim( $id ),
			'Vencido: es un job muerto y se retoma.'
		);
	}

	/** TC-05 — pending() trae lo pendiente y los claims vencidos, no los recientes. */
	public function test_pending_incluye_los_claims_vencidos(): void {
		$pendiente = $this->movements->record( $this->movement( array( 'sequence' => 1 ) ) );

		$reciente = $this->movements->record( $this->movement( array( 'sequence' => 2 ) ) );
		$this->movements->claim( $reciente );

		$ids = wp_list_pluck( $this->movements->pending(), 'id' );
		$this->assertSame( array( (string) $pendiente ), $ids, 'El reciente no es trabajo disponible.' );

		$this->backdate_claim( $reciente, MovementRepository::STALE_CLAIM_SECONDS + 60 );

		$ids = wp_list_pluck( $this->movements->pending(), 'id' );
		$this->assertCount( 2, $ids, 'Muerto el job, su movimiento vuelve a estar disponible.' );
	}

	/** TC-06 — cerrar un movimiento que no se tomó falla en vez de pasar desapercibido. */
	public function test_cerrar_un_movimiento_no_tomado_devuelve_false(): void {
		global $wpdb;

		$id = $this->movements->record( $this->movement() );

		$this->assertFalse( $this->movements->mark_applied( $id ) );

		$table  = Schema::movements_table();
		$estado = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $id ) );
		$this->assertSame( MovementRepository::STATUS_PENDING, $estado, 'Sigue pendiente.' );
	}

	/** TC-07 — la suma cuenta lo aplicado e ignora lo pendiente. */
	public function test_sum_applied_after_ignora_lo_pendiente(): void {
		$this->apply_movement( array( 'sequence' => 1, 'delta' => -3 ) );
		$this->apply_movement( array( 'sequence' => 2, 'delta' => -2 ) );
		$this->movements->record( $this->movement( array( 'sequence' => 3, 'delta' => -100 ) ) );

		$this->assertSame( -5, $this->movements->sum_applied_after( 'SKU-1', 0 ) );
	}

	/** TC-07b — el corte por ID excluye lo ya consolidado. */
	public function test_sum_applied_after_respeta_el_corte(): void {
		$primero = $this->apply_movement( array( 'sequence' => 1, 'delta' => -3 ) );
		$this->apply_movement( array( 'sequence' => 2, 'delta' => -2 ) );

		$this->assertSame( -2, $this->movements->sum_applied_after( 'SKU-1', $primero ) );
	}
}
