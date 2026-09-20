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
| `{prefix}wss_links` | El par de productos, su generación y su stock base |
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

**Cabeceras de firma:** `X-WSS-Store`, `X-WSS-Timestamp`, `X-WSS-Nonce`, `X-WSS-Signature`.
Ventana de reloj: ±5 minutos.

**Campos de un movimiento**, todos en inglés (ADR-001 §4):

```json
{ "id": "A-77", "generation": 4, "delta": -1,
  "product": 123, "reason": "sale", "reference": "pedido #4512" }
```

`reason`: `sale` · `refund` · `adjustment` · `bootstrap`.

**Dentro de `wss/v1` el receptor ignora los campos que no conoce.** Sumar campos es
compatible; quitar uno o cambiarle el significado es `wss/v2`.

### Opciones

| Opción | Descripción |
|---|---|
| `wss_store_id` | Identificador opaco de 128 bits. Inmutable (ADR-002) |
| `wss_store_label` | Etiqueta legible, editable |
| `wss_site_fingerprint` | `sha256( home_url() )` al emparejar. Detecta el clon (ADR-001) |
| `wss_peers` | Por tienda remota: URL, identificador, secreto, fecha del emparejamiento y **hasta qué secuencia suya purgamos** (ADR-004) |
| `wss_schema_version` | Versión del esquema, para las migraciones |
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
│   ├── Detector/                               # pendiente de ADR
│   ├── Linking/                                # pendiente de ADR
│   └── Reconciliation/
├── admin/                                      # Sólo se carga con is_admin()
├── docs/
└── tests/
```

**Regla de dependencias:** `includes/` no conoce `admin/`; `admin/` consume `includes/`.
Nada de `includes/` imprime HTML.

**Depende de WooCommerce activo**, verificado con `class_exists( 'WooCommerce' )`. Sin él, el
plugin no registra nada y muestra un aviso en el admin. Action Scheduler viene dentro de
WooCommerce (§5 del SPEC exige 8.2+), así que no es una dependencia aparte.

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
