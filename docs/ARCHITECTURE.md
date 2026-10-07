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
| `{prefix}wss_links` | El par de productos, su generación y su stock base. **Sin columna de estado**: vinculado, suspendido y sin vincular se derivan (UC-03, ADR-004 §2). Desde la versión 2 del esquema, las cuatro columnas de una discrepancia abierta —`expected_stock`, `found_stock`, `discrepancy_since`, `discrepancy_seen_at`—, en `NULL` si no hay (ADR-010 §4) |

**La generación de un par es la secuencia del bootstrap que lo abrió** (ADR-005 §4): nunca se
repite, así que un movimiento de una época anterior no puede colarse como vigente.
| `{prefix}wss_nonces` | Los nonces vistos, para rechazar un request reenviado |
| `{prefix}wss_events` | El log: lo que pasa y no es un movimiento —acciones del dueño, intentos fallidos, la otra tienda que se cae, reconciliaciones, discrepancias, pares rotos—. El texto se arma al mostrarlo (`Log\Events::describe()`). 90 días, salvo el descarte y lo avisado (ADR-011). Escriben: `Linker` —cada intento, `link_unresolved` una vez por bootstrap con la marca `unresolved` en su `last_error`—, `Sender` —lo abandonado salvo con la otra caída, y `ledger_error`, con lo que deja `Ledger::last_claim()`—, `Reconciler` y `Rest\Reconcile` —`reconciled`, cada tienda la suya—, `Audit` y `Books` |

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

**La autenticación es una sola**, `Rest\Auth::authenticate()`, y va en el
`permission_callback` de cada ruta. Toma el cuerpo como `(string) get_body()`: sin cuerpo,
como en un GET, WordPress devuelve `null`.

**Ninguna respuesta de `wss/v1` se guarda en caché**, tampoco las de error. `Auth::no_cache()`,
en `rest_post_dispatch`, les suma los encabezados de `wp_get_nocache_headers()`. WordPress los
manda sólo con un usuario logueado, y estos requests vienen firmados: sin ellos, una caché que
guarda por URL —Varnish, la de Nginx, "cache everything" de Cloudflare— le daría la respuesta
de un request firmado a cualquiera que pida la misma URL, sin que la autenticación corra. Con
`GET /products` eso sería el catálogo con el stock. Se engancha antes de la guarda.

**`POST /pair`** (`Rest\Pair`, `Transport\Pairing`, ADR-001 §1) autentica con el código que
emitió esta tienda, no con un secreto, y cualquier falla del código es `wss_pairing_invalid`.
`Pairing::accept()` llama a `GET /health` de la secundaria firmado con el código y comprueba
que su `store` sea el que dijo; recién ahí genera el secreto, guarda a la otra con
`Peers::pair()` —que borra la URL y el secreto de cualquier otra entrada— y su propia huella
con `Store::remember_fingerprint()`, y borra el código. Del otro lado, `Pairing::redeem()`
guarda el código en `wss_redeeming` mientras dura el `POST /pair`, y **`health` verifica con
el código mientras dura el canje**, aunque ya haya un secreto de esa maestra: si no,
re-emparejar no pasaría nunca el callback. Https en los dos lados, salvo en `local` y
`development`. Se registra antes de la guarda: re-emparejar es la forma de salir de la
suspensión por un cambio de URL.

**Mientras no hay pantalla, WP-CLI** (`Cli\Command`): `wp wss pairing-code` en la maestra y
`wp wss pair <código>` en la secundaria. Se registra antes de la guarda, como `/pair`. Para
PHPStan hay un stub propio de tres métodos en `tests/stubs/wp-cli.php`, en vez de la
dependencia `php-stubs/wp-cli-stubs`: si el comando crece, conviene la dependencia, con su ADR.

**El bootstrap y el `unlink`** (ADR-005, ADR-008). Del lado de la secundaria, `Ledger::apply()`
los atiende **antes** de buscar el vínculo, porque uno lo crea y el otro lo borra. El bootstrap
pasa las revisiones de ADR-008 §3 sin escribir nada —si una falla, vuelve su motivo como
`error`— y después, en una transacción de `Ledger::applying()`, guarda la fila con los campos en
`details`, **borra cualquier vínculo en conflicto** —un resto de un desvincular que no llegó—,
crea el par al revés y fija el stock con el guard. Un `unlink` viejo o ajeno se guarda
`discarded`. `GET /movements/<origen>/<secuencia>` responde `Ledger::state_of()`, sin anotar el
nonce. Del lado de la maestra, `Ledger::record_bootstrap()` escribe la fila `syncing` y el
vínculo juntos; `resolve_bootstrap()` anota cómo terminó y, si falló, borra el vínculo;
`record_unlink()` escribe el `unlink` con la generación del vínculo y lo borra. **`claim()`
retiene todo movimiento del vínculo de un bootstrap en `syncing` o `uncertain`**: por `link_id` y
no por generación, porque después de invertir los roles la generación de un vínculo recibido
puede coincidir con la secuencia de un bootstrap propio. Los `link_id` se leen en una consulta
aparte, no en una subconsulta del `UPDATE`, que MySQL rechaza con el error 1093 —y envolverla en
una tabla derivada depende de que el optimizador no la funda—. `resolve_bootstrap()` es
condicional —sólo toca uno que siga sin resolver— y va en una transacción, también por el
`link_id`. **Revisar el stock y escribir van en el mismo paso**, en las dos puntas: adentro de la
transacción, `SELECT … FOR UPDATE` sobre `_stock`, comparar y recién ahí escribir. Una venta que
llegue en ese momento espera el `COMMIT`, encuentra el vínculo armado y viaja después.

**`Linking\Linker`**, del lado de la maestra. `link()` revisa lo suyo sin salir a la red,
escribe el bootstrap y su vínculo con `record_bootstrap()`, lo manda en el momento con
`Sender::post()` y anota cómo terminó: `received` es `confirmed`; un rechazo o un 4xx del lote,
`failed` con su motivo, y el vínculo se borra; sin respuesta, un 5xx o un 200 que no se entiende,
`uncertain`. `resolve_uncertain()`, que corre en cada pasada, pregunta por los inciertos de más de
10 minutos con un GET firmado: `applied` confirma; cualquier otra respuesta da por fallido; sin
respuesta sigue incierto. `unlink()` escribe el `unlink`, que sale por la cola.

**`GET /products`** (`Rest\Products`, ADR-005 §5) devuelve simples, variaciones y padres
variables, la papelera incluida, de a 100 por página como mucho y ordenados por ID, con `id`,
`sku`, `name`, `stock` —`null` si el producto no gestiona su propio stock, y **leído de la base**,
que es contra lo que compara el bootstrap—, `status` y `linked`. **Con `include=1,2,3` vuelve cada
producto pedido, de cualquier tipo y estado, sin páginas**: se buscan los posts por ID, no por tipo
de WooCommerce, así que uno que no vuelve ya no existe. Hasta 100 IDs por request. No anota el
nonce. Se registra después de la guarda. `linked` y el stock salen de una consulta por página.
Del otro lado, `Transport\Catalog::fetch()` lo llama con la query en texto —la misma que firma—,
reparte `include` de a 100, no pide nada con una lista vacía y descarta las entradas mal armadas.

**El estado de sincronización** (`Linking\State::of()`, ADR-005 §1) se calcula al mostrarlo,
nunca se guarda: `unlinked` sin fila; `uncertain` si el bootstrap del vínculo sigue sin
resolver; `suspended` con el mismo criterio con que `apply()` retiene —los motivos de ADR-005 §1:
el producto de allá o el de acá en la papelera, borrado o sin su propio stock, o un vínculo con
una tienda que ya no es la socia—; y `linked` el resto. "Gestiona su propio stock" es una sola
función, `Links::holds_own_stock()`, que usan `apply()`, el bootstrap, `Linker`, el catálogo y el
estado. Los vínculos salen de una consulta, y al catálogo se le pregunta de a 100: **si no
responde, o no hay socia, los vinculados siguen vinculados**, y `remote_error` dice por qué. Una
tanda que falla deja sin saber sólo a los suyos. `wp wss status <ids>` lo muestra.

**`GET /health`** (`Rest\Health`, ADR-001 §4) responde `store`, `label`, `version`, `api` y
`suspended` —`null`, `cloned_site` o `unhealthy_schema`—. Se registra **antes de la guarda**:
un sitio suspendido responde igual, para que la otra tienda pueda explicar por qué no le
llegan movimientos. Autentica sin anotar el nonce: sólo lee, y tiene que responder aunque la
tabla de nonces esté rota.

**`POST /movements`** (`Rest\Movements`, ADR-001 §4). La autenticación va en el
`permission_callback`, en este orden: tienda emparejada con secreto → timestamp → firma →
nonce. Cada rechazo tiene su código (`wss_unknown_store`, `wss_stale_timestamp`,
`wss_bad_signature`, `wss_replayed_nonce`, 401); después, `wss_bad_request` (400) y
`wss_batch_too_large` (413). Autenticado, responde 200 con un resultado por movimiento **en el
orden del lote**:

```json
{ "results": [
    { "origin": "b1b1…", "seq": 77, "status": "received" },
    { "origin": "b1b1…", "seq": null, "status": "error", "reason": "invalid_seq" }
] }
```

`received` es el ACK: la fila está escrita, se haya aplicado, retenido o descartado. Los
`reason` de error son `origin_mismatch`, `invalid_seq`, `invalid_generation`, `invalid_delta`,
`invalid_product`, `invalid_movement` y `not_stored`, cuando `apply()` no pudo guardar. Los
enteros se validan con el rango de su columna: `delta` es `INT`, `generation` `INT UNSIGNED`. El
`reason` de un movimiento se guarda como llega, recortado a 20; la referencia, a 100.

El secreto de cada tienda lo lee `Transport\Peers::secret()` de `wss_peers[<uuid>]['secret']`;
lo escribe el emparejamiento (5.7).

**El emisor** (`Transport\Sender::run()`, ADR-001 §2) manda a la única tienda emparejada
(`Peers::only()`): sin una sola con `rest_url` y secreto, no reclama nada. `rest_url` es la
base de la API de la otra tienda tal como ella la informó al emparejarse
(`https://otra.com/wp-json/wss/v1`, o con `?rest_route=` sin permalinks), y se le suma
`/movements`. Firma la ruta REST, no la URL. Espera 30 segundos, sin seguir redirecciones:
un POST redirigido llega como GET. Empareja los resultados por posición, y uno que trae el
`seq` de otra fila no confirma. Si falla el request entero, cada fila guarda el mismo motivo
—el mensaje de la red o `HTTP <estado> <código>`— en `last_error`.

**Lo de un pedido sale al final de su request** (`Transport\Express`, ADR-006 §5). Cuando el
detector estampa un movimiento con su pedido, lo anota. En `shutdown`, con `PHP_INT_MAX`, si hay
PHP-FPM o LiteSpeed, se cierra la conexión del cliente con `fastcgi_finish_request()` o
`litespeed_finish_request()` y se llama a `Sender::run()` con esos IDs y 5 segundos de timeout:
`claim()` se lleva sólo esas filas, sin mirar `next_attempt_at`, y con la misma retención de los
bootstraps. Sin ninguna de las dos no se manda nada y lo lleva la pasada. Tampoco si un envío
falló entero hace menos de una pasada: `Sender::run()` lo anota en el transient
`wss_send_failed`, de 60 segundos, y lo borra el primero que sale bien. Los ajustes a mano y las importaciones no se anotan: esperan la pasada,
para que la ráfaga se agrupe.

**La reconciliación, fase 1** (`Reconciliation\Reconciler`, ADR-009). Toma el candado y le
hace a la otra tienda dos preguntas por `POST /reconcile/digest` (`Rest\Reconcile`), de a
5.000 secuencias por tanda y cada tanda con su tramo:

1. `step: theirs` — "¿qué tenés de lo mío?", hasta el techo propio. La otra contesta la lista,
   su marca de purga **leída después de la lista**, y su techo. `Books::compare_own()` reencola
   lo propio que falta —abandonado siempre, confirmado sólo por encima de la marca de **esa
   tanda**— y confirma lo que llegó. Cada cambio es un `UPDATE` sobre el estado esperado.
2. `step: mine` — "esto tengo de lo tuyo", hasta el techo de la otra, con la marca propia. La
   otra hace lo mismo con lo suyo, y suma lo que hizo en su resultado (`peer`, por `session`).

3. `step: links` — sólo si lanza la maestra: sus vínculos hasta su techo. La secundaria
   (`Books::compare_links()`) **borra** el suyo que la maestra no tiene y no comparte ningún
   producto con uno de ella; **informa** el mismo par con otra generación y el que le falta. Si
   lo pide la secundaria, `403 wss_not_master`. La maestra guarda en el resultado los productos:
   `peer_unlinked`, `peer_missing` —sin los de un bootstrap sin resolver— y `peer_mismatched`.

Si esta tienda tiene el candado tomado, contesta `409 wss_reconciling`. Si la otra no responde
en la primera pregunta, no se toca nada; si se corta después, lo comparado queda.

**La fase 2** (`Reconciliation\Audit`, ADR-010). Al final de cada reconciliación —aunque la
otra se haya caído después de la primera pregunta—, esta tienda compara cada vínculo: lo
esperado es el stock base más lo propio y lo recibido aplicado, de ese vínculo y su generación.
No revisa un vínculo con retenidos, con el bootstrap sin resolver, ni un producto sin su propio
stock. Lo que no cierra no se abre: agenda `wss_audit_confirm` a los 2 minutos con el vínculo y
la diferencia, y se abre sólo si sigue igual. Una abierta igual se marca como vista; una que
vuelve a coincidir se cierra sola.

**La tercera pregunta** (`step: audit`) le pide a la otra su fase 2; contesta sus discrepancias
abiertas, que la maestra guarda en `peer_discrepancies`. Las nuevas se confirman 2 minutos
después, así que la maestra las ve en la reconciliación siguiente. Si falla sólo la tercera, la
fase 2 propia corre igual.

**Resolver una discrepancia** es una de dos acciones, porque corregir el stock a mano también
se anota y el esperado se mueve junto con el real (ADR-010 §6). Las dos rehacen la cuenta y se
niegan si la diferencia no es la guardada:

- `Audit::accept()` — "el stock de acá está bien": `Ledger::record()` de la diferencia como
  ajuste, sin tocar el stock. Viaja a la otra.
- `Audit::restore()` — "el libro está bien": `Guard::expect()` y `wc_update_product_stock()` con
  `increase` o `decrease`, que escribe `meta_value = meta_value + x`: una venta en el medio se
  suma, no se pisa. No se anota.

Hasta la pantalla, `wp wss discrepancies` y `wp wss discrepancy accept|restore <producto>`, cada
tienda con las suyas.

**Los avisos por email** (`Log\Notifier`, ADR-012). Cada tienda avisa de lo suyo, y cada aviso
sale de un evento del log. **El tick** corre con la pasada, cada minuto, aunque no haya nada que
mandar:

- Si la otra no está bien, le pregunta a `GET /health` (`Peer::probe()`) antes de decidir; a los
  30 minutos de caída o de rechazo, avisa una vez, y avisa que volvió si lo había avisado.
- Lee lo nuevo del log —`link_failed` que salió solo, `link_unresolved`, `pair_broken`—, salvo
  lo atribuido a la caída o al rechazo, y lo junta en la ventana de su causa.
- Manda lo de cada ventana cerrada: la primera de una causa sale en el momento, lo siguiente se
  junta 10 minutos. Si `wp_mail()` falla, `notify_failed` y se reintenta al cerrar la ventana.

**El resumen de la noche** lista lo que no sincroniza: publicado, con su propio stock, sin
vincular desde hace más del plazo —desde que se creó o se desvinculó—, sin descartar, y del que
no se avisó desde la última vez que quedó sin vincular. Hasta 50 por email, con el total.

**Como mucho 10 emails por día**: al llegar al tope sale uno último que remite al log. Los
emails son como los de woonderful-sync: texto plano, asunto con `[Woonderful Stock Sync]`, la
tienda y el panel al pie.

**Los pares rotos** (`Log\Pairs`, ADR-011 §7). La noche revisa, en cada tienda, sus propios
productos vinculados: borrado, en la papelera o sin su propio stock. Lo recuerda en
`broken_since` de `wss_links`, y registra `pair_broken` y `pair_restored` sólo en el cambio.

**El lote, la curva de backoff, las 6 horas y los 5 minutos del claim son constantes**, no
opciones (ADR-001, revisado el 2026-09-29).

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
| `wss_peers` | Por tienda remota: URL, identificador, secreto, etiqueta, **su rol** —`master` o `secondary`, ADR-008 §2—, fecha del emparejamiento y **hasta qué secuencia suya purgamos** (ADR-004) |
| `wss_schema_version` | Versión del esquema, para las migraciones. **Autocargada**: `maybe_upgrade()` la lee en cada request |
| `wss_schema_healthy` | Si las tres tablas existen y admiten transacciones. **Autocargada**: la guarda de arranque la lee en cada request |
| `wss_delete_data_on_uninstall` | La casilla de ADR-003. Desmarcada de fábrica |
| `wss_pairing` | El código que emitió esta tienda y su vencimiento (15 min), del lado de la maestra. Se guarda entero: para verificar un HMAC hace falta la clave. Se borra al primer canje exitoso y siempre al desinstalar |
| `wss_redeeming` | El código que esta tienda está canjeando, la maestra y el vencimiento (2 min), del lado de la secundaria. Se borra al terminar el canje y siempre al desinstalar |
| `wss_pass_checked_at` | Cuándo se controló por última vez que la pasada esté agendada. **Autocargada**: se lee en cada request. Se borra siempre al desinstalar (ADR-003) |
| `wss_send_failed` | Transient de 60 segundos: cuándo falló entero el último envío. Mientras exista, el final del request no manda (ADR-006 §5). Se borra siempre al desinstalar (ADR-003) |
| `wss_peer_state` | El estado de la otra tienda: `ok`, `unreachable` —error de red, timeout, 5xx— o `rejected` —un 4xx que no sea `409 wss_reconciling`—, desde cuándo, y si ya se avisó. Lo actualiza `Log\Peer::observe()` en cada request a la otra; sólo un cambio escribe en el log (ADR-011 §4). Se borra siempre al desinstalar |
| `wss_notify_enabled` · `wss_notify_email` · `wss_notify_after_hours` | Los avisos por email: si salen (por defecto sí), a quién (por defecto el administrador) y el plazo de lo que no sincroniza (24 horas). Ajustes del dueño: se borran con la casilla. `wp wss notify` los muestra y los cambia (ADR-012 §6) |
| `wss_notify_state` | Lo que los avisos recuerdan: hasta qué evento del log se leyó, la ventana de cada causa con lo que se juntó, y la cuenta del día. Se borra siempre |
| `wss_reconciling` | El candado de la reconciliación: `<token>\|<vence>`, 15 minutos. Se toma con `INSERT IGNORE`, o con un `UPDATE` que sólo pisa el valor vencido; se suelta con un `DELETE` que sólo borra el propio. Siempre con SQL directo, nunca con `get_option()` (ADR-009 §4). Se borra siempre al desinstalar |
| `wss_last_reconciliation` | Cómo terminó la última reconciliación en esta tienda: cuándo, quién la lanzó, `done`, `unreachable` o `busy`, y qué hizo. No autocargada (ADR-009 §7). Se borra siempre al desinstalar |

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
│   └── Reconciliation/                         # ADR-009, ADR-010
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
| Vaciar pendientes hacia la otra tienda, y purgar nonces | Action Scheduler, **una** acción recurrente: `wss_deliver`, grupo `woonderful-stock-sync` | 60 s, constante | El receptor deduplica por `UNIQUE (origin_store, origin_seq)` |
| La noche: reconciliar (sólo la maestra) y purgar el libro (las dos) | Action Scheduler, una acción simple `wss_nightly` que agenda la siguiente al correr. La agenda también el control horario de la pasada | A las 3 de la hora del sitio en la maestra; a las 4 en la secundaria, que sólo purga: purgar toma el candado, y con él tomado le contestaría "ocupada" a la maestra | El candado (ADR-009 §4). La purga lo toma también: si no puede, la hace la noche siguiente |
| El cierre de una importación | Action Scheduler, `wss_import_closed` con el techo como argumento. Lo agenda una pasada que manda un lote lleno, si no hay otro agendado | 5 min después; vuelve a mirar cada 5 min mientras quede algo de la ráfaga sin intentar o en camino | El candado |

**La noche es una acción por vez, no una recurrente con cron**: así la hora es la del sitio,
sin depender de cómo interpreta Action Scheduler la zona horaria de una expresión cron. Se
reagenda **sin `unique`**, porque Action Scheduler cuenta como agendada la acción que está
corriendo, y compara también los argumentos (`Reconciliation\Schedule`).

**`wp wss reconcile`** lanza la manual, sólo en la maestra. Si la otra no responde, termina con
error: "no se pudo mirar" no es "no había nada".

**No se agenda una acción por movimiento**: la pasada toma un lote del libro.

**`Transport\Pass`.** El callback se engancha **siempre**, antes de la guarda: un clon trae la
acción agendada desde la tienda real, y sin callback Action Scheduler la marcaría fallida cada
minuto. Es la pasada la que, suspendida, no hace nada.

**El agendado se controla en cualquier request, a lo sumo una vez por hora**, en
`action_scheduler_init`. Action Scheduler agenda la próxima vuelta **después** de ejecutar la
acción: un fatal a mitad —sin memoria, o PHP-FPM que mata el proceso— o cinco fallas seguidas
la dejan sin próxima vuelta. Controlarlo sólo en el admin podía dejar la tienda días sin
mandar. La hora del último control va en `wss_pass_checked_at`, autocargada, así que en cada
request se lee sin ir a la base; `unique` impide una segunda acción.

Al desactivar se desagenda y se borra esa hora; con eso alcanza, porque WordPress no deja
desinstalar un plugin activo. **Si WooCommerce se desactivó antes**, Action Scheduler no está
y la acción queda agendada: no rompe nada, porque al volver WooCommerce el callback está
enganchado y la pasada sigue.

**"Cada 60 segundos" depende del tráfico.** Action Scheduler lo dispara WP-Cron, que corre
cuando alguien visita el sitio: en una tienda sin visitas la pasada puede tardar bastante más.
Un cron real del servidor contra `wp-cron.php`, con `DISABLE_WP_CRON`, lo hace cada minuto de
verdad. El envío al final del request de la venta (5.9) lo resuelve para las ventas.

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

**Los nonces se guardan dos ventanas.** `Transport\Nonces::claim()` anota con `INSERT IGNORE`
y la clave primaria decide: una fila escrita es un nonce nuevo, cero es un reenvío, y un
error cuenta como visto. `purge()` borra lo anotado hace más de 10 minutos, porque un request
con el reloj adelantado sigue pasando la ventana hasta entonces. El receptor anota el nonce
**después** de verificar la firma.

**`apply()` no deja nada a medias.** Atrapa cualquier `\Throwable` —un `TypeError` de un hook
de terceros no es una `Exception`—, hace `ROLLBACK`, descarta la anotación del guard y limpia
la caché de **cada producto cuyo stock cambió** adentro de la transacción: el `ROLLBACK`
deshace la base pero no la caché, y con Redis WooCommerce seguiría vendiendo con el stock
deshecho. Los anota en el `before_set_stock`, que corre antes de escribir. Límite declarado:
lo que un tercero escriba adentro de la transacción que no sea stock queda con su caché vieja.

**Un `record()` adentro de `apply()` usa `SAVEPOINT`.** Si un hook cambia el stock de otro
producto vinculado mientras `apply()` corre, el movimiento de ese cambio queda en la misma
transacción: se confirma o se deshace junto. Un `START TRANSACTION` ahí confirmaría la de
`apply()` a mitad de camino. La marca que elige entre los dos caminos la baja un `finally`.

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
| ADR-008 | Cómo se vincula un producto | rest-api, opciones, modulos |
| ADR-009 | Reconciliación, fase 1: completar los libros | rest-api, opciones, uninstall, modulos |
| ADR-010 | Reconciliación, fase 2: el stock contra el propio libro | db-schema, rest-api, modulos |
| ADR-011 | El log: qué queda registrado y dónde | db-schema, uninstall, modulos |
| ADR-012 | Los avisos por email | opciones, uninstall, modulos |
| ADR-013 | La interfaz: la app, sus pantallas y cómo habla con el servidor | rest-api, capabilities, dependencias, modulos |
