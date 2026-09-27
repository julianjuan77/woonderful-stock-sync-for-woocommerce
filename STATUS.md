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

**3.2 — `Ledger::apply()`: aplicar un movimiento recibido.**

El guard (4.2) ya está, así que `apply()` puede escribirse entera: el caso `applied` necesita
tocar el stock de WooCommerce, y sin guard eso emitiría un movimiento de vuelta.

Done cuando: decide entre `applied`, `held` y `discarded` según el vínculo y la generación,
aplica el delta con el guard puesto, escribe la fila del receptor, tiene sus tests `LED-NN`,
lint y tests en verde, y está commiteada.

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
| 1.8 | ADR — Reconciliación (UC-06): las dos fases, el candado, el cierre de una importación | ⬜ |
| 1.9 | ADR — Log de acciones y avisos (UC-03, UC-08) | ⬜ |
| 1.10 | ADR — Interfaz (UC-05): pantalla de vinculación y de ajustes | ⬜ |

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
| 3.2 | `apply()`: aplicar un movimiento recibido y decidir `applied` / `held` / `discarded` | 🔄 | | |
| 3.3 | `claim()`: liberar lo vencido y reclamar un lote | ⬜ | | |
| 3.4 | `ack()`: anotar el resultado de cada fila entregada | ⬜ | | |
| 3.5 | `purge()`: la retención, consolidando el stock base | ⬜ | | |

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
| 6.2 | Recibir un bootstrap y crear la fila del otro lado | ⬜ |
| 6.3 | Desvincular y emitir el `unlink` | ⬜ |
| 6.4 | El estado derivado: vinculado, suspendido, sin vincular | ⬜ |
| 6.5 | `Links::for_product()` — escrito en 3.1, le falta el resto de la clase | 🔄 |

### 7. La reconciliación — `includes/Reconciliation/`

| | Tarea | Estado |
|---|---|---|
| 7.1 | Fase 1: el digest y la reentrega de lo que falta | 🔒 1.8 |
| 7.2 | Fase 2: comparar el stock real contra el libro | 🔒 1.8 |
| 7.3 | El candado con vencimiento y la pasada nocturna | 🔒 1.8 |

### 8. La interfaz — `admin/`

| | Tarea | Estado |
|---|---|---|
| 8.1 | Pantalla de ajustes: emparejamiento, estado del canal, la casilla de borrado | 🔒 1.10 |
| 8.2 | Pantalla de vinculación: sugerencias por SKU, vinculación masiva, estado por producto | 🔒 1.10 |

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
| El SPEC referencia ADR-004 y ADR-005 con otro significado, heredado de un arranque anterior | `docs/SPEC.md` |
| UC-01 dice "reintenta un número **finito** de veces"; ADR-001 fijó el techo en tiempo | `docs/SPEC.md` |
| Preguntas abiertas de §6 sin cerrar: multisite y volumen real del catálogo | `docs/SPEC.md` |
| El clon servido en la **misma URL por HTTPS** pasa la detección. Declarado, no resuelto | ADR-001 §1, ADR-007 |
| El emparejamiento tiene que rechazar una tienda cuya opción `home` no sea `https` | ADR-007 → Transporte |
| La reconciliación de cierre de una importación no tiene ADR | §6 del SPEC → tarea 1.8 |
| El auditor de tests ya es un agente del entorno: `.claude/agents/test-auditor.md` | — |
| **`LED-02` no detecta si se saca la transacción de `record()`.** Verificar que dos escrituras estén en una transacción pide simular un corte del proceso | Auditoría 2026-09-26 |
| **`PLG-07` dice "la identidad antes que el esquema" y sólo verifica que existan las dos.** Verificar un orden, no un resultado | Auditoría 2026-09-26 |
| `record()` escribiría movimientos con origen vacío si faltara el identificador. Hoy no puede pasar: `boot()` lo genera antes de todo | Auditoría 2026-09-26 |
| `Links::for_product()` devuelve la primera fila si un producto tuviera dos vínculos. Con dos tiendas no ocurre | Auditoría 2026-09-26 |
| ADR-001 dice que el tamaño de lote y la curva de backoff son "configuración". Cuando se escriba 5.4, decidir si de verdad lo son o son constantes, como se resolvió con el retardo | ADR-001 §2 |
| Infection cuando exista `Transport/`: necesita PCOV en la imagen de wp-restore | — |
