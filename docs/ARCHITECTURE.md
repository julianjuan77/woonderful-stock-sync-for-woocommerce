# Woonderful Stock Sync for WooCommerce — Arquitectura técnica

> Última actualización: 2026-09-10

**Éste es el documento para entender el plugin.** Se actualiza en el mismo paso en que se
escribe el código, no al final. Si algo de acá no coincide con el código, es un bug del
documento.

Documenta el **resultado** de las decisiones; el porqué de cada una y las alternativas
descartadas viven en `DECISIONS.md`, con su ADR.

- **§1 y §2** son la puerta de entrada: qué guardan las dos tablas y cómo se lee el código.
- **§3 a §6** son el contrato: lo que no se puede romper sin romperle algo a alguien.
- **§7 y §8** son las decisiones tomadas y cómo se verifica todo.

Los flujos de detección, transporte y reconciliación no están: se escriben cuando se
implementen. Lo que falta está listado al final de §2.

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

## 2. Recorrido del código

Esta sección es para leer el plugin por primera vez, o para volver dentro de tres meses.

### Qué hay en cada archivo

| Archivo | Qué hace | Líneas |
|---|---|---|
| `woonderful-stock-sync-for-woocommerce.php` | Arranque. Define constantes, carga el autoloader, declara HPOS, verifica WooCommerce y crea el `Plugin`. | ~95 |
| `includes/Plugin.php` | Arma los componentes y declara qué hook escucha cada uno. No registra nada por sí mismo. | ~68 |
| `includes/Loader.php` | Acumula esos hooks y los registra todos juntos en `run()`. | ~78 |
| `includes/Activator.php` | Una línea: crea las tablas al activar. | ~23 |
| `includes/Admin.php` | El submenú bajo WooCommerce. Hoy imprime un cartel de "no implementado". | ~48 |
| `includes/Db/Schema.php` | La definición SQL de las dos tablas, y crearlas / actualizarlas / borrarlas. | ~113 |
| `includes/Db/MovementRepository.php` | Todas las consultas contra `wss_movements`. | ~273 |
| `includes/Db/ProductStateRepository.php` | Todas las consultas contra `wss_product_state`. | ~126 |
| `uninstall.php` | Borra opciones, jobs y tablas al eliminar el plugin. | ~49 |

### En qué orden leerlo

1. **§1 de este documento**, las dos tablas. Todo el plugin es mover filas entre esas dos;
   si se entienden las tablas, el código es un detalle.
2. **`MovementRepository::record()` y `claim()`.** Son las dos operaciones que definen el
   plugin, y las dos tienen algo raro a propósito (ver más abajo).
3. **`PurgeConsolidationTest`.** Es el que muestra cómo se combinan las dos tablas para
   sacar un número, con datos concretos.
4. El resto —`Plugin`, `Loader`, `Admin`— es andamiaje estándar de WordPress y se puede
   leer en diagonal.

### Lo que sorprende al leerlo: el código casi no verifica nada

Es lo que más cuesta de este plugin y conviene saberlo antes de abrir un archivo.

Las dos garantías del sistema **no están escritas en PHP**. No hay un `if` que las
implemente; las hace cumplir MySQL:

| Garantía | Quién la hace cumplir | Qué hace el PHP |
|---|---|---|
| El mismo movimiento no se guarda dos veces | `UNIQUE KEY origin_sequence (origin_store, sequence)` | `record()` mira si el INSERT afectó 0 filas |
| Dos procesos no aplican el mismo movimiento | El `WHERE status = 'pending'` del UPDATE de `claim()` | `claim()` compara las filas afectadas contra 1 |

Está hecho así porque la alternativa —consultar antes de escribir— tiene una condición de
carrera: entre el `SELECT` que dice "no existe" y el `INSERT`, otro proceso puede haber
insertado. La base es el único lugar donde esa decisión se toma sin carrera.

**Consecuencia práctica:** si alguna vez se toca el `CREATE TABLE` de `Schema.php` y
desaparece el índice único, el plugin sigue compilando, los tests de PHP siguen pasando en
apariencia, y la idempotencia se pierde en silencio. Por eso `SchemaTest` verifica que el
índice exista y sea `UNIQUE`.

### Arranque: qué pasa en cada request

```
WordPress carga el plugin
  │
  ├─ ¿existe vendor/autoload.php?  ──no──► aviso en el admin y corta acá
  │
  ├─ register_activation_hook(...)        se registra siempre, dispara solo al activar
  ├─ before_woocommerce_init              declara compatibilidad con HPOS
  │
  └─ plugins_loaded
       ├─ ¿existe la clase WooCommerce?  ──no──► aviso en el admin y corta acá
       └─ new Plugin() → run()
            └─ Loader registra:
                 init        → Plugin::load_textdomain()
                 admin_menu  → Admin::register_menu()
                 admin_init  → Plugin::maybe_upgrade_schema()
```

`new Plugin()` **no toca WordPress**: solo encola hooks en el `Loader`. Se registran recién
en `run()`. Por eso un test puede instanciar cualquier componente sin ensuciar el estado
global.

### La vida de un movimiento

Un movimiento nace, espera, lo toma alguien, y se cierra:

```
                 record()                claim()              mark_applied()
   (nada)  ──────────────►  pending  ──────────────►  claimed  ──────────────►  applied
                               ▲                         │
                               └─────────────────────────┘
                                  claimed_at vencido:
                                  otro proceso lo retoma
```

- **`pending`** — está guardado en el libro, pero el stock de WooCommerce todavía no cambió.
- **`claimed`** — un proceso se lo llevó para trabajarlo. Nadie más puede tomarlo mientras
  `claimed_at` sea reciente.
- **`applied`** — el stock local ya refleja el movimiento. Recién ahora cuenta para el stock
  esperado.
- **`rejected`** — se descartó. Existe para dejar rastro en el libro en vez de borrar.

El estado intermedio `claimed` existe por una razón concreta: **Action Scheduler no
garantiza que un job corra una sola vez**. Dos pasadas de cron simultáneas pueden agarrar el
mismo trabajo, así que la exclusión tiene que estar en la base.

Y `claimed_at` existe por otra: si el proceso que tomó el movimiento se muere antes de
cerrarlo, el movimiento quedaría trabado para siempre. Pasados 5 minutos
(`STALE_CLAIM_SECONDS`) se considera un job muerto y `claim()` vuelve a entregarlo.

### El cálculo que hay que entender: el stock esperado

No está guardado en ningún lado. Se calcula:

```
stock esperado  =  base_qty  +  suma de los delta 'applied' con id > base_up_to
                   └────────┘     └──────────────────────────────────────────┘
                  wss_product_state                wss_movements
```

Con números: si `base_qty` es 10, `base_up_to` es 500, y hay movimientos aplicados
posteriores de −2 y −3, el stock esperado es **5**.

**Por qué importa el purgado.** A los 90 días los movimientos viejos se borran. Si se
borraran a secas, esos −2 y −3 desaparecerían del cálculo y el stock esperado pasaría a 10.
Por eso hay dos pasos, y el orden no es negociable:

```php
// 1. El base absorbe lo que se va a borrar
$state->absorb( 'ZAP-001', -5, $corte );      // base_qty: 10 → 5, base_up_to → $corte
// 2. Recién ahora se borra
$movements->delete_applied_up_to( 'ZAP-001', $corte );
```

Después de los dos pasos el stock esperado sigue dando **5**. Al revés, daría 10 y la
reconciliación reportaría un desvío que no existe, en todos los productos con historia. Es
el peor modo de falla del plugin porque no rompe nada visible. `PurgeConsolidationTest` lo
verifica en las dos direcciones: que hacerlo bien preserve el número, y que hacerlo mal lo
rompa.

### Qué NO existe todavía

Buena parte del plugin está en el SPEC y no en el disco. Al leer el código puede parecer que
falta algo: falta de verdad.

| Pieza | Estado |
|---|---|
| Identidad de la tienda (`origin_store`) y rol maestra/secundaria | **No existe.** `record()` lo exige y hoy nadie se lo provee. |
| Detección de ventas y cambios de stock (UC-01, UC-04) | No existe. Nada llama a `record()` fuera de los tests. |
| Transporte entre tiendas | No existe. Bloqueado por el ADR de autenticación. |
| Job que aplica los movimientos | No existe. Nada llama a `claim()` fuera de los tests. |
| Reconciliación (UC-06) y purgado | No existen. Las primitivas de purgado sí, sin quien las orqueste en orden. |
| Pantalla de estado (UC-05) | Solo el lugar en el menú. |

---

## 3. Contrato público

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

## 4. Límites de módulos

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

## 5. Trabajo diferido

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

## 6. Seguridad

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

## 7. Decisiones

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

## 8. Tests

> ADR-002 — `Temas: dependencias`

**Son de integración, contra MySQL real.** Las dos invariantes del plugin no viven en el
PHP: la idempotencia la hace cumplir el índice único y la ejecución única el `UPDATE`
condicionado del claim. Un `$wpdb` simulado devuelve lo que el test le pide, así que pasaría
igual con el índice borrado.

```bash
make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce
make lint-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce
```

**Los checks automáticos corren en `sandbox`**, que es el sitio sin datos de clientes y el
default de `gate-run-checks.sh`. El plugin está montado ahí vía `DEV_PLUGINS`, y por eso el
pre-commit funciona sin pasarle nada. Se puede correr contra otro sitio con
`WP_GATES_SITE=<slug>`, pero no hay motivo: los tests no necesitan datos reales —ni siquiera
WooCommerce activo— porque solo ejercitan la capa de base de datos.

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
