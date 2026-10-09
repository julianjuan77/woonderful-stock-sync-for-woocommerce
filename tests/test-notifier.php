<?php
/**
 * Tests de los avisos por email.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * Lo que pasa cuando no hay nadie mirando, por email, sin inundar (ADR-012).
 */
class Test_Notifier extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Los emails que salieron.
	 *
	 * @var array<int, array{to: string, subject: string, message: string}>
	 */
	private array $mails = array();

	/**
	 * Si `wp_mail()` sale bien.
	 *
	 * @var bool
	 */
	private bool $mail_works = true;

	/**
	 * Lo que contesta la otra tienda a `GET /health`.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $health;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		foreach ( array( Peer::OPTION, Notifier::OPTION_STATE, Notifier::OPTION_ENABLED, Notifier::OPTION_EMAIL, Notifier::OPTION_AFTER ) as $option ) {
			delete_option( $option );
		}

		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => 'secreto-de-prueba',
					'role'     => Peers::SECONDARY,
				),
			),
			false
		);

		$this->health = new WP_Error( 'http_request_failed', 'no responde' );

		// Otros tests dejan productos confirmados por sus DROP TABLE: el resumen los contaría.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( 'product', 'product_variation' )" ); // phpcs:ignore WordPress.DB

		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'answer_health' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'answer_health' ) );

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		foreach ( array( Peer::OPTION, Notifier::OPTION_STATE, Notifier::OPTION_ENABLED, Notifier::OPTION_EMAIL, Notifier::OPTION_AFTER, Peers::OPTION ) as $option ) {
			delete_option( $option );
		}

		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Atrapa cada email en vez de mandarlo.
	 *
	 * @param null|bool            $short Lo que venía.
	 * @param array<string, mixed> $atts  El email.
	 */
	public function catch_mail( $short, array $atts ): bool {
		if ( $this->mail_works ) {
			$this->mails[] = array(
				'to'      => (string) $atts['to'],
				'subject' => (string) $atts['subject'],
				'message' => (string) $atts['message'],
			);
		}

		return $this->mail_works;
	}

	/**
	 * Contesta `GET /health` por la otra tienda.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function answer_health() {
		return $this->health;
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int $status El código.
	 *
	 * @return array<string, mixed>
	 */
	private function http( int $status ): array {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Un vínculo que falló solo, sin nadie mirando.
	 *
	 * @param int         $product El producto.
	 * @param string|null $cause   A qué se atribuye.
	 */
	private function background_failure( int $product, ?string $cause = null ): void {
		Events::record(
			Events::LINK_FAILED,
			Events::WARNING,
			$product,
			array(
				'reason'     => 'not_applied: unknown',
				'background' => true,
			),
			$cause
		);
	}

	/**
	 * Un producto publicado que lleva su stock, creado hace tantas horas.
	 *
	 * @param int  $hours  Hace cuántas horas.
	 * @param bool $manage Si lleva su stock.
	 */
	private function product( int $hours, bool $manage = true ): int {
		global $wpdb;

		$product = new WC_Product_Simple();
		$product->set_name( 'Alpargata ' . wp_rand() );
		$product->set_manage_stock( $manage );
		$product->set_stock_quantity( 5 );
		$id = (int) $product->save();

		$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * NOT-01 — **Un vínculo que falló solo sale en el momento**, con el nombre del plugin en el
	 * asunto, al administrador, y con la tienda y el panel al pie. Uno que falló mientras el
	 * dueño miraba, no.
	 */
	public function test_a_background_failure_is_mailed_at_once(): void {
		Events::record( Events::LINK_FAILED, Events::WARNING, 7, array( 'background' => false ) );
		Notifier::tick();

		$this->assertSame( array(), $this->mails, 'el dueño estaba mirando' );

		$this->background_failure( 8 );
		Notifier::tick();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Woonderful Stock Sync] ', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '(#8)', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Panel: ', $this->mails[0]['message'] );
	}

	/**
	 * NOT-02 — **La primera de una causa sale enseguida; lo que llega en los 10 minutos se
	 * junta en un solo email** al cerrarse la ventana (UC-08).
	 */
	public function test_a_burst_is_one_email_per_window(): void {
		$now = time();

		Events::record( Events::PAIR_BROKEN, Events::WARNING, 1, array( 'reason' => 'local_trashed' ) );
		Notifier::tick( $now );

		for ( $product = 2; $product <= 300; $product++ ) {
			Events::record( Events::PAIR_BROKEN, Events::WARNING, $product, array( 'reason' => 'local_trashed' ) );
		}

		Notifier::tick( $now + 60 );
		Notifier::tick( $now + 300 );

		$this->assertCount( 1, $this->mails, 'la ventana está abierta' );

		Notifier::tick( $now + Notifier::WINDOW );

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( '299', $this->mails[1]['subject'] );
		$this->assertStringContainsString( 'y otros 249', $this->mails[1]['message'] );
	}

	/**
	 * NOT-03 — **Lo que se atribuye a la caída o al rechazo de la otra no sale**: la caída se
	 * avisa una vez (ADR-012 §2).
	 */
	public function test_what_is_attributed_to_the_peer_is_not_mailed(): void {
		$this->background_failure( 8, Events::PEER_UNREACHABLE );
		Events::record( Events::LINK_UNRESOLVED, Events::WARNING, 9, array(), Events::PEER_REJECTED );

		Notifier::tick();

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * NOT-04 — Una causa no tapa a otra: cada una tiene su ventana.
	 */
	public function test_one_cause_does_not_silence_another(): void {
		$now = time();

		$this->background_failure( 8 );
		Notifier::tick( $now );
		Events::record( Events::PAIR_BROKEN, Events::WARNING, 1 );
		Notifier::tick( $now + 60 );

		$this->assertCount( 2, $this->mails );
	}

	/**
	 * NOT-05 — **La caída se avisa a los 30 minutos seguidos, una vez**; antes, no. Mientras no
	 * está bien, el tick le pregunta a la otra.
	 */
	public function test_an_outage_is_mailed_after_thirty_minutes_once(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'cURL error 28' ) );

		$now = Peer::current()['since'];

		Notifier::tick( $now + Notifier::PEER_AFTER - 60 );
		$this->assertSame( array(), $this->mails, 'un corte corto no avisa' );

		Notifier::tick( $now + Notifier::PEER_AFTER );
		Notifier::tick( $now + Notifier::PEER_AFTER + 60 );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'no responde', $this->mails[0]['subject'] );
	}

	/**
	 * NOT-06 — **Si la otra volvió, el tick se entera al preguntarle**, y no sale un aviso de
	 * caída falso aunque nadie le haya mandado nada.
	 */
	public function test_a_peer_that_came_back_is_not_mailed_as_down(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		$this->health = $this->http( 200 );

		Notifier::tick( time() + Notifier::PEER_AFTER );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( Peer::OK, Peer::current()['state'] );
	}

	/**
	 * NOT-07 — "Volvió" se avisa sólo si se avisó la caída.
	 */
	public function test_coming_back_is_mailed_only_after_a_mailed_outage(): void {
		$now = time();

		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		Notifier::tick( $now + Notifier::PEER_AFTER );
		$this->health = $this->http( 200 );
		Notifier::tick( $now + Notifier::PEER_AFTER + 60 );

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'volvió', $this->mails[1]['subject'] );
	}

	/**
	 * NOT-08 — El rechazo también se avisa a los 30 minutos, con su propio texto.
	 */
	public function test_a_rejection_is_mailed_after_thirty_minutes(): void {
		$this->health = $this->http( 401 );
		Peer::observe( $this->http( 401 ) );

		Notifier::tick( time() + Notifier::PEER_AFTER );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'rechaza', $this->mails[0]['subject'] );
	}

	/**
	 * NOT-09 — **Como mucho 10 por día**: al llegar sale uno último que remite al log, y
	 * después nada hasta el día siguiente (ADR-012 §5).
	 */
	public function test_there_is_a_daily_cap(): void {
		// A las 10 de la mañana del sitio: las pasadas del test no cruzan la medianoche.
		$now = ( new DateTimeImmutable( 'today 10:00', wp_timezone() ) )->getTimestamp();

		for ( $i = 0; $i < 15; $i++ ) {
			$this->background_failure( 100 + $i );
			Notifier::tick( $now + $i * Notifier::WINDOW );
		}

		$this->assertCount( Notifier::DAILY_CAP + 1, $this->mails );
		$this->assertStringContainsString( 'Muchos avisos', end( $this->mails )['subject'] );

		// Al día siguiente, la cuenta vuelve a cero.
		$this->background_failure( 200 );
		Notifier::tick( $now + DAY_IN_SECONDS );

		$this->assertCount( Notifier::DAILY_CAP + 2, $this->mails );
	}

	/**
	 * NOT-10 — **Apagado, no sale nada, y los errores no quedan para después**: al encender no
	 * llega lo de antes.
	 */
	public function test_disabled_sends_nothing_and_keeps_nothing(): void {
		$now = time();

		// Algo que quedó juntado en una ventana antes de apagar, y una vuelta pendiente.
		$this->background_failure( 7 );
		Notifier::tick( $now );
		$this->background_failure( 6 );
		Notifier::tick( $now + 60 );
		$this->assertCount( 1, $this->mails );

		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		Peer::mark_notified();
		Peer::observe( $this->http( 200 ) );

		update_option( Notifier::OPTION_ENABLED, 'no' );
		$this->background_failure( 8 );
		Notifier::tick( $now + 120 );
		$this->product( 30 );
		Notifier::digest( $now + 120 );

		update_option( Notifier::OPTION_ENABLED, 'yes' );
		Notifier::tick( $now + Notifier::WINDOW * 2 );

		$this->assertCount( 1, $this->mails, 'ni lo juntado, ni lo de apagado, ni la vuelta vieja, ni el resumen' );
	}

	/**
	 * NOT-11 — **Si el email no sale, queda en el log y se reintenta al cerrarse la ventana**,
	 * no cada minuto.
	 */
	public function test_a_failed_email_is_retried_per_window(): void {
		$now              = time();
		$this->mail_works = false;

		$this->background_failure( 8 );
		Notifier::tick( $now );
		Notifier::tick( $now + 60 );

		$this->assertCount( 1, Events::list( array( 'kind' => Events::NOTIFY_FAILED ) )['items'], 'un intento, no uno por minuto' );

		$this->mail_works = true;
		Notifier::tick( $now + 120 );
		$this->assertSame( array(), $this->mails, 'espera la ventana' );

		Notifier::tick( $now + Notifier::WINDOW );
		$this->assertCount( 1, $this->mails );
	}

	/**
	 * NOT-12 — El destinatario y el plazo se configuran.
	 */
	public function test_the_recipient_and_the_delay_are_settings(): void {
		update_option( Notifier::OPTION_EMAIL, 'deposito@example.org' );
		update_option( Notifier::OPTION_AFTER, 48 );

		$this->assertSame( 'deposito@example.org', Notifier::recipient() );
		$this->assertSame( 48, Notifier::after_hours() );

		$this->product( 30 );
		Notifier::digest();

		$this->assertSame( array(), $this->mails, '30 horas no llegan al plazo de 48' );
	}

	/**
	 * NOT-13 — **Lo que no sincroniza, en el resumen de la noche, una vez por producto**: lo
	 * vinculado, lo que no lleva su stock, lo descartado y lo nuevo no entran; la noche
	 * siguiente, si no hay nada nuevo, no sale nada (ADR-012 §3).
	 */
	public function test_the_nightly_digest_lists_each_unsynced_product_once(): void {
		$unsynced  = $this->product( 30 );
		$linked    = $this->product( 30 );
		$unmanaged = $this->product( 30, false );
		$discarded = $this->product( 30 );
		$young     = $this->product( 2 );

		Links::create( $linked, self::REMOTE_STORE, 900, 1, 5 );
		Events::record( Events::DISCARDED, Events::INFO, $discarded );

		Notifier::digest();
		Notifier::digest();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( "(#{$unsynced})", $this->mails[0]['message'] );

		foreach ( array( $linked, $unmanaged, $discarded, $young ) as $product ) {
			$this->assertStringNotContainsString( "(#{$product})", $this->mails[0]['message'] );
		}
	}

	/**
	 * NOT-14 — Al día siguiente, uno nuevo: el email lista sólo el nuevo. No dice un total: el
	 * número de productos sin vincular es uno solo, el de la pantalla.
	 */
	public function test_the_digest_lists_only_the_new_ones_and_the_total(): void {
		$first = $this->product( 30 );
		Notifier::digest();

		$second = $this->product( 30 );
		Notifier::digest();

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( "(#{$second})", $this->mails[1]['message'] );
		$this->assertStringNotContainsString( "(#{$first})", $this->mails[1]['message'] );
		$this->assertStringNotContainsString( 'En total', $this->mails[1]['message'], 'un solo número: el de la pantalla' );
	}

	/**
	 * NOT-22 — **Cada resumen que sale deja una sola línea en el log**, con cuántos nuevos y
	 * cuántos en total; uno que no sale, ninguna.
	 */
	public function test_each_digest_leaves_one_line(): void {
		$this->product( 30 );
		$this->product( 30 );
		$this->mail_works = false;
		Notifier::digest();

		$this->assertSame( 0, Events::list( array( 'kind' => Events::DIGEST_SENT ) )['total'] );

		$this->mail_works = true;
		Notifier::digest();

		$sent = Events::list( array( 'kind' => Events::DIGEST_SENT ) )['items'];

		$this->assertCount( 1, $sent );
		$this->assertSame( 2, $sent[0]['context']['count'] );
		$this->assertSame( 2, $sent[0]['context']['total'] );
	}

	/**
	 * NOT-15 — **Desvincularlo después es un problema nuevo**: se vuelve a avisar, pasado el
	 * plazo desde que se desvinculó.
	 */
	public function test_unlinking_again_makes_it_new(): void {
		global $wpdb;

		$product = $this->product( 30 );
		Notifier::digest();

		Events::record( Events::UNLINKED, Events::INFO, $product );
		Notifier::digest();
		$this->assertCount( 1, $this->mails, 'recién desvinculado: el plazo corre de nuevo' );

		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ), array( 'kind' => Events::UNLINKED ) ); // phpcs:ignore WordPress.DB
		Notifier::digest();

		$this->assertCount( 2, $this->mails );
	}

	/**
	 * NOT-16 — Un bootstrap que no se puede resolver, sin causa, se avisa en el momento.
	 */
	public function test_an_unresolved_bootstrap_is_mailed(): void {
		Events::record( Events::LINK_UNRESOLVED, Events::WARNING, 9 );

		Notifier::tick();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '(#9)', $this->mails[0]['message'] );
	}

	/**
	 * NOT-17 — **Si el email del resumen no sale, no se da por avisado**: la noche siguiente lo
	 * lista.
	 */
	public function test_a_failed_digest_is_not_marked_as_notified(): void {
		$product          = $this->product( 30 );
		$this->mail_works = false;
		Notifier::digest();

		$this->mail_works = true;
		Notifier::digest();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( "(#{$product})", $this->mails[0]['message'] );
	}

	/**
	 * NOT-18 — Deshacer un descarte lo vuelve a poner en el resumen.
	 */
	public function test_undiscarding_brings_it_back(): void {
		$product = $this->product( 30 );
		Events::record( Events::DISCARDED, Events::INFO, $product );
		Events::record( Events::UNDISCARDED, Events::INFO, $product );

		Notifier::digest();

		$this->assertStringContainsString( "(#{$product})", $this->mails[0]['message'] );
	}

	/**
	 * NOT-19 — **Si el email de la caída no sale, se reintenta una vez por ventana**, no cada
	 * minuto, y el fallo queda en el log una sola vez (ADR-012 §7).
	 */
	public function test_a_failed_outage_email_is_retried_per_window(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		$now              = Peer::current()['since'] + Notifier::PEER_AFTER;
		$attempts         = 0;
		$count            = static function ( $short ) use ( &$attempts ) {
			++$attempts;

			return $short;
		};
		$this->mail_works = false;

		add_filter( 'pre_wp_mail', $count, 5 );
		for ( $minute = 0; $minute < 15; $minute++ ) {
			Notifier::tick( $now + $minute * 60 );
		}
		remove_filter( 'pre_wp_mail', $count, 5 );

		$this->assertSame( 2, $attempts, 'a los 0 y a los 10 minutos' );
		$this->assertCount( 1, Events::list( array( 'kind' => Events::NOTIFY_FAILED ) )['items'] );

		$this->mail_works = true;
		Notifier::tick( $now + 20 * 60 );

		$this->assertCount( 1, $this->mails );
	}

	/**
	 * NOT-20 — Con el correo roto, el fallo de una causa queda en el log una vez por incidente,
	 * no una por ventana; si después sale, un fallo nuevo vuelve a registrarse.
	 */
	public function test_a_failed_email_is_logged_once_per_incident(): void {
		$now              = time();
		$this->mail_works = false;

		$this->background_failure( 8 );
		Notifier::tick( $now );
		Notifier::tick( $now + Notifier::WINDOW );
		Notifier::tick( $now + Notifier::WINDOW * 2 );

		$this->assertCount( 1, Events::list( array( 'kind' => Events::NOTIFY_FAILED ) )['items'] );

		$this->mail_works = true;
		Notifier::tick( $now + Notifier::WINDOW * 3 );
		$this->mail_works = false;
		$this->background_failure( 9 );
		Notifier::tick( $now + Notifier::WINDOW * 4 );

		$this->assertCount( 2, Events::list( array( 'kind' => Events::NOTIFY_FAILED ) )['items'] );
	}

	/**
	 * NOT-21 — **Con un solo producto, en singular**; y el motivo de un par roto, en castellano.
	 */
	public function test_one_product_is_singular_and_reasons_are_readable(): void {
		$this->product( 30 );
		Notifier::digest();

		Events::record( Events::PAIR_BROKEN, Events::WARNING, 5, array( 'reason' => 'local_trashed' ) );
		Notifier::tick();

		$this->assertStringContainsString( '1 producto sin vincular', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'hay 1 producto que todavía no está vinculado', $this->mails[0]['message'] );
		$this->assertStringContainsString( '— está en la papelera', $this->mails[1]['message'] );
	}

	/**
	 * NOT-23 — **El email explica de qué se trata**: qué productos, por qué importa —el stock no
	 * se descuenta en la otra—, dónde se vinculan, y que si está bien así se puede ignorar.
	 * El pie lleva al panel de Stock Sync.
	 */
	public function test_the_digest_explains_itself(): void {
		$this->product( 30 );
		Notifier::digest();

		$message = $this->mails[0]['message'];

		$this->assertStringContainsString( 'el stock de la otra no se descuenta', $message );
		$this->assertStringContainsString( 'Stock Sync → Productos', $message );
		$this->assertStringContainsString( 'podés ignorar este mensaje', $message );
		$this->assertStringContainsString( 'admin.php?page=woonderful-stock-sync', $message );
		$this->assertStringNotContainsString( 'descartalo', $message );
	}
}
