# Woonderful Stock Sync for WooCommerce — Casos de prueba

> Trazabilidad entre lo que deciden los ADRs y los tests que lo verifican.
> Los IDs no se renumeran.

## Cómo se corren

```bash
make test-plugin SITE=sandbox PLUGIN=woonderful-stock-sync-for-woocommerce
```

**Dentro del sitio**, que es donde están las extensiones PHP y la base. Los tests usan la
suite de WordPress (`WP_UnitTestCase`) traída por Composer con `wp-phpunit`, contra la base
del sitio pero con el prefijo `wptests_`: la suite borra y recrea **sus** tablas en cada
arranque y no toca las del sitio.

**Dos trampas del entorno, que valen por todos los tests de esquema que se escriban después:**

1. `WP_UnitTestCase` reescribe los `CREATE TABLE` como `CREATE TEMPORARY TABLE`. Una tabla
   temporal es invisible para `SHOW TABLES` y para `information_schema`, así que cualquier
   test que mire el motor o los índices tiene que desactivar eso en `set_up()`:

   ```php
   remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
   remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
   ```

   El síntoma cuando no se sabe: `dbDelta` devuelve *"Created table"* y listar las tablas
   da vacío.

2. Con tablas de verdad, el rollback por test no alcanza: `CREATE TABLE` hace COMMIT
   implícito en MySQL. Hay que borrarlas en `tear_down()` o los tests se contaminan.

---

## Cómo se lee un ID

Prefijo por dominio, número correlativo, y **no se renumeran** aunque un caso se elimine.

| Prefijo | Dominio | Archivo |
|---|---|---|
| `SCH-NN` | El esquema | [`tests/test-installer.php`](test-installer.php) |
| `ID-NN` | La identidad de la tienda | [`tests/test-store.php`](test-store.php) |
| `PLG-NN` | El arranque y la guarda que suspende | [`tests/test-plugin.php`](test-plugin.php) |

Cada test lleva su ID en el docblock, así que se encuentra buscándolo:

```bash
$ grep -rn "SCH-04" .
tests/TEST-CASES.md:81:      | `SCH-04` | Dos movimientos propios con `origin_seq` en NULL |…
tests/test-installer.php:102:	 * SCH-04
```

Los que no necesitan explicación llevan el ID y la frase en la misma línea
(`SCH-01 — install() sobre una base limpia crea las tres tablas.`); los que sí, la
explicación arriba y el ID al final del docblock.

Los enlaces de este archivo apuntan al archivo y no a la línea: un `#L102` queda viejo con
el primer test que se agregue arriba, y el ID se busca igual con un grep.

Un ID en este archivo que no aparezca en ningún test es un caso sin escribir.

---

## Matriz decisión ↔ test

| Decisión | Dónde está | Tests | Archivo |
|---|---|---|---|
| Tres tablas, InnoDB | ADR-004 §1-§3 | `SCH-01`, `SCH-02` | [test-installer.php](test-installer.php) |
| Tres índices en el libro, ni uno más | ADR-004 §1 | `SCH-03` | [test-installer.php](test-installer.php) |
| La secuencia propia es el `AUTO_INCREMENT`, en `NULL` hasta el `UPDATE` | ADR-004 §1 | `SCH-04` | [test-installer.php](test-installer.php) |
| La deduplicación vive en el `UNIQUE`, no en PHP | SPEC §3, ADR-001 | `SCH-05`, `SCH-06` | [test-installer.php](test-installer.php) |
| Versión de esquema y migración | ADR-004 §5 | `SCH-07`, `SCH-08` | [test-installer.php](test-installer.php) |
| Sin transacciones no se sincroniza | ADR-004 §5 | `SCH-09` | [test-installer.php](test-installer.php) |
| Identificador opaco de 128 bits, inmutable | ADR-002 §1 | `ID-01`, `ID-02`, `ID-03` | [test-store.php](test-store.php) |
| Etiqueta legible, editable | ADR-002 §1 | `ID-04` | [test-store.php](test-store.php) |
| La huella del sitio se guarda hasheada | ADR-001 §1 | `ID-05` | [test-store.php](test-store.php) |
| El clon se apaga solo | ADR-001 §1 | `ID-06`, `ID-07`, `ID-08`, `PLG-02`, `PLG-05` | [test-store.php](test-store.php), [test-plugin.php](test-plugin.php) |
| Sin transacciones no se sincroniza | ADR-004 §5 | `PLG-03` | [test-plugin.php](test-plugin.php) |
| La identidad se genera antes que el esquema | ADR-002, consecuencias | `PLG-07` | [test-plugin.php](test-plugin.php) |

---

## [`tests/test-installer.php`](test-installer.php) — el esquema

| ID | Caso | Resultado esperado |
|---|---|---|
| `SCH-01` | `install()` sobre una base limpia | Existen las tres tablas |
| `SCH-02` | Idem | Las tres figuran en `information_schema` como InnoDB, por nombre |
| `SCH-03` | Idem | Los cuatro índices del libro, con sus **columnas en orden** y cuáles son únicos |
| `SCH-04` | Dos movimientos propios con `origin_seq` en `NULL` | Los dos entran: en un `UNIQUE`, `NULL` no choca con `NULL` |
| `SCH-05` | Dos veces el mismo `(origin_store, origin_seq)` | El segundo lo rechaza la base |
| `SCH-06` | La misma secuencia de dos tiendas distintas | Los dos entran |
| `SCH-07` | `maybe_upgrade()` con la versión al día | No ejecuta ninguna consulta |
| `SCH-08` | `maybe_upgrade()` con la versión atrasada | Reinstala las tres tablas **por nombre** y deja la versión al día |
| `SCH-09` | Una de las tablas pasada a MyISAM | `tables_without_transactions()` la nombra |
| `SCH-10` | Las tres definiciones SQL | Declaran `ENGINE=InnoDB` explícito, no el default del host |

## [`tests/test-store.php`](test-store.php) — la identidad

| ID | Caso | Resultado esperado |
|---|---|---|
| `ID-01` | Activación sobre una instalación limpia | 32 caracteres hexadecimales (128 bits) |
| `ID-02` | `ensure_identity()` dos veces | El identificador no cambia |
| `ID-03` | Dos instalaciones | Identificadores distintos |
| `ID-04` | Etiqueta vacía | `label()` cae en el identificador |
| `ID-05` | Cambia la URL del sitio | La huella cambia con ella y es `sha256` de la URL nueva |
| `ID-06` | Sin huella guardada | No es un clon: todavía no se emparejó |
| `ID-07` | El sitio donde se emparejó | No es un clon |
| `ID-08` | Se emparejó en una URL y la base se restauró en otra | **Es un clon** |

## [`tests/test-plugin.php`](test-plugin.php) — el arranque y la guarda

| ID | Caso | Resultado esperado |
|---|---|---|
| `PLG-01` | Tablas sanas y sitio en su lugar | Nada suspende la sincronización |
| `PLG-02` | La base restaurada en otra URL | Suspendida por clon |
| `PLG-03` | Una tabla pasada a MyISAM | Suspendida por falta de transacciones |
| `PLG-04` | Las dos causas a la vez | Gana el clon: arreglar el motor de una copia no sirve de nada |
| `PLG-05` | Sitio suspendido | Avisa en el admin |
| `PLG-06` | Sitio sano | No avisa nada |
| `PLG-07` | `activate()` sobre una instalación vacía | Quedan la identidad y el esquema |

---

## Lo que todavía no tiene tests

- Todo `Transport/` y `Reconciliation/`: no hay código todavía.
- El `INSERT` + `UPDATE` de la secuencia dentro de una transacción (`Ledger/`).
- La purga que consolida el stock base.
- **`uninstall.php` entero**, que es el archivo que borra datos: las cuatro decisiones de
  ADR-003 (el secreto siempre se borra, los nonces siempre se borran, con la casilla en
  falso queda todo lo demás, con la casilla marcada no queda nada).
- Que `boot()` **no registre** la sincronización cuando está suspendida. Hoy no hay nada
  registrado que verificar; el test va cuando exista el detector.

---

## Hallazgos de auditoría ya corregidos

| Fecha | Qué estaba verde por la razón equivocada |
|---|---|
| 2026-09-20 | `SCH-01` contaba tres tablas en vez de mirar cuáles eran |
| 2026-09-21 | `ID-05`, `ID-07` e `ID-08` nunca cambiaban la URL del sitio: `fingerprint()` podía ignorar `home_url()` por completo y los tres pasaban. `ID-05` además afirmaba que un hash hexadecimal no contiene un dominio, que es imposible de violar |
| 2026-09-21 | `SCH-02` afirmaba que la lista de problemas estaba vacía: una función rota que siempre devuelve `array()` lo cumplía, y de hecho pasó verde mientras lo estaba |
| 2026-09-21 | `SCH-03` comparaba sólo nombres de índice: agregarle `generation` a `dedup` rompía la deduplicación y el test no se enteraba. `SCH-04`, `SCH-05` y `SCH-06` tampoco, porque todos insertan con `generation = 1` |
| 2026-09-21 | `SCH-08` contaba tres tablas, la misma debilidad que ya se había corregido en `SCH-01` |
