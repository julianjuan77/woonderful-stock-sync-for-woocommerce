# Woonderful Stock Sync for WooCommerce — Estado del proyecto

> Plugin: `woonderful-stock-sync-for-woocommerce` | WP 6.5+ | PHP 8.1+ | WooCommerce 9.1+
> Repo: [woonderful-stock-sync-for-woocommerce](https://github.com/julianjuan77/woonderful-stock-sync-for-woocommerce)
> Creado: 2026-09-20 · Última sesión: 2026-09-29 — el transporte: firma (5.1), nonces (5.2), `POST /movements` (5.3) y el envío (5.4)

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

Y para correr sólo los tests de una pieza, en vez de los 219:

```bash
make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce ARGS="--filter Test_Ledger"
```

---

## 🔄 En progreso

Nada. Lo siguiente es **5.8, `GET /products`**, o saltar a la vinculación (bloque 6), que es lo
que falta para que dos tiendas se sincronicen sin armar el vínculo a mano.

**Para probar entre dos tiendas:** `sandbox` (8150) y `sandbox-b` (8170) están emparejadas y
tienen la "Alpargata azul 38" vinculada. Con `DISABLE_WP_CRON` la pasada no corre sola: se
dispara con `wp eval '\Woonderful\StockSync\Transport\Sender::run();'` pasados los 10 s del
retardo. Ver el README de wp-restore, "Dos sitios que se hablan".

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
| 1.9 | ADR — Reconciliación (UC-06): las dos fases, el candado, el cierre de una importación | ⬜ |
| 1.10 | ADR — Log de acciones y avisos (UC-03, UC-08) | ⬜ |
| 1.11 | ADR — Interfaz (UC-05): pantalla de vinculación y de ajustes | ⬜ |

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
| 4.4 | Qué se ignora: sin stock gestionado y sin vincular ya van en 4.1; falta el bootstrap, que depende de 6.1 | ⬜ | | |

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
| 5.8 | `GET /products` | ⬜ | | |
| 5.9 | **El envío al final del request de la venta**, con `fastcgi_finish_request()` como en woonderful-sync. Sólo lo escrito en ese request, con un timeout corto (≈5 s); sin PHP-FPM, queda para la pasada. Necesita revisar ADR-006 §4: el retardo de 10 s no hace falta para lo que se manda en el `shutdown`, porque el estampado ya corrió y la ráfaga del request sale junta | ⬜ | | |

**Para el ADR del log (1.10):** `Sender::run()` devuelve cuántos mandó, confirmó y fallaron, y
el error del request si lo hubo —`no_peer` si no hay tienda emparejada—. Es lo que necesita la
línea de log de la pasada (ADR-001 §2), que todavía no se escribe. Antes de emparejar, `no_peer`
es lo normal: loguearlo en cada pasada sería ruido.



### E. El entorno — `../wp-restore`, otro repo

| | Tarea | Estado |
|---|---|---|
| E.1 | Dos tiendas locales que se hablan: `sandbox` y `sandbox-b`, con `PEER_NETWORK=1` (wp-restore `019585b`). Emparejadas con `wp wss pairing-code` / `wp wss pair`; una venta en A descuenta en B y un ajuste en B descuenta en A, sin eco. El vínculo se armó a mano: la vinculación es 6.1 | ✅ |

### 6. La vinculación — `includes/Linking/`

| | Tarea | Estado |
|---|---|---|
| 6.1 | Crear el vínculo y emitir el bootstrap | ⬜ |
| 6.2 | Recibir un bootstrap y crear la fila del otro lado. **`apply()` tiene que atenderlo antes de buscar el vínculo**: hoy lo descartaría con ACK y el par no se crearía nunca (ADR-001 §3). **Y el endpoint también**: `Movements::receive_one()` le pasa a `apply()` siete campos fijos, y los extra del bootstrap se pierden ahí | ⬜ |
| 6.3 | Desvincular y emitir el `unlink`. Mismo caso que 6.2: se atiende antes de buscar el vínculo, porque lo borra, y el endpoint tiene que pasarle sus campos | ⬜ |
| 6.4 | El estado derivado: vinculado, suspendido, sin vincular | ⬜ |
| 6.5 | `Links::for_product()` — escrito en 3.1, le falta el resto de la clase | 🔄 |

### 7. La reconciliación — `includes/Reconciliation/`

| | Tarea | Estado |
|---|---|---|
| 7.1 | Fase 1: el digest y la reentrega de lo que falta | 🔒 1.9 |
| 7.2 | Fase 2: comparar el stock real contra el libro | 🔒 1.9 |
| 7.3 | El candado con vencimiento y la pasada nocturna | 🔒 1.9 |

### 8. La interfaz — `admin/`

| | Tarea | Estado |
|---|---|---|
| 8.1 | Pantalla de ajustes: emparejamiento, estado del canal, la casilla de borrado | 🔒 1.11 |
| 8.2 | Pantalla de vinculación: sugerencias por SKU, vinculación masiva, estado por producto | 🔒 1.11 |

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
| ADR-006 | Los hooks del detector, el estampado y el guard anti-eco. Revisado el 2026-09-28: el delta sale de la base, y el agujero del guard bien descrito | 2026-09-24 |
| ADR-007 | La huella hashea la opción `home` cruda, con esquema | 2026-09-26 |
| ADR-008 | El bootstrap se manda en el momento; sólo vincula la maestra; se revisa que nada haya cambiado; un incierto se pregunta | 2026-10-01 |

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
| La reconciliación de cierre de una importación no tiene ADR | §6 del SPEC → tarea 1.9 |
| El auditor de tests ya es un agente del entorno: `.claude/agents/test-auditor.md` | — |
| `record()` escribiría movimientos con origen vacío si faltara el identificador. Hoy no puede pasar: `boot()` lo genera antes de todo | Auditoría 2026-09-26 |
| `Links::for_remote_product()` devuelve la primera fila si hubiera dos vínculos con el mismo remoto. Con dos tiendas no ocurre en producción, pero **ya tropezó un test**: el fixture creaba dos | Auditoría 2026-09-26 |
| La marca de purga se calcula con `max()` de lo purgado, y los reintentos desordenan las secuencias: el ADR de la reconciliación tiene que decir que cada tienda ofrece sus filas no confirmadas sin mirar la marca | Revisión 2026-09-27 → tarea 1.9 |
| `remember_purged()` crea en `wss_peers` una entrada por cada tienda de la que purgó filas, aunque ya no esté emparejada: queda una entrada vieja sin URL ni secreto. `Peers::only()` ya no las cuenta (`SND-10`), así que no corta la entrega; lo que queda es basura en la opción. Re-emparejar le deja a la entrada anterior sólo el `purged_through` (`PAR-08`) | Revisión 2026-09-27, decidido en 5.7 |
| Un cambio ajeno al stock del mismo producto entre las dos lecturas del detector se cuenta dos veces. Declarado y aceptado: son milisegundos, y lo corrige la reconciliación | ADR-006 §1 → tarea 7.2 |
| Editar las líneas de un pedido desde el admin deja el movimiento en `adjustment`, sin número de pedido: `wc_maybe_adjust_line_item_product_stock()` no dispara ningún hook que estampe | Sesión 2026-09-28 → ADR del log (UC-04) |
| Si un hook de terceros escribe algo que no es stock adentro de la transacción de `apply()` y después falla, el `ROLLBACK` lo deshace pero su caché queda vieja. Sólo se limpia la del stock | Sesión 2026-09-28, declarado |
| **Condición para publicar:** ninguna versión puede salir con 5.3 y sin 6.2 y 6.3. Una tienda con esa versión le confirmaría con ACK el bootstrap o el `unlink` de otra más nueva, lo descartaría, y el par no se crearía —o no se borraría— sin que nadie se entere | Revisión 2026-09-29 → 9.3 |
| Un `reason` desconocido se guarda y su delta se aplica, que está bien para una etiqueta nueva. Pero si una versión futura suma un motivo con **significado especial**, como el `bootstrap`, la vieja lo aplicaría como un ajuste común. La sección *Compatibilidad entre versiones* de ADR-001 tiene que decir cómo se marca un movimiento que una versión vieja no debe aplicar | Revisión 2026-09-29 → ADR-001 |
| **Un lote que siempre voltea al receptor arrastra a los otros 199.** Si el receptor muere con un error que `apply()` no puede atrapar —sin memoria, o el tiempo máximo de PHP—, las 200 filas reciben el mismo error, el mismo backoff, vuelven a salir juntas y a las 6 horas se abandonan todas. Un timeout de 30 s no tiene el problema: el receptor termina igual y el reintento deduplica. La mitigación posible es partir el lote a la mitad tras una falla del lote entero que no sea de red ni de autenticación. No se hace hasta ver el volumen real | Revisión 2026-09-29 → §6 del SPEC |
| **En el sandbox faltaban `wp_wss_links` y `wp_wss_movements`** —sólo quedaba `wp_wss_nonces`— con `wss_schema_version` en 1: el sitio estaba suspendido por esquema, y `maybe_upgrade()` no lo arregla porque la versión está al día. No coincide con ninguno de los dos caminos de `uninstall.php`. Causa sin encontrar; se reparó reactivando el plugin. Si se repite, mirar quién hace `DROP TABLE` sobre el prefijo `wp_` | E.1, 2026-09-30 |
| Infection sobre `Transport/`, que ya existe: necesita PCOV en la imagen de wp-restore | — |
