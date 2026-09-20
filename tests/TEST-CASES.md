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

## Matriz decisión ↔ test

| Decisión | Dónde está | Tests |
|---|---|---|
| Tres tablas, InnoDB | ADR-004 §1-§3 | `SCH-01`, `SCH-02` |
| Tres índices en el libro, ni uno más | ADR-004 §1 | `SCH-03` |
| La secuencia propia es el `AUTO_INCREMENT`, en `NULL` hasta el `UPDATE` | ADR-004 §1 | `SCH-04` |
| La deduplicación vive en el `UNIQUE`, no en PHP | SPEC §3, ADR-001 | `SCH-05`, `SCH-06` |
| Versión de esquema y migración | ADR-004 §5 | `SCH-07`, `SCH-08` |
| Sin transacciones no se sincroniza | ADR-004 §5 | `SCH-09` |
| Identificador opaco de 128 bits, inmutable | ADR-002 §1 | `ID-01`, `ID-02`, `ID-03` |
| Etiqueta legible, editable | ADR-002 §1 | `ID-04` |
| La huella del sitio se guarda hasheada | ADR-001 §1 | `ID-05` |
| El clon se apaga solo | ADR-001 §1 | `ID-06`, `ID-07`, `ID-08` |

---

## `tests/test-installer.php` — el esquema

| ID | Caso | Resultado esperado |
|---|---|---|
| `SCH-01` | `install()` sobre una base limpia | Existen las tres tablas |
| `SCH-02` | Idem | Las tres son InnoDB |
| `SCH-03` | Idem | El libro tiene exactamente `PRIMARY`, `dedup`, `delivery_queue`, `by_link` |
| `SCH-04` | Dos movimientos propios con `origin_seq` en `NULL` | Los dos entran: en un `UNIQUE`, `NULL` no choca con `NULL` |
| `SCH-05` | Dos veces el mismo `(origin_store, origin_seq)` | El segundo lo rechaza la base |
| `SCH-06` | La misma secuencia de dos tiendas distintas | Los dos entran |
| `SCH-07` | `maybe_upgrade()` con la versión al día | No ejecuta ninguna consulta |
| `SCH-08` | `maybe_upgrade()` con la versión atrasada | Reinstala y deja la versión al día |
| `SCH-09` | Una de las tablas pasada a MyISAM | `tables_without_transactions()` la nombra |

## `tests/test-store.php` — la identidad

| ID | Caso | Resultado esperado |
|---|---|---|
| `ID-01` | Activación sobre una instalación limpia | 32 caracteres hexadecimales (128 bits) |
| `ID-02` | `ensure_identity()` dos veces | El identificador no cambia |
| `ID-03` | Dos instalaciones | Identificadores distintos |
| `ID-04` | Etiqueta vacía | `label()` cae en el identificador |
| `ID-05` | `fingerprint()` | Es un sha256 y **no contiene el dominio**: un search-replace de restore no lo puede reescribir |
| `ID-06` | Sin huella guardada | No es un clon: todavía no se emparejó |
| `ID-07` | Huella igual a la del sitio actual | No es un clon |
| `ID-08` | Huella de otra URL | **Es un clon**: se suspende la sincronización |

---

## Lo que todavía no tiene tests

- Todo `Transport/` y `Reconciliation/`: no hay código todavía.
- El `INSERT` + `UPDATE` de la secuencia dentro de una transacción (`Ledger/`).
- La purga que consolida el stock base.
