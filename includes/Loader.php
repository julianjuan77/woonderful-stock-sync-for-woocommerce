<?php
/**
 * Registro centralizado de hooks.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Junta los hooks de todos los componentes y los registra de una sola vez.
 *
 * Permite instanciar un componente en un test sin que registre nada en el estado global
 * de WordPress: los hooks se declaran acá, no en los constructores.
 */
class Loader {

	/**
	 * Acciones declaradas, todavía sin registrar.
	 *
	 * @var array<int, array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $actions = array();

	/**
	 * Filtros declarados, todavía sin registrar.
	 *
	 * @var array<int, array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $filters = array();

	/** Declara una acción, sin registrarla todavía. */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/** Declara un filtro, sin registrarlo todavía. */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/** Registra en WordPress todo lo declarado. */
	public function run(): void {
		foreach ( $this->filters as $entry ) {
			add_filter(
				$entry['hook'],
				array( $entry['component'], $entry['callback'] ),
				$entry['priority'],
				$entry['accepted_args']
			);
		}

		foreach ( $this->actions as $entry ) {
			add_action(
				$entry['hook'],
				array( $entry['component'], $entry['callback'] ),
				$entry['priority'],
				$entry['accepted_args']
			);
		}
	}
}
