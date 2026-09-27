# Woonderful Stock Sync for WooCommerce — Estado del proyecto

> Plugin: `woonderful-stock-sync-for-woocommerce` | WP 6.5+ | PHP 8.1+ | WooCommerce 8.2+
> Repo: [woonderful-stock-sync-for-woocommerce](https://github.com/julianjuan77/woonderful-stock-sync-for-woocommerce)
> Creado: 2026-09-20 · Última sesión: 2026-09-26

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

Y para correr sólo los tests de una pieza, en vez de los 38:

```bash
make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce ARGS="--filter Test_Ledger"
```

---

## 🔄 En progreso

**3.5 — `Ledger::purge()`: la retención, consolidando el stock base.**

Los 90 días de §5 del SPEC, con las tres reglas duras de ADR-004 §4: sólo se purga lo
terminado, el stock base absorbe la suma de lo purgado en la misma transacción, y no se purga
por debajo de lo que la otra tienda todavía no conoce.

Done cuando: las tres reglas se cumplen, tiene sus tests `LED-NN`, lint y tests en verde, y
está commiteada.

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
| 3.4 | `ack()`: anotar el resultado de cada fila entregada, y abandonar lo que pasó el techo de 6 h | ✅ | `47e084c` | `LED-31`…`LED-36` |
| 3.5 | `purge()`: la retención, consolidando el stock base | 🔄 | | |

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
| 4.1 | Los cuatro hooks de stock y el cálculo del delta | ⬜ | | |
| 4.2 | El guard anti-eco | ✅ | `1feb126` | `GRD-01`…`GRD-07` |
| 4.3 | El estampado del motivo desde los hooks de pedido | ⬜ | | |
| 4.4 | Qué se ignora: sin stock gestionado, sin vincular, bootstrap | ⬜ | | |

### 5. El transporte — `includes/Transport/` y `includes/Rest/`

| | Tarea | Estado |
|---|---|---|
| 5.1 | La firma HMAC: firmar y verificar | ⬜ |
| 5.2 | El almacén de nonces y su purga | ⬜ |
| 5.3 | `POST /movements` del lado receptor, con el ACK por movimiento | ⬜ |
| 5.4 | El envío por lotes del lado emisor | ⬜ |
| 5.5 | La pasada recurrente de Action Scheduler | ⬜ |
| 5.6 | `GET /health` | ⬜ |
| 5.7 | El emparejamiento: `POST /pair` y el canje con su callback | ⬜ |
| 5.8 | `GET /products` | ⬜ |

### 6. La vinculación — `includes/Linking/`

| | Tarea | Estado |
|---|---|---|
| 6.1 | Crear el vínculo y emitir el bootstrap | ⬜ |
| 6.2 | Recibir un bootstrap y crear la fila del otro lado. **`apply()` tiene que atenderlo antes de buscar el vínculo**: hoy lo descartaría con ACK y el par no se crearía nunca (ADR-001 §3) | ⬜ |
| 6.3 | Desvincular y emitir el `unlink`. Mismo caso que 6.2: se atiende antes de buscar el vínculo, porque lo borra | ⬜ |
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
| 9.1 | `readme.txt` | ⬜ |
| 9.2 | `.pot` con `wp i18n make-pot` | ⬜ |
| 9.3 | `make check-release` | ⬜ |

---

## Decisiones

La fuente es `docs/DECISIONS.md`. Acá sólo el índice de las aprobadas.

| ADR | Decide | Aprobada |
|---|---|---|
| [ADR-000](docs/DECISIONS.md) | El scaffold y el arranque | 2026-09-24 |
| ADR-001 | `wss/v1` firmado con HMAC; el libro es la cola; dos ejes de estado | 2026-09-20 |
| ADR-002 | UUID de tienda; la secuencia es el `AUTO_INCREMENT` | 2026-09-20 |
| ADR-003 | Qué se borra al desinstalar; el secreto siempre | 2026-09-20 |
| ADR-004 | Tres tablas InnoDB, tres índices, la retención | 2026-09-20 |
| ADR-005 | El vínculo, su generación, el bootstrap y el `unlink` | 2026-09-21 |
| ADR-006 | Los hooks del detector, el estampado y el guard anti-eco | 2026-09-24 |
| ADR-007 | La huella hashea la opción `home` cruda, con esquema | 2026-09-26 |

`docs/ARCHITECTURE.md` documenta el resultado: modelo de datos, contrato público, módulos,
seguridad y convenciones de código.

---

## Deuda y cabos sueltos conocidos

| Qué | Dónde |
|---|---|
| Preguntas abiertas de §6 sin cerrar: multisite y volumen real del catálogo | `docs/SPEC.md` |
| El clon servido en la **misma URL por HTTPS** pasa la detección. Declarado, no resuelto | ADR-001 §1, ADR-007 |
| El emparejamiento tiene que rechazar una tienda cuya opción `home` no sea `https` | ADR-007 → Transporte |
| La reconciliación de cierre de una importación no tiene ADR | §6 del SPEC → tarea 1.9 |
| El auditor de tests ya es un agente del entorno: `.claude/agents/test-auditor.md` | — |
| `record()` escribiría movimientos con origen vacío si faltara el identificador. Hoy no puede pasar: `boot()` lo genera antes de todo | Auditoría 2026-09-26 |
| `Links::for_remote_product()` devuelve la primera fila si hubiera dos vínculos con el mismo remoto. Con dos tiendas no ocurre en producción, pero **ya tropezó un test**: el fixture creaba dos | Auditoría 2026-09-26 |
| ADR-001 dice que el tamaño de lote y la curva de backoff son "configuración". Cuando se escriba 5.4, decidir si de verdad lo son o son constantes, como se resolvió con el retardo | ADR-001 §2 |
| Infection cuando exista `Transport/`: necesita PCOV en la imagen de wp-restore | — |
