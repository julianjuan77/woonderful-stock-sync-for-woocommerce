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
| `DET-NN` | El detector y el estampado del motivo | [`tests/test-detector.php`](test-detector.php) |
| `SIG-NN` | La firma de los requests | [`tests/test-signature.php`](test-signature.php) |
| `NON-NN` | El almacén de nonces | [`tests/test-nonces.php`](test-nonces.php) |
| `MOV-NN` | `POST /movements` | [`tests/test-rest-movements.php`](test-rest-movements.php) |
| `SND-NN` | El envío del lado emisor | [`tests/test-sender.php`](test-sender.php) |
| `PAS-NN` | La pasada recurrente | [`tests/test-pass.php`](test-pass.php) |
| `HLT-NN` | `GET /health` | [`tests/test-rest-health.php`](test-rest-health.php) |
| `PAR-NN` | El emparejamiento | [`tests/test-pairing.php`](test-pairing.php) |
| `CLI-NN` | Los comandos de WP-CLI | [`tests/test-cli.php`](test-cli.php) |
| `BOO-NN` | El bootstrap y el `unlink`, en el libro y en el receptor | [`tests/test-bootstrap.php`](test-bootstrap.php) |
| `LNK-NN` | Vincular y desvincular, del lado de la maestra | [`tests/test-linker.php`](test-linker.php) |
| `PRD-NN` | `GET /products` y su cliente | [`tests/test-rest-products.php`](test-rest-products.php) |
| `STA-NN` | El estado de sincronización | [`tests/test-state.php`](test-state.php) |

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
| Cuatro tablas, InnoDB | ADR-004 §1-§3, ADR-011 §2 | `SCH-01`, `SCH-02` | [test-installer.php](test-installer.php) |
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
| La firma es la del ADR, no la que el código crea | ADR-001 §1 | `SIG-01`, `SIG-02` | [test-signature.php](test-signature.php) |
| Cada parte del request está firmada, la query incluida | ADR-001 §1 | `SIG-04` | [test-signature.php](test-signature.php) |
| La firma no depende de los permalinks ni del orden de la query | ADR-001 §1 | `SIG-05`, `SIG-06` | [test-signature.php](test-signature.php) |
| Ventana de ±5 minutos | ADR-001 §1 | `SIG-08` | [test-signature.php](test-signature.php) |
| El código vale una vez, vence, y una firma con otro no lo gasta | ADR-001 §1 | `PAR-03`, `PAR-04`, `PAR-05` | [test-pairing.php](test-pairing.php) |
| El callback va firmado con el código y tiene que declarar la tienda que dijo | ADR-001 §1 | `PAR-02`, `PAR-06` | [test-pairing.php](test-pairing.php) |
| La secundaria acepta el callback sólo mientras canjea, aunque tenga un secreto viejo | ADR-001 §1 | `PAR-09`, `PAR-10`, `PAR-11` | [test-pairing.php](test-pairing.php) |
| Sin https no se empareja | ADR-001 §1, ADR-007 | `PAR-07`, `PAR-14` | [test-pairing.php](test-pairing.php) |
| Emparejar deja una sola socia | ADR-001 §1 | `PAR-08` | [test-pairing.php](test-pairing.php) |
| La secundaria fija el par y el stock de la maestra, sin eco | ADR-005 §2, ADR-008 §3 | `BOO-01` | [test-bootstrap.php](test-bootstrap.php) |
| Cada revisión rechaza sin escribir nada | ADR-008 §3 | `BOO-02`, `BOO-06` | [test-bootstrap.php](test-bootstrap.php) |
| Una venta espera a su bootstrap | ADR-008 §1, §4 | `BOO-08`, `BOO-09` | [test-bootstrap.php](test-bootstrap.php) |
| Un incierto se averigua recién pasado el tiempo de espera | ADR-008 §5 | `BOO-10` | [test-bootstrap.php](test-bootstrap.php) |
| El `unlink` borra el par vigente y descarta el viejo o ajeno | ADR-005 §3, ADR-008 §3 | `BOO-04`, `BOO-05`, `BOO-11` | [test-bootstrap.php](test-bootstrap.php) |
| La maestra revisa lo suyo antes de escribir y sin salir a la red | ADR-008 §3 | `LNK-02` | [test-linker.php](test-linker.php) |
| Sin respuesta o con un 5xx queda incierto; un rechazo o un 4xx, fallido | ADR-008 §1 | `LNK-03`, `LNK-04`, `LNK-05` | [test-linker.php](test-linker.php) |
| Un incierto se pregunta, no se reenvía | ADR-008 §5 | `LNK-06`, `LNK-07` | [test-linker.php](test-linker.php) |
| El catálogo incluye la papelera, y un producto borrado no vuelve | ADR-005 §5 | `PRD-01`, `PRD-02` | [test-rest-products.php](test-rest-products.php) |
| Lo que pide el cliente, con su query firmada, lo responde el endpoint | ADR-001 §1, ADR-005 §5 | `PRD-07` | [test-rest-products.php](test-rest-products.php) |
| El estado es una vista; la caída de la otra tienda no suspende | ADR-005 §1, UC-03 | `STA-01`, `STA-02`, `STA-05` | [test-state.php](test-state.php) |
| Una copia de la base no se empareja consigo misma | ADR-002 §1, ADR-001 §1 | `PAR-17`, `PAR-18` | [test-pairing.php](test-pairing.php) |
| Ninguna respuesta firmada se guarda en caché | ARCHITECTURE §5 | `HLT-06`, `HLT-07`, `PLG-24` | [test-rest-health.php](test-rest-health.php), [test-plugin.php](test-plugin.php) |
| Sin secreto no hay firma válida | ADR-001 §1 | `SIG-11` | [test-signature.php](test-signature.php) |
| Un nonce se acepta una sola vez | ADR-001 §1, ADR-004 §3 | `NON-02` | [test-nonces.php](test-nonces.php) |
| Los nonces se guardan dos ventanas: el reenvío de un reloj adelantado se frena | ADR-001 §1 | `NON-04`, `NON-05` | [test-nonces.php](test-nonces.php) |
| Sin la tabla, ningún nonce es nuevo | ADR-001 §1 | `NON-07` | [test-nonces.php](test-nonces.php) |
| El receptor autentica en el orden del ADR, y el nonce va al final | ADR-001 §4 | `MOV-02`…`MOV-05` | [test-rest-movements.php](test-rest-movements.php) |
| El origen de cada movimiento es la tienda que firmó | ADR-001 §4 | `MOV-08` | [test-rest-movements.php](test-rest-movements.php) |
| Un movimiento roto falla solo, en su posición; lo que no impide aplicar no se rechaza | ADR-001 §4 | `MOV-09`, `MOV-10`, `MOV-11` | [test-rest-movements.php](test-rest-movements.php) |
| El techo de 200 es del receptor | ADR-001 §4 | `MOV-07`, `MOV-12` | [test-rest-movements.php](test-rest-movements.php) |
| Un sitio suspendido no acepta movimientos | ADR-001 §1 | `PLG-19` | [test-plugin.php](test-plugin.php) |
| Lo que manda el emisor lo acepta el receptor, con permalinks y sin ellos | ADR-001 §1, §4 | `SND-04` | [test-sender.php](test-sender.php) |
| El emisor empareja los resultados por posición, y no confirma uno desordenado | ADR-001 §4 | `SND-05`, `SND-08` | [test-sender.php](test-sender.php) |
| Sin tienda a quien mandar no se reclama nada | ADR-001 §2 | `SND-01` | [test-sender.php](test-sender.php) |
| Una sola acción recurrente, cada 60 segundos | ADR-001 §2 | `PAS-03` | [test-pass.php](test-pass.php) |
| Un clon corre la pasada pero no manda nada | ADR-001 §1 | `PAS-02`, `PLG-20` | [test-pass.php](test-pass.php), [test-plugin.php](test-plugin.php) |
| `health` responde aunque la sincronización esté suspendida, y dice por qué | ADR-001 §4 | `HLT-02`, `HLT-03`, `PLG-23` | [test-rest-health.php](test-rest-health.php), [test-plugin.php](test-plugin.php) |
| `health` no anota el nonce | ADR-001 §4 | `HLT-03`, `HLT-04` | [test-rest-health.php](test-rest-health.php) |
| El motivo se estampa después; sin documento queda `adjustment` | ADR-006 §2 | `DET-11`…`DET-14` | [test-detector.php](test-detector.php) |
| El estampado nunca cae sobre una fila que no es la del pedido | ADR-006 §2 | `DET-15`, `DET-16`, `LED-54` | [test-detector.php](test-detector.php), [test-ledger.php](test-ledger.php) |
| Lo que ya salió no se reescribe | ADR-006 §2 | `LED-53` | [test-ledger.php](test-ledger.php) |
| Una falla adentro de `apply()` no deja nada a medias, ni en la caché | STATUS, deuda de 5.3 | `LED-56`, `LED-57` | [test-ledger.php](test-ledger.php) |
| Un `record()` anidado queda adentro de la transacción de `apply()` | STATUS, deuda de 5.3 | `LED-57`, `LED-58`, `LED-59` | [test-ledger.php](test-ledger.php) |
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
| `SCH-01` | `install()` sobre una base limpia | Existen las cuatro tablas, por nombre |
| `SCH-02` | Idem | Las cuatro figuran en `information_schema` como InnoDB, por nombre |
| `SCH-03` | Idem | Los cuatro índices del libro, con sus **columnas en orden** y cuáles son únicos |
| `SCH-04` | Dos movimientos propios con `origin_seq` en `NULL` | Los dos entran: en un `UNIQUE`, `NULL` no choca con `NULL` |
| `SCH-05` | Dos veces el mismo `(origin_store, origin_seq)` | El segundo lo rechaza la base |
| `SCH-06` | La misma secuencia de dos tiendas distintas | Los dos entran |
| `SCH-07` | `maybe_upgrade()` con la versión al día | No ejecuta ninguna consulta |
| `SCH-08` | `maybe_upgrade()` con la versión atrasada | Reinstala las cuatro tablas **por nombre** y deja la versión al día |
| `SCH-09` | **Cada una** de las cuatro tablas pasada a MyISAM | `unhealthy_tables()` la nombra |
| `SCH-10` | Las cuatro definiciones SQL | Declaran `ENGINE=InnoDB` explícito, no el default del host |
| `SCH-11` | **Cada una** de las cuatro tablas borrada | `unhealthy_tables()` la nombra: se busca por ausencia, no entre lo que devuelve `information_schema` |
| `SCH-12` | Las opciones que se leen en cada request | Están autocargadas, leído de la columna `autoload` |
| `SCH-13` | Sin resultado guardado —un sitio que actualiza desde antes del control— | Mira las tablas una vez, en vez de dar por buena la ausencia del dato |
| `SCH-14` | El esquema está roto y no había resultado guardado | El resultado **malo** queda guardado: con un booleano no se guardaba y la consulta cara se repetía en cada request |
| `SCH-15` | **Una tabla de vínculos de la versión 1, con una fila** | Migrar agrega las cuatro columnas de la discrepancia y conserva la fila (ADR-010 §4) |

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
| `PLG-18` | `boot()` con el sitio sano | Engancha `POST /movements` |
| `PLG-19` | `boot()` con el sitio suspendido | **No** engancha `POST /movements` |
| `PLG-20` | `boot()` con el sitio suspendido | **Sí** engancha el callback de la pasada —sin él Action Scheduler la marcaría fallida cada minuto—, pero no el control de su agendado |
| `PLG-21` | `boot()` con el sitio sano | Engancha la pasada, y el control de su agendado en `action_scheduler_init`, no en `admin_init` |
| `PLG-22` | El archivo principal | Engancha la desactivación |
| `PLG-23` | `boot()` con el sitio suspendido | **Sí** engancha `GET /health` |
| `PLG-24` | `boot()` con el sitio suspendido | **Sí** engancha los encabezados que prohíben la caché |
| `PLG-25` | `boot()` con el sitio suspendido | **Sí** engancha `POST /pair`: re-emparejar es la forma de salir de la suspensión |
| `PLG-26` | `boot()` con el sitio sano | Engancha `POST /reconcile/digest` |
| `PLG-27` | `boot()` con el sitio suspendido | No lo engancha: un clon confirmaría como propios movimientos de la tienda real |
| `PLG-28` | `boot()` con el sitio suspendido | **Sí** engancha los callbacks de la noche, del cierre y de la segunda revisión: un clon trae las acciones agendadas |

## [`tests/test-uninstall.php`](test-uninstall.php) — la desinstalación

El test define `WP_UNINSTALL_PLUGIN` e **incluye** `uninstall.php`, que es exactamente como
lo corre WordPress: el archivo no tiene funciones ni clases, así que se puede incluir en
varios tests sin chocar.

| ID | Caso | Resultado esperado |
|---|---|---|
| `UNI-01` | Casilla en falso | El secreto de `wss_peers` se va; la URL y el identificador quedan |
| `UNI-02` | Casilla en falso | Sólo se borra la tabla de nonces, y el libro, los vínculos y el log **conservan sus filas** |
| `UNI-03` | Casilla en falso | Quedan la identidad, la versión de esquema, la huella, la etiqueta y las tres opciones de los avisos |
| `UNI-04` | Casilla marcada | No queda ninguna tabla |
| `UNI-05` | Casilla marcada | No queda ninguna de las diez opciones |
| `UNI-06` | **Casilla nunca tocada** — el estado de fábrica | No se borra nada: es el caso del dueño que nunca abrió los ajustes |
| `UNI-07` | Desinstalar conservando los datos | La hora del control de la pasada se va igual: no es un dato del dueño |
| `UNI-08` | Desinstalar conservando los datos, con un código de emparejamiento vivo de cada lado | Los dos se van: son una credencial |
| `UNI-09` | Desinstalar conservando los datos, con un envío fallido anotado | `wss_send_failed` se borra igual (ADR-003, revisado el 2026-10-02) |
| `UNI-10` | Desinstalar conservando los datos, con el candado y el resultado de la reconciliación | Los dos se borran igual (ADR-003, revisado el 2026-10-05) |
| `UNI-11` | Desinstalar conservando los datos, con el estado de la otra tienda y el de los avisos | Los dos se borran igual (ADR-011, ADR-012) |

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
| `LED-52` | Estampar un movimiento propio pendiente | Quedan el motivo y la referencia |
| `LED-53` | Estampar un movimiento que la cola ya se llevó | No cambia: viaja con el motivo que tenía |
| `LED-54` | Estampar un movimiento recibido | No cambia: no es nuestro |
| `LED-56` | **Un hook de terceros tira un `TypeError` adentro de `apply()`** | Nada a medias: sin fila, el stock de la base y el de la caché como estaban, sin la anotación del guard, y el movimiento siguiente se aplica |
| `LED-57` | Un hook cambia el stock de otro producto vinculado adentro de `apply()`, y después falla | Se deshace todo junto: la fila recibida, el movimiento del tercero y su stock, **también en la caché** |
| `LED-58` | El mismo cambio anidado, sin falla | El movimiento del tercero se confirma con `apply()` |
| `LED-59` | El `record()` anidado falla | Deshace sólo lo suyo: la fila recibida y el stock aplicado quedan |
| `LED-60` | **Una variación vinculada que pasó a tomar el stock del padre** | `held`, y el stock del padre no se toca. Reemplaza a `LED-21`, que probaba lo contrario: que se le descontara al padre |
| `LED-55` | Estampar una referencia de 150 caracteres | Se guardan 100 y el motivo. Sin el recorte, el `UPDATE` falla entero y la fila queda `adjustment` |


**Las transacciones de `apply()`**, verificado rompiéndolo: atrapando `\Exception` en vez de
`\Throwable` cae `LED-56`; con `START TRANSACTION` adentro en vez de `SAVEPOINT` cae `LED-57`;
con un `ROLLBACK` pelado en el `record()` anidado cae `LED-59`; sin limpiar la caché de lo que
cambió caen `LED-56` y `LED-57`; sin descartar la anotación del guard cae `LED-56`.

`LED-57` encontró que limpiar sólo la caché del producto aplicado no alcanzaba: el otro quedaba
con 19 en la caché y 20 en la base.
## [`tests/test-signature.php`](test-signature.php) — la firma

| ID | Caso | Resultado esperado |
|---|---|---|
| `SIG-01` | **El request de referencia, firmado** | La misma firma que calcula `hmac` de Python sobre el texto del ADR: otra implementación del ADR se entendería con ésta |
| `SIG-02` | El texto que se firma, con query y **sin query** (`POST /movements`) | Seis líneas en el orden del ADR, sin salto al final, método en mayúsculas. Sin query la línea queda vacía, pero está |
| `SIG-03` | Un request bien firmado | Verifica |
| `SIG-04` | Cambiar método, ruta, query, timestamp, nonce, cuerpo o secreto | Cada uno rompe la firma |
| `SIG-05` | La query en otro orden | Verifica igual |
| `SIG-06` | `rest_route` en la query, como con permalinks sin formato | Verifica igual |
| `SIG-07` | Un parámetro que es una lista | No verifica, y no se puede firmar |
| `SIG-08` | El timestamp a ±300 y a ±301 segundos | Vale hasta 300, en los dos sentidos |
| `SIG-09` | La firma en mayúsculas | No verifica: va en hex en minúsculas |
| `SIG-10` | Las cabeceras que arma el emisor | Verifican, y cada request lleva su nonce |
| `SIG-11` | **Un request firmado con secreto vacío** | No verifica: un HMAC con clave vacía lo calcula cualquiera |

Verificado rompiéndolo: sin sacar `rest_route` cae `SIG-06`; sin ordenar la query caen `SIG-01`,
`SIG-02` y `SIG-05`; con un segundo más de ventana cae `SIG-08`; aceptando el secreto vacío cae
`SIG-11`; filtrando las líneas vacías cae `SIG-02` —y sólo él: `SIG-01` usa una query con
parámetros.

**Los booleanos no van en la query.** `(string) false` es `""`, pero `http_build_query()` lo
manda como `0`: el receptor firmaría otro texto. El tipo de `sign()` y `headers()` es
`array<string, string|int>`, así que PHPStan lo frena en quien arme la URL (5.4, 5.8).

## [`tests/test-nonces.php`](test-nonces.php) — el almacén de nonces

| ID | Caso | Resultado esperado |
|---|---|---|
| `NON-01` | Un nonce que nunca se vio | Es nuevo |
| `NON-02` | El mismo nonce, otra vez | Es un reenvío |
| `NON-03` | Un nonce vacío, o de 65 caracteres | Se rechaza. El de 64 entra |
| `NON-04` | **El reloj del emisor adelanta 5 minutos y el reenvío llega a los 10 justos, después de una purga** | La firma todavía verifica, y el nonce sigue anotado: se frena. Es el peor caso: una retención un segundo menor lo deja pasar |
| `NON-05` | La purga, con nonces justo en el borde de las dos ventanas | Se va el de un segundo más; el del borde se queda |
| `NON-07` | La tabla no está | Nada es nuevo: el request se rechaza |

`NON-06` comparaba la constante con la misma expresión que la define: repetía el código sin
probar nada, y se borró cuando `NON-04` pasó a medir el borde.

Verificado rompiéndolo: con una sola ventana de retención, o con un segundo menos, cae `NON-04`; sin el
control de largo cae `NON-03`; tomando como nuevo cualquier resultado que no sea un error
—el `0` de un repetido— caen `NON-02`, `NON-04` y `NON-05`.

## [`tests/test-rest-movements.php`](test-rest-movements.php) — `POST /movements`

| ID | Caso | Resultado esperado |
|---|---|---|
| `MOV-01` | Un lote de dos, bien firmado | 200, dos `received` en orden, los dos stocks aplicados |
| `MOV-02` | Una tienda que no está, y una que está sin secreto | 401 `wss_unknown_store` las dos |
| `MOV-03` | El timestamp fuera de la ventana | 401 `wss_stale_timestamp`, no `wss_bad_signature` |
| `MOV-04` | **Una firma mala** | 401 `wss_bad_signature`, y el nonce **no queda anotado** |
| `MOV-05` | El mismo request, reenviado | 401 `wss_replayed_nonce`, el stock descontado una vez |
| `MOV-06` | Un cuerpo que no es JSON, sin `movements`, o con `movements` que no es lista | 400 `wss_bad_request` |
| `MOV-07` | Un lote de 201 | 413 `wss_batch_too_large`, nada escrito |
| `MOV-08` | **Un movimiento con el `origin` de esta tienda** en medio del lote | `error` `origin_mismatch` en su posición; los otros dos, `received` |
| `MOV-09` | `seq` como texto, `generation` negativa, `delta` decimal, `product` en cero, y algo que no es un movimiento | Cada uno `error` en su posición, con su motivo; el `seq` ilegible vuelve `null` |
| `MOV-10` | Un `reason` que esta versión no conoce y una referencia de 150 | `received`: el motivo se guarda como llega y la referencia se recorta a 100 |
| `MOV-11` | Un hook de terceros hace fallar un movimiento | Ése vuelve `error` `not_stored`, sin tocar su stock; el siguiente se aplica |
| `MOV-12` | Un lote de 200 justos | 200, con 200 resultados |
| `MOV-13` | Un `delta` de ±2³¹ y una `generation` de 2³² | `error` `invalid_delta` e `invalid_generation`: el rango es el de la columna, no el de PHP |

Verificado rompiéndolo: sin el control de `origin` cae `MOV-08`; anotando el nonce antes de la
firma cae `MOV-04`; mirando la firma antes que el timestamp cae `MOV-03`; tomando el `null` de
`apply()` como recibido cae `MOV-11`; con el techo en `>=` cae `MOV-12`; sin el tope superior de los rangos cae `MOV-13`.

## [`tests/test-sender.php`](test-sender.php) — el envío

La red la hace `pre_http_request`: el test guarda cada request y contesta lo que el caso
necesita, sin salir a ningún lado.

| ID | Caso | Resultado esperado |
|---|---|---|
| `SND-01` | **Sin tienda emparejada** | No sale nada y **no se reclama nada**: las filas siguen `pending` con 0 intentos. El resumen dice `no_peer`, distinto de no tener nada que mandar |
| `SND-02` | Dos movimientos pendientes | Un POST a `<rest_url>/movements`; los dos confirmados |
| `SND-03` | El cuerpo | Cada movimiento con los siete campos de ADR-001 §4 |
| `SND-04` | **El request que arma el emisor, del lado del receptor** | Pasa `Movements::authenticate()`, con la base con permalinks y con `?rest_route=` |
| `SND-05` | Un resultado `received` y uno `error` | El primero confirmado; el segundo vuelve a la cola con el `reason` del receptor |
| `SND-06` | Falla la red | Todo el lote vuelve a la cola con el mensaje de la red |
| `SND-07` | El receptor contesta 401 | Todo el lote vuelve a la cola con `HTTP 401 <código>` |
| `SND-08` | Falta un resultado, y otro trae en su posición el `seq` de otra fila | Ninguno de los dos confirma: `missing_result` y `result_mismatch` |
| `SND-09` | Nada venció su retardo | No sale nada, y el resumen no trae error |
| `SND-10` | **Una entrada vieja en `wss_peers`, sin URL ni secreto**, junto a la tienda emparejada | Se manda igual: la entrada que dejó la purga de un UUID anterior no corta la entrega |
| `SND-11` | Al final del request, **uno anotado sin cumplir los 10 segundos** y otro pendiente sin anotar | Sale sólo el anotado, y se confirma; el otro queda para la pasada (ADR-006 §5) |
| `SND-12` | Al final del request, la otra tienda no contesta | Se esperó 5 segundos, y la fila vuelve a `pending` con un intento contado |
| `SND-13` | Al final del request, el anotado ya lo tiene una pasada | No sale dos veces |
| `SND-14` | Al final del request, el anotado cuelga de un bootstrap sin resolver | No sale: lo retiene, como en la pasada |
| `SND-15` | Sin `fastcgi_finish_request()` | No sale nada, y lo anotado se descarta: lo lleva la pasada |
| `SND-16` | Un envío falla entero, y después uno sale bien | El primero anota `wss_send_failed`; el segundo lo borra |
| `SND-17` | **Al final del request, con un fallo de hace menos de un minuto** | No sale nada ni se cuenta un intento: lo lleva la pasada (ADR-006 §5) |
| `SND-18` | Con la función de PHP-FPM, con la de LiteSpeed, sin ninguna | Elige la que hay, o ninguna |
| `SND-19` | **Dos movimientos que pasan las 6 horas, con la otra respondiendo** | Un `abandoned` con la cuenta (ADR-011 §3) |
| `SND-20` | **Lo mismo con la otra caída, y después rechazando** | Caída: nada. Rechazando: `abandoned`, atribuido al rechazo |
| `SND-21` | La base rechaza el claim | `ledger_error` con el error |

Verificado rompiéndolo: reclamando antes de mirar si hay tienda cae `SND-01`; sin el control
del `seq` o tomando un resultado faltante como confirmado cae `SND-08`; ignorando el error del
request caen `SND-06` y `SND-07`; firmando otra ruta cae `SND-04`; contando todas las entradas
de `wss_peers` cae `SND-10`; sin el `no_peer` cae `SND-01`. Del final del request: reclamando sin
filtrar por los anotados, o mirando la espera, caen `SND-11` y `SND-12`; sin retener lo de un
bootstrap cae `SND-14`; con el timeout de 30 cae `SND-12`; mandando sin PHP-FPM cae `SND-15`; sin
anotar o sin borrar el fallo cae `SND-16`; sin mirarlo al final del request cae `SND-17`; sin
LiteSpeed cae `SND-18`.

## [`tests/test-pass.php`](test-pass.php) — la pasada recurrente

| ID | Caso | Resultado esperado |
|---|---|---|
| `PAS-01` | Una pasada con un movimiento pendiente y un nonce viejo | Lo manda y lo confirma, y purga el nonce |
| `PAS-02` | **La misma pasada en un sitio suspendido** | No manda ni purga nada |
| `PAS-03` | Agendar dos veces | Una sola acción, recurrente, cada 60 segundos |
| `PAS-04` | Desactivar el plugin | La acción se desagenda |
| `PAS-05` | El primer control | Agenda, y la hora queda en una opción **autocargada** |
| `PAS-06` | Un control dentro de la hora | No mira: aunque la acción se haya perdido, no hace nada |
| `PAS-07` | **La pasada se perdió y nadie entra al admin**; pasó la hora | Cualquier request la vuelve a agendar |
| `PAS-08` | Una pasada que manda un lote lleno de 200 | Agenda el cierre de la importación (ADR-009 §5) |
| `PAS-09` | **Una pasada sin nada que mandar, con la otra caída hace una hora** | Avisa la caída: el tick corre igual (ADR-012 §2) |

Verificado rompiéndolo: sin el control de suspensión en la pasada cae `PAS-02`; agendando sin
`unique` cae `PAS-03`; desactivando sin desagendar cae `PAS-04`; enganchando el callback
después de la guarda caen `PLG-20` y `PLG-21`. Un primer `as_has_scheduled_action()` antes de
agendar resultó redundante —la mutación que lo sacaba no hacía caer nada— y se borró: lo que
impide la segunda es `unique`. Sin el límite horario cae `PAS-06`; con la hora sin autocarga
cae `PAS-05`; enganchando el control a `admin_init` cae `PLG-21`; sin borrarla al desinstalar
cae `UNI-07`.

## [`tests/test-rest-health.php`](test-rest-health.php) — `GET /health`

| ID | Caso | Resultado esperado |
|---|---|---|
| `HLT-01` | **Un GET firmado, sin cuerpo** | 200 con `store`, `label`, `version`, `api` y `suspended` en `null` |
| `HLT-02` | El mismo GET en un clon | 200, con `suspended: cloned_site` |
| `HLT-03` | La tabla de nonces rota | 200, con `suspended: unhealthy_schema`: no `wss_replayed_nonce` |
| `HLT-04` | El mismo request dos veces | 200 las dos, y ningún nonce anotado |
| `HLT-05` | Firma mala, o tienda que no está | 401 `wss_bad_signature` y `wss_unknown_store` |
| `HLT-06` | **La respuesta y el error de una ruta `wss/v1`, como los manda `serve_request()`** | Con `Cache-Control: … no-store …`, y sin el `Last-Modified` vacío |
| `HLT-07` | Una ruta que no es de `wss/v1` | No se toca |

`HLT-01` encontró que `Auth` le pasaba a `verify()` el `null` que devuelve `get_body()` sin
cuerpo: un `TypeError`, que el cliente veía como un 500. `POST /movements` no lo mostraba
porque siempre trae cuerpo.

Verificado rompiéndolo: sin pasar el cuerpo a texto caen los cinco; anotando el nonce caen
`HLT-03` y `HLT-04`; con `suspended` siempre en `null` caen `HLT-02` y `HLT-03`; registrando la
ruta después de la guarda cae `PLG-23`; aplicando los encabezados a toda ruta cae `HLT-07`; sin
ponerlos cae `HLT-06`; sin engancharlos en `boot()` cae `PLG-24`. Los tests aplican
`rest_post_dispatch` a mano: `dispatch()` no lo aplica, lo hace `serve_request()`.

## [`tests/test-pairing.php`](test-pairing.php) — el emparejamiento

Una sola instalación hace de las dos tiendas por turno; la otra la hace `pre_http_request`. La
opción `home` pasa a `https` en el `set_up`: el entorno de tests es `production`, y la excepción
de `local` y `development` no se puede probar en el mismo proceso, porque WordPress guarda el
tipo de entorno en una estática sin filtro.

| ID | Caso | Resultado esperado |
|---|---|---|
| `PAR-01` | Emitir un código | Lleva la URL de la API, el identificador y 256 bits; se guarda con 15 minutos de vida |
| `PAR-02` | **El canje completo, del lado de la maestra** | El callback va a `<url>/health` firmado con el código; se guardan la socia —con `role: secondary`— y la huella, el código deja de valer, y esta tienda queda maestra |
| `PAR-03` | El mismo código dos veces | La segunda, 401 `wss_pairing_invalid` |
| `PAR-04` | Un código vencido | 401 `wss_pairing_invalid` |
| `PAR-05` | Firmado con otro código | 401 `wss_pairing_invalid`, no `wss_bad_signature`; el código de verdad sigue valiendo |
| `PAR-06` | El callback declara otra tienda | 502 `wss_callback_failed`, nada guardado, el código sigue valiendo |
| `PAR-07` | Una secundaria en http, o esta tienda en http | `wss_insecure` |
| `PAR-08` | Emparejar con una socia vieja en `wss_peers` | A la vieja le quedan sólo el `purged_through` |
| `PAR-09` | **El canje completo, del lado de la secundaria** | El `POST /pair` va firmado con el código; **el callback pasa `health` mientras dura el canje**; se guardan la socia y la huella, y se cierra la ventana |
| `PAR-10` | El callback después de un canje **que terminó bien** | 401: el código ya no autentica nada |
| `PAR-11` | **Re-emparejar con un secreto viejo de la maestra** | El callback firmado con el código pasa igual, y queda el secreto nuevo |
| `PAR-12` | La maestra rechaza el canje | La secundaria recibe su código de error, no guarda nada, y cierra la ventana |
| `PAR-13` | Contesta otra tienda | `wss_pairing_failed`, nada guardado |
| `PAR-14` | Algo que no es un código, o una maestra en http | `wss_pairing_invalid` y `wss_insecure`, sin mandar nada |
| `PAR-15` | Un cuerpo sin `id` | 400 `wss_bad_request`, y el código sigue valiendo |
| `PAR-16` | **Mientras dura el canje, otra tienda firma un `health` con el mismo código** | 401: el código autentica sólo a la maestra que lo emitió |
| `PAR-17` | **Una copia de la base de la maestra canjea su código** | 409 `wss_same_store`: ni se guarda a sí misma ni borra a la socia real, no guarda la huella, no hace el callback, y el código sigue valiendo |
| `PAR-18` | La secundaria canjea un código con su propio identificador | `wss_same_store` sin salir a la red |
| `PAR-19` | Un emparejamiento anterior a ADR-008, sin rol | No es maestra ninguna de las dos |

Verificado rompiéndolo, con nueve cambios: `health` sin la variante del canje (caen `PAR-09` y
`PAR-11`) o que la usa sólo sin secreto (`PAR-11`); sin invalidar el código (`PAR-02`, `PAR-03`);
el callback sin mirar el `store` (`PAR-06`); el canje sin cerrar la ventana (`PAR-09`, `PAR-10`,
`PAR-12`); la firma mala como `wss_bad_signature` (`PAR-05`); sin mirar quién contestó
(`PAR-13`); sin borrar las otras socias (`PAR-08`); todo como si fuera https (`PAR-07`, `PAR-14`);
sin mirar qué maestra canjea (`PAR-16`); sin el control del mismo identificador en la maestra
(`PAR-17`) o en la secundaria (`PAR-18`).

**Las opciones se borran en el `set_up`.** Por el mismo motivo que las líneas de pedido del
detector: en el `tear_down` van después del `DROP TABLE`, en una transacción que el `ROLLBACK` de
la suite deshace. `wss_peers` llegaba con entradas de otras clases, como el `purged_through` de
la purga del libro.

## [`tests/test-cli.php`](test-cli.php) — los comandos de WP-CLI

WP-CLI no está cargado en los tests: `tests/fakes/class-wp-cli.php` hace de él, guarda lo que se
imprime y convierte `error()` en una excepción. Que `boot()` registre el comando no se prueba: la
constante `WP_CLI` no se puede definir en un test sin que quede para todos los demás.

| ID | Caso | Resultado esperado |
|---|---|---|
| `CLI-01` | `wp wss pairing-code` | Imprime un código que se puede canjear |
| `CLI-02` | `wp wss pairing-code` sin https | Termina con error, y el mensaje dice https |
| `CLI-03` | `wp wss pair <código>` | Canjea y avisa que quedaron emparejadas |
| `CLI-04` | `wp wss pair` con algo que no es un código | Termina con error y el código del error. Sin el `error()`, cae |
| `CLI-05` | `wp wss link` y `wp wss unlink` en la maestra | Vincula y desvincula, con su aviso |
| `CLI-06` | `wp wss link` en la secundaria | Termina con error: `wss_not_master` |
| `CLI-07` | `wp wss status 12 abc` | Termina con error, y no muestra un `0: unlinked` |
| `CLI-08` | `wp wss reconcile` en la secundaria | Termina con error: `wss_not_master` |
| `CLI-09` | `wp wss reconcile` en la maestra | Reconcilia, dice qué hizo de cada lado, y queda el resultado `manual` |
| `CLI-10` | `wp wss reconcile` con la otra caída | **Termina con error, y dice que no se pudo mirar**: no es lo mismo que no haber nada |
| `CLI-11` | `wp wss reconcile` con otra en curso | Termina con error, sin lanzar otra |
| `CLI-12` | `wp wss reconcile` y la otra borró un vínculo sin respaldo | Lo dice, con el producto de allá |
| `CLI-13` | `wp wss discrepancies` sin nada abierto | Lo dice |
| `CLI-14` | `wp wss discrepancies` con una, y `wp wss discrepancy accept` | La lista con lo que dice el libro, lo que hay y desde cuándo; y la resuelve |
| `CLI-15` | `wp wss discrepancy borrar 12` | Termina con error |
| `CLI-16` | `wp wss log` | Cada evento con fecha, nivel y frase |
| `CLI-17` | `wp wss log --product=12` | El historial: el evento y el movimiento del libro, juntos |
| `CLI-18` | `wp wss notify --enabled=no --email=… --after=48` | Lo guarda y muestra cómo quedó |
| `CLI-19` | `wp wss notify --email=no-es-un-email` | Termina con error y no lo guarda |

**Lo que el WP-CLI falso no ve: el docblock.** El de verdad arma los argumentos a partir de él,
y con `<código>` rechazaba el código como argumento de más. Lo encontró la prueba entre dos
sitios (E.1), no un test: el nombre del argumento va sin tildes.

**PHPUnit toma sólo `tests/test-*.php`.** Antes tomaba cualquier `.php` de `tests/`, y cargaba
como test el stub de WP-CLI para PHPStan (`tests/stubs/`), que define la clase de verdad.

## [`tests/test-bootstrap.php`](test-bootstrap.php) — el bootstrap y el `unlink`

| ID | Caso | Resultado esperado |
|---|---|---|
| `BOO-01` | **La secundaria recibe un bootstrap** de 10 sobre un producto con 7 | Stock en 10, par al revés con la secuencia como generación, los campos en `details`, y **ningún movimiento propio**: el guard cubre la escritura |
| `BOO-02` | Stock distinto del visto, producto en la papelera, sin stock gestionado, y que no viene de la maestra | Cada uno con su motivo, y **nada escrito**: ni fila, ni par, ni stock |
| `BOO-03` | El mismo bootstrap reenviado, después de una venta | `applied`, y el stock no se vuelve a fijar: la venta queda |
| `BOO-04` | Un `unlink` de la maestra, de la generación vigente | Borra el par |
| `BOO-05` | Un `unlink` de una generación vieja, o que no viene de la maestra | `discarded` y el par sigue |
| `BOO-06` | Por el endpoint: uno bueno, uno con el stock cambiado, uno sin `base_stock`; y la consulta | `received`, `stock_changed`, `invalid_bootstrap`; la consulta responde `applied` y `unknown` |
| `BOO-07` | **La maestra escribe el bootstrap** | Fila y vínculo juntos, la secuencia como generación, la fila `syncing`, y la cola no se la lleva |
| `BOO-08` | **Una venta mientras el bootstrap está en camino, y después incierto** | No sale; con `confirmed`, sale |
| `BOO-09` | El bootstrap falla | Se borra el vínculo, queda el motivo, y la venta que esperaba sale |
| `BOO-10` | Un incierto viejo, uno nuevo y uno confirmado | Se averigua sólo el viejo |
| `BOO-11` | **La maestra desvincula** | El `unlink` va a la cola con la generación del vínculo, y el vínculo se borra |
| `BOO-12` | **Un bootstrap falla, y hay un vínculo recibido con la misma generación** —pasa después de invertir los roles— | Se borra el suyo, no el ajeno |
| `BOO-13` | El mismo gemelo, con una venta de cada uno | Espera sólo la del bootstrap |
| `BOO-14` | Un bootstrap `confirmed`, resuelto después como `uncertain` y como `failed` | Sigue `confirmed`, con su vínculo |

| `BOO-15` | **La secundaria aplica un bootstrap** | El stock se lee con `FOR UPDATE`, adentro de la transacción y antes de escribir |
| `BOO-16` | La maestra escribe un bootstrap | Lo mismo |
| `BOO-17` | **Un vínculo viejo del producto de acá**, de un desvincular que no llegó | Se reemplaza: el bootstrap entra y queda el nuevo. Sin el reemplazo, cae |
| `BOO-18` | Un vínculo viejo que apunta al producto de la maestra desde otro de acá | También se reemplaza, y queda un solo vínculo con ese remoto |

`BOO-15` y `BOO-16` miran el mecanismo, como `LED-10`: una venta concurrente no se puede
reproducir en un test de un solo proceso. Con el código de antes caen los dos.

`BOO-12` a `BOO-14` se corrieron contra el `Ledger` anterior: caen los tres. El gemelo se crea
**antes** que el bootstrap, para que buscar por generación lo encuentre primero.

Verificado rompiéndolo, con diez cambios, y cada uno hace caer al menos uno: sin retener la venta
(`BOO-08`); el bootstrap por el camino de siempre (`BOO-01`, `BOO-02`, `BOO-03`, `BOO-06`); sin el
guard (`BOO-01`); sin revisar el stock (`BOO-02`, `BOO-06`) o la maestra (`BOO-02`); el `unlink`
sin mirar la generación (`BOO-05`) o sin borrar el vínculo (`BOO-11`); el fallido sin borrar el
vínculo (`BOO-09`); sin la espera (`BOO-10`); el rechazo tomado como recibido (`BOO-06`).

## [`tests/test-linker.php`](test-linker.php) — vincular y desvincular

Esta tienda hace de maestra; la otra la hace `pre_http_request`.

| ID | Caso | Resultado esperado |
|---|---|---|
| `LNK-01` | **Vincular, y la otra dice que sí** | El bootstrap sale firmado a `<url>/movements` con sus campos, la secuencia como generación; queda `confirmed` y el par armado |
| `LNK-02` | Stock distinto del visto, uno de los dos ya vinculado, sin stock gestionado, un producto que no existe, y desde la secundaria | Cada uno con su código, **sin salir a la red ni escribir nada** |
| `LNK-03` | La otra lo rechaza con `stock_changed` | Ese código; el par se borra y el motivo queda en la fila |
| `LNK-04` | **Sin respuesta** | `uncertain`, y el par se mantiene |
| `LNK-05` | Un 500, y un 401 del lote entero | El 500 queda `uncertain`; el 401 es `bootstrap_failed`, con el detalle en el mensaje, y borra el par |
| `LNK-06` | **Tres inciertos viejos: la otra dice `applied`, `unknown`, y uno no contesta** | El primero queda, el segundo se borra, el tercero sigue `uncertain`; la pregunta es un GET firmado |
| `LNK-07` | Un incierto nuevo | No se pregunta |
| `LNK-08` | Desvincular; otra vez sin vínculo; y desde la secundaria | El `unlink` a la cola; `not_linked`; `wss_not_master` |
| `LNK-09` | **Una copia de la tienda, suspendida, vincula y desvincula** | `wss_suspended` las dos, sin salir a la red ni escribir nada. Sin la guarda, cae |
| `LNK-10` | Vincular sin emparejar | `wss_not_paired` |
| `LNK-11` | La espera antes de preguntar contra la ventana de la firma | Es más larga: un bootstrap tardío ya no pasa la firma cuando se pregunta |
| `LNK-12` | **Un vínculo que sale bien, uno rechazado antes de mandar, y uno incierto** | `linked` y `link_failed` con su motivo, marcado como lo que el dueño estaba haciendo; el incierto, ningún evento de vinculación (ADR-011 §3) |
| `LNK-13` | Un intento fallido con la otra caída | El `link_failed` se atribuye a la caída (ADR-011 §5) |
| `LNK-14` | **La pregunta de los 10 minutos: aplicado, desconocido, sin respuesta; dos pasadas** | `linked` y `link_failed` como algo que corrió solo; `link_unresolved` **una vez** |
| `LNK-15` | Desvincular | `unlinked` |

Verificado rompiéndolo, con seis cambios: sin revisar el stock propio (`LNK-02`) o la maestra
(`LNK-02`); el timeout como fallido (`LNK-04`, `LNK-06`); el 5xx como fallido (`LNK-05`); el
`unknown` como confirmado (`LNK-06`); sin la espera (`LNK-07`).

## [`tests/test-rest-products.php`](test-rest-products.php) — `GET /products`

| ID | Caso | Resultado esperado |
|---|---|---|
| `PRD-01` | **Un simple vinculado, uno sin stock gestionado, uno en la papelera, un padre variable que gestiona el stock y su variación** | Los seis campos; `stock: null` sin stock gestionado y en la variación que toma el del padre; el padre con su número; `trash`; `linked` |
| `PRD-02` | `include` con uno que existe y uno borrado | Vuelve sólo el que existe: el borrado no aparece, y así se sabe |
| `PRD-03` | `include` sin IDs válidos | Nada, y no el catálogo entero |
| `PRD-04` | Recorrer todo el catálogo de a dos por página | En orden de ID, sin repetidos, con los tres del test, y el total coincide con lo recorrido |
| `PRD-05` | `per_page=500` con 101 productos | Vuelven 100 |
| `PRD-06` | Sin firma, y con firma | 401; y con firma, ningún nonce anotado |
| `PRD-07` | **Lo que pide `Catalog::fetch()`, del lado del endpoint**, con dos IDs | Pasa la autenticación con la query firmada —la coma viaja como `%2C`— y trae lo pedido |
| `PRD-08` | **`include` con un producto agrupado y uno con un estado de otro plugin** | Vuelven los dos: con `include` no se filtra por tipo ni por estado |
| `PRD-09` | `include` con 101 IDs | 400 `wss_bad_request` |
| `PRD-10` | `include` con `page=2` y `per_page=1` | Vuelven todos los pedidos: con `include` no hay páginas |
| `PRD-11` | **El stock cambió en la base y la caché sigue con el viejo** | Vuelve el de la base, que es contra lo que compara el bootstrap |
| `PRD-12` | `fetch()` con una lista vacía | No pide nada: el endpoint devolvería el catálogo entero |
| `PRD-13` | `fetch()` con 150 IDs | Dos requests, y vuelven los 150 juntos |
| `PRD-14` | `fetch()` recibe una entrada con el `id` como texto, y algo que no es un producto | Se descartan |

Los SKU llevan un sufijo por corrida y `PRD-04` recorre el catálogo entero: el `CREATE TABLE`
del `set_up` confirma la transacción, y los productos de un test quedan para el siguiente.

Verificado rompiéndolo, con seis cambios: sin la papelera (`PRD-01`); el `include` vacío que
devuelve todo (`PRD-03`); sin el tope (`PRD-05`); el stock del padre en la variación (`PRD-01`); el
cliente firmando sin la query (`PRD-07`); anotando el nonce (`PRD-06`). Y con otros seis, después
de la revisión: `include` filtrando por tipo y estado (`PRD-08`); sin el tope de IDs (`PRD-09`);
el stock del objeto (`PRD-11`); la lista vacía que pide (`PRD-12`); sin tandas (`PRD-13`); sin
validar (`PRD-14`).

## [`tests/test-state.php`](test-state.php) — el estado de sincronización

| ID | Caso | Resultado esperado |
|---|---|---|
| `STA-01` | **Uno sin vincular, uno vinculado, uno con el de allá en la papelera, uno con el de allá borrado y uno con el de acá en la papelera** | `unlinked`, `linked`, `suspended` con `remote_trashed`, `remote_missing` y `local_trashed`; **una sola pregunta** a la otra tienda |
| `STA-02` | **La otra tienda no responde** | El vinculado sigue `linked`, y el resultado avisa una vez que no se la pudo consultar |
| `STA-03` | Un vínculo con el bootstrap incierto | `uncertain`, sin preguntarle a la otra tienda |
| `STA-04` | Sin ningún vínculo | No se pregunta nada |
| `STA-05` | El de allá en la papelera, y después restaurado | `suspended`, y después `linked` solo: no hay nada que reanudar |
| `STA-06` | **El de acá dejó de gestionar su stock**, y una variación que pasó a tomar el del padre | `suspended` con `local_not_managing_stock`: `apply()` les retiene los movimientos |
| `STA-07` | El de allá vuelve con `stock: null` | `suspended` con `remote_not_managing_stock` |
| `STA-08` | **Un vínculo con una tienda que ya no es la socia** | `suspended` con `peer_changed`, y no se le pregunta a la nueva |
| `STA-09` | Sin socia | `linked`, con `remote_error: wss_not_paired`, y sin preguntar nada |
| `STA-10` | 101 vinculados, y falla la segunda tanda | Los de la primera, con lo que respondió; el de la segunda, `linked`; y el error en `remote_error` |

Verificado rompiéndolo, con seis cambios: la caída como suspensión (`STA-02`); sin el incierto
(`STA-03`); el que no vuelve como vinculado (`STA-01`); sin mirar la papelera de allá (`STA-01`,
`STA-05`) o la de acá (`STA-01`); preguntar sin vínculos (`STA-03`, `STA-04`). Y con otros seis,
después de la revisión: sin mirar el stock propio de acá (`STA-06`) o el de allá (`STA-07`); sin
`peer_changed` (`STA-08`); sin el motivo de la falta de socia (`STA-09`); una tanda que falla y
marca a todos (`STA-10`); el comando sin validar los IDs (`CLI-07`).

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

**El agujero declarado:** entre el `expect()` y el `set` corren los hooks de `before` y el
`save()` del producto con todo lo que dispara. Si código de terceros cambia desde ahí el stock
del **mismo** producto, ese cambio consume la anotación y se pierde; el nuestro tampoco se
emite, así que no hay eco. Una venta de otro request corre en otro proceso y no pasa por
nuestro callback (ADR-006 §3).

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
| `DET-11` | Un cambio sin pedido detrás | Queda `adjustment`, sin referencia |
| `DET-12` | `wc_reduce_stock_levels()` de un pedido | `sale` con `pedido #<número>` |
| `DET-13` | El mismo pedido, cancelado: `wc_increase_stock_levels()` | El segundo movimiento es `refund` con la misma referencia |
| `DET-14` | Un reembolso que repone una unidad | `refund` de +1 |
| `DET-15` | **El cambio del pedido lo tapó el guard**, con un ajuste anterior del mismo producto | El ajuste sigue siendo `adjustment`: el estampado no cae sobre una fila ajena |
| `DET-16` | El pedido de una variación que toma el stock del padre | Se estampa la fila del padre |
| `DET-17` | Un pedido descuenta stock | Su movimiento queda anotado para el final del request, enganchado con `PHP_INT_MAX` en `shutdown` (ADR-006 §5) |
| `DET-18` | Un cambio sin pedido | No se anota: espera la pasada |

Verificado rompiéndolo: leyendo el objeto en el `before` en vez de la base caen `DET-02` y
`DET-03`; sin borrar el movimiento recordado en cada `set` cae `DET-15`; estampando por el ID de
la línea y no por el que lleva el stock cae `DET-16`; sin anotar lo estampado, o enganchando con
la prioridad por omisión, cae `DET-17`.

**Las líneas de pedido se limpian en el `set_up`.** La suite recrea las tablas de WordPress en
cada corrida pero no las de WooCommerce, y las líneas quedan confirmadas: la transacción de
`record()` y el `DROP TABLE` del `tear_down` confirman la del test. En la corrida siguiente los
IDs de pedido vuelven a empezar y un pedido nuevo hereda las líneas viejas. El síntoma: la suite
pasa la primera vez y falla la segunda, con un pedido de una línea que descuenta ocho veces.
Borrarlas en el `tear_down` no sirve: va después del `DROP TABLE`, en una transacción nueva que
el `ROLLBACK` de la suite deshace.

**Límite conocido:** editar las líneas de un pedido desde el admin cambia el stock por
`wc_maybe_adjust_line_item_product_stock()`, que no dispara ninguno de los tres hooks. Ese
movimiento queda `adjustment` sin número de pedido: el delta es correcto, la atribución no.

**La ventana declarada:** si otro request escribe el stock del mismo producto entre las dos
lecturas, ese cambio se cuenta dos veces. Son milisegundos y no se puede reproducir en un test
de un solo proceso; lo corrige la reconciliación (ADR-006 §1).

## [`tests/test-reconciliation.php`](test-reconciliation.php) — la reconciliación

| ID | Caso | Esperado |
| --- | --- | --- |
| `REC-01` | Tomar el candado libre | Queda guardado con su vencimiento a 15 minutos, sin autocargar (ADR-009 §4) |
| `REC-02` | Tomarlo cuando está tomado y sin vencer | No se toma, y no se pisa |
| `REC-03` | Tomarlo vencido | Se toma: una reconciliación que murió a mitad no la apaga para siempre |
| `REC-04` | Quien lo tomó lo suelta | Queda libre y se puede volver a tomar |
| `REC-05` | **Uno que venció quiere soltar el de la que vino después** | No lo suelta |
| `REC-06` | Preguntar si está tomado | Sí mientras no venció; no después |
| `REC-07` | **Una caché vieja dice "vencido"** y en la base está vigente | No se toma: se lee de la base |
| `REC-08` | Un valor ilegible en la opción | Se toma como vencido |
| `REC-09` | Guardar el resultado y leerlo | Se lee como se guardó, sin autocargar (ADR-009 §7) |
| `REC-10` | **Dos procesos toman el mismo candado vencido a la vez** | Gana uno: el `UPDATE` sólo pisa el valor vencido |

Verificado rompiéndolo: sin mirar el vencimiento caen `REC-02` y `REC-07`; un `UPDATE` que no
mira el valor vencido cae `REC-10`; un `DELETE` que no mira el valor propio cae `REC-05`;
`is_taken()` sin mirar el vencimiento cae `REC-06`; leyendo de la caché cae `REC-07`; un valor
ilegible tomado como vigente cae `REC-08`; autocargado cae `REC-01`; otro vencimiento caen
`REC-01`, `REC-02` y `REC-06`.

## [`tests/test-books.php`](test-books.php) — la fase 1, del lado de cada tienda

| ID | Caso | Esperado |
| --- | --- | --- |
| `BOK-01` | Lo recibido de la otra en tres estados, uno fuera del tramo, y uno propio | Las tres del tramo, en orden; lo propio no cuenta |
| `BOK-02` | 5.001 recibidos | Una tanda llena que cubre hasta su última secuencia y avisa que hay más; la segunda, hasta el final |
| `BOK-03` | Uno propio abandonado que la otra no tiene | Vuelve a `pending` con el reloj en cero (ADR-001 §4) |
| `BOK-04` | **Confirmados y abandonados por encima y por debajo de la marca de purga** | Lo confirmado se reabre sólo por encima; lo abandonado, siempre (ADR-009 §2) |
| `BOK-05` | Pendientes y en camino, los tenga la otra o no | No se tocan |
| `BOK-06` | Pendiente y abandonado que la otra tiene | Se confirman |
| `BOK-07` | Un bootstrap que la otra no tiene | No se reabre |
| `BOK-08` | Tres abandonados, uno por tramo | Sólo se toca el del tramo |
| `BOK-09` | **La pasada se lleva la fila entre la lectura y el `UPDATE`** | No se la confirma encima |
| `BOK-10` | El techo | La última secuencia propia; lo recibido no cuenta |
| `BOK-11` | Vínculos con dos tiendas, uno por encima del techo | Sólo los de esa tienda, hasta el techo |
| `BOK-12` | Los mismos vínculos de los dos lados | Nada que hacer |
| `BOK-13` | **Uno de acá que la maestra no tiene, sin productos en común** | Se borra (ADR-009 §8) |
| `BOK-14` | El mismo par con otra generación | Se informa como `mismatched`, no se toca, y no se informa también como faltante |
| `BOK-15` | Uno de la maestra que acá no está | Se informa por su generación |
| `BOK-16` | **Uno de acá armado después del techo de la maestra** | No se borra: la foto de ella no lo tenía |
| `BOK-17` | Bootstraps propios `syncing`, `uncertain` y `confirmed` | Las generaciones de los dos primeros |
| `BOK-18` | La misma generación con otros productos | No es el mismo vínculo: se borra el de acá y falta el de allá |
| `BOK-19` | Un vínculo borrado por no tener respaldo | `link_removed` |

Verificado rompiéndolo: sin la marca cae `BOK-04`; con el bootstrap adentro cae `BOK-07`; sin
el tramo cae `BOK-08`; un `UPDATE` sin el estado esperado cae `BOK-09`; sin resetear el primer
intento cae `BOK-03`. De los vínculos: sin el techo caen `BOK-11` y `BOK-16`; borrando el mismo
par con otra generación cae `BOK-14`; sin borrar caen `BOK-13` y `RCN-08`; comparando sólo por
generación cae `BOK-18`; informándolo dos veces cae `BOK-14`.

## [`tests/test-rest-reconcile.php`](test-rest-reconcile.php) — `POST /reconcile/digest`

| ID | Caso | Esperado |
| --- | --- | --- |
| `RCN-01` | "¿Qué tenés de lo mío?" | La lista, hasta dónde cubre, la marca de purga y el techo |
| `RCN-02` | **La purga confirma justo antes de que se lea la lista** | La marca que vuelve ya cubre lo borrado: se lee después de la lista |
| `RCN-03` | "Esto tengo de lo tuyo" | Reencola lo que falta, confirma lo que llegó, y lo anota como resultado `peer` |
| `RCN-04` | Tres tandas, dos de una reconciliación y una de otra | Las de la misma se suman; la nueva empieza de cero |
| `RCN-05` | Con el candado propio tomado | `409 wss_reconciling`, sin tocar nada |
| `RCN-06` | Once preguntas mal armadas | `400 wss_bad_request`, sin tocar nada |
| `RCN-07` | Sin firma | `401` |
| `RCN-08` | "Estos son mis vínculos", de la maestra | Borra el de acá sin respaldo, e informa el que falta |
| `RCN-09` | **Los mismos, de la secundaria** | `403 wss_not_master`: no se compara nada |
| `RCN-10` | Cinco listas mal armadas, una con una generación por encima del techo | `400` |
| `RCN-11` | "Hacé tu fase 2", con un vínculo que no cierra y una discrepancia ya abierta | La hace —dos revisados, uno sospechoso—, contesta la abierta, y lo anota en su resultado |
| `RCN-12` | Sin sesión | `400` |
| `RCN-13` | La tienda que contesta, al contestar la última pregunta | Deja su `reconciled`, con quién la lanzó |

Verificado rompiéndolo: leyendo la marca antes que la lista cae `RCN-02`; sin mirar el candado
cae `RCN-05`; sin validar las secuencias cae `RCN-06`; sin sumar las tandas cae `RCN-04`; sin
mirar quién manda los vínculos cae `RCN-09`; sin el techo cae `RCN-10`.

## [`tests/test-reconciler.php`](test-reconciler.php) — la tienda que lanza

La otra tienda es un doble que contesta por HTTP interceptado.

| ID | Caso | Esperado |
| --- | --- | --- |
| `RCL-01` | Una reconciliación entera | Lo que falta se reencola, lo que llegó se confirma, la segunda pregunta va hasta el techo de la otra, queda el resultado y el candado se suelta |
| `RCL-02` | **La otra purga entre dos tandas** | Cada tanda se compara con su propia marca: lo que la otra borró no se reenvía |
| `RCL-03` | La otra no responde | No se toca nada; `unreachable` en la primera pregunta; el candado se suelta |
| `RCL-04` | El candado propio tomado | `busy`, sin preguntar ni guardar nada |
| `RCL-05` | La otra contesta `409` | `busy`, sin guardar nada, y el candado se suelta |
| `RCL-06` | Falla la segunda pregunta | Lo de la primera queda |
| `RCL-07` | La otra dice "hay más" sin avanzar | Se corta con `wss_bad_answer` |
| `RCL-08` | Sin socia | `unreachable` con `wss_not_paired`, sin preguntar |
| `RCL-09` | 5.001 recibidos de la otra | La segunda pregunta va en dos tandas, cada una con su tramo |
| `RCL-10` | La marca de purga propia | Viaja en la segunda pregunta |
| `RCL-11` | **La maestra con un vínculo por encima de su techo** | Manda sólo los de hasta el techo |
| `RCL-12` | Lanza la secundaria | No manda vínculos |
| `RCL-13` | La otra informa vínculos borrados, faltantes y con otra generación | Quedan en el resultado; los faltantes, como productos de acá, y sin los de un bootstrap sin resolver |
| `RCL-14` | Falla la comparación de vínculos | `unreachable` en `links`, y lo de los movimientos queda |
| `RCL-15` | Falla la segunda pregunta, con un producto que no cierra | **La fase 2 propia corre igual** y lo encuentra: la cuenta es local (ADR-010 §7) |
| `RCL-16` | Falla la primera pregunta | No corre ninguna fase, tampoco la propia |
| `RCL-17` | **La tercera pregunta** | Las discrepancias abiertas de la otra quedan en el resultado |
| `RCL-18` | Falla sólo la tercera, con un producto que no cierra | La fase 2 propia corre igual y lo encuentra; `unreachable` en `audit` |
| `RCL-19` | **Una que corre, una que no puede mirar, una salteada por el candado** | Dos `reconciled`: `info` y `warning`; la salteada no deja nada |

Verificado rompiéndolo: con la marca de la primera tanda para todas cae `RCL-02`; sin soltar el
candado cuando falla caen `RCL-03` y `RCL-05`; guardando el `busy` cae `RCL-05`; con el techo
propio en la segunda pregunta caen `RCL-01` y `RCL-09`; la secundaria mandando vínculos cae
`RCL-12`; informando los inciertos cae `RCL-13`; siguiendo cuando fallan los vínculos cae
`RCL-14`.

## [`tests/test-schedule.php`](test-schedule.php) — quién lanza la reconciliación

| ID | Caso | Esperado |
| --- | --- | --- |
| `SCD-01` | La próxima noche, antes y después de las 3, en Buenos Aires | Hoy a las 3 de la hora del sitio, o mañana |
| `SCD-02` | Agendar la noche dos veces | Una sola acción, para la próxima noche |
| `SCD-03` | La noche en la maestra | Reconcilia (`nightly`), **después purga**, suelta el candado, y agenda la siguiente a las 3 |
| `SCD-04` | La noche en la secundaria | No reconcilia, pero agenda la siguiente |
| `SCD-05` | La noche con el sitio suspendido | No reconcilia, pero agenda la siguiente |
| `SCD-06` | **Una pasada con 199, y otra con 200** | Sólo la de 200 agenda el cierre, con el techo de ese momento, para dentro de 5 minutos |
| `SCD-07` | Dos lotes llenos seguidos | Un solo cierre agendado |
| `SCD-08` | El cierre con algo nunca intentado, o en camino, hasta su techo | Vuelve a mirar en 5 minutos, con el mismo techo, sin reconciliar |
| `SCD-09` | **El cierre con algo que espera su reintento, y algo nuevo por encima del techo** | No los espera: reconcilia (`import`) |
| `SCD-10` | El cierre en la secundaria | Reconcilia: lo lanza la tienda que importó |
| `SCD-11` | El cierre con el sitio suspendido | No hace nada |
| `SCD-12` | Desactivar el plugin | Desagenda la noche, el cierre y la segunda revisión de la fase 2 |
| `SCD-13` | El control horario de la pasada | Agenda también la noche |
| `SCD-14` | **La noche en la secundaria, con un confirmado de hace 100 días** | Lo purga (ADR-004 §4), y suelta el candado |
| `SCD-15` | La noche con el candado tomado | No purga: la marca no se mueve en medio de una reconciliación |
| `SCD-16` | La noche con el sitio suspendido | No purga |
| `SCD-17` | **La noche de la secundaria** | A las 4: purgar toma el candado, y a las 3 le contestaría "ocupada" a la maestra |
| `SCD-18` | **La noche en la secundaria, con un par roto, un evento de hace 100 días y un producto sin vincular de hace 2 días** | Registra el par roto, purga el evento viejo, y manda el resumen con el producto (ADR-011, ADR-012) |

Verificado rompiéndolo: en UTC cae `SCD-01`; sin mirar si ya está agendada cae `SCD-02`; sin
agendar la siguiente caen `SCD-03` a `SCD-05`; la secundaria reconciliando cae `SCD-04`; sin
mirar la suspensión caen `SCD-05` y `SCD-11`; con el umbral corrido caen `SCD-06` y `PAS-08`;
dos cierres cae `SCD-07`; esperando los reintentos o sin el techo cae `SCD-09`; sin lo que está
en camino cae `SCD-08`; la pasada sin agendar el cierre cae `PAS-08`; el control sin la noche
cae `SCD-13`; desagendando el cierre sólo sin argumentos cae `SCD-12`; el comando sin mirar la
maestra cae `CLI-08`; sin purgar o sin soltar el candado cae `SCD-14`; purgando sin el candado
cae `SCD-15`.

## [`tests/test-audit.php`](test-audit.php) — la fase 2: el stock contra el propio libro

| ID | Caso | Esperado |
| --- | --- | --- |
| `AUD-01` | **Propios en tres estados, recibidos aplicados, retenidos y descartados, y propios y recibidos aplicados de otra generación y de otro vínculo** | Lo esperado es el stock base más lo propio y lo recibido aplicado, de esa generación y ese vínculo (ADR-010 §1) |
| `AUD-02` | El stock coincide con el libro | Nada que volver a revisar |
| `AUD-03` | **No coincide** | No se abre todavía: se agenda la segunda revisión en 2 minutos, con la diferencia (ADR-010 §3) |
| `AUD-04` | A los 2 minutos sigue igual | Se abre: esperado, encontrado, desde y visto |
| `AUD-05` | **A los 2 minutos ya cerró** | No se abre: era una venta anotándose |
| `AUD-06` | A los 2 minutos la diferencia es otra | No se abre |
| `AUD-07` | Una abierta que sigue igual | Se anota que se la vio; el "desde" no cambia, y no hay segunda revisión |
| `AUD-08` | Una abierta cuya diferencia cambió | Los números se reemplazan recién después de la segunda revisión |
| `AUD-09` | **Los números vuelven a coincidir** | Se cierra sola |
| `AUD-10` | Retenidos, bootstrap sin resolver, sin stock propio, en la papelera, borrado | No se revisa (ADR-010 §2) |
| `AUD-11` | La segunda revisión con el sitio suspendido | No abre nada |
| `AUD-12` | Un vínculo nuevo | Nace con las cuatro columnas en `NULL` |
| `AUD-13` | Dos segundas revisiones de lo mismo | La segunda no mueve el "desde" |
| `AUD-14` | **"El stock de acá está bien"** | Anota la diferencia como ajuste pendiente, sin tocar el stock, y la cierra (ADR-010 §6) |
| `AUD-15` | **"El libro está bien"**, con el detector enganchado | El stock vuelve a lo esperado sin anotar un movimiento, y la cierra |
| `AUD-16` | **Una venta entra justo mientras se restaura** | No se pierde: se suma la diferencia, no se escribe el número |
| `AUD-17` | Las dos acciones, con la diferencia cambiada desde que se vio | `wss_changed`, sin tocar nada |
| `AUD-18` | Sin discrepancia, sin vínculo, o con retenidos | `wss_no_discrepancy` o `wss_not_checkable` |
| `AUD-19` | "El libro está bien" con 53 contra 50 | Baja a 50, sin anotar un movimiento |
| `AUD-20` | Una discrepancia que se cierra sola, una aceptada, una restaurada | `discrepancy_opened` con sus números, y `closed`, `accepted` y `restored` |

Verificado rompiéndolo: sumando los retenidos o sin la generación cae `AUD-01`; abriendo a la
primera caen `AUD-03` y `AUD-08`; confirmando sin volver a medir caen `AUD-05` y `AUD-06`; sin
cerrar cae `AUD-09`; reabriendo una igual cae `AUD-07`; moviendo siempre el "desde" cae `AUD-13`;
sin cada exclusión cae su caso de `AUD-10`; confirmando suspendida cae `AUD-11`; con la fase 2
cuando la otra no respondió cae `RCL-16`, y sin ella `RCL-15`; sin desagendar la segunda
revisión cae `SCD-12`. De las acciones: con el signo del ajuste al revés o sin cerrar cae
`AUD-14`; escribiendo el número cae `AUD-16`; sin el guard cae `AUD-15`; sin comparar con lo
visto cae `AUD-17`; sin la tercera pregunta caen `RCL-17` y `RCL-18`; ignorando su falla cae
`RCL-18`; sin validar la sesión cae `RCN-12`.

## [`tests/test-events.php`](test-events.php) — el log

| ID | Caso | Esperado |
| --- | --- | --- |
| `EVT-01` | Un evento con producto, causa y datos, sin persona | Queda todo, y el usuario en `NULL`: WP-CLI sin `--user` da 0 |
| `EVT-02` | Con una persona | Queda quién fue |
| `EVT-03` | **Escribir con la tabla borrada** | No corta nada, no imprime nada, y deja los errores de la base como estaban (ADR-011 §7) |
| `EVT-04` | Tres eventos | De lo más nuevo a lo más viejo; filtra por producto, tipo, nivel y causa; pagina |
| `EVT-05` | **Movimientos del libro y eventos de un producto, y de otro** | Una sola línea de tiempo, sólo de ese producto (UC-03) |
| `EVT-06` | **Eventos de hace 91 días: uno común, un descarte, un deshacer, un aviso; y uno de hace 89** | La purga borra sólo el común (ADR-011 §6) |
| `EVT-07` | Cada tipo | Tiene su frase, no el nombre interno |
| `EVT-08` | Un movimiento recibido de la otra para el producto | Entra en su historial, por el vínculo |
| `EVT-09` | Un par roto, una reconciliación, un motivo desconocido | En castellano —"está en la papelera", "de la noche", "no se pudo mirar"—; el desconocido, como vino |
| `EVT-10` | Una fecha del log, en Buenos Aires | Se muestra en la hora del sitio, no en UTC |

Verificado rompiéndolo: guardando el usuario 0 cae `EVT-01`; sin restaurar los errores cae
`EVT-03`; purgando todo cae `EVT-06`; el historial sin el libro cae `EVT-05`; en orden viejo o
sin el filtro de causa cae `EVT-04`.

## [`tests/test-peer.php`](test-peer.php) — el estado de la otra tienda

| ID | Caso | Esperado |
| --- | --- | --- |
| `PER-01` | Un 200 | Nada al log |
| `PER-02` | **Un error de red, un 503, otro error, con el "desde" atrasado** | Una sola `peer_unreachable`, y el "desde" no se mueve (ADR-011 §4) |
| `PER-03` | Un 401 `wss_bad_signature` | `peer_rejected`, como `error`: no es una caída |
| `PER-04` | **Un `409 wss_reconciling`** con la otra bien y con la otra caída, y otro 409 | El `wss_reconciling` no cambia nada, ni dice que volvió; el otro es un rechazo |
| `PER-05` | Caída y vuelta; rechazo avisado y vuelta | `peer_back` o `peer_accepted`; "volvió" queda pendiente de avisar sólo si se avisó lo anterior, y una vez |
| `PER-06` | La causa | Nada, `peer_unreachable` o `peer_rejected`, según el estado |
| `PER-07` | Preguntarle si anda | Un `GET /health` **firmado**, con 10 segundos de espera, y anota lo que conteste |
| `PER-08` | **El envío, el catálogo, la reconciliación y la pregunta por un bootstrap** | Los cuatro anotan el estado |

Verificado rompiéndolo: sin la excepción del 409 cae `PER-04`; escribiendo en cada respuesta caen
`PER-01` y `PER-02`; un 4xx como caída cae `PER-03`; "volvió" sin haber avisado cae `PER-05`;
el rechazo como `warning` cae `PER-03`; el envío o el catálogo sin anotar cae `PER-08`.

**Los eventos del log (10.3), verificados rompiéndolos:** sin `linked` cae `LNK-12`; sin la causa
cae `LNK-13`; registrando lo irresoluble en cada pasada cae `LNK-14`; sin `unlinked` cae
`LNK-15`; registrando lo abandonado durante la caída cae `SND-20`; sin la cuenta del claim cae
`SND-19`; sin el error del claim cae `SND-21`; sin `reconciled` caen `RCL-19` y `RCN-13`; sin
abrir o cerrar la discrepancia cae `AUD-20`; sin `link_removed` cae `BOK-19`.

## [`tests/test-pairs.php`](test-pairs.php) — los pares rotos

| ID | Caso | Esperado |
| --- | --- | --- |
| `PAI-01` | Un par sano | Nada |
| `PAI-02` | **Un producto vinculado a la papelera, dos noches** | Un solo `pair_broken`, con el motivo, y `broken_since` lleno |
| `PAI-03` | **A la papelera, de vuelta, y a la papelera otra vez** | `pair_broken`, `pair_restored`, `pair_broken`: se registra de nuevo |
| `PAI-04` | Borrado, en la papelera, sin su propio stock | Cada uno con su motivo |

## [`tests/test-notifier.php`](test-notifier.php) — los avisos por email

Los emails se atrapan con `pre_wp_mail`; `GET /health` lo contesta el test.

| ID | Caso | Esperado |
| --- | --- | --- |
| `NOT-01` | **Un vínculo que falló mientras el dueño miraba, y uno que falló solo** | Sólo el segundo, en el momento: al administrador, con `[Woonderful Stock Sync]`, la tienda y el panel |
| `NOT-02` | **300 pares rotos de golpe** | Uno enseguida; el resto, en un solo email al cerrarse la ventana de 10 minutos, con 50 listados y "y otros 249" (UC-08) |
| `NOT-03` | Fallas atribuidas a la caída y al rechazo | Ningún email (ADR-012 §2) |
| `NOT-04` | Dos causas distintas | Cada una su email: una no tapa a otra |
| `NOT-05` | **La caída a los 29 y a los 30 minutos, y otra vez** | Sólo a los 30, una vez |
| `NOT-06` | **La caída, y la otra volvió sin que nadie le hablara** | El tick le pregunta y no sale un aviso de caída falso |
| `NOT-07` | La caída avisada, y vuelve | "Volvió" |
| `NOT-08` | Un rechazo de 30 minutos | Su propio aviso |
| `NOT-09` | **15 avisos en un día, y uno al día siguiente** | 10, y uno último que remite al log; después nada, hasta el día siguiente (ADR-012 §5) |
| `NOT-10` | **Apagado con algo juntado en una ventana y una vuelta pendiente; una falla y un resumen; y se enciende** | Nada de eso sale: lo de antes no queda para después |
| `NOT-11` | **`wp_mail()` falla** | `notify_failed` una vez; se reintenta al cerrarse la ventana, no cada minuto |
| `NOT-12` | El destinatario y el plazo, y un producto de 30 horas con plazo de 48 | Se configuran, y el producto no entra en el resumen |
| `NOT-13` | **Sin vincular, vinculado, sin stock propio, descartado, recién creado; dos noches** | Un solo resumen, sólo con el sin vincular (ADR-012 §3) |
| `NOT-14` | Al día siguiente, uno nuevo | Lista sólo el nuevo, y el total |
| `NOT-15` | **Avisado, y después desvinculado** | Se vuelve a avisar pasado el plazo desde que se desvinculó |
| `NOT-16` | Un `link_unresolved` sin causa | Se avisa en el momento |
| `NOT-17` | **El resumen con el correo roto, y la noche siguiente andando** | No se da por avisado: la noche siguiente lo lista |
| `NOT-18` | Descartado y después deshecho el descarte | Vuelve al resumen |
| `NOT-19` | **El email de la caída falla durante 15 minutos** | Dos intentos —a los 0 y a los 10—, un solo `notify_failed`, y sale cuando el correo anda (ADR-012 §7) |
| `NOT-20` | **El correo roto durante tres ventanas, después anda, y se rompe de nuevo** | Un `notify_failed` por incidente, no por ventana |
| `NOT-21` | Un solo producto sin sincronizar, y un par roto | "1 producto", en singular; y el motivo en castellano |

Verificado rompiéndolo: avisando lo que el dueño miraba cae `NOT-01`; lo atribuido a la caída,
`NOT-03`; sin la ventana, `NOT-02` y `NOT-11`; sin los 30 minutos, `NOT-05`; avisando la caída
de nuevo, `NOT-05`, `NOT-07` y `PAS-09`; sin preguntarle a la otra, `NOT-06` y `NOT-07`; sin el
tope, `NOT-09`; guardando lo de apagado, `NOT-10`; reintentando cada minuto, `NOT-11`; repitiendo
el resumen, `NOT-13` y `NOT-14`; sin el descarte o sin el plazo, `NOT-13`; sin mirar el último
desvínculo, `NOT-15`; registrando el par roto cada noche, `PAI-02`; sin la vuelta, `PAI-03`; la
noche sin pares, sin resumen o sin purgar el log, `SCD-18`; la pasada sin el tick, `PAS-09`;
`notify` sin validar el email, `CLI-19`. Después de la revisión: reintentando la caída cada
minuto cae `NOT-19`; el día del reloj real, `NOT-09`; un `notify_failed` por intento, `NOT-20`;
una vuelta vieja con los avisos apagados, `NOT-10`; sin `link_unresolved`, `NOT-16`; el resumen
marcado sin mandarse, `NOT-17`; la pregunta a `/health` sin firma, `PER-07`; el plazo fijo,
`NOT-12`.

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
| 2026-10-06 | `AUD-01` no tenía recibidos aplicados de otro vínculo ni de otra generación: sin los paréntesis del `OR`, la cuenta sumaba lo de todos los vínculos y pasaba igual. Ningún test veía que la maestra purgara de noche (`SCD-03`), ni un `restore` que baja (`AUD-19`). `RCN-11`, `RCL-15` y `RCL-18` corrían sin vínculos y comparaban contra ceros |