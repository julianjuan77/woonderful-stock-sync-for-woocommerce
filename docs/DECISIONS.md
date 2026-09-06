# Woonderful Stock Sync for WooCommerce — Decisiones de arquitectura (ADRs)

> Cada decisión de diseño vive acá y **necesita aprobación humana explícita** antes de que
> se pueda escribir el código que la implementa.

## Cómo funciona

1. Claude propone el ADR con `Estado: Propuesta` y 2-3 opciones con trade-offs.
2. El humano lo lee y aprueba con:
   ```bash
   ./scripts/gate-approve.sh woonderful-stock-sync-for-woocommerce ADR-003
   ```
   Ese script exige terminal interactiva, así que **Claude no puede ejecutarlo**. La
   aprobación se guarda fuera del repo (`~/.claude/wp-gates/approvals/`) junto con un
   snapshot del texto aprobado.
3. Recién entonces el gate de edición permite escribir los archivos que el ADR declara en
   `Cubre:`.
4. Si el ADR se edita después de aprobado, la aprobación caduca: el gate muestra el diff y
   pide re-aprobar.

**El formato de los tres campos es obligatorio y se parsea mecánicamente.** Un ADR sin
`Estado:`, `Temas:` y `Cubre:` es invisible para el gate.

- `Estado:` — `Propuesta` · `Aprobada` · `Rechazada` · `Reemplazada-por-ADR-N`
- `Temas:` — uno o más del vocabulario cerrado, separados por coma:
  `db-schema` · `rest-api` · `hooks-publicos` · `opciones` · `capabilities` · `uninstall` ·
  `api-externa` · `dependencias` · `modulos` · `scaffold`
- `Cubre:` — globs de path relativos a la raíz del plugin, separados por coma

Los ADRs no se renumeran ni se borran: un ADR superado pasa a
`Estado: Reemplazada-por-ADR-N`, y así queda el registro de por qué se cambió de idea.

---

## ADR-000 — Scaffold estándar de plugin

Estado: Aprobada
Temas: scaffold, uninstall, capabilities
Cubre: woonderful-stock-sync-for-woocommerce.php, uninstall.php, includes/Plugin.php, includes/Loader.php, includes/Admin.php, includes/Frontend.php, composer.json

**Decisión:** el plugin arranca con el scaffold estándar del entorno —archivo principal con
cabeceras de WordPress.org y guard de `ABSPATH`, constantes prefijadas, patrón loader para
registrar hooks, `uninstall.php` que borra sus propios datos y menú de admin detrás de
`current_user_can()`— con una variante: **las clases van en un namespace con autoload PSR-4**
en lugar del prefijo largo del scaffold clásico.

**Por qué:** el scaffold no es una decisión de diseño del plugin, es la convención del
entorno, y ya está validada en los plugins existentes. Sin este ADR aprobado, el gate
bloquearía el primer `Write` del scaffold —que necesariamente contiene
`register_activation_hook`, `uninstall.php` y un chequeo de capability— y no habría forma
de arrancar.

**Por qué no exigir un ADR por cada pieza del scaffold:** serían cinco aprobaciones para
llegar a un plugin que apenas carga. El scaffold se aprueba una vez, en bloque, al crear el
plugin.

**Alcance:** este ADR cubre **solo** la estructura. Cualquier tabla, endpoint, opción, hook
público o integración concreta necesita su propio ADR.

**Nombres y prefijos.** El slug completo es inmanejable como prefijo de clase
(`Woonderful_Stock_Sync_For_WooCommerce_Loader`). La separación queda así:

| Elemento | Valor |
|---|---|
| Archivo principal | `woonderful-stock-sync-for-woocommerce.php` |
| Text domain | `woonderful-stock-sync-for-woocommerce` |
| Namespace raíz | `Woonderful\StockSync` |
| Clases | `Loader`, `Ledger`, `Reconciler`, `Admin`… sin prefijo |
| Archivos de clase | `includes/Loader.php` (PSR-4: un archivo por clase, mismo nombre) |
| Constantes | `WSS_` |
| Hooks públicos y opciones | `wss_` |

El archivo principal y el text domain llevan el slug completo porque WordPress.org lo exige.
Las constantes y los hooks conservan el prefijo `wss_` porque **viven en el espacio global**:
PHP no aplica namespaces a los nombres de hooks ni a las claves de opciones.

**Por qué namespace y no el prefijo clásico de WordPress:** el plugin exige PHP 8.1 y va a
tener bastantes clases (libro, cola, transporte, reconciliación, REST, detección, admin). Con
prefijo, cada una arrastra 21 caracteres antes del nombre útil, y el `composer.json` con
autoload PSR-4 que pide el scaffold queda decorativo: PSR-4 mapea **namespaces** a
directorios, así que con clases prefijadas habría que cargar todo con `require_once` a mano.
WordPress.org acepta plugins con namespaces sin objeción.

**Por qué `Frontend` y no `Public`:** `public` es palabra reservada en PHP y no puede ser
nombre de clase ni segmento de namespace. La carpeta `public/` del scaffold clásico se
vuelve `includes/Frontend.php`.

**Por qué el PHP va en `includes/` y no en `src/`:** `@wordpress/scripts` usa `src/` para el
JavaScript por defecto, y la pantalla de estado (UC-05) es React. Dejar el PHP en `includes/`
evita mezclar dos mundos en la misma carpeta, no obliga a reconfigurar el build, y es donde
un revisor de WordPress.org espera encontrar el PHP. PSR-4 mapea el namespace a cualquier
directorio, así que no cuesta nada:

```json
{ "autoload": { "psr-4": { "Woonderful\\StockSync\\": "includes/" } } }
```

**Consecuencias:** el plugin depende de Composer para generar el autoloader, y el `vendor/`
con el autoload tiene que viajar en el paquete que se publica en .org. Si en algún momento se
quisiera eliminar esa dependencia, habría que escribir un autoloader propio o volver a
`require_once`.

---

## ADR-001 — Esquema del libro de movimientos

Estado: Aprobada
Temas: db-schema
Cubre: includes/Db/Schema.php, includes/Db/MovementRepository.php, includes/Db/ProductStateRepository.php, includes/Activator.php

**Decisión:** el libro vive en **dos tablas propias**, con los movimientos inmutables y un
snapshot de estado por SKU. Los movimientos se aplican con el **patrón inbox**: primero se
registran, después un job los aplica, y el paso de pendiente a aplicado se toma con un claim
atómico en la base.

### Tabla `{prefix}wss_movements` — el libro

| Columna | Tipo | Para qué |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | Clave local, sin significado entre tiendas |
| `origin_store` | VARCHAR(32) | Tienda que originó el movimiento |
| `sequence` | BIGINT | Secuencia propia de esa tienda |
| `sku` | VARCHAR(100) | Identificador global del producto (§3 del SPEC) |
| `delta` | INT | Puede ser negativo; en el bootstrap, el valor fijado |
| `reason` | VARCHAR(20) | `bootstrap`, `sale`, `refund`, `adjustment`, `reconciliation` |
| `reference` | VARCHAR(64) NULL | Pedido u origen concreto, para el historial de UC-05 |
| `created_at` | DATETIME | Momento en la tienda de origen |
| `received_at` | DATETIME | Momento en esta tienda |
| `status` | VARCHAR(12) | `pending`, `claimed`, `applied`, `rejected` |
| `applied_at` | DATETIME NULL | |
| `claimed_at` | DATETIME NULL | Marca del claim, para detectar jobs colgados |

- `UNIQUE KEY (origin_store, sequence)` — **la garantía de idempotencia.**
- `KEY (sku, id)` — para reconstruir el historial y sumar movimientos por producto.
- `KEY (status, claimed_at)` — para que el job encuentre lo pendiente sin recorrer la tabla.

### Tabla `{prefix}wss_product_state` — el snapshot

| Columna | Tipo | Para qué |
|---|---|---|
| `sku` | VARCHAR(100) PRIMARY KEY | |
| `base_qty` | INT | Stock base: el valor fijado en el bootstrap (UC-07) |
| `base_up_to` | BIGINT | Hasta qué `id` de movimiento está consolidado el base |
| `bootstrapped_at` | DATETIME | |

El stock esperado de la fase 2 (UC-06) es `base_qty` más la suma de los `delta` con
`id > base_up_to`. **Al purgar a los 90 días, `base_qty` absorbe los deltas purgados y
`base_up_to` avanza**, de modo que el resultado no cambia. Esa es la restricción dura que
salió de la revisión del SPEC: purgar sin consolidar haría que la fase 2 reportara
discrepancias falsas en todos los productos con historia.

### Idempotencia y ejecución única

Son dos problemas distintos y se resuelven en capas distintas:

- **Un movimiento no se registra dos veces:** lo impide el índice único. Un movimiento
  repetido choca contra la base y se descarta; no hay consulta previa, que tendría una
  condición de carrera entre el `SELECT` y el `INSERT`.
- **Un movimiento registrado no se aplica dos veces:** el job toma el trabajo con un claim
  atómico —`UPDATE … SET status='claimed' WHERE id=? AND status='pending'`— y solo
  continúa si afectó exactamente una fila. **Action Scheduler no garantiza ejecución única**:
  dos pasadas de cron concurrentes pueden tomar el mismo job, así que la exclusión tiene que
  estar en la base y no en la cola. Un claim con `claimed_at` viejo se considera un job
  colgado y puede volver a tomarse.

**Por qué:** el modelo es *event sourcing con snapshot*, el patrón estándar cuando el estado
se deriva de una secuencia de eventos y esa secuencia no puede guardarse para siempre. La
separación en dos tablas es lo que permite purgar sin perder la capacidad de verificar.

**Por qué no post meta:** miles de filas por producto en `wp_postmeta` degradan todas las
consultas del sitio, no permiten indexar "movimientos posteriores a la secuencia N", y el
purgado periódico sería inviable.

**Por qué no una sola tabla con el bootstrap como fila mutable:** al purgar habría que
reescribir esa fila, y un registro que se reescribe deja de ser un registro histórico. Se
pierde la auditabilidad, que es la razón de tener un libro.

**Por qué no envolver todo en una transacción InnoDB:** la escritura de stock pasa por
WooCommerce, que mantiene cachés propias y dispara hooks de terceros. Un plugin ajeno
ejecutándose dentro de nuestra transacción puede alargarla o romperla.

**Consecuencias:**

- Hay dos tablas que mantener coherentes, y la coherencia depende de que el purgado consolide
  correctamente. Un bug ahí produce discrepancias silenciosas: conviene un test que purgue y
  verifique que el stock esperado no cambió.
- El job de aplicación puede quedar rezagado: entre que un movimiento se registra y se
  aplica, el stock local todavía no refleja el cambio. Es coherente con §5, que no promete un
  plazo máximo de propagación.
- `delta` es entero. Si alguna vez hiciera falta soportar stock decimal —hay plugins que lo
  agregan a WooCommerce— habría que migrar la columna.
- El estado de emparejamiento (*sin SKU*, *sin coincidencia*, *duplicado*) **no se almacena**:
  es derivado, como fija UC-03. La tabla de estado guarda solo lo que no se puede recalcular.
  La excepción a resolver en su propio ADR es qué inconsistencias ya fueron avisadas por
  email (UC-08), que sí es estado persistente.

---

## ADR-002 — Toolchain de calidad y estrategia de tests

Estado: Aprobada

Temas: dependencias

Cubre: composer.json, phpcs.xml, phpstan.neon.dist, phpunit.xml.dist, tests/bootstrap.php, tests/*.php

**Decisión:** PHPCS con WPCS, PHPStan nivel 6, y **tests de integración con la suite oficial
de tests de WordPress** corriendo contra una base de datos dedicada, separada de la del
sitio. Los tests unitarios con dobles quedan solo para lo que es validación pura de PHP.

**Por qué de integración y no unitarios:** las dos invariantes que ADR-001 se propone
garantizar **no viven en el PHP**. La idempotencia la hace cumplir
`UNIQUE KEY (origin_store, sequence)`, y la ejecución única la hace cumplir el `UPDATE`
condicionado del claim. En los dos casos el código PHP solo mira cuántas filas se afectaron;
quien decide es MySQL.

Un `$wpdb` simulado devuelve lo que el test le pidió que devuelva. Un test así **pasa
igual si se borra el índice único de `Schema.php`**: no verifica la invariante, verifica el
mock. Once de los trece casos de `tests/TEST-CASES.md` están en esa situación, incluido
TC-08 —purgar sin que cambie el stock esperado—, que es la restricción dura de ADR-001 y
cuyo modo de falla es silencioso: discrepancias falsas en todo el catálogo con historia.

**Aislamiento de los datos del sitio.** La suite de WordPress hace `DROP DATABASE` de su
propia base al inicializarse, para arrancar limpia. Por eso la base de tests es **siempre
una base dedicada** (`wss_tests`), nunca la del sitio. En un entorno donde varios sitios son
copias de producción con datos reales de clientes, esto no es una preferencia: es la
condición para poder correr tests ahí.

**Opciones consideradas:**

- **A — Unitarios con Brain\Monkey**, como `woonderful-sync`. Es la convención del entorno,
  no necesita infraestructura y corre en segundos. Se descarta porque no verifica ninguna de
  las dos invariantes del ADR-001: sería cobertura de adorno exactamente donde más importa.
- **B — Suite oficial de WordPress** (elegida). `WP_UnitTestCase` envuelve cada test en una
  transacción que revierte al terminar, así un caso no ensucia al siguiente. Es lo que usa el
  ecosistema de WooCommerce, así que hay documentación y ejemplos.
- **C — Bootstrap propio** que cree una base dedicada y arme un `$wpdb` a mano. Evita
  instalar la suite, pero deja un arnés a medida que hay que mantener para siempre y no gana
  nada que B no dé.

**Dependencias que agrega:**

| Paquete | Para qué |
|---|---|
| `wp-coding-standards/wpcs` | PHPCS con las reglas de WordPress |
| `dealerdirect/phpcodesniffer-composer-installer` | Registra los standards de PHPCS |
| `phpcompatibility/phpcompatibility-wp` | Verifica el mínimo de PHP declarado |
| `phpstan/phpstan` | Análisis estático |
| `php-stubs/wordpress-stubs` | Firmas de WordPress para PHPStan |
| `phpunit/phpunit` ^9.6 | El runner. La versión la fija la suite de WP |
| `yoast/phpunit-polyfills` | Requisito de la suite de WP |

**Por qué PHPStan nivel 6 y no más:** el 6 exige tipos en firmas y detecta los errores que
importan sin obligar a anotar genéricos de array en cada método. Subir a 8 en un plugin que
habla con `$wpdb` —que devuelve `mixed` por todos lados— produce más ruido que señal.

**Consecuencias:**

- Los tests **no corren en el host**: necesitan MySQL y las extensiones PHP del contenedor.
  Se corren con `make test-plugin SITE=<slug> PLUGIN=woonderful-stock-sync-for-woocommerce`,
  lo que implica que el plugin esté listado en `DEV_PLUGINS` del `site.env` de ese sitio.
- Son más lentos que unitarios: del orden del minuto contra segundos. Aceptable para una
  suite que hoy son trece casos.
- Si algún día se quisiera correr los tests en CI sin Docker, habría que levantar un MySQL
  en el runner. Es lo normal en GitHub Actions y no se considera un bloqueo.
- `vendor/` queda con dependencias de desarrollo que **no deben viajar** al paquete que se
  publica en WordPress.org: el release tiene que correr `composer install --no-dev`.

