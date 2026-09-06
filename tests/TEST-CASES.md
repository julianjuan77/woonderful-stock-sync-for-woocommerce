# Woonderful Stock Sync — Casos de test

> Última actualización: 2026-09-06
> Cada caso nombra la invariante que protege y el ADR o caso de uso del que sale.

Los casos marcados **[BD real]** no se pueden verificar con un `$wpdb` simulado: lo que
prueban es comportamiento de la base de datos —un índice único que rechaza, un `UPDATE`
condicionado que afecta una fila y no dos—, no código PHP. Un mock que devuelve lo que le
pedimos que devuelva no prueba nada de eso.

---

## Capa de base de datos (ADR-001)

### TC-01 — Un movimiento repetido se registra una sola vez **[BD real]**

> Invariante: idempotencia. `UNIQUE KEY (origin_store, sequence)`.

Registrar dos veces el mismo `(origin_store, sequence)`. La primera devuelve un ID > 0; la
segunda devuelve 0 y la tabla queda con una sola fila. Sin excepción: el duplicado es un
caso esperado, no un error.

### TC-02 — Un movimiento con razón desconocida se rechaza

Registrar con `reason = 'inventado'` lanza `InvalidArgumentException` y no escribe nada. La
validación es en el borde: una razón desconocida contamina el libro, que es la fuente de
verdad para auditar.

### TC-03 — Dos claims sobre el mismo movimiento: solo uno gana **[BD real]**

> Invariante: ejecución única. Action Scheduler no la garantiza; la base sí.

Dos llamadas a `claim()` sobre el mismo ID: la primera devuelve `true`, la segunda `false`.
Al final el movimiento queda en `aplicando` con un solo `claimed_at`.

### TC-04 — Un claim vencido se puede retomar **[BD real]**

Un movimiento en `aplicando` con `claimed_at` más viejo que `STALE_CLAIM_SECONDS` vuelve a
ser reclamable: `claim()` devuelve `true`. Con `claimed_at` reciente devuelve `false`. Sin
esto, un job que muere a mitad deja su movimiento sin aplicar para siempre.

### TC-05 — `pending()` incluye los claims vencidos **[BD real]**

Un pendiente y un `aplicando` vencido aparecen los dos; un `aplicando` reciente no. Mismo
razonamiento que TC-04, del lado de la búsqueda de trabajo.

### TC-06 — Cerrar un movimiento que no se tomó devuelve false **[BD real]**

`mark_applied()` sobre un movimiento en `pendiente` (nunca reclamado) devuelve `false` y no
cambia el estado. Cerrar algo que no se tomó es un bug de secuencia y tiene que fallar
ruidosamente.

### TC-07 — `sum_applied_after()` ignora lo pendiente **[BD real]**

Con movimientos aplicados y pendientes mezclados sobre el mismo SKU, la suma cuenta solo los
aplicados. Incluir pendientes haría que la reconciliación reporte desvíos inexistentes: el
stock local todavía no se movió.

### TC-08 — Purgar no cambia el stock esperado **[BD real]**

> **La restricción dura de ADR-001.**

Dado un SKU con bootstrap y varios movimientos aplicados: calcular el stock esperado
(`base_qty` + suma posterior a `base_up_to`), consolidar con `absorb()`, borrar con
`delete_applied_up_to()`, y volver a calcular. **Los dos números tienen que ser idénticos.**
Un bug acá produce discrepancias silenciosas en todos los productos con historia — el peor
modo de falla del plugin, porque no se ve hasta que la reconciliación empieza a mentir.

### TC-09 — `absorb()` no retrocede **[BD real]**

`absorb()` con un `new_up_to` menor o igual al `base_up_to` actual devuelve `false` y no
toca la fila. Protege contra aplicar dos veces la misma consolidación, que restaría stock
inventado.

### TC-10 — Re-bootstrapear reemplaza, no acumula **[BD real]**

`bootstrap()` dos veces sobre el mismo SKU con cantidades distintas deja la segunda. Un
recuento físico tiene que poder corregir el base.

### TC-11 — `forget()` no borra el libro **[BD real]**

Sacar un SKU de la malla elimina su fila de `wss_product_state` y deja intactos sus
movimientos. El libro es inmutable: es el registro de auditoría.

### TC-12 — Las tablas se crean con sus índices **[BD real]**

Tras `Schema::create_tables()` existen las dos tablas y los tres índices de `wss_movements`.
Sin el único, TC-01 pasaría por casualidad.

### TC-13 — `create_tables()` es idempotente **[BD real]**

Llamarla dos veces no rompe nada ni pierde datos. Es lo que permite invocarla en cada
activación sin condicionarla.

---

## Pendientes de fases posteriores

| Caso | Depende de |
|---|---|
| Una venta descuenta en todas las tiendas | UC-01, ADR de transporte |
| Devolución y cancelación reponen | UC-02 |
| Emparejamiento por SKU: sin SKU, sin coincidencia, duplicado | UC-03 |
| Cambio manual se propaga como ajuste; modo importación en ráfaga | UC-04, ADR de umbral |
| Reconciliación nocturna | UC-06 |
| Bootstrap de un producto que entra a la malla | UC-07 |
| Aviso por email de inconsistencias de SKU | UC-08, ADR de registro de avisos |
