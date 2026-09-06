# Woonderful Stock Sync for WooCommerce — Estado del proyecto

> Creado: 2026-09-05 (docs) · scaffold: 2026-09-06
> Plugin: `woonderful-stock-sync-for-woocommerce` | WP 6.5+ | PHP 8.1+ | WC 8.0+
> Repo propio: pendiente de crear.

Sincroniza el stock de un mismo depósito físico entre dos o más tiendas WooCommerce
propias. **Pool único, sin reparto:** si hay 10 unidades, las 10 se ven en cada tienda, y
una venta en cualquiera las deja en 9 en todas. Objetivo: eliminar el lucro cesante del
colchón de stock por canal.

---

## Estado general

| Área                        | Estado |
|-----------------------------|--------|
| SPEC funcional              | ✅ 100% (8 casos de uso, UC-01→UC-08) |
| ADRs                        | 🟡 3 aprobados; ADR-001 caducado por un bug del gate |
| Estructura y scaffold       | ✅ 100% |
| Capa de base de datos       | ✅ 100% (20 tests en verde, lint limpio) |
| Detección de movimientos    | ⬜ 0% |
| Transporte entre tiendas    | ⬜ 0% (bloqueado: falta ADR de autenticación) |
| Reconciliación              | ⬜ 0% |
| Admin / UI (UC-05)          | 🟡 5% (solo el lugar del menú) |
| Tests                       | 🟡 20 tests de integración (capa de BD); el resto sin implementar |
| Estándares (PHPCS)          | ✅ PHPCS 0 errores · PHPStan nivel 6 OK |
| i18n                        | 🟡 strings con text domain; sin .pot |
| Documentación               | 🟡 SPEC, DECISIONS, ARCHITECTURE y TEST-CASES; falta readme.txt |

---

## ✅ Implementado

- **Documentación previa** — `docs/SPEC.md` (8 casos de uso, fuente de verdad, supuestos y
  restricciones) y `docs/DECISIONS.md` con ADR-000 y ADR-001, ambos aprobados.
- **Scaffold (ADR-000)** — namespace `Woonderful\StockSync` con autoload PSR-4 sobre
  `includes/`, constantes `WSS_`, patrón loader.
  - `woonderful-stock-sync-for-woocommerce.php` — cabeceras de WordPress.org, guard de
    `ABSPATH`, autoloader con aviso si falta `vendor/`, declaración de compatibilidad con
    HPOS, y requisito duro de WooCommerce activo (avisa y corta, no se autodesactiva).
  - `includes/Loader.php` — registro centralizado de hooks; los componentes declaran qué
    escuchan sin llamar a `add_action()` en el constructor, así se instancian en tests sin
    tocar el estado global.
  - `includes/Plugin.php` — arma los componentes; text domain en `init` (antes dispara el
    aviso de WP 6.7).
  - `includes/Admin.php` — submenú bajo WooCommerce detrás de `manage_woocommerce`, con la
    verificación repetida en el render para cubrir la URL directa.
  - `uninstall.php` — borra opciones y transients propios por lista explícita (no por LIKE
    sobre `wp_options`) y desencola los jobs de Action Scheduler.
  - `composer.json` — PSR-4, PHP ≥8.1, PHPCS con WPCS y PHPUnit con los polyfills de Yoast.

- **Capa de base de datos (ADR-001)** — dos tablas, movimientos inmutables, patrón inbox.
  - `includes/Db/Schema.php` — `wss_movements` (con `UNIQUE (origin_store, sequence)` como
    garantía de idempotencia, `KEY (sku,id)` y `KEY (status,claimed_at)`) y
    `wss_product_state` (`sku` como PK). `create_tables()` vía dbDelta —idempotente, se
    puede llamar en cada activación—, `maybe_upgrade()` por versión de esquema, y
    `drop_tables()` que solo llama `uninstall.php`.
  - `includes/Db/MovementRepository.php` — `record()` con `INSERT IGNORE` contra el índice
    único (sin SELECT previo, que tendría carrera); `claim()` con el UPDATE condicionado
    que resuelve la exclusión en la base y no en la cola, y que retoma claims vencidos;
    `pending()`, `sum_applied_after()`, `max_applied_id()`, `delete_applied_up_to()`,
    `history()`.
  - `includes/Db/ProductStateRepository.php` — `bootstrap()` como upsert (re-bootstrapear
    tras un recuento físico es legítimo), `absorb()` con UPDATE **relativo** para la
    consolidación del purgado, `get()`, `is_bootstrapped()`, `all_skus()` paginado,
    `forget()`.
  - `includes/Activator.php` + `register_activation_hook` en el archivo principal;
    `Schema::maybe_upgrade()` en `admin_init`; `uninstall.php` llamando a `drop_tables()`.

- **Toolchain y tests (ADR-002)** — 20 tests de integración contra base real, en verde.
  - `tests/wp-tests-config.php` apunta a la base dedicada `wss_tests`, con las credenciales
    tomadas del entorno del contenedor (nada versionado) y una guarda que aborta si alguien
    la apunta a la base de un sitio.
  - `MovementRepositoryTest` (9) — idempotencia por índice único, claim atómico, retoma de
    claim vencido, cierre de un movimiento no tomado, suma que ignora lo pendiente.
  - `PurgeConsolidationTest` (3) — **TC-08**, el que más importa: purgar consolidando no
    mueve el stock esperado. Incluye el test inverso, que verifica que purgar *sin*
    consolidar sí lo corrompe — si algún día pasa a dar igual, TC-08 dejó de probar lo que
    cree probar.
  - `SchemaTest` (3) — las tablas existen con sus tres índices y el único es único;
    `create_tables()` es idempotente y no pierde datos.
  - `ProductStateRepositoryTest` (5) — upsert del bootstrap, `absorb()` que no retrocede,
    `forget()` que no toca el libro.
  - PHPCS 0 errores y PHPStan nivel 6 limpio.

---

## 🔄 En progreso — sesión actual (2026-09-06)

- **Repo git y primer commit.** Bloqueado: el pre-commit rechaza commits con ADRs
  caducados, y ADR-001 figura caducado por un bug del gate (ver abajo). Done cuando:
  `git init`, fila en `PROJECTS.md`, repo remoto creado y commit inicial.

---

## ⬜ Pendiente

- **`readme.txt`** de WordPress.org y `.pot` de traducciones.
- **Repo propio + fila en `PROJECTS.md`.** El plugin todavía no es un repo git.
- **`Frontend.php`** — ADR-000 lo autoriza pero el plugin no tiene salida al frontend
  (se entera de las ventas por hooks). No se creó para no dejar una clase vacía; si
  aparece la necesidad, el ADR ya lo cubre.
- **Habilitar los gates en modo bloqueante** — el plugin no está en
  `.claude/gates/enabled-plugins.txt`, así que corre en modo aviso. Ya tiene SPEC y ADRs:
  es candidato a entrar.

---

## Decisiones

Las decisiones de arquitectura viven en `docs/DECISIONS.md` como ADRs con aprobación
humana registrada. Acá solo el índice:

| ADR | Título | Estado |
|-----|--------|--------|
| ADR-000 | Scaffold estándar de plugin (namespace + PSR-4) | ✅ Aprobada |
| ADR-001 | Esquema del libro de movimientos (dos tablas, patrón inbox) | ⚠️ Caducada por un bug del gate — re-aprobar |
| ADR-002 | Toolchain de calidad y tests de integración contra base real | ✅ Aprobada |

### Pendientes de ADR

- **Autenticación entre tiendas.** Bloquea todo el transporte. Candidatos: claves de la
  REST API de WooCommerce y Application Passwords de WordPress core.
- **Umbral de ráfaga** para el modo importación de UC-04 (N cambios en M segundos).
- **Registro de avisos de inconsistencias de SKU** (UC-08) — el único estado de
  emparejamiento que sí se persiste.
- **Orquestación del purgado.** Las primitivas existen y están testeadas; falta el
  componente que las llama en el orden correcto.

### Choques con WPCS que resolvió `phpcs.xml`

Documentados en el ruleset con su justificación, para que no parezcan silenciamientos
arbitrarios:

- **Nombres de archivo.** WPCS pide `class-plugin.php`; PSR-4 exige `Plugin.php`. Son
  incompatibles por definición y ADR-000 eligió PSR-4: con el nombre en minúsculas el
  autoloader no encuentra la clase y el plugin no carga.
- **`@param`/`@return` y `@var` en propiedades.** Las firmas están tipadas (PHP 8.1);
  repetir el tipo es duplicación que se desactualiza sola. El `@var` se conserva donde
  PHPStan necesita el genérico del array.
- **Interpolación en SQL.** Son nombres de tabla derivados de `$wpdb->prefix`, nunca
  entrada de usuario; los valores van siempre por `prepare()`. La justificación vive una
  vez en el ruleset en lugar de repetida en quince llamadas.
- **Escapado de mensajes de excepción.** Van a logs y a quien depura, nunca al navegador.

---

## Preguntas abiertas del SPEC

Ninguna impide seguir, pero cada una bloquea una parte concreta (detalle en `docs/SPEC.md`
§6): soporte de multisite, volumen real del catálogo, umbral de ráfaga, autenticación entre
tiendas, y revisión del estado del arte de los plugins de multi-tienda existentes.
