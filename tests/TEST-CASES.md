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
| `UNI-NN` | La desinstalación | [`tests/test-uninstall.php`](test-uninstall.php) |
| `LED-NN` | El libro de movimientos | [`tests/test-ledger.php`](test-ledger.php) |
| `GRD-NN` | El guard anti-eco | [`tests/test-guard.php`](test-guard.php) |

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

## Cómo se elige qué probar

**Antes de escribir los tests, enumerar los estados posibles de lo que la función mira.**
Una tabla, una opción, una fila, un parámetro: cada cosa que el código consulta tiene un
conjunto de estados, y cada estado es un test.

```
La función revisa tres tablas. Una tabla puede estar:
  1. presente, con el motor correcto     → SCH-02
  2. presente, con otro motor            → SCH-09
  3. ausente                             → SCH-11
```

Parece obvio escrito así, y sin embargo el caso 3 faltó: al escribir esa función el motor era
lo único que estaba en la cabeza, así que los tests salieron del mismo lugar. **Un test que
no existe no falla nunca**, y ninguna herramienta lo encuentra — ni PHPCS, ni PHPStan, ni el
auditor de tests, que revisa los tests que hay. Lo encontró otra persona leyendo el código.

De ahí la costumbre: la lista de tests sale de los estados, no de lo que uno tenía en mente
mientras escribía la función.

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
| Etiqueta legible, editable | ADR-002 §1 | `ID-04`, `ID-12` | [test-store.php](test-store.php) |
| La huella del sitio se guarda hasheada | ADR-001 §1 | `ID-05` |
| La huella no depende del request | ADR-007 | `ID-09`, `ID-10` | [test-store.php](test-store.php) |
| El clon se apaga solo | ADR-001 §1, ADR-007 | `ID-06`, `ID-07`, `ID-08`, `ID-11`, `PLG-02`, `PLG-05` | [test-store.php](test-store.php), [test-plugin.php](test-plugin.php) |
| Una tabla que falta o no admite transacciones suspende | ADR-004 §5 | `SCH-09`, `SCH-11`, `PLG-03` | [test-installer.php](test-installer.php), [test-plugin.php](test-plugin.php) |
| La identidad se genera antes que el esquema | ADR-002, consecuencias | `PLG-07`, `PLG-08` | [test-plugin.php](test-plugin.php) |
| La secuencia propia es el `AUTO_INCREMENT`, completado en la misma transacción | ADR-004 §1 | `LED-02`, `LED-09`, `LED-10` | [test-ledger.php](test-ledger.php) |
| El movimiento lleva la generación vigente del par | ADR-005 §4 | `LED-03` | [test-ledger.php](test-ledger.php) |
| Un movimiento no se entrega enseguida | ADR-006 §4 | `LED-05` | [test-ledger.php](test-ledger.php) |
| Nada entra a la malla hasta que el dueño vincula | UC-03, ADR-006 §1 | `LED-07` | [test-ledger.php](test-ledger.php) |
| El ACK confirma recepción y no efecto: un reenvío no aplica dos veces | ADR-001 §3 | `LED-13` | [test-ledger.php](test-ledger.php) |
| El receptor sólo aplica la generación vigente | ADR-005 §4 | `LED-15` | [test-ledger.php](test-ledger.php) |
| Un movimiento que no se puede aplicar se retiene, no se descarta | SPEC §3, UC-03 | `LED-16`, `LED-20` | [test-ledger.php](test-ledger.php) |
| El guard no tapa la venta de otro producto del mismo request | ADR-006 §3, SPEC §3 | `GRD-04` | [test-guard.php](test-guard.php) |
| El guard es de un solo uso | ADR-006 §3 | `GRD-03` | [test-guard.php](test-guard.php) |
| El delta sale de la base, no del objeto | ADR-006 §1 | `DET-02`, `DET-03` | [test-detector.php](test-detector.php) |
| Aplicar un movimiento remoto no genera eco | ADR-006 §3, SPEC §3 | `DET-10` | [test-detector.php](test-detector.php) |
| La variación que toma el stock del padre mueve al padre | ADR-006 §1 | `DET-07` | [test-detector.php](test-detector.php) |
| Un sitio suspendido no emite movimientos | ADR-001 §1 | `PLG-17` | [test-plugin.php](test-plugin.php) |
| El claim vence a los 5 minutos | ADR-001 §2 | `LED-25`, `LED-26` | [test-ledger.php](test-ledger.php) |
| El techo de 6 h se mide desde el primer intento, y el intento es el claim | ADR-001 §2 | `LED-29`, `LED-30` | [test-ledger.php](test-ledger.php) |
| Agotado el techo, el movimiento se abandona y lo rescata la reconciliación | ADR-001 §2 | `LED-34`, `LED-35`, `LED-39`, `LED-40` | [test-ledger.php](test-ledger.php) |
| Una fila recibida no tiene eje de entrega | ADR-001 §3 | `LED-38` | [test-ledger.php](test-ledger.php) |
| No se purga lo que la otra tienda todavía no confirmó | ADR-004 §4 | `LED-46` | [test-ledger.php](test-ledger.php) |
| Al purgar, el stock base absorbe lo purgado | ADR-004 §4 | `LED-47`, `LED-48` | [test-ledger.php](test-ledger.php) |
| Cada tienda declara hasta dónde purgó | ADR-004 §4 | `LED-49` | [test-ledger.php](test-ledger.php) |
| El lote no pasa de 200 | ADR-001 §4 | `LED-27` | [test-ledger.php](test-ledger.php) |
| El secreto se borra siempre al desinstalar | ADR-003 | `UNI-01` | [test-uninstall.php](test-uninstall.php) |
| Los nonces se borran siempre | ADR-003 | `UNI-02` | [test-uninstall.php](test-uninstall.php) |
| Con la casilla en falso no se pierde nada más | ADR-003 | `UNI-02`, `UNI-03` | [test-uninstall.php](test-uninstall.php) |
| Con la casilla marcada no queda nada | ADR-003 | `UNI-04`, `UNI-05` | [test-uninstall.php](test-uninstall.php) |

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
| `SCH-09` | **Cada una** de las tres tablas pasada a MyISAM | `unhealthy_tables()` la nombra |
| `SCH-10` | Las tres definiciones SQL | Declaran `ENGINE=InnoDB` explícito, no el default del host |
| `SCH-11` | **Cada una** de las tres tablas borrada | `unhealthy_tables()` la nombra: se busca por ausencia, no entre lo que devuelve `information_schema` |
| `SCH-12` | Las opciones que se leen en cada request | Están autocargadas, leído de la columna `autoload` |
| `SCH-13` | Sin resultado guardado —un sitio que actualiza desde antes del control— | Mira las tablas una vez, en vez de dar por buena la ausencia del dato |
| `SCH-14` | El esquema está roto y no había resultado guardado | El resultado **malo** queda guardado: con un booleano no se guardaba y la consulta cara se repetía en cada request |

## [`tests/test-store.php`](test-store.php) — la identidad

| ID | Caso | Resultado esperado |
|---|---|---|
| `ID-01` | Activación sobre una instalación limpia | Un UUID v4 |
| `ID-02` | `ensure_identity()` dos veces | El identificador no cambia |
| `ID-03` | Dos instalaciones | Identificadores distintos |
| `ID-04` | Etiqueta vacía | `label()` cae en el identificador |
| `ID-05` | Cambia la URL del sitio | La huella cambia con ella y es `sha256` de la URL nueva |
| `ID-06` | Sin huella guardada | No es un clon: todavía no se emparejó |
| `ID-07` | El sitio donde se emparejó | No es un clon |
| `ID-08` | Se emparejó en una URL y la base se restauró en otra | **Es un clon** |
| `ID-13` | Tras la activación | El identificador se autocarga; la etiqueta no |
| `ID-09` | Un plugin filtra `home_url` | La huella no cambia: sale de la opción `home` |
| `ID-10` | `home` en `http` y el request llega por HTTPS | La huella no cambia |
| `ID-11` | Se emparejó en `https` y la copia se sirve con el mismo dominio por `http` | **Es un clon**: el esquema es parte de la huella |
| `ID-12` | Instalación limpia | La etiqueta es el nombre del sitio |

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
| `PLG-09` | `suspension_reason()` con todo en orden | No ejecuta ninguna consulta: lee la opción autocargada |
| `PLG-10` | Sin WooCommerce | El plugin avisa en el admin |
| `PLG-11` | `boot()` con el esquema atrasado | Migra: la versión queda al día **y las tres tablas vuelven a existir** |
| `PLG-12` | Cuando sale el primer `CREATE TABLE` de la activación | La identidad ya existe (ADR-002) |
| `PLG-13` | Las tablas rotas | El aviso habla de las tablas, no del clon |
| `PLG-14` | El archivo principal | Engancha `plugins_loaded` y el hook de activación |
| `PLG-15` | `boot()` con el sitio suspendido | Engancha el aviso, no sólo sabe que está suspendido |
| `PLG-08` | `boot()` sin identidad: el plugin no pasó por la activación | Genera la identidad |
| `PLG-16` | `boot()` con el sitio sano | Engancha el detector |
| `PLG-17` | `boot()` con el sitio suspendido | **No** engancha el detector: un clon descontaría stock de la tienda real |

## [`tests/test-uninstall.php`](test-uninstall.php) — la desinstalación

El test define `WP_UNINSTALL_PLUGIN` e **incluye** `uninstall.php`, que es exactamente como
lo corre WordPress: el archivo no tiene funciones ni clases, así que se puede incluir en
varios tests sin chocar.

| ID | Caso | Resultado esperado |
|---|---|---|
| `UNI-01` | Casilla en falso | El secreto de `wss_peers` se va; la URL y el identificador quedan |
| `UNI-02` | Casilla en falso | Sólo se borra la tabla de nonces, y el libro y los vínculos **conservan sus filas** |
| `UNI-03` | Casilla en falso | Quedan la identidad, la versión de esquema, la huella y la etiqueta |
| `UNI-04` | Casilla marcada | No queda ninguna tabla |
| `UNI-05` | Casilla marcada | No queda ninguna de las siete opciones |
| `UNI-06` | **Casilla nunca tocada** — el estado de fábrica | No se borra nada: es el caso del dueño que nunca abrió los ajustes |

## [`tests/test-ledger.php`](test-ledger.php) — el libro

| ID | Caso | Resultado esperado |
|---|---|---|
| `LED-01` | Cambio de un producto vinculado | Escribe la fila con origen, delta, producto, vínculo, motivo y referencia |
| `LED-02` | Idem | `origin_seq` termina igual al `id` de la fila |
| `LED-03` | Idem | Toma la generación del vínculo |
| `LED-04` | Idem | Nace `pending`, con 0 intentos y sin confirmar |
| `LED-05` | Idem | La diferencia entre `next_attempt_at` y `created_at` es el retardo decidido, y el retardo son 10 segundos |
| `LED-06` | Sin motivo declarado | `adjustment`, y sin referencia |
| `LED-07` | Producto sin vincular | Devuelve `null` y no escribe nada |
| `LED-08` | Delta 0 | Devuelve `null` y no escribe nada |
| `LED-09` | El `UPDATE` de la secuencia falla | Se deshacen las dos escrituras: no queda ninguna fila |
| `LED-10` | Las consultas de `record()` | El `INSERT` va después del `START TRANSACTION` y el `COMMIT` después del `UPDATE` |
| `LED-11` | Un movimiento aplicable | Descuenta el stock de verdad y devuelve `applied` |
| `LED-12` | Idem | La fila del receptor guarda origen, secuencia y producto de origen, con el eje de entrega vacío |
| `LED-13` | El mismo movimiento dos veces | No descuenta dos veces y queda una sola fila |
| `LED-14` | Sin vínculo | `discarded` |
| `LED-15` | Generación que ya no corre | `discarded`, y el stock no se toca |
| `LED-16` | El producto local borrado | `held`: guardado y reintentable |
| `LED-17` | Al aplicar | El guard ya está anotado **cuando WooCommerce avisa el cambio**, no después |
| `LED-18` | Un movimiento de +2 | Suma: una devolución no descuenta |
| `LED-19` | El `INSERT` de la fila falla | Devuelve `null`: sin fila no hay nada que confirmar, y el emisor reintenta |
| `LED-20` | El producto local en la papelera | `held`, y el stock no se toca |
| `LED-21` | Una variación que toma el stock del padre | El guard anota el **padre**, que es quien cambia |
| `LED-22` | Dos movimientos pendientes | Los reclama los dos y quedan `in_flight` con el worker anotado |
| `LED-23` | Un movimiento con el reintento en el futuro | No se reclama |
| `LED-24` | Tres pendientes, dos pasadas de a dos | Ninguna fila cae en las dos: el token lo genera `claim()` |
| `LED-25` | Un claim de hace 10 minutos, **con la sesión en otra zona horaria** | Se libera igual: el paso 1 también compara en UTC |
| `LED-26` | Un claim de hace 30 segundos | No se toca |
| `LED-27` | Cinco pendientes, tope de 3 | Reclama 3 |
| `LED-28` | La sesión de MySQL en otra zona horaria | Reclama igual: el paso 2 compara contra `UTC_TIMESTAMP()` |
| `LED-29` | Reclamar un movimiento | Cuenta como intento: `attempts` sube y queda `first_attempt_at` |
| `LED-30` | Reclamar uno que ya tenía intentos | `first_attempt_at` no se pisa; `attempts` sube |
| `LED-31` | Una fila confirmada | Queda `confirmed`, con su hora y sin claim |
| `LED-32` | Una fila que falló | Vuelve a `pending`, con el próximo intento más adelante y el motivo guardado |
| `LED-33` | La curva de backoff | 1, 2, 4, 8, 16 min… con tope de 30 |
| `LED-34` | Una fila con 7 horas desde el primer intento | Se abandona al reclamar, y no se entrega |
| `LED-35` | Una con 5 horas | Se sigue reclamando |
| `LED-36` | Un id que no está en el libro | No rompe nada |
| `LED-37` | Un `ack()` con el token de otra pasada | No toca la fila |
| `LED-38` | Un `ack()` sobre un movimiento **recibido** | No le toca el `delivery`: no tiene entrega que anotar |
| `LED-39` | Una fila pasada del techo que un worker vivo está entregando | **No se abandona**: se abandona lo que espera, no lo que está en camino |
| `LED-40` | La misma, de un worker muerto | Se libera y se abandona en la misma pasada |
| `LED-41` | Un motivo de 400 caracteres | Se recorta a 255 y la fila se actualiza igual |
| `LED-42` | Falla y después se confirma | El motivo del fallo queda como historia |
| `LED-43` | Dos fallos seguidos | La segunda espera es el doble de la primera, no un número fijo |
| `LED-44` | Movimientos terminados de hace 100 días | Se purgan |
| `LED-45` | Uno terminado de hace 10 días | No se toca |
| `LED-46` | Viejos pero `abandoned`, `pending` o `held` | **No se purgan**: lo que no llegó se queda |
| `LED-47` | Purgar −2 y −1 | El stock base baja 3: con deltas que sumaran cero, el test pasaba sin consolidar nada |
| `LED-48` | Purgar una recibida `discarded` | Se borra pero **no se consolida**: nunca tocó el stock |
| `LED-49` | Purgar una recibida de la otra tienda | Queda la marca de hasta qué secuencia se purgó |
| `LED-50` | Purgar un movimiento de una generación anterior | Se borra pero **no se consolida** |
| `LED-51` | El `DELETE` de la purga falla | No queda nada a medias: ni el stock base movido, ni la marca |

## [`tests/test-guard.php`](test-guard.php) — el guard anti-eco

| ID | Caso | Resultado esperado |
|---|---|---|
| `GRD-01` | Un cambio que nadie anotó | No es nuestro |
| `GRD-02` | El cambio del producto anotado | Es nuestro |
| `GRD-03` | Dos cambios del mismo producto, una anotación | Sólo el primero es nuestro |
| `GRD-04` | **La venta de otro producto mientras aplicamos** | No se tapa: es el agujero que tiene una bandera global y el guard no |
| `GRD-05` | Un lote con dos movimientos del mismo producto | Dos anotaciones, dos cambios nuestros |
| `GRD-06` | Varios productos anotados | Cada uno se consume por su cuenta |
| `GRD-07` | Una anotación que nadie consumió | Se puede descartar |

**El agujero declarado:** una venta del **mismo** producto que caiga entre el `expect()` y la
escritura se comería la anotación. Entre esas dos líneas no corre nada, y una venta de otro
request corre en otro proceso de PHP y no pasa por nuestro callback: haría falta un hook
anidado dentro de nuestra propia llamada que toque el mismo producto.

## [`tests/test-detector.php`](test-detector.php) — el detector

| ID | Caso | Resultado esperado |
|---|---|---|
| `DET-01` | Una venta de 2 | Un movimiento de −2 |
| `DET-02` | **Guardar el producto de 10 a 15** | Un movimiento de +5. Con el objeto, el `before` ya traía 15 y no salía nada |
| `DET-03` | **Vender 2 con un objeto cargado antes de otra venta de 1** | −1 y −2. Con el objeto, el segundo salía −3 |
| `DET-04` | Un producto sin vincular | Nada |
| `DET-05` | Guardar un producto que no gestiona stock | Nada |
| `DET-06` | Un cambio anotado en el guard, y otro después | Sólo el segundo |
| `DET-07` | Una variación que toma el stock del padre | El movimiento es del padre |
| `DET-08` | Una variación con stock propio | El movimiento es de la variación |
| `DET-09` | Un `before` que se quedó sin su `set` | No sirve de punto de partida para el cambio siguiente |
| `DET-10` | `apply()` de un movimiento remoto, con el detector enganchado | Aplica y no emite nada propio: no hay eco |

Verificado rompiéndolo: leyendo el objeto en el `before` en vez de la base caen `DET-02` y
`DET-03`.

**La ventana declarada:** si otro request escribe el stock del mismo producto entre las dos
lecturas, ese cambio se cuenta dos veces. Son milisegundos y no se puede reproducir en un test
de un solo proceso; lo corrige la reconciliación (ADR-006 §1).

## Cómo se prueba algo que no deja rastro en el estado final

Tres casos de esta suite no se pueden verificar mirando cómo queda la base, porque lo que
deciden es **cuándo** o **dentro de qué** pasa algo. Los tres usan el mismo recurso: engancharse
al filtro `query` de WordPress y mirar las consultas mientras pasan.

| Qué hay que probar | Cómo |
|---|---|
| Un orden (`PLG-12`) | Cuando sale el primer `CREATE TABLE`, preguntar si ya había identidad |
| Una transacción (`LED-10`) | Ver que el `INSERT` caiga después del `START TRANSACTION` y el `COMMIT` después del `UPDATE` |
| Un fallo de escritura (`LED-09`) | Reemplazar la consulta por una rota y ver que no quede nada |

Los dos primeros miran el mecanismo y no el efecto, que es menos deseable y acá es lo único
posible: el efecto de una transacción no se puede observar desde adentro de la transacción que
la suite de WordPress abre para cada test.

---

## Lo que todavía no tiene tests

- Todo `Transport/` y `Reconciliation/`: no hay código todavía.
- Que el libro que crece sin techo —porque la otra tienda no vuelve nunca— se **avise**
  (UC-08). `purge()` ya lo deja crecer a propósito; el aviso va con el ADR del log.
- La purga que consolida el stock base.
- **La rama `if ( ! class_exists( 'WooCommerce' ) )` de `boot()`.** El bootstrap carga
  WooCommerce de verdad —el plugin le cambia el stock a productos reales— así que la clase
  existe durante toda la corrida y esa rama queda fuera de alcance. `PLG-10` cubre lo que el
  dueño ve —el aviso—, pero no que `boot()` efectivamente corte ahí.

  El costo es acotado: la cabecera declara `Requires Plugins: woocommerce`, así que desde
  WordPress 6.5 el plugin **no se puede activar** sin WooCommerce. Esa rama sólo se alcanza
  si alguien desactiva WooCommerce con el plugin ya andando.

---

## Hallazgos de auditoría ya corregidos

| Fecha | Qué estaba verde por la razón equivocada |
|---|---|
| 2026-09-20 | `SCH-01` contaba tres tablas en vez de mirar cuáles eran |
| 2026-09-21 | `ID-05`, `ID-07` e `ID-08` nunca cambiaban la URL del sitio: `fingerprint()` podía ignorar `home_url()` por completo y los tres pasaban. `ID-05` además afirmaba que un hash hexadecimal no contiene un dominio, que es imposible de violar |
| 2026-09-21 | `SCH-02` afirmaba que la lista de problemas estaba vacía: una función rota que siempre devuelve `array()` lo cumplía, y de hecho pasó verde mientras lo estaba |
| 2026-09-21 | `SCH-03` comparaba sólo nombres de índice: agregarle `generation` a `dedup` rompía la deduplicación y el test no se enteraba. `SCH-04`, `SCH-05` y `SCH-06` tampoco, porque todos insertan con `generation = 1` |
| 2026-09-21 | `SCH-08` contaba tres tablas, la misma debilidad que ya se había corregido en `SCH-01` |
| 2026-09-26 | `SCH-07` y `PLG-09` contaban consultas sobre una caché que cebaba el propio test: pasaban con `autoload = false`, que es justo lo que decían verificar. Y la versión del esquema efectivamente no se autocargaba: una consulta por página |
| 2026-09-26 | `UNI-05` revisaba seis opciones de las siete que borra `uninstall.php`; faltaba justo la autocargada |
| 2026-09-26 | `LED-05` comparaba la fila contra `Ledger::DELIVERY_DELAY`, así que bajar la constante a 1 segundo cambiaba los dos lados y el test pasaba igual |
| 2026-09-27 | `LED-47` usaba deltas que sumaban cero y esperaba el mismo número de antes: borrar la consolidación entera dejaba la suite en verde, en el test que justificaba la regla principal de la tarea |
| 2026-09-27 | `LED-32` miraba que el próximo intento quedara "más adelante" y no cuánto: un backoff fijo pasaba igual, y una tienda caída recibiría un intento por minuto durante seis horas |
| 2026-09-27 | `LED-24` decía "no reclama lo de otro worker" y lo probaba con el mismo identificador: el caso real —dos pasadas que se pisan— no lo cubría nadie |
| 2026-09-27 | `LED-28` protegía sólo el paso 2 del claim: un `NOW()` en el paso 1 dejaba un claim muerto tres horas sin liberar |
| 2026-09-27 | `pending_movement()` generaba la secuencia con `wp_rand()`: dos filas con el mismo número chocaban contra el `UNIQUE` y el test fallaba de vez en cuando |
| 2026-09-27 | `LED-11` probaba sólo un delta negativo: usar siempre `'decrease'` pasaba igual, y las devoluciones suman |
| 2026-09-27 | `LED-17` miraba el guard **después** de aplicar, así que anotarlo al final pasaba igual. Ahora lo mira desde el hook de WooCommerce |
| 2026-09-27 | Los tests que rompían una tabla rompían siempre `nonces` o `movements`: sacar `links` de las tablas controladas no lo detectaba nadie |
| 2026-09-27 | "No se borró nada" se verificaba sobre tablas vacías, así que un `TRUNCATE` pasaba igual |
| 2026-09-27 | Ningún test leía el aviso de esquema roto, ni verificaba que los `add_action` del arranque estuvieran puestos: los tests llaman a `boot()` a mano |
| 2026-09-27 | Ningún test miraba que el resultado del control de esquema quedara **guardado**. Con un booleano nunca se guardaba el caso malo, y el cacheo que evita la consulta cara no funcionaba — pero como la función devolvía el valor correcto, todo pasaba en verde |
