# Woonderful Stock Sync for WooCommerce — Arquitectura técnica

> Última actualización: 2026-09-20
> Documentación para desarrolladores: cómo funciona, por qué se diseñó así, cómo extenderlo.

Las decisiones y su justificación viven en `DECISIONS.md`. Acá se documenta el **resultado**
de esas decisiones, con referencia al ADR que las respalda.

**Estado:** el plugin está en diseño; no hay código todavía. Las secciones marcadas
_(pendiente de ADR-004)_ se completan cuando se apruebe el esquema.

---

## 1. Modelo de datos

> ADR-001 (dos ejes de estado), ADR-002 (identidad y numeración), ADR-004 (esquema).

Tres tablas propias, con el prefijo de WordPress más `wss_`:

| Tabla | Qué guarda |
|---|---|
| `{prefix}wss_movements` | El libro: los movimientos propios y los recibidos, en la misma tabla |
| `{prefix}wss_links` | El par de productos, su generación y su stock base. **Sin columna de estado**: vinculado, suspendido y sin vincular se derivan (UC-03, ADR-004 §2) |

**La generación de un par es la secuencia del bootstrap que lo abrió** (ADR-005 §4): nunca se
repite, así que un movimiento de una época anterior no puede colarse como vigente.
| `{prefix}wss_nonces` | Los nonces vistos, para rechazar un request reenviado |

**El libro es también la cola** (ADR-001): no hay tabla de pendientes. Cada movimiento propio
lleva en su fila el estado de entrega, y eso es lo único que dice qué falta entregar.

**Dos ejes de estado, y ninguna fila lleva los dos** (ADR-001 §3):

```
delivery     pending · in_flight · confirmed · abandoned      sólo en las filas propias
application  applied · held · discarded                       sólo en las recibidas
```

**La secuencia de un movimiento propio es el `AUTO_INCREMENT` de su fila** (ADR-002). Tiene
huecos —las filas recibidas consumen números, y un `INSERT` revertido gasta el suyo— y **nada
puede deducir de un hueco que falta un movimiento**: eso lo dice la reconciliación comparando
listas.

**Tres índices en el libro, y ni uno más** (ADR-004). El cuarto candidato —la purga por
fecha— se rechaza a propósito: corre una vez por noche y costaría en cada venta.

```sql
UNIQUE KEY dedup          (origin_store, origin_seq)   -- la deduplicación
KEY       delivery_queue  (delivery, next_attempt_at)  -- la pasada
KEY       by_link         (link_id, generation)        -- la fase 2 y el historial
```

**Las tablas son `ENGINE=InnoDB` explícito**, no lo que el host tenga de default: hay dos
escrituras que tienen que ser atómicas o los números quedan mal — numerar un movimiento propio
(`INSERT` + `UPDATE` de la secuencia) y purgar consolidando el stock base.

**Convención no obvia:** `origin_seq` va en `NULL` entre el `INSERT` y el `UPDATE`, nunca en 0.
En un índice `UNIQUE` de MySQL, `NULL` no choca con `NULL`; dos ceros sí, y dos ventas
simultáneas se pisarían.

**Retención — 90 días, con tres reglas duras** (ADR-004):

- Sólo se purga lo terminado: propias `confirmed`, recibidas `applied` o `discarded`. Una
  `abandoned` o una `held` se queda.
- Al purgar, `base_stock` absorbe la suma de lo purgado, en la misma transacción.
- Cada tienda declara en `wss_peers` hasta qué secuencia de la otra purgó, y la fase 1
  compara sólo por encima de esa marca. Sin eso, la reconciliación re-entrega lo purgado y el
  movimiento se aplica dos veces.

Columnas completas: ver ADR-004.

**Migraciones:** versión de esquema en la opción `wss_schema_version`, aplicada con `dbDelta`
en la activación y en `plugins_loaded` si la guardada es menor.

---

## 2. Contrato público

**Namespace PHP:** `Woonderful\StockSync\` · **Prefijo global:** `wss_` / `WSS_`

### API REST entre tiendas — `wss/v1`

> ADR-001. Es el contrato entre las dos tiendas: romper un campo es `wss/v2`.

| Endpoint | Método | Autenticación | Descripción |
|---|---|---|---|
| `/pair` | POST | Firmado con el código de emparejamiento | Canje del código, una sola vez |
| `/movements` | POST | HMAC con el secreto compartido | Entrega un lote (máximo **200**) |
| `/reconcile/digest` | POST | HMAC con el secreto compartido | Fase 1 de la reconciliación |
| `/health` | GET | HMAC; durante el canje, con el código | Canal, firma y versión del otro lado |
| `/products` | GET | HMAC con el secreto compartido | Sólo lectura del catálogo, paginado, para la pantalla de vinculación (ADR-005) |

**Cabeceras de firma:** `X-WSS-Store`, `X-WSS-Timestamp`, `X-WSS-Nonce`, `X-WSS-Signature`.
Ventana de reloj: ±5 minutos.

**Campos de un movimiento**, todos en inglés (ADR-001 §4):

```json
{ "id": "A-77", "generation": 4, "delta": -1,
  "product": 123, "reason": "sale", "reference": "pedido #4512" }
```

`reason`: `sale` · `refund` · `adjustment` · `bootstrap` · `unlink`.

**El bootstrap y el `unlink` viajan como movimientos**, con `delta: 0` y los campos extra en
`details` (ADR-005). Van por la cola y no por un endpoint aparte para que no adelanten a una
venta que quedó en vuelo.

**Dentro de `wss/v1` el receptor ignora los campos que no conoce.** Sumar campos es
compatible; quitar uno o cambiarle el significado es `wss/v2`.

### Opciones

| Opción | Descripción |
|---|---|
| `wss_store_id` | UUID v4 de `wp_generate_uuid4()`. Inmutable (ADR-002). **Autocargada** |
| `wss_store_label` | Etiqueta legible, editable |
| `wss_site_fingerprint` | `sha256( get_option( 'home' ) )` al emparejar, con esquema. Detecta el clon (ADR-001, ADR-007) |
| `wss_peers` | Por tienda remota: URL, identificador, secreto, fecha del emparejamiento y **hasta qué secuencia suya purgamos** (ADR-004) |
| `wss_schema_version` | Versión del esquema, para las migraciones. **Autocargada**: `maybe_upgrade()` la lee en cada request |
| `wss_schema_healthy` | Si las tres tablas existen y admiten transacciones. **Autocargada**: la guarda de arranque la lee en cada request |
| `wss_delete_data_on_uninstall` | La casilla de ADR-003. Desmarcada de fábrica |

### Hooks para terceros

Todavía ninguno. El primero que se agregue pasa a ser contrato público y necesita ADR con
`Temas: hooks-publicos`.

---

## 3. Límites de módulos

```
woonderful-stock-sync-for-woocommerce/
├── woonderful-stock-sync-for-woocommerce.php   # Bootstrap: constantes, activación
├── uninstall.php                               # Existe desde el día uno (ADR-003)
├── includes/                                   # Woonderful\StockSync\  (PSR-4)
│   ├── Transport/                              # ADR-001
│   ├── Identity/                               # ADR-002
│   ├── Ledger/                                 # el libro
│   ├── Detector/                               # ADR-006
│   ├── Linking/                                # pendiente de ADR
│   └── Reconciliation/
├── admin/                                      # Sólo se carga con is_admin()
├── docs/
└── tests/
```

**Una opción que se lee en cada request va autocargada, y se prueba mirando la columna
`autoload`**, no contando consultas: un test que escribe la opción y después la lee mide su
propia caché, no el autoload. `SCH-12` e `ID-13` lo verifican directo.

**Lo que corre en cada request tiene que ser barato.** `Plugin::boot()` se ejecuta en cada
carga de la tienda, así que ahí no va ninguna consulta que se pueda evitar: lo que no cambia
solo —el motor de las tablas, la versión del esquema— se calcula al instalar o al migrar y se
guarda en una opción **autocargada**. `PLG-09` lo verifica contando consultas.

**Regla de dependencias:** `includes/` no conoce `admin/`; `admin/` consume `includes/`.
Nada de `includes/` imprime HTML.

**El detector lee el stock de la base, nunca del objeto del producto.** `Detector` engancha
los pares `before`/`set` de producto y variación y en cada uno hace un `SELECT` directo a
`_stock`: el delta es la diferencia. El objeto que entrega el hook puede venir desactualizado
en una venta, y ya trae el valor nuevo en el `before` de un guardado (ADR-006 §1). Se
registra en `boot()` después de la guarda: un sitio suspendido no emite (`PLG-17`).

**El motivo se estampa sobre la fila recién escrita.** El detector recuerda, por producto, el
movimiento que acaba de escribir; cada `set` pisa o borra ese recuerdo. Los tres hooks de
pedido lo consumen y llaman a `Ledger::stamp()`, que sólo toca una fila propia que siga
`pending`: lo que la cola ya se llevó viaja con el motivo que tenía. La referencia es
`pedido #` más `get_order_number()`, lo que ve el dueño (ADR-006 §2).

**Depende de WooCommerce activo**, verificado con `class_exists( 'WooCommerce' )`. Sin él, el
plugin no registra nada y muestra un aviso en el admin. Action Scheduler viene dentro de
WooCommerce (§5 del SPEC exige 9.1+), así que no es una dependencia aparte.

---

## 4. Trabajo diferido

> ADR-001 §2.

| Job | Mecanismo | Frecuencia | Idempotencia |
|---|---|---|---|
| Vaciar pendientes hacia la otra tienda | Action Scheduler, **una** acción recurrente | Configurable | El receptor deduplica por `UNIQUE (origin_store, origin_seq)` |

**No se agenda una acción por movimiento**: la pasada toma un lote del libro.

**Cada pasada, en orden:** libera los claims vencidos (5 min) → reclama un lote → envía y
anota el ACK de cada fila. La purga de nonces viejos va en la misma pasada.

**Reintentos:** backoff creciente, con **techo de 6 horas desde el primer intento**. Agotado,
el movimiento pasa a `abandoned` y lo rescata la reconciliación nocturna, que lo devuelve a
`pending` **con el reloj en cero**.

**Si el cron no corre:** no se pierde nada —el libro conserva todo— pero el stock diverge
hasta que vuelva. La reconciliación nocturna lo repara; §5 del SPEC acepta hasta 24 h.

---

## 5. Seguridad

| Acción | Capability | Nonce | Sanitización | Escapado |
|---|---|---|---|---|
| Pantalla de ajustes y emparejamiento | `manage_woocommerce` | sí | `sanitize_text_field`, `absint` | `esc_html`, `esc_attr` |
| Endpoints `wss/v1` | — (firma HMAC) | nonce propio de la firma | por campo, según el tipo | `wp_send_json` |

**Datos sensibles:** el secreto compartido por par de tiendas. Vive en `wss_peers`, se borra
siempre al desinstalar (ADR-003) y **nunca aparece en el log ni en un mensaje de error**.
Tampoco se loguea el código de emparejamiento.

**La firma no depende de los permalinks.** `Transport\Signature` firma la ruta REST de
`get_route()` y la query ordenada sin `rest_route`, así que el mismo request verifica igual en
una tienda con permalinks y en una sin ellos. La compara con `hash_equals()`. Sólo cubre la
firma y la ventana de ±5 minutos: que el nonce sea nuevo y que la tienda esté emparejada lo
deciden el almacén de nonces y quien busca el secreto (ADR-001 §1).

**Queries directas:** siempre con `$wpdb->prepare()`. El libro se toca sólo desde `Ledger/`.

---

## 6. Convenciones de código

**PSR-4 con namespaces, y todo lo demás según WordPress.**

```
Namespace raíz:  Woonderful\StockSync\
Autoload:        PSR-4 por Composer, includes/ → Woonderful\StockSync\
Archivos:        el archivo se llama como la clase     Transport/Sender.php → class Sender
```

| Elemento | Convención | Ejemplo |
|---|---|---|
| Clases, interfaces, traits | `PascalCase`, una por archivo | `class Sender` |
| Métodos y funciones | `snake_case` | `public function send_batch()` |
| Variables y propiedades | `snake_case` | `$next_attempt_at` |
| Constantes de clase | `UPPER_SNAKE` | `const MAX_BATCH = 200;` |
| Constantes globales | `UPPER_SNAKE` con prefijo | `WSS_PLUGIN_FILE` |
| Hooks | `snake_case` con prefijo | `wss_movement_applied` |
| Opciones | `snake_case` con prefijo | `wss_store_id` |
| Tablas | prefijo de WP + `wss_` | `{prefix}wss_movements` |
| Columnas | `snake_case`, en inglés | `first_attempt_at` |
| Text domain | el slug del plugin | `woonderful-stock-sync-for-woocommerce` |

**Los métodos van en `snake_case` aunque estén en un namespace**, porque es lo que pide
`WordPress.NamingConventions.ValidFunctionName` y lo que hace que el código se lea como el
WordPress que tiene alrededor. El namespace resuelve el prefijo, no el estilo.

**El prefijo es para lo que sale al espacio global.** Una clase dentro del namespace no lo
necesita: `Woonderful\StockSync\Transport\Sender` no puede colisionar con nada. Sí lo llevan
las opciones, las tablas, los hooks y las constantes globales, que no tienen namespace.

**El idioma:** el código, la base de datos y el contrato REST, en **inglés**. Los comentarios,
los ADRs y los textos de la interfaz (traducibles), en castellano.

### Tests

| Elemento | Convención | Ejemplo |
|---|---|---|
| Archivo | `tests/test-{componente}.php`, minúsculas con guiones | `tests/test-installer.php` |
| Clase | `Test_{Componente}` | `class Test_Installer` |
| Método | `test_{sujeto}_{comportamiento}[_{condición}]`, **en inglés** | `test_maybe_upgrade_does_nothing_when_version_is_current()` |
| Docblock | Primera línea `{ID} — {qué verifica}`, **en castellano** | `SCH-01 — install() sobre una base limpia crea las tres tablas.` |
| ID del caso | Prefijo por dominio + número, en `tests/TEST-CASES.md` | `SCH-04`, `ID-05` |

**El nombre del método está en inglés porque es código**, igual que todo lo demás; el
docblock en castellano porque es un comentario. No es una distinción caprichosa: PHPUnit
imprime el nombre del método convertido en frase, así que es lo que se lee cuando algo falla.

```
test_two_own_movements_coexist_with_null_sequence()
   ↓ lo que imprime PHPUnit
 ✔ Two own movements coexist with null sequence
```

**Un método de test hace una sola afirmación conceptual.** Si el nombre necesita un "y", son
dos tests.

**Un test nuevo no está listo hasta haberlo visto fallar.** Escribirlo antes del código, o
romper el código a propósito, comprobar que el mensaje de error nombra el problema, revertir
con `git checkout` —nunca a mano— y correr la suite entera.

**Commitear antes de romper, siempre.** `git checkout` devuelve el archivo a su última
versión commiteada, así que se lleva puesto **todo** lo que no esté commiteado, no sólo la
mutación. Si el archivo es nuevo, directamente no tiene a dónde volver. Las dos veces que
pasó en este plugin el síntoma fue el mismo: tests en rojo después de "revertir". Aplica a los tests que verifican
una decisión de un ADR y a los de un bugfix; no hace falta para wiring ni getters.

El motivo está medido, no es teoría: de los primeros diecisiete tests de este plugin, el
único que se rompió a propósito resultó estar mal escrito —contaba tres tablas en vez de
mirar cuáles eran— y daba verde con el esquema roto.

### Análisis estático

**PHPStan corre en nivel 8**, que marca el uso de algo que puede ser `null` o `false` sin
haberlo comprobado. En WordPress eso aparece todo el tiempo:

```php
$product = wc_get_product( $id );     // WC_Product o false
if ( ! $product instanceof \WC_Product ) {
    return;
}
$product->get_stock_quantity();
```

**Las filas de `$wpdb` se declaran `?\stdClass`, no `?object`.** Un `object` genérico no tiene
propiedades declaradas y el nivel 8 rechaza leerle cualquiera; `stdClass` sí las admite.

La regla vale también para los tipos de retorno: una función que puede no encontrar lo que
busca devuelve `?Tipo` y el que llama decide qué hacer, en vez de devolver un objeto vacío
que después nadie distingue de uno real.

### `phpcs.xml`

```xml
<rule ref="WordPress-Core"/>
<rule ref="WordPress-Docs"/>
<rule ref="WordPress-Extra"/>

<!-- PSR-4: el archivo se llama como la clase, no class-foo.php. -->
<!-- No lo exige el directorio: el ruleset de Plugin Check no incluye este sniff
     (verificado el 2026-09-20 contra phpcs-rulesets/plugin-check.ruleset.xml). -->
<rule ref="WordPress">
    <exclude name="WordPress.Files.FileName"/>
</rule>

<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
    <properties>
        <property name="prefixes" type="array">
            <element value="wss_"/>
            <element value="WSS_"/>
            <element value="Woonderful\StockSync"/>
        </property>
    </properties>
</rule>
```

---

## 7. Decisiones

Ver `DECISIONS.md`. Índice de las aprobadas:

| ADR | Título | Temas |
|---|---|---|
| ADR-001 | Transporte entre tiendas | rest-api, api-externa, db-schema, opciones |
| ADR-002 | Identidad de tienda y numeración de movimientos | db-schema, opciones, rest-api |
| ADR-003 | Desinstalación y borrado de datos | uninstall, opciones |
| ADR-004 | Esquema de la base de datos | db-schema |
| ADR-005 | Vinculación de productos | db-schema, rest-api |
| ADR-006 | El detector de cambios de stock | hooks-publicos, db-schema |
| ADR-007 | Qué se hashea en la huella del sitio | opciones |
