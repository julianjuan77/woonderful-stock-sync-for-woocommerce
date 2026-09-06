# Woonderful Stock Sync for WooCommerce — Arquitectura técnica

> Última actualización: 2026-09-06
> Documentación para desarrolladores: cómo funciona, por qué se diseñó así, cómo extenderlo.

Este archivo documenta el **resultado** de las decisiones; la justificación vive en
`DECISIONS.md`, con el ADR que respalda cada una.

Estado: las cinco secciones de contrato están escritas. Los flujos detallados de detección,
transporte y reconciliación se agregan a medida que se implementen — antes son ficción.

---

## 1. Modelo de datos

> ADR-001 — `Temas: db-schema`

**Dónde viven los datos y por qué:** dos tablas propias. El libro de movimientos no puede
vivir en `wp_postmeta` —miles de filas por producto degradan todas las consultas del sitio y
no se puede indexar "movimientos posteriores a la secuencia N"— y el purgado periódico sería
inviable. El modelo es *event sourcing con snapshot*.

```sql
CREATE TABLE {wp_prefix}wss_movements (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    origin_store  VARCHAR(32) NOT NULL,     -- tienda que originó el movimiento
    sequence      BIGINT UNSIGNED NOT NULL, -- secuencia propia de esa tienda
    sku           VARCHAR(100) NOT NULL,    -- identificador global del producto (§3 SPEC)
    delta         INT NOT NULL,             -- puede ser negativo; en bootstrap, el valor fijado
    reason        VARCHAR(20) NOT NULL,     -- bootstrap|sale|refund|adjustment|reconciliation
    reference     VARCHAR(64) DEFAULT NULL, -- pedido u origen concreto (historial de UC-05)
    created_at    DATETIME NOT NULL,        -- momento en la tienda de origen
    received_at   DATETIME NOT NULL,        -- momento en esta tienda
    status        VARCHAR(12) NOT NULL,     -- pending|claimed|applied|rejected
    applied_at    DATETIME DEFAULT NULL,
    claimed_at    DATETIME DEFAULT NULL,    -- marca del claim, para detectar jobs colgados
    PRIMARY KEY (id),
    UNIQUE KEY origin_sequence (origin_store, sequence),  -- garantía de idempotencia
    KEY sku_id (sku, id),                                 -- historial y suma por producto
    KEY status_claimed (status, claimed_at)               -- el job encuentra lo pendiente
);

CREATE TABLE {wp_prefix}wss_product_state (
    sku              VARCHAR(100) NOT NULL, -- se lee y escribe siempre por SKU
    base_qty         INT NOT NULL,          -- stock base fijado en el bootstrap (UC-07)
    base_up_to       BIGINT UNSIGNED NOT NULL, -- hasta qué movimiento está consolidado
    bootstrapped_at  DATETIME NOT NULL,
    PRIMARY KEY (sku)
);
```

**Convenciones no obvias:**

- **Los estados y razones son identificadores, no texto.** Van en inglés como el resto de
  los identificadores del código, nunca se muestran tal cual: la pantalla de UC-05 los mapea
  a etiquetas traducidas. `refund` cubre devoluciones y cancelaciones (UC-02).
- **`status = 'claimed'`** es el estado intermedio del claim atómico. Son cuatro estados, no
  tres: la primera versión de ADR-001 lo describía en la sección de ejecución única pero lo
  omitía en la tabla de columnas.
- **El stock esperado es derivado:** `base_qty` + suma de los `delta` **aplicados** con
  `id > base_up_to`. Los pendientes no cuentan: todavía no movieron stock local, e incluirlos
  reportaría desvíos inexistentes.
- **Purgar exige consolidar primero.** Al borrar los movimientos de más de 90 días,
  `base_qty` absorbe su suma y `base_up_to` avanza, de modo que el stock esperado da el mismo
  número antes y después. Purgar sin consolidar produce discrepancias falsas en todo el
  catálogo con historia. Es la restricción dura de ADR-001.
- **`sequence` es palabra clave en MariaDB** (≥10.3, por la feature de secuencias). Se escapa
  con backticks en el DML. En el `CREATE TABLE` va sin backticks porque dbDelta parsea las
  definiciones y podría creer que la tabla difiere, emitiendo el mismo `ALTER` en cada carga.
- **El estado de emparejamiento no se almacena** (*sin SKU*, *sin coincidencia*, *duplicado*):
  es derivado, como fija UC-03. La excepción —qué inconsistencias ya se avisaron por email
  (UC-08)— sí es estado persistente y necesita su propio ADR.

**Migraciones:** versión de schema en la opción `wss_schema_version`, aplicada con `dbDelta`
en la activación (`Activator::activate`) y en `admin_init` si la guardada quedó atrás
(`Schema::maybe_upgrade`). En el admin y no en cada request del frontend: un plugin se puede
actualizar sin reactivarlo, y ahí el hook de activación no corre.

---

## 2. Contrato público

> Todavía **no hay contrato público**. Ningún hook, endpoint ni opción documentada es
> estable: nada de lo de abajo se puede consumir desde afuera hasta que exista el ADR que lo
> fije (`Temas: rest-api`, `hooks-publicos` u `opciones`).

**Prefijo / namespace:** `wss_` (hooks, opciones, constantes `WSS_`) · `Woonderful\StockSync\`

El archivo principal y el text domain llevan el slug completo porque WordPress.org lo exige;
las constantes y los hooks conservan `wss_` porque viven en el espacio global — PHP no aplica
namespaces a nombres de hooks ni a claves de opciones (ADR-000).

### Hooks para terceros

Ninguno todavía. El plugin **no dispara** `do_action` ni `apply_filters` propios.

### Endpoints REST

Ninguno todavía. El transporte entre tiendas está bloqueado por la pregunta abierta de
autenticación (§6 del SPEC) y necesita ADR antes de la primera línea.

### Opciones

| Opción | Tipo | Default | Descripción |
|---|---|---|---|
| `wss_schema_version` | string | `'0'` | Versión del esquema aplicado. Interna. |

Las demás (`wss_store_id`, `wss_peers`, `wss_last_reconciliation`) están previstas y ya
figuran en `uninstall.php`, pero **no se escriben todavía**: las fija el ADR de transporte.

---

## 3. Límites de módulos

**Qué carga siempre:** el autoloader, la declaración de compatibilidad con HPOS, el hook de
activación, y `Plugin` con su `Loader`.

**Qué carga solo en admin:** `Admin` (submenú) y `Schema::maybe_upgrade()`, ambos colgados de
hooks de admin. La clase se instancia siempre pero no hace nada fuera de sus hooks.

**Qué depende de WooCommerce activo:** todo. Se verifica con `class_exists( 'WooCommerce' )`
en `plugins_loaded` y se degrada con aviso de admin. **No se autodesactiva**: así el aviso
sigue visible en lugar de que el plugin desaparezca sin explicación.

```
woonderful-stock-sync-for-woocommerce/
├── woonderful-stock-sync-for-woocommerce.php  # Bootstrap: constantes, autoloader, activación
├── uninstall.php                              # Borrado de datos (delega el DROP en Schema)
├── includes/                                  # Lógica de dominio, sin salida HTML
│   ├── Plugin.php                             #   armado de componentes
│   ├── Loader.php                             #   registro centralizado de hooks
│   ├── Activator.php                          #   crea las tablas
│   ├── Admin.php                              #   pantallas (solo hooks de admin)
│   └── Db/                                    #   un repositorio por tabla
└── docs/                                      # SPEC.md, DECISIONS.md, este archivo
```

El PHP va en `includes/` y no en `src/` porque `@wordpress/scripts` usa `src/` para el
JavaScript, y la pantalla de estado de UC-05 es React (ADR-000). No hay `admin/` ni
`public/` separados: `Public` es palabra reservada en PHP, y el plugin no tiene salida al
frontend.

**Regla de dependencias:** `Db/` no conoce a nadie —cada repositorio habla de una sola tabla
y nada más—; el resto de `includes/` consume `Db/`. **Componer las dos tablas no es trabajo
de los repositorios:** el stock esperado lo arma el reconciliador (UC-06), que es quien
conoce las dos. Por eso no existe `expected_qty()` en ninguno de los dos.

---

## 4. Trabajo diferido

| Job | Mecanismo | Frecuencia | Idempotencia |
|---|---|---|---|
| `wss_apply_movements` | Action Scheduler | por definir | claim atómico en la base |
| `wss_reconcile` | Action Scheduler | diaria, fuera de horario de venta | recalcula desde el libro |

Los dos nombres ya están reservados en `uninstall.php`, pero **ninguno está implementado**.

**La idempotencia no la da la cola.** Action Scheduler no garantiza ejecución única: dos
pasadas de cron concurrentes pueden tomar el mismo job. La exclusión vive en la base, con
`UPDATE … SET status='claimed' WHERE id=? AND status='pending'`, y solo sigue quien
afectó exactamente una fila. Un claim con `claimed_at` más viejo que
`MovementRepository::STALE_CLAIM_SECONDS` (300 s) se considera un job colgado y se retoma.

**Qué pasa si el cron no corre:** los movimientos quedan en `pendiente` y el stock local no
refleja los cambios. Es coherente con §5 del SPEC, que no promete un plazo máximo de
propagación. El peor caso de un mensaje perdido es de 24 horas, hasta la reconciliación
nocturna.

**Por qué no una transacción InnoDB alrededor de todo:** la escritura de stock pasa por
WooCommerce, que mantiene cachés propias y dispara hooks de terceros. Un plugin ajeno
corriendo dentro de nuestra transacción puede alargarla o romperla (ADR-001).

---

## 5. Seguridad

| Acción | Capability | Nonce | Sanitización de entrada | Escapado de salida |
|---|---|---|---|---|
| Ver la pantalla de estado | `manage_woocommerce` | — | — | `esc_html__` |

`manage_woocommerce` y no `manage_options`: quien administra la tienda es quien necesita ver
el estado del stock, y no siempre es administrador del sitio. La verificación se repite en el
render — `add_submenu_page()` oculta el item del menú pero no impide entrar por la URL.

**Datos sensibles:** hoy ninguno. Las credenciales de las tiendas pares aparecen con el
transporte y su almacenamiento se decide en ese ADR.

**Qué nunca aparece en logs:** credenciales de tiendas pares, y datos del comprador. Un
movimiento referencia el pedido por número (`reference`), nunca por datos personales.

**Queries directas:** siempre con `$wpdb->prepare()` para los valores. Los nombres de tabla
se interpolan —`prepare()` no los admite— y son constantes derivadas de `$wpdb->prefix`,
nunca entrada de usuario. La justificación vive una sola vez, en `phpcs.xml`, en lugar de
repetida en quince llamadas que dirían todas lo mismo.

`record()` usa `INSERT IGNORE` a propósito: el duplicado es un caso esperado —el mismo
movimiento puede llegar dos veces si se reintenta un envío— y no un error. El costo es que
`IGNORE` degrada otros errores a warning, así que se inspecciona `$wpdb->last_error` para no
confundir un fallo real con un duplicado.

---

## 6. Decisiones

Ver `DECISIONS.md`. Índice de las aprobadas:

| ADR | Título | Temas |
|---|---|---|
| ADR-000 | Scaffold estándar de plugin (namespace + PSR-4) | scaffold, uninstall, capabilities |
| ADR-001 | Esquema del libro de movimientos (dos tablas, patrón inbox) | db-schema |
| ADR-002 | Toolchain de calidad y tests de integración contra base real | dependencias |

Pendientes de escribir, con lo que bloquea cada uno:

| Decisión | Bloquea |
|---|---|
| Autenticación entre tiendas | Todo el transporte. Depende de §6 del SPEC. |
| Umbral de ráfaga (N cambios en M segundos) | El modo importación de UC-04. |
| Registro de avisos de inconsistencias de SKU | UC-08. Es el único estado de emparejamiento que se persiste. |
| Orquestación del purgado | La retención a 90 días. Consolidar y después borrar, en ese orden. |

### Decisiones tomadas sin ADR

Menores como para no justificar una decisión formal, pero no obvias al leer el código:

- **`manage_woocommerce` y no `manage_options`** para las pantallas. Quien administra la
  tienda no siempre es administrador del sitio.
- **El plugin no se autodesactiva** si falta WooCommerce: avisa y no hace nada. Si se
  desactivara solo, el aviso desaparecería junto con él.
- **No existe `Frontend.php`** aunque ADR-000 lo autoriza. Este plugin no tiene salida al
  frontend; sería una clase vacía.
- **No existe `expected_qty()`** en ningún repositorio: cada uno habla de una sola tabla.
- **`sum_applied_after()` ignora lo pendiente.** Un movimiento pendiente todavía no movió
  stock local.
- **El nombre de archivo gana sobre WPCS.** El sniff pide `class-plugin.php`; PSR-4 exige
  `Plugin.php`. Con el nombre en minúsculas el autoloader no encuentra la clase.

### Valores elegidos a ojo

Números que hoy no tienen respaldo en datos y conviene revisar cuando los haya:

| Valor | Dónde | Por qué ése | Cuándo revisarlo |
|---|---|---|---|
| 300 s | `MovementRepository::STALE_CLAIM_SECONDS` | Un job que tarda más de 5 minutos está muerto | Si aparecen movimientos retomados que sí estaban vivos |
| 50 | `pending()` por lote | Conservador | Cuando se sepa el volumen real de movimientos por día |
| 500 | `all_skus()` por página | Ídem | Con el tamaño real del catálogo |
| 90 días | Retención del libro | Sale del SPEC | Cuando se mida cuánto pesa la tabla |
| 100 | `varchar` del SKU | Cubre cualquier SKU razonable | Si algún SKU real se trunca |

---

## 7. Tests

> ADR-002 — `Temas: dependencias`

**Son de integración, contra MySQL real.** Las dos invariantes del plugin no viven en el
PHP: la idempotencia la hace cumplir el índice único y la ejecución única el `UPDATE`
condicionado del claim. Un `$wpdb` simulado devuelve lo que el test le pide, así que pasaría
igual con el índice borrado.

```bash
make test-plugin SITE=<sitio> PLUGIN=woonderful-stock-sync-for-woocommerce
make lint-plugin SITE=<sitio> PLUGIN=woonderful-stock-sync-for-woocommerce
```

**Aislamiento.** La suite de WordPress hace `DROP DATABASE` de su base al inicializarse, así
que `tests/wp-tests-config.php` apunta siempre a una base dedicada (`wss_tests`, prefijo
`wptests_`) y aborta si alguien la apunta a la de un sitio. Las credenciales salen del
entorno del contenedor; no hay ninguna versionada.

**Las tablas se crean en el bootstrap**, no en cada test: dbDelta emite DDL y en MySQL el DDL
hace commit implícito, lo que rompería la transacción con la que `WP_UnitTestCase` revierte
cada caso.

El detalle de qué prueba cada caso está en `tests/TEST-CASES.md`. Dos que conviene conocer:

- **TC-08** (`PurgeConsolidationTest`) es el más importante de la suite: purgar consolidando
  no mueve el stock esperado. Su modo de falla es silencioso.
- **`test_purgar_sin_consolidar_corrompe_el_stock_esperado`** verifica que hacerlo mal *sí*
  rompe. Es el guardián de TC-08: si algún día pasa a dar igual, TC-08 dejó de probar lo que
  cree probar.
