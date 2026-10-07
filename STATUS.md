# Woonderful Stock Sync for WooCommerce — Estado del proyecto

> Plugin: `woonderful-stock-sync-for-woocommerce` | WP 6.5+ | PHP 8.1+ | WooCommerce 9.1+
> Repo: [woonderful-stock-sync-for-woocommerce](https://github.com/julianjuan77/woonderful-stock-sync-for-woocommerce)
> Creado: 2026-09-20 · Última sesión: 2026-10-07 — los ADRs de la interfaz (ADR-013, con la configuración inicial, y ADR-014) y la base de la interfaz (8.1). Antes, 2026-10-06 — los ADRs del log (ADR-011) y de los avisos (ADR-012), y el bloque 10 entero: la tabla del log, el estado de la otra tienda, los eventos de cada pieza, los pares rotos, la purga y los avisos por email

Sincroniza el stock entre dos tiendas WooCommerce propias que despachan del mismo depósito.
Pool único, sin reparto: lo que se vende en una descuenta en las dos.

| | |
|---|---|
| **Sitio de trabajo** | `sandbox` — `make up SITE=sandbox` |
| **Tests** | `make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce` |
| **Lint** | `make lint-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce` |

---

## Cómo mirar qué se hizo en una tarea

Cada tarea terminada lleva su commit. Con ese hash:

```bash
git show 2668a7a --stat      # qué archivos tocó y cuántas líneas
git show 2668a7a             # el diff completo
```

Y para correr sólo los tests de una pieza, en vez de los 481:

```bash
make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce ARGS="--filter Test_Ledger"
```

---

## 🔄 En progreso

El bloque 8, la interfaz. La 8.1 —la base— está hecha: la app abre en `sandbox` con la sidebar y
el diseño de woonderful-sync, y las pantallas son esqueletos que se llenan en las tareas que
siguen. El JavaScript se compila con `npm run build` y se prueba con `npm run lint:js` y
`npm run test:unit`, desde la carpeta del plugin.

Los emails de los sitios de prueba se ven en el Mailhog de cada uno: `sandbox` en 8250,
`sandbox-b` en 8270.

**Para probar entre dos tiendas:** `sandbox` (8150, maestra) y `sandbox-b` (8170) están
emparejadas con rol. Con `DISABLE_WP_CRON` la pasada no corre sola: se dispara con
`wp eval '\Woonderful\StockSync\Transport\Pass::run();'` pasados los 10 s del retardo. Para
vincular: `wp wss link <acá> <allá> --stock=<acá> --remote-stock=<allá>` en la maestra.
Quedaron vinculadas la "Alpargata verde 39" (A 15 ↔ B 13); la "roja 40" se desvinculó, y la
"azul 38" es el vínculo armado a mano de E.1.

---

## Plan

Leyenda: ✅ hecho · 🔄 en curso · ⬜ pendiente · 🔒 bloqueado

### 1. Diseño — las decisiones antes del código

| | Tarea | Estado |
|---|---|---|
| 1.1 | ADR-000 — Scaffold | ✅ |
| 1.2 | ADR-001 — Transporte entre tiendas | ✅ |
| 1.3 | ADR-002 — Identidad de tienda y numeración | ✅ |
| 1.4 | ADR-003 — Desinstalación y borrado de datos | ✅ |
| 1.5 | ADR-004 — Esquema de la base de datos | ✅ |
| 1.6 | ADR-005 — Vinculación de productos | ✅ |
| 1.7 | ADR-006 — El detector de cambios de stock | ✅ |
| 1.8 | ADR-007 — Qué se hashea en la huella del sitio | ✅ |
| 1.9 | ADR — Reconciliación (UC-06): las dos fases, el candado, el cierre de una importación. ADR-009 (fase 1) y ADR-010 (fase 2) | ✅ |
| 1.10 | ADR — Log de acciones y avisos (UC-03, UC-08). ADR-011 (el log) y ADR-012 (los avisos) | ✅ |
| 1.11 | ADR — Interfaz (UC-05): pantalla de vinculación y de ajustes. ADR-013 (la estructura) y ADR-014 (la vinculación) | ✅ |

### 2. Cimientos

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 2.1 | Scaffold: archivo principal, Composer con PSR-4, `phpcs.xml`, `phpstan.neon.dist` | ✅ | `377ce09` | — |
| 2.2 | `Identity\Store`: el UUID de la tienda y la huella del sitio | ✅ | `377ce09`, `3b22cf5`, `a8d4f69` | `ID-01`…`ID-12` |
| 2.3 | `Schema\Installer`: las tres tablas, la versión de esquema, el control del motor | ✅ | `377ce09` | `SCH-01`…`SCH-10` |
| 2.4 | `Plugin`: la guarda que suspende por clon o por falta de transacciones | ✅ | `3598386`, `b5a528e` | `PLG-01`…`PLG-08` |
| 2.5 | `uninstall.php` | ✅ | `377ce09` | `UNI-01`…`UNI-05` |
| 2.6 | Suite de tests con `wp-phpunit` | ✅ | `9a323bf` | — |

### 3. El libro — `includes/Ledger/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 3.1 | `record()`: escribir un movimiento propio, con la secuencia en una transacción | ✅ | `2668a7a` | `LED-01`…`LED-08` |
| 3.2 | `apply()`: aplicar un movimiento recibido y decidir `applied` / `held` / `discarded` | ✅ | `7af47c9` | `LED-11`…`LED-17` |
| 3.3 | `claim()`: liberar lo vencido y reclamar un lote | ✅ | `3b768ef` | `LED-22`…`LED-28` |
| 3.4 | `ack()`: anotar el resultado de cada fila entregada, y abandonar lo que pasó el techo de 6 h | ✅ | `47e084c` | `LED-31`…`LED-43` |
| 3.5 | `purge()`: la retención, consolidando el stock base | ✅ | `74cc9b5` | `LED-44`…`LED-51` |

**3.1 en detalle** — `2668a7a`

```
Código                                        Documentación
  + includes/Ledger/Ledger.php    93 líneas     ~ STATUS.md
  + includes/Linking/Links.php    43 líneas     ~ tests/TEST-CASES.md
                                                ~ docs/ARCHITECTURE.md
Tests
  + tests/test-ledger.php   8 tests, LED-01 a LED-08
```

`record()` descarta lo que no es asunto de la malla —delta 0, producto sin vincular— y
escribe la fila con dos escrituras en una transacción: el `INSERT` deja `origin_seq` en
`NULL` y el `UPDATE` lo completa con el `AUTO_INCREMENT`, que es la secuencia del movimiento
(ADR-004 §1). Nace `pending` y con `next_attempt_at` diez segundos en el futuro (ADR-006 §4).

Verificado rompiéndolo: sin el `UPDATE` cae `LED-02`; registrando productos sin vincular cae
`LED-07`.

### 4. El detector — `includes/Detector/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 4.1 | Los cuatro hooks de stock y el cálculo del delta, leído de la base | ✅ | `a3db571`, `25984d3` | `DET-01`…`DET-10`, `PLG-16`, `PLG-17` |
| 4.2 | El guard anti-eco | ✅ | `1feb126` | `GRD-01`…`GRD-07` |
| 4.3 | El estampado del motivo desde los hooks de pedido | ✅ | `3173cc4`, `c2da67f` | `DET-11`…`DET-16`, `LED-52`…`LED-55` |
| 4.4 | Qué se ignora: sin stock gestionado y sin vincular, en 4.1; la escritura del bootstrap la cubre el guard | ✅ | `a855ea4` | `BOO-01` |

### 5. El transporte — `includes/Transport/` y `includes/Rest/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 5.1 | La firma HMAC: firmar y verificar, con la ruta REST y la query ordenada | ✅ | `83051db`, `c2fd2aa` | `SIG-01`…`SIG-11` |
| 5.2 | El almacén de nonces y su purga: dos ventanas de retención | ✅ | `b247cb9`, `909a69d` | `NON-01`…`NON-07`, sin `NON-06` |
| 5.3 | `POST /movements` del lado receptor, con el ACK por movimiento | ✅ | `7e8f5c7`, `0cfae8a`, `32c64eb` | `MOV-01`…`MOV-13`, `LED-56`…`LED-59`, `PLG-18`, `PLG-19` |
| 5.4 | El envío por lotes del lado emisor | ✅ | `b164978`, `4f2f842` | `SND-01`…`SND-10` |
| 5.5 | La pasada recurrente de Action Scheduler | ✅ | `753f4eb` | `PAS-01`…`PAS-07`, `PLG-20`…`PLG-22`, `UNI-07` |
| 5.6 | `GET /health` | ✅ | `02ce7dc`, `638df6f` | `HLT-01`…`HLT-07`, `PLG-23`, `PLG-24` |
| 5.7 | El emparejamiento: `POST /pair` y el canje con su callback. Https en los dos lados, salvo en desarrollo. Se dispara desde WP-CLI. La prueba entre dos sitios de verdad espera a E.1 | ✅ | `b972a51`, `10b245e`, `ae25584`, `ba6d266` | `PAR-01`…`PAR-18`, `CLI-01`…`CLI-04`, `PLG-25`, `UNI-08` |
| 5.8 | `GET /products`, y su cliente `Transport\Catalog` | ✅ | `80f40ab`, `daa8b16` | `PRD-01`…`PRD-14` |
| 5.9 | **El envío al final del request de un pedido** (`Transport\Express`), con `fastcgi_finish_request()` o `litespeed_finish_request()` y 5 s de timeout; sin ellas, o con un envío fallido hace menos de un minuto, queda para la pasada. ADR-006 §5 | ✅ | `4711ba2` | `SND-11`…`SND-18`, `DET-17`, `DET-18`, `UNI-09` |

**Para el ADR del log (1.10):** `Sender::run()` devuelve cuántos mandó, confirmó y fallaron, y
el error del request si lo hubo —`no_peer` si no hay tienda emparejada—. Es lo que necesita la
línea de log de la pasada (ADR-001 §2), que todavía no se escribe. Antes de emparejar, `no_peer`
es lo normal: loguearlo en cada pasada sería ruido.



### E. El entorno — `../wp-restore`, otro repo

| | Tarea | Estado |
|---|---|---|
| E.1 | Dos tiendas locales que se hablan: `sandbox` y `sandbox-b`, con `PEER_NETWORK=1` (wp-restore `019585b`). Emparejadas con `wp wss pairing-code` / `wp wss pair`; una venta en A descuenta en B y un ajuste en B descuenta en A, sin eco. El vínculo se armó a mano: la vinculación es 6.1 | ✅ |
| E.2 | La reconciliación entre `sandbox` y `sandbox-b` (2026-10-06): una pasada limpia; una venta abandonada que vuelve a la cola y llega; un ACK perdido que se confirma sin reenviar; una escritura por fuera del plugin que la fase 2 abre a la segunda revisión y la maestra ve en la siguiente; `discrepancy accept` que viaja (a los 10 s de la espera); un vínculo huérfano que la secundaria borra; y `discrepancy restore` sin movimiento | ✅ |
| E.3 | Los avisos entre `sandbox` y `sandbox-b` (2026-10-06), con Mailhog: `sandbox-b` apagada, la venta no sale y se registra la caída; a los 29 minutos nada, a los 30 el aviso —el primer intento falló y el reintento esperó la ventana—; al volver, "volvió" y la venta entregada. El resumen de la noche lista los sin vincular una vez. Un producto a la papelera en `sandbox-b`: su aviso de par roto, y `pair_restored` al volver. Encontró que en wp-restore `wp_mail()` fallaba —remitente `wordpress@localhost`—, arreglado en su mu-plugin de Mailhog; y textos en código interno, fechas en UTC y "1 productos", arreglados | ✅ |

### 6. La vinculación — `includes/Linking/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 6.1 | Crear el vínculo y mandar el bootstrap en el momento (ADR-008). Una copia suspendida no vincula; el stock se revisa con la fila bloqueada; el bootstrap se identifica por su vínculo | ✅ | `7e6b919`, `a855ea4`, `b37244d`, `ce80b76`, `46e2f49`, `34949cb`, `fc0e529` | `LNK-01`…`LNK-11`, `BOO-07`…`BOO-10`, `BOO-12`…`BOO-14`, `BOO-16` |
| 6.2 | Recibir el bootstrap: las revisiones, el par al revés y el stock de la maestra con el guard. Se atiende antes de buscar el vínculo. El stock se revisa con la fila bloqueada, y un vínculo viejo se reemplaza (ADR-008, revisado) | ✅ | `a855ea4`, `34949cb`, `0879fdb` | `BOO-01`…`BOO-03`, `BOO-06`, `BOO-15`, `BOO-17`, `BOO-18` |
| 6.3 | Desvincular: el `unlink` sale por la cola; la secundaria borra el par vigente y descarta el viejo o ajeno. Si el `unlink` no llega, el próximo bootstrap reemplaza el vínculo que quedó | ✅ | `a855ea4`, `b37244d`, `0879fdb` | `LNK-08`, `LNK-09`, `BOO-04`, `BOO-05`, `BOO-11` |
| 6.4 | El estado derivado: vinculado, suspendido —por el producto de allá o el de acá—, sin vincular e incierto. La caída de la otra tienda no suspende. `wp wss status` | ✅ | `dd3cc0f` | `STA-01`…`STA-10`, `LED-60` |
| 6.5 | `Links` | ✅ | `a855ea4` | — |
| 6.6 | Los roles al emparejar: la maestra es la que emite el código (ADR-008 §2) | ✅ | `1f18fef` | `PAR-02`, `PAR-09`, `PAR-19` |
| 6.7 | `wp wss link` y `wp wss unlink`, y la prueba entre `sandbox` y `sandbox-b`: rechazo por stock cambiado, vínculo con el stock de la maestra, una venta que llega, el `unlink` y el rechazo desde la secundaria | ✅ | `ff66beb` | `CLI-05`, `CLI-06` |

### 7. La reconciliación — `includes/Reconciliation/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 7.1 | El candado (ADR-009 §4) y el resultado guardado (§7) | ✅ | `8c8886c` | `REC-01`…`REC-10`, `UNI-10` |
| 7.2 | Fase 1: las dos preguntas de `POST /reconcile/digest`, por tandas, y qué se reencola o se confirma (ADR-009 §1–§3) | ✅ | `979803b` | `BOK-01`…`BOK-10`, `RCN-01`…`RCN-07`, `RCL-01`…`RCL-10`, `PLG-26`, `PLG-27` |
| 7.3 | Quién la lanza: la noche en la maestra, `wp wss reconcile`, y el cierre de una importación (ADR-009 §5) | ✅ | `34d8773` | `SCD-01`…`SCD-13`, `CLI-08`…`CLI-11`, `PAS-08`, `PLG-28` |
| 7.4 | Los vínculos armados de un solo lado (ADR-009 §8) | ✅ | `d3f3661` | `BOK-11`…`BOK-18`, `RCN-08`…`RCN-10`, `RCL-11`…`RCL-14`, `CLI-12` |
| 7.5 | Fase 2: la cuenta, la confirmación a los 2 minutos y las cuatro columnas (ADR-010 §1–§5) | ✅ | `d16c5fc` | `AUD-01`…`AUD-13`, `SCH-15`, `RCL-15`, `RCL-16` |
| 7.6 | La tercera pregunta, y `wp wss discrepancies`, `accept` y `restore` (ADR-010 §6–§7) | ✅ | `b156ddf` | `AUD-14`…`AUD-18`, `RCN-11`, `RCN-12`, `RCL-17`, `RCL-18`, `CLI-13`…`CLI-15` |
| 7.7 | La purga nocturna: `Ledger::purge()` existe y nada la llama (ADR-004 §4) | ✅ | `e307214` | `SCD-14`…`SCD-16` |

### 8. La interfaz — `admin/`, `src/`

| | Tarea | Estado | Commit | Tests |
|---|---|---|---|---|
| 8.1 | La base: `@wordpress/scripts`, el menú, la app con la sidebar y el diseño copiados de woonderful-sync, `wss-admin/v1` con su permiso (ADR-013 §1, §2, §4, §5) | ✅ | | `ADM-01`…`ADM-06`, `routing.test.js` |
| 8.2 | Resumen: el estado de la otra, la última reconciliación y la manual, las discrepancias de las dos y cómo resolverlas (ADR-013 §3) | ⬜ | | |
| 8.3 | Registros: el log, filtros y paginado, desde `Logs.js` (ADR-013 §3) | ⬜ | | |
| 8.4 | Configuración: el emparejamiento, los avisos, la casilla de borrado (ADR-013 §3) | ⬜ | | |
| 8.5 | La copia del catálogo de la otra: la tabla, el refresco, `GET /products` con tipo y padre (ADR-014 §1, §2) | ⬜ | | |
| 8.6 | Productos: las filas, los motivos y la sugerencia, filtros y búsqueda (ADR-014 §3, §4) | ⬜ | | |
| 8.7 | Vincular a mano, desvincular, reasignar, descartar (ADR-014 §5, §7) | ⬜ | | |
| 8.8 | La vinculación masiva (ADR-014 §6) | ⬜ | | |
| 8.9 | La secundaria en modo lectura, con el enlace a la maestra (`GET /health` con `admin_url`) (ADR-013 §6) | ⬜ | | |
| 8.10 | La configuración inicial: el asistente en pasos, los requisitos, el nombre y el rol, el emparejamiento, los avisos, y el email de prueba (ADR-013 §3, revisado) | ⬜ | | |

**El diseño parte de woonderful-sync** (pedido de Julián, 2026-10-06): los mismos logos (`assets/brand/`), la misma disposición —sidebar con logo, pantallas como `src/screens/Logs.js`— y los mismos colores y estilos (`src/style.scss`, clases `woon-*`).

### 9. Publicación

| | Tarea | Estado |
|---|---|---|
| 9.1 | `readme.txt`. Recomendar un cron real del servidor contra `wp-cron.php` con `DISABLE_WP_CRON`: sin él, la pasada de cada minuto depende de que alguien visite el sitio | ⬜ |
| 9.2 | `.pot` con `wp i18n make-pot` | ⬜ |
| 9.3 | `make check-release` | ⬜ |

---

## Decisiones

La fuente es `docs/DECISIONS.md`. Acá sólo el índice de las aprobadas.

| ADR | Decide | Aprobada |
|---|---|---|
| [ADR-000](docs/DECISIONS.md) | El scaffold y el arranque | 2026-09-24 |
| ADR-001 | `wss/v1` firmado con HMAC; el libro es la cola; dos ejes de estado. Revisado el 2026-09-28: qué entra exactamente en la firma, los nonces se guardan dos ventanas, y cómo responde `POST /movements`. Revisado el 2026-09-29: el lote y los tiempos de la entrega son constantes, y qué responde `GET /health`. Revisado el 2026-09-30: el emparejamiento en detalle, y el rechazo de una copia con el mismo identificador | 2026-09-20 |
| ADR-002 | UUID de tienda; la secuencia es el `AUTO_INCREMENT` | 2026-09-20 |
| ADR-003 | Qué se borra al desinstalar; el secreto siempre. Revisado el 2026-09-29: la hora del control de la pasada también, y desactivar la desagenda | 2026-09-20 |
| ADR-004 | Tres tablas InnoDB, tres índices, la retención | 2026-09-20 |
| ADR-005 | El vínculo, su generación, el bootstrap y el `unlink` | 2026-09-21 |
| ADR-006 | Los hooks del detector, el estampado y el guard anti-eco. Revisado el 2026-09-28: el delta sale de la base, y el agujero del guard bien descrito. Revisado el 2026-10-02: lo de un pedido sale al final de su request, salvo después de un fallo | 2026-09-24 |
| ADR-007 | La huella hashea la opción `home` cruda, con esquema | 2026-09-26 |
| ADR-008 | El bootstrap se manda en el momento; sólo vincula la maestra; se revisa que nada haya cambiado; un incierto se pregunta. Revisado el 2026-10-02: el bootstrap reemplaza un vínculo viejo de la secundaria | 2026-10-01 |
| ADR-009 | Reconciliación, fase 1: las dos tiendas se pasan la lista de secuencias y cada una reencola lo propio que falta; el candado; quién la lanza; el cierre de una importación; los vínculos de un solo lado | 2026-10-05 |
| ADR-010 | Reconciliación, fase 2: cada tienda compara su stock contra su propio libro; se confirma a los 2 minutos; cuatro columnas en `wss_links`; dos acciones para resolver. Corrige el SPEC UC-06 | 2026-10-05 |
| ADR-011 | El log: una tabla `wss_events` para lo que no es un movimiento —acciones del dueño, intentos fallidos, caídas y rechazos de la otra tienda, reconciliaciones, discrepancias, pares rotos—; el texto se arma al mostrarlo; 90 días, salvo el descarte y lo ya avisado. Revisado el 2026-10-06: el 409 no es un rechazo, y `link_failed` dice si salió solo | 2026-10-06 |
| ADR-012 | Los avisos por email: cada tienda de lo suyo; lo que no sincroniza en el resumen de la noche, una vez por producto; los errores en el momento; la caída y el rechazo a los 30 minutos; ventana de 10 minutos por causa; un tick por minuto; tope de 10 por día. Corrige el SPEC UC-08 | 2026-10-06 |
| ADR-013 | La interfaz: una app en React igual a la de woonderful-sync —sidebar, `#/ruta`, tokens y clases `woon-*` copiados—; Resumen, Productos, Registros y Configuración; un namespace `wss-admin/v1` con nonce y `manage_woocommerce`; la secundaria en modo lectura con el enlace a la maestra. Revisado el 2026-10-07: la configuración inicial, con los requisitos | 2026-10-07 |
| ADR-014 | La vinculación en la pantalla: una copia del catálogo de la otra (`wss_remote_products`) que se refresca; filas con los dos lados, motivos y sugerencia por SKU; filtros y búsqueda en el servidor; vincular a mano mirando el stock en el momento; masiva de a 20 revalidando; reasignar, descartar | 2026-10-07 |

El 2026-09-28 el SPEC §5 subió el mínimo de WooCommerce de 8.2 a 9.1: es la versión que trae
el hook que estampa las cancelaciones (ADR-006 §2).

`docs/ARCHITECTURE.md` documenta el resultado: modelo de datos, contrato público, módulos,
seguridad y convenciones de código.

---

## Deuda y cabos sueltos conocidos

| Qué | Dónde |
|---|---|
| Preguntas abiertas de §6 sin cerrar: multisite y volumen real del catálogo | `docs/SPEC.md` |
| El clon servido en la **misma URL por HTTPS** pasa la detección. Declarado, no resuelto | ADR-001 §1, ADR-007 |
| El auditor de tests ya es un agente del entorno: `.claude/agents/test-auditor.md` | — |
| `record()` escribiría movimientos con origen vacío si faltara el identificador. Hoy no puede pasar: `boot()` lo genera antes de todo | Auditoría 2026-09-26 |
| `Links::for_remote_product()` devuelve la primera fila si hubiera dos vínculos con el mismo remoto. Desde ADR-008 (revisado) el bootstrap borra el que choque, así que no se arma un segundo; queda por si se crea a mano | Auditoría 2026-09-26 |
| `remember_purged()` crea en `wss_peers` una entrada por cada tienda de la que purgó filas, aunque ya no esté emparejada: queda una entrada vieja sin URL ni secreto. `Peers::only()` ya no las cuenta (`SND-10`), así que no corta la entrega; lo que queda es basura en la opción. Re-emparejar le deja a la entrada anterior sólo el `purged_through` (`PAR-08`) | Revisión 2026-09-27, decidido en 5.7 |
| Un cambio ajeno al stock del mismo producto entre las dos lecturas del detector se cuenta dos veces. Declarado y aceptado: son milisegundos, y lo corrige la reconciliación | Lo ve la fase 2 como discrepancia (7.5), y se resuelve con `wp wss discrepancy accept` (7.6) |
| Editar las líneas de un pedido desde el admin deja el movimiento en `adjustment`, sin número de pedido: `wc_maybe_adjust_line_item_product_stock()` no dispara ningún hook que estampe | Sesión 2026-09-28 → límite del estampado (ADR-006 §2), dicho en ADR-011 |
| Si un hook de terceros escribe algo que no es stock adentro de la transacción de `apply()` y después falla, el `ROLLBACK` lo deshace pero su caché queda vieja. Sólo se limpia la del stock | Sesión 2026-09-28, declarado |
| **Condición para publicar:** ninguna versión puede salir con 5.3 y sin 6.2 y 6.3. Una tienda con esa versión le confirmaría con ACK el bootstrap o el `unlink` de otra más nueva, lo descartaría, y el par no se crearía —o no se borraría— sin que nadie se entere | Revisión 2026-09-29 → 9.3 |
| Un `reason` desconocido se guarda y su delta se aplica, que está bien para una etiqueta nueva. Pero si una versión futura suma un motivo con **significado especial**, como el `bootstrap`, la vieja lo aplicaría como un ajuste común. La sección *Compatibilidad entre versiones* de ADR-001 tiene que decir cómo se marca un movimiento que una versión vieja no debe aplicar | Revisión 2026-09-29 → ADR-001 |
| **Un lote que siempre voltea al receptor arrastra a los otros 199.** Si el receptor muere con un error que `apply()` no puede atrapar —sin memoria, o el tiempo máximo de PHP—, las 200 filas reciben el mismo error, el mismo backoff, vuelven a salir juntas y a las 6 horas se abandonan todas. Un timeout de 30 s no tiene el problema: el receptor termina igual y el reintento deduplica. La mitigación posible es partir el lote a la mitad tras una falla del lote entero que no sea de red ni de autenticación. No se hace hasta ver el volumen real | Revisión 2026-09-29 → §6 del SPEC |
| **En el sandbox faltaban `wp_wss_links` y `wp_wss_movements`** —sólo quedaba `wp_wss_nonces`— con `wss_schema_version` en 1: el sitio estaba suspendido por esquema, y `maybe_upgrade()` no lo arregla porque la versión está al día. No coincide con ninguno de los dos caminos de `uninstall.php`. Causa sin encontrar; se reparó reactivando el plugin. Si se repite, mirar quién hace `DROP TABLE` sobre el prefijo `wp_` | E.1, 2026-09-30 |
| Las dos tiendas del sandbox tienen todavía el vínculo armado a mano en E.1 ("Alpargata azul 38"), con generación 1: no corresponde a ningún bootstrap. Si molesta, borrarlo de `wss_links` en las dos | E.1, 2026-09-30 |
| **Un bootstrap incierto que nunca recibe respuesta** —por ejemplo, un 401 después de re-emparejar— retiene las ventas de ese producto para siempre, sin avisarle a nadie. No se pierden, pero nadie se entera; y el dueño ve `already_linked` si intenta revincular. Lo tiene que avisar el log (UC-08) | Resuelto en 10.3: `link_unresolved` (ADR-011) |
| `wp wss link` sale con código 0 cuando el resultado queda incierto: un script no lo distingue de un éxito | Revisión 2026-10-02 |
| `GET /movements/<origen>/<secuencia>` no exige que el origen consultado sea el de quien firma. Con dos tiendas no expone nada, pero conviene acotarlo | Revisión 2026-10-02 |
| Los bootstraps `failed` no se purgan nunca: `purge()` sólo mira lo confirmado | Revisión 2026-10-02 → 7.x |
| `claim()` ignora si el `UPDATE` devuelve false: un error de la base cortaría la entrega sin aviso. Ya no puede ser el 1093, pero cualquier otro sí | Resuelto en 10.3: `ledger_error` (ADR-011) |
| La paginación de `GET /products` es por número de página: si se borra un producto definitivamente mientras se recorre el catálogo, todo se corre un lugar y uno de la página siguiente no aparece. Para sugerir pares por SKU es poco grave; si importa, se pagina por "los que vienen después del ID X" | Revisión 2026-10-02 → ADR de interfaz |
| La pantalla tiene que decir "esperando respuesta" para un `uncertain` que todavía está en `syncing`, y "no se sabe" recién cuando ya es `uncertain`: hoy `State` los junta en uno | Revisión 2026-10-02 → ADR de interfaz |
| El envío al final del request no se probó con PHP-FPM de verdad: los sitios de wp-restore corren Apache con mod_php, y ahí `Express` no manda. Los tests cubren la lógica; falta ver un pedido real salir en el `shutdown`. Y averiguar qué corren los servidores de las dos tiendas: con Apache y mod_php no se dispara nunca | 5.9 → con un sitio en PHP-FPM |
| Un movimiento recibido **retenido** no sale nunca de ese estado: no hay nada que lo aplique cuando el par se restablece (SPEC §6, pregunta abierta). Mientras tanto, ese producto queda fuera de la fase 2 (`Audit::measure()`) | Revisión 2026-10-06 → sin ADR |
| La suite tarda unos 6 minutos: cada test crea y borra las tablas, y en esta base esas operaciones de esquema son lentas. El log borra filas en vez de su tabla, lo que bajó de 7 y medio a 6. Bajarlo más pediría no recrear el esquema en cada test | Sesión 2026-10-06 |
| Si la caída avisada pasa a ser un rechazo antes de volver, "volvió" no se avisa: el cambio a rechazo arranca un incidente nuevo, sin avisar todavía. Es raro, y se prefirió declararlo a complicar el estado | Revisión 2026-10-06 |
| Infection sobre `Transport/`, que ya existe: necesita PCOV en la imagen de wp-restore | — |
