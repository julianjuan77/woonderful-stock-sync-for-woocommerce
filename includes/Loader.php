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
 * Acumula los hooks de todos los componentes y los registra de una sola vez en run().
 *
 * Los componentes declaran qué escuchan a través de esta clase en vez de llamar a
 * add_action() ellos mismos, así instanciarlos no toca el estado global de WordPress.
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

	/** Encola una acción. No llama a add_action(): eso pasa en run(). */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/** Encola un filtro. No llama a add_filter(): eso pasa en run(). */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/** Registra en WordPress todos los hooks encolados. */
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
