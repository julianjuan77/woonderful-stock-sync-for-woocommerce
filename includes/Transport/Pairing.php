<?php
/**
 * El emparejamiento entre las dos tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Identity\Store;

defined( 'ABSPATH' ) || exit;

/**
 * El canje de un código de un solo uso por un secreto compartido (ADR-001 §1).
 *
 * La maestra emite el código y lo recibe; la secundaria lo canjea. Al terminar las dos son
 * iguales: cada una tiene a la otra en `wss_peers`.
 */
final class Pairing {

	/**
	 * El código que emitió esta tienda, del lado de la maestra.
	 */
	public const OPTION_PAIRING = 'wss_pairing';

	/**
	 * El código que esta tienda está canjeando, del lado de la secundaria.
	 */
	public const OPTION_REDEEMING = 'wss_redeeming';

	/**
	 * El prefijo del código, para reconocerlo al pegarlo.
	 */
	public const PREFIX = 'wss_';

	/**
	 * Cuánto vale un código, en segundos.
	 */
	public const CODE_TTL = 900;

	/**
	 * Cuánto dura un canje del lado de la secundaria: lo que tarda el `POST /pair`, con el
	 * callback adentro.
	 */
	public const REDEEM_TTL = 120;

	/**
	 * Cuánto se espera la respuesta del `POST /pair`, que incluye el callback.
	 */
	public const PAIR_TIMEOUT = 30;

	/**
	 * Cuánto se espera la respuesta del callback.
	 */
	public const CALLBACK_TIMEOUT = 10;

	/**
	 * La ruta del canje.
	 */
	public const ROUTE = '/wss/v1/pair';

	/**
	 * Emite un código. Del lado de la maestra.
	 *
	 * @return string|\WP_Error El código, para que el dueño lo pegue en la otra tienda.
	 */
	public static function create_code() {
		if ( ! self::is_secure( (string) get_option( 'home' ) ) ) {
			return self::insecure( __( 'Esta tienda no está en https: la huella se guardaría en http y pasar a https después suspendería la sincronización.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$code = bin2hex( random_bytes( 32 ) );

		update_option(
			self::OPTION_PAIRING,
			array(
				'code'    => $code,
				'expires' => time() + self::CODE_TTL,
			),
			false
		);

		$payload = (string) wp_json_encode(
			array(
				'url' => untrailingslashit( rest_url( 'wss/v1' ) ),
				'id'  => Store::id(),
				'c'   => $code,
			)
		);

		return self::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Lo que lleva adentro un código, o null si no es uno.
	 *
	 * @param string $code El código pegado.
	 *
	 * @return array{url: string, id: string, c: string}|null
	 */
	public static function decode( string $code ): ?array {
		$code = trim( $code );

		if ( ! str_starts_with( $code, self::PREFIX ) ) {
			return null;
		}

		$json    = base64_decode( strtr( substr( $code, strlen( self::PREFIX ) ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $payload ) ) {
			return null;
		}

		foreach ( array( 'url', 'id', 'c' ) as $field ) {
			if ( ! is_string( $payload[ $field ] ?? null ) || '' === $payload[ $field ] ) {
				return null;
			}
		}

		return array(
			'url' => $payload['url'],
			'id'  => $payload['id'],
			'c'   => $payload['c'],
		);
	}

	/**
	 * El código que emitió esta tienda, si todavía vale; si no, ''.
	 */
	public static function pending_code(): string {
		$pairing = (array) get_option( self::OPTION_PAIRING, array() );

		if ( (int) ( $pairing['expires'] ?? 0 ) < time() ) {
			return '';
		}

		return is_string( $pairing['code'] ?? null ) ? $pairing['code'] : '';
	}

	/**
	 * El código que esta tienda está canjeando con esa maestra, si el canje sigue en curso.
	 *
	 * Es lo que autentica el callback: fuera de la ventana del canje, el código no autentica
	 * nada (ADR-001 §1).
	 *
	 * @param string $master Identificador de la tienda que llama.
	 */
	public static function redeeming_code_for( string $master ): string {
		$redeeming = (array) get_option( self::OPTION_REDEEMING, array() );

		if ( (int) ( $redeeming['expires'] ?? 0 ) < time() || ( $redeeming['master'] ?? null ) !== $master ) {
			return '';
		}

		return is_string( $redeeming['code'] ?? null ) ? $redeeming['code'] : '';
	}

	/**
	 * Canjea un código. Del lado de la secundaria.
	 *
	 * @param string $code El código que emitió la otra tienda.
	 *
	 * @return true|\WP_Error
	 */
	public static function redeem( string $code ) {
		$decoded = self::decode( $code );

		if ( null === $decoded ) {
			return new \WP_Error( 'wss_pairing_invalid', __( 'Eso no es un código de emparejamiento.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( ! self::is_secure( (string) get_option( 'home' ) ) ) {
			return self::insecure( __( 'Esta tienda no está en https: la huella se guardaría en http y pasar a https después suspendería la sincronización.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		// El secreto vuelve en la respuesta, por esta dirección.
		if ( ! self::is_secure( $decoded['url'] ) ) {
			return self::insecure( __( 'La otra tienda no está en https: el secreto viajaría en claro.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		update_option(
			self::OPTION_REDEEMING,
			array(
				'code'    => $decoded['c'],
				'master'  => $decoded['id'],
				'expires' => time() + self::REDEEM_TTL,
			),
			false
		);

		try {
			$response = self::send_pair( $decoded );
		} finally {
			delete_option( self::OPTION_REDEEMING );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$payload = is_array( $payload ) ? $payload : array();

		if ( 200 !== $status ) {
			$error = is_string( $payload['code'] ?? null ) ? $payload['code'] : 'wss_pairing_failed';

			return new \WP_Error( $error, is_string( $payload['message'] ?? null ) ? $payload['message'] : 'HTTP ' . $status );
		}

		$secret = is_string( $payload['secret'] ?? null ) ? $payload['secret'] : '';

		// Quien contestó tiene que ser la tienda que emitió el código.
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $secret ) || ( $payload['id'] ?? null ) !== $decoded['id'] ) {
			return new \WP_Error( 'wss_pairing_failed', __( 'La respuesta de la otra tienda no es la esperada.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		Peers::pair( $decoded['id'], $decoded['url'], $secret, is_string( $payload['label'] ?? null ) ? $payload['label'] : '' );
		Store::remember_fingerprint();

		return true;
	}

	/**
	 * Recibe un canje ya autenticado con el código. Del lado de la maestra.
	 *
	 * @param array<mixed, mixed> $body El cuerpo del `POST /pair`.
	 *
	 * @return array{secret: string, id: string, label: string}|\WP_Error Lo que se le responde
	 *         a la secundaria.
	 */
	public static function accept( array $body ) {
		foreach ( array( 'url', 'id', 'label' ) as $field ) {
			if ( ! is_string( $body[ $field ] ?? null ) || ( 'label' !== $field && '' === $body[ $field ] ) ) {
				return new \WP_Error( 'wss_bad_request', __( 'Faltan la url, el id o la etiqueta de la tienda.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		if ( ! self::is_secure( (string) get_option( 'home' ) ) || ! self::is_secure( $body['url'] ) ) {
			return self::insecure( __( 'Las dos tiendas tienen que estar en https.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$code = self::pending_code();

		if ( ! self::callback_answers( $body['url'], $body['id'], $code ) ) {
			return new \WP_Error( 'wss_callback_failed', __( 'La otra tienda no respondió en la dirección que dio, o respondió otra tienda.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 502 ) );
		}

		$secret = bin2hex( random_bytes( 32 ) );

		Peers::pair( $body['id'], $body['url'], $secret, $body['label'] );
		Store::remember_fingerprint();

		// El código vale para un solo canje exitoso.
		delete_option( self::OPTION_PAIRING );

		return array(
			'secret' => $secret,
			'id'     => Store::id(),
			'label'  => Store::label(),
		);
	}

	/**
	 * Si la URL es https, o si esto es un sitio de desarrollo.
	 *
	 * En `local` y `development` se acepta http: dos sitios locales no podrían emparejarse
	 * nunca, y ahí no hay nadie en el medio ni una migración a https que suspenda.
	 *
	 * @param string $url La URL.
	 */
	public static function is_secure( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME )
			|| in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Manda el `POST /pair`, firmado con el código.
	 *
	 * @param array{url: string, id: string, c: string} $decoded El código.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function send_pair( array $decoded ) {
		$body = (string) wp_json_encode(
			array(
				'url'   => untrailingslashit( rest_url( 'wss/v1' ) ),
				'id'    => Store::id(),
				'label' => Store::label(),
			)
		);

		return wp_remote_post(
			untrailingslashit( $decoded['url'] ) . '/pair',
			array(
				'headers'     => array_merge(
					array( 'Content-Type' => 'application/json' ),
					Signature::headers( Store::id(), $decoded['c'], 'POST', self::ROUTE, array(), $body )
				),
				'body'        => $body,
				'timeout'     => self::PAIR_TIMEOUT,
				'redirection' => 0,
			)
		);
	}

	/**
	 * El callback: si la secundaria responde en su URL y dice ser quien dijo.
	 *
	 * Prueba además el canal en el sentido maestra → secundaria, que si no quedaría sin
	 * probar hasta la primera venta (ADR-001 §1).
	 *
	 * @param string $rest_url La base de la API que dio la secundaria.
	 * @param string $store    El identificador que dijo tener.
	 * @param string $code     El código, con que se firma.
	 */
	private static function callback_answers( string $rest_url, string $store, string $code ): bool {
		$response = wp_remote_get(
			untrailingslashit( $rest_url ) . '/health',
			array(
				'headers'     => Signature::headers( Store::id(), $code, 'GET', '/wss/v1/health', array(), '' ),
				'timeout'     => self::CALLBACK_TIMEOUT,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$health = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $health ) && ( $health['store'] ?? null ) === $store;
	}

	/**
	 * Un rechazo por falta de https.
	 *
	 * @param string $message Por qué.
	 */
	private static function insecure( string $message ): \WP_Error {
		return new \WP_Error( 'wss_insecure', $message, array( 'status' => 400 ) );
	}
}
