# Woonderful Stock Sync for WooCommerce — Especificación funcional

> Estado: Aprobado — los ocho casos de uso releídos y marcados (2026-09-13)
> Última actualización: 2026-09-13

Este documento se completa **antes** de escribir la primera línea de código. El gate de
edición exige que exista con al menos un caso de uso (`UC-01`) antes de permitir escribir
archivos `.php`/`.js` del plugin.

Regla de oro: **no se codea una pieza mientras no se pueda decir qué hace cuando sale bien
y qué hace cuando sale mal.** Lo que todavía no se sabe se anota como pregunta abierta en
§6, y eso frena exactamente la pieza que nombra, no el resto del plugin.

**Cada criterio de aceptación que falta es una decisión que va a terminar tomando el código
en vez de vos.**

**Estado por caso de uso.** Un `Estado: Aprobada` bajo el título significa que el humano lo
releyó y que **el comportamiento descrito es el que se quiere**. No significa que se pueda
codear: eso lo habilitan los ADRs, y varios casos de uso aprobados siguen esperando el de
transporte. Un caso sin la marca todavía no se releyó.

---

## 1. Problema y usuario

**Quién:** el dueño de un comercio que opera **dos tiendas WooCommerce**
(por marca, mayorista/minorista, etc) y despacha todas desde **un mismo depósito
físico**.

**Qué hace hoy a mano:** reparte el stock real entre las tiendas para no sobrevender —
10 unidades reales se publican como 5 y 5, o se deja un colchón fijo por tienda. Cada
reposición o corrección obliga a recalcular ese reparto y cargarlo tienda por tienda.

---

## 2. Alcance

### Entra en v1

- **Sincronización de la cantidad de stock** y del estado derivado (en stock / agotado /
  disponible bajo pedido) entre **exactamente dos** tiendas WooCommerce. La cantidad
  puede ser negativa en los productos que aceptan reservas.
- **Pool único, sin reparto:** las dos tiendas publican el stock real completo. Si hay
  10 unidades, las 10 se ven en cada una; una venta en cualquiera las deja en 9 en ambas.
- **Productos simples y variaciones.** Cada variación lleva su propio stock.
- **Propagación ante cualquier cambio de stock**: una venta, una devolución, una edición
  manual en el admin, o un cambio hecho por la API REST, WP-CLI o el importador CSV.
- **Log de sincronizaciones y de errores recientes**, en texto, consultable desde el admin.
- **Aviso por email, una sola vez por producto**, de todo lo que no está sincronizando tras
  un plazo configurable —tenga o no sugerencia—, de las vinculaciones que fallaron y de los
  pares rotos, para enterarse sin entrar al panel.
- **Reconciliación manual** disparable desde la pantalla, sin esperar a la tarea programada.
- **Pantalla de ajustes** para dar de alta y conectar las tiendas entre sí, y para designar
  cuál es la **tienda maestra**: la única desde la que se administran los pares y la que fija
  el stock de arranque de cada producto (§3).
- **Pantalla de vinculación y estado** (React): una tabla con filtros y buscador que muestra
  qué productos se sincronizan y cuáles no, con el motivo y el historial por producto. En la
  **maestra** es además **el único lugar donde se administran los pares** —vincular,
  desvincular, reasignar, descartar—, con sugerencias automáticas por SKU y vinculación
  masiva para el arranque. En la **secundaria** la misma pantalla es de solo lectura y remite
  a la maestra. El stock nunca se edita desde acá.

### NO entra en v1

> Esta lista es la que evita el scope creep. Es más importante que la de arriba.

- **Sincronizar cualquier cosa que no sea stock** (precios, títulos, descripciones,
  imágenes, categorías) — **por qué:** los precios legítimamente difieren entre tiendas
  (mayorista vs. minorista) y alinearlos exigiría reglas de conversión; el resto es
  replicación de catálogo, otro producto.
- **Alta automática de productos** — **por qué:** el plugin sincroniza productos que ya
  existen en ambas tiendas. Crearlos implica imágenes, categorías y atributos, es decir la
  replicación de catálogo que quedó afuera.
- **Reserva o colchón por tienda** (topes tipo "la tienda B nunca ve más de 3") —
  **por qué:** reintroduce el reparto de stock que el plugin viene justamente a eliminar, y
  complica cada cálculo de propagación.
- **Dashboard de reportes** con gráficos y métricas — **por qué:** mucho front-end para
  poco valor inicial. No confundir con el historial de movimientos por producto de UC-05,
  que sí entra: ese es una lista de lectura sobre datos que el libro ya guarda, no un panel
  de análisis.
- **Emparejamiento automático de productos** (que el plugin fije pares por su cuenta, sin
  que nadie confirme) — **por qué:** el SKU es un campo editable del catálogo del cliente, y
  el bootstrap es la única escritura de valor absoluto del plugin. Un par fijado solo contra
  un SKU que el dueño estaba corrigiendo pisa el stock del producto equivocado, y el par no
  se vuelve a revisar nunca. El SKU **sugiere**; vincular es siempre una decisión humana
  tomada en la pantalla (UC-03). Para que eso no vuelva inviable el arranque, la vinculación
  masiva fija en bloque las coincidencias inequívocas, una vez y a la vista.
- **Administrar stock desde el plugin** (editar cantidades en línea, conteo físico,
  edición en lote, alertas de stock bajo, exportación) — **por qué:** convierte el plugin
  en un gestor de inventario, que es otro producto y otro tamaño. El stock se sigue
  editando en el admin de WooCommerce, y el plugin se limita a propagar el cambio. La
  pantalla administra **pares**, no inventario: dice qué producto es cuál, nunca cuánto hay.
- **Avisar por email de cada error de sincronización suelto** — **por qué:** un movimiento
  que no se entrega casi nunca es un problema propio: es un síntoma de que la otra tienda no
  responde, y **eso sí se avisa, una vez y agrupado por causa** (UC-08). Mandar un email por
  cada entrega fallida llenaría la casilla y lograría que el dueño filtre todos los mails del
  plugin, justo el día que uno importe.
- **Sincronizar pedidos y clientes** (panel unificado de ventas) — **por qué:** es otro
  producto. Este plugin sincroniza stock, no gestiona ventas.
- **Malla de tres o más tiendas** — **por qué:** el ahorro está concentrado en la parte
  cara. Con dos tiendas la pantalla de UC-05 muestra un estado por producto y no una matriz
  producto × tienda, la reconciliación de UC-06 es un intercambio entre dos y no una
  recolección de N antes de calcular, y la pantalla de ajustes tiene un destino en vez de una
  lista con alta y baja. La propagación, en cambio, no se simplifica: enviar a uno o a N es
  el mismo recorrido sobre una lista.

  **El diseño no lo impide y no hace falta volver atrás para abrirlo.** El libro ya es una
  malla: los movimientos se identifican por tienda de origen más secuencia, los deltas son
  conmutativos e idempotentes por ID, y ninguna de las dos tablas tiene columnas por par. La
  condición para que siga siendo barato es **derivar el estado de sincronización en vez de
  guardarlo** (UC-03): calculado al mostrarlo, pasar de dos a N es una pantalla distinta;
  guardado como un dato por producto, es una migración. Lo que sí se persiste acompaña bien:
  el **par** ya es por definición un dato por enlace —con N tiendas, N-1 filas por producto,
  no una columna nueva— y el **descarte** es del producto local, así que no se multiplica.

  **La regla para cuando se abra, ya razonada:** un producto está completamente sincronizado
  cuando tiene par fijado con **cada** tienda participante, y cuando le falta alguno **se
  bloquean solo los enlaces afectados, no la malla entera** — que el producto no tenga par en
  C no detiene A↔B. Es coherente con §3: una tienda que no puede recibir es como una tienda
  caída, el movimiento espera en el libro de origen y la reconciliación se lo entrega cuando
  el par se establece.

---

## 3. Fuente de verdad

**La topología es una malla entre pares.** Para propagar los cambios de stock las dos tiendas
son iguales y se avisan entre sí: ninguna manda sobre la otra y ninguna es punto único de
falla. Una de ellas se designa **maestra**, pero ese papel es de **administración** —vincular
productos, fijar el stock de arranque, programar la reconciliación; se detalla más abajo— y
**no interviene en los movimientos**, que es lo que pasa todos los días.

**Unidad de sincronización — el movimiento.** Cada cambio de stock genera un movimiento con
un **identificador único global** (tienda de origen + secuencia) y un delta: `venta #123 en
la tienda B → -1`. Las tiendas se propagan movimientos, nunca totales.

De ahí salen tres propiedades que la malla necesita para funcionar:

- **Los deltas no se pisan.** Dos ventas simultáneas en tiendas distintas dan `-2`, que es
  lo correcto. Con valores absolutos, ambas escribirían "quedan 9" y se perdería una unidad.
- **Se pueden aplicar en cualquier orden.** No hace falta que los mensajes lleguen
  ordenados.
- **Son idempotentes por su ID.** Un movimiento ya aplicado se ignora si vuelve a llegar.
  Eso resuelve la **reentrega** del mismo movimiento, sin necesidad de un contador de saltos.

**El eco es otro problema, y la idempotencia no lo cubre.** Cuando B aplica el `-1` que
recibió de A, el stock local de B cambia, y el detector de UC-04 —que observa el valor y no
sabe quién lo cambió— lo ve como cualquier otro cambio y emite un movimiento **nuevo**: otra
tienda de origen, otra secuencia, otro ID. A lo aplica, su propio detector se dispara, y
vuelve. No es solo un bucle: **amplifica el descuento en cada vuelta**.

Es el precio de haber puesto un punto único de detección por diferencia de valor (UC-04).
Hacen falta dos cosas, y una ya está:

- **Deduplicación durable** por tienda de origen más secuencia, que es el `UNIQUE` que el
  libro ya tiene. Cubre la reentrega.
- **Un guard mientras se aplica un movimiento remoto**, para que el detector no emita nada
  por el cambio que provocó el propio plugin. El guard tiene que liberarse aunque la
  aplicación falle a mitad, y no debe tapar una venta local genuina que caiga en el mismo
  request. El mecanismo concreto va al ADR del detector.

El movimiento recibido no necesita reemitirse: ya está en el libro de B con su ID original, y
es lo que la fórmula de `stock base + movimientos` usa para reconstruir el total.

**Reconciliación periódica.** El punto débil de los deltas es el mensaje perdido: si no
llega, el desvío queda pegado para siempre. Por eso, cada cierto tiempo las tiendas comparan
qué movimientos conoce cada una.

**Desempate — el libro de movimientos.** Cuando dos tiendas discrepan, **no gana ninguna por
ser quien es**. El valor correcto se reconstruye: es el stock base más la suma de los
movimientos únicos conocidos entre todas. La reconciliación no adivina un número: identifica
**exactamente qué movimiento le falta a quién** y se lo entrega.

**La identidad de un producto es un par, no su SKU.** El movimiento viaja identificado por
la tienda de origen y el **ID local del producto en esa tienda**: _"producto #123 de la
tienda A → -1"_. Cada tienda guarda, junto al stock base, a qué producto suyo corresponde el
del otro lado, y resuelve el movimiento con esa correspondencia.

**El par lo fija el dueño al vincular**, en el mismo acto que el stock base: "estos dos
productos son el mismo y arrancan en N" (UC-03, UC-07). Con una sola vía para fijarlo —la
pantalla— no hay mapeos cruzados posibles (ADR-004).

**El SKU sigue siendo el lenguaje del dueño, pero no la identidad del plugin.** Es lo que
**sugiere** el par la primera vez —lo que evita tener que aparear a mano un catálogo
entero—, y lo que se ve y se busca en la pantalla de UC-05. No fija nada por su cuenta.

La razón de separarlos es que el SKU es un campo editable del catálogo del cliente. Mientras
fue la identidad, renombrarlo sacaba al producto de la malla y —peor— reasignar un SKU
liberado a un producto nuevo le entregaba el stock base y el historial del anterior, sin
ninguna forma de notarlo. Con la identidad en el par, **cambiar un SKU no tiene ningún
efecto**: un error de SKU impide que se sugiera un par, que es visible y corregible, en vez
de romper uno que ya funcionaba.

**La tienda maestra — el puesto de trabajo del dueño.** Una de las tiendas se designa como
maestra, y su papel es concentrar todo lo que una persona tiene que decidir:

- **Es la única desde donde se administran los pares** (UC-05). Vincular, desvincular,
  reasignar y descartar se hacen ahí y en ningún otro lado. La secundaria muestra el estado
  en modo lectura y le dice al dueño que vaya a la maestra.
- **Su stock es el que fija el bootstrap.** Cuando un producto entra a la malla, el pool
  arranca con el valor que tenga en la maestra y la secundaria se alinea (UC-07).
- **Es la tienda donde se importan los CSV**, por la misma razón: cargar el mismo archivo
  dos veces duplicaría el stock, y tener un solo lugar hace que no haya dónde equivocarse
  (§5).
- **Programa la reconciliación nocturna**, lo que evita el problema de qué huso horario es
  "la madrugada" cuando hay tiendas en países distintos, y es **desde donde se lanza la
  manual** (UC-06). La de cierre de importación la dispara sola la tienda que importó.

**El criterio detrás de la lista: donde decide una persona, hay un solo lugar.** Que se pueda
vincular desde las dos pantallas no agrega ninguna capacidad —el par es el mismo— pero sí
agrega una pregunta que el dueño tiene que hacerse cada vez: *"¿desde cuál estoy
vinculando?"*, con un resultado distinto según la respuesta. Concentrarlo hace que la regla
sea una sola frase: **el stock que vale es el de la maestra**, y ahí es donde hay que cargarlo.

**Pero la maestra no interviene en los movimientos.** Ventas, devoluciones y ajustes se
detectan y viajan desde donde ocurren: una venta en la secundaria descuenta en las dos, y su
número no vale menos por no ser la maestra. En la reconciliación tampoco tiene privilegio —
cada tienda se compara contra su propio libro (UC-06). **Manda en lo que se decide una vez
por producto, no en lo que pasa todos los días.**

**Qué se suspende mientras la maestra está caída.** Dos cosas, y ninguna urgente:

- **Vincular productos nuevos** (UC-05, UC-07). Se pueden ver, no tocar. Un producto que
  esperaba vinculación espera un rato más; los que ya están vinculados siguen sincronizando
  con total normalidad, porque los movimientos no pasan por ella.
- **La reconciliación periódica** (UC-06), que solo ella programa. Con dos tiendas eso no
  quita nada, porque **la reconciliación necesita a las dos de todos modos**: si una está
  caída no hay con quién comparar libros, la dispare quien la dispare.

Técnicamente la reconciliación no requiere autoridad: la fase 1 es un intercambio entre pares
y la fase 2 solo necesita que la otra tienda responda. Que salga de la maestra es coherencia
—un solo puesto de trabajo— y no un permiso que haga falta.

**Entrega en dos niveles: la cola es el camino rápido, el libro es la garantía.** Cada
movimiento se propaga por una cola con reintentos. Si la cola agota los reintentos —una
tienda apagada, por ejemplo— **abandona ese envío y lo registra en el log**, sin reintentar
indefinidamente. El movimiento no se pierde: sigue en el libro de la tienda de origen y la
reconciliación lo entrega cuando la otra vuelva. Insistir para siempre sería acumular
trabajo en el cron para hacer algo que la reconciliación resuelve igual.

**El ACK confirma el estado del receptor, no el efecto de esa entrega.** Un movimiento que
llega repetido **se descarta y se confirma igual**: la respuesta correcta a "aplicá `M-A-77`"
no es "lo apliqué recién", es **"lo tengo"**. Si el receptor descartara en silencio, el
emisor no recibiría confirmación, seguiría reintentando algo ya aplicado hasta agotar los
reintentos, escribiría un error falso en el log, y la reconciliación se lo volvería a ofrecer
cada noche para siempre — porque el movimiento nunca deja de figurar como pendiente. Todo eso
por un mensaje que en realidad llegó bien.

Esto vale en las dos vías, la cola y la reconciliación, y tiene dos condiciones:

- **Se confirma después de que el movimiento está durablemente guardado**, no antes. Con la
  deduplicación apoyada en el `UNIQUE` del identificador global, que un movimiento ya esté
  presente significa que ya está guardado: confirmarlo es decir la verdad.
- **Recibido y aplicado no son lo mismo, y lo que se confirma es recibido.** Un movimiento
  que llega para un producto suspendido queda retenido sin aplicarse (UC-03), y aun así se
  confirma: el emisor cumplió, y aplicarlo es problema del receptor. Si no se confirmara, un
  producto suspendido generaría reintentos eternos por cada venta.

**El bootstrap es la única escritura de valor absoluto de todo el plugin.** Ocurre una sola
vez por producto, al entrar a la malla. De ahí en adelante, solo deltas. Como cualquier otro
movimiento **lo emite una tienda de origen**, que en el caso del bootstrap es siempre **la
maestra**: es su stock el que queda como base y la secundaria se alinea (UC-07).

Consecuencias directas, que van a ADR antes de codearse:

- Cada tienda mantiene una **tabla propia de movimientos** (no basta con el `_stock` de
  WooCommerce, que solo guarda el resultado).
- Esa tabla necesita una **política de retención**: no puede crecer para siempre.
- El **stock base** de un producto es el valor fijado en su bootstrap; a partir de ahí, el
  stock esperado es `stock base + suma de los movimientos posteriores al bootstrap vigente`.
- **Cada vínculo tiene una generación, y los movimientos la llevan encima.** Cada bootstrap
  abre una **generación nueva** del par, y el receptor **solo aplica los movimientos de la
  generación vigente**. Sin eso hay un agujero concreto: si el dueño desvincula y vuelve a
  vincular, los deltas que quedaron en cola del vínculo anterior llegan después del bootstrap
  nuevo y **no son duplicados** —son movimientos legítimos, con su secuencia propia, de una
  época que ya no corre—, así que la deduplicación por identificador no los detiene. El
  receptor no tiene con qué distinguirlos, los aplica, y el stock queda mal contra un libro
  que no los cuenta. Con la generación, la decisión es trivial y local.
- **Un movimiento recibido puede no ser aplicable todavía.** Si llega para un producto cuyo
  mapeo apunta a algo que ya no existe, o que quedó suspendido (UC-03), no se aplica pero
  **tampoco se descarta**: tiene que quedar retenido y reintentable, porque descartarlo
  pierde stock en silencio. **Resuelto en ADR-001**: el libro lleva dos ejes de estado
  independientes —entrega (`pending`, `in_flight`, `confirmed`, `abandoned`) para los
  movimientos propios y aplicación (`applied`, `held`, `discarded`) para los recibidos—, y
  este caso es `held`.
- **No se puede purgar lo que la otra tienda todavía no recibió.** Es una condición aparte de
  la de abajo y más dura: si A borra movimientos que B nunca llegó a aplicar, **ya no puede
  reentregarlos** y la fase 1 de UC-06 pierde para siempre la capacidad de reparar ese hueco.
  Lo grave es que queda **invisible**: como cada tienda se compara contra su propio libro
  (UC-06), las dos van a dar por bueno su número y nadie va a reportar nada; el desvío entre
  A y B se vuelve permanente y silencioso. Alcanza con que cada tienda recuerde **hasta qué
  secuencia confirmó la otra** y no purgue más allá de esa marca. Con dos tiendas es un solo
  número por par; va al ADR del esquema de BD junto con la retención.
- **El libro tiene que poder auditar un bootstrap entero.** Hoy guarda el delta y una
  `reference` corta, y eso no alcanza: un bootstrap necesita dejar registrados **los dos IDs
  apareados, el stock previo de cada tienda y la generación que abre**. Es lo único que
  después permite reconstruir por qué el pool arrancó donde arrancó, y detectar el caso de
  haber vinculado con la maestra en 0 (UC-07).
- **Purgar movimientos obliga a consolidar el stock base.** Si a los 90 días los movimientos
  se borran sin más, la fórmula de arriba deja de reconstruir el total y la fase 2 de UC-06
  reportaría discrepancias falsas en todos los productos con historia. Al purgar, el stock
  base tiene que absorber la suma de lo purgado, de modo que el resultado no cambie. Es una
  restricción dura sobre el diseño de la retención, y va al ADR del esquema de BD.

### Prior art — de dónde viene este diseño

Ninguna de las piezas de arriba es nueva. Cada una tiene nombre propio en la literatura y
está probada fuera de WordPress; lo específico de este plugin es la combinación.

| Decisión de §3                                        | Nombre en inglés                                      | Origen                                                                    |
| ----------------------------------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------- |
| El stock se deriva de un libro de movimientos         | **Stock ledger** / _event sourcing_                   | Contabilidad de inventario; modelo estándar en ERPs (Odoo, NetSuite, SAP) |
| Movimientos con ID único que no se aplican dos veces  | **Idempotent consumer** sobre entrega _at-least-once_ | _Enterprise Integration Patterns_ (Hohpe & Woolf, 2003)                   |
| Reconciliación periódica que repara desvíos           | **Anti-entropy** / _read repair_                      | Amazon Dynamo (2007); Cassandra, Riak                                     |
| Deltas que convergen sin importar el orden de llegada | **PN-Counter**, un _CRDT_                             | Shapiro, Preguiça, Baquero & Zawirski (2011)                              |
| No perder el movimiento si falla el envío             | **Outbox pattern**                                    | Práctica estándar en arquitecturas de microservicios                      |

**Confirmación en el rubro:** la API de inventario de **Shopify** ajusta stock enviando un
delta (`available_adjustment`), no el valor final, y **Square** modela el inventario como
una secuencia de cambios en vez de un número. Ambos llegaron a la misma conclusión que §3
por el mismo motivo: un contador que suma y resta converge siempre, mientras que escribir
el valor absoluto pisa lo que haya pasado en el intervalo.

**Diferencia con los plugins de WooCommerce del rubro:** la mayoría de las soluciones de
multi-tienda usan un maestro que empuja el **valor absoluto** a las demás. Es más simple y
pierde ventas bajo concurrencia. La corrección bajo concurrencia es el diferencial de este
plugin, y conviene revisar el estado del arte concreto antes de la Fase 2.

---

## 4. Casos de uso

> Formato: `UC-NN` + una línea "como X quiero Y para Z" + criterios de aceptación
> **testeables**. Los IDs se referencian desde `tests/TEST-CASES.md`, desde los mensajes de
> commit y desde `STATUS.md`. Nunca se renumeran: si un caso muere, se marca `OBSOLETO`.

### UC-01 — Una venta descuenta stock en las dos tiendas

> Estado: Aprobada · 2026-09-13

Como dueño de dos tiendas quiero que una venta en cualquiera de ellas descuente el stock en
la otra, para publicar el stock real completo en las dos sin sobrevender.

El descuento se propaga **cuando WooCommerce ya redujo el stock local**, no antes. El
disparador es la reducción efectiva, no el estado del pedido: hay pedidos que la aplican al
pasar a _procesando_, otros al quedar _en espera_ y otros —productos virtuales, ciertas
pasarelas— que van directo a _completado_. Los tres casos se propagan igual, porque los tres
pasan por el mismo detector de UC-04.

**Criterios de aceptación:**

- [ ] Dado un producto vinculado con stock 10 en las tiendas A y B, cuando un pedido de 1
      unidad en A reduce el stock local a 9, entonces se **registra un movimiento `-1` en el
      libro** y se encola su entrega; una vez que la cola la procesa, el stock en B queda en
      9. El orden importa: el libro es la garantía, la cola es solo el camino rápido (§3).
- [ ] Dado ese mismo producto, cuando un pedido de 1 unidad en A pasa directo a _completado_
      sin pasar por _procesando_, entonces se propaga igual: un solo movimiento `-1`.
- [ ] Dado ese mismo producto, cuando se crea en A un pedido que queda _pendiente de pago_,
      entonces WooCommerce no reduce el stock local y el stock en B **no cambia**.
- [ ] Dado un producto con stock 10 en A y en B, cuando se vende un pedido de **3 unidades**
      en A, entonces B queda en 7. Lo que se exige es que **el total descontado sea exacto**,
      no que viaje un movimiento único: el detector de UC-04 emite un movimiento por cada
      reducción de stock que observa, y la suma es lo que tiene que dar.
- [ ] _(caso borde — mismo SKU en dos líneas)_ Dado un pedido en A que incluye el mismo
      producto en **dos líneas** de 2 unidades cada una, entonces B baja exactamente 4. Que
      WooCommerce reduzca línea por línea —y el detector emita dos movimientos de `-2`— es
      correcto y no se corrige: ninguna unidad se descuenta dos veces ni queda sin descontar.
- [ ] _(caso borde — reducción repetida)_ Dado un pedido en A que redujo stock, se canceló
      —reponiéndolo— y vuelve a _procesando_, entonces se propagan los tres movimientos
      (`-1`, `+1`, `-1`): la idempotencia es por movimiento, no por pedido.
- [ ] _(caso borde — estados que no tocan el stock)_ Dado un pedido en A ya reducido, cuando
      pasa a _en espera_ y vuelve a _procesando_, entonces **no se genera ningún movimiento**:
      WooCommerce no reduce dos veces —lo impide su marca `_order_stock_reduced`— y su
      reposición automática cuelga de _cancelado_ y _pendiente_, no de _en espera_. Como el
      detector observa el valor del stock y el valor no cambió, no hay nada que propagar.
      Invocar el hook de nuevo sobre el mismo pedido tampoco crea un movimiento.
- [ ] Dado un producto variable, cuando se vende la variación _Talle M_, entonces en B baja
      el stock de _Talle M_ y ninguna otra variación se modifica.
- [ ] _(caso borde — idempotencia)_ Dado que el movimiento `M-A-77` ya fue aplicado en B,
      cuando ese mismo movimiento vuelve a llegar, entonces el stock de B no vuelve a bajar.
- [ ] _(concurrencia — el caso que justifica los deltas)_ Dado un producto con stock 10 en A
      y en B, cuando **cada tienda vende 1 unidad antes de recibir el movimiento de la otra**,
      entonces al aplicarse ambos movimientos las dos terminan en **8**. Con valores absolutos
      las dos escribirían "quedan 9" y se perdería una unidad; este criterio fija la propiedad
      central de §3 y no puede quedar sin test.
- [ ] _(caso borde — entrega fuera de orden)_ Dado que B recibe primero `M-A-78` (`-2`) y
      después `M-A-77` (`-1`), entonces ambos se aplican una sola vez y el stock final es el
      mismo que si hubieran llegado en orden: la malla no garantiza orden de entrega y el
      diseño no lo necesita (§3).
- [ ] _(fallo — tienda inaccesible)_ Dado que B no responde en el momento de la venta en A,
      entonces la cola reintenta un número **finito** de veces; si los agota, abandona el
      envío y lo registra en el log. El movimiento sigue en el libro de A y lo entrega la
      reconciliación de UC-06 cuando B vuelve. Por cualquiera de los dos caminos, el stock de
      B queda en 9 y el descuento se aplica **una sola vez**. No hay reintento eterno (§3).
- [ ] _(caso borde — sin vincular)_ Dado un producto que existe en A pero no está vinculado
      con ninguno de B, cuando se vende en A, entonces no se propaga nada y **no** se escribe
      una entrada de log por cada venta: el producto ya figura como _sin vincular_ en la
      pantalla (UC-03) y un log por venta convertiría un catálogo con productos sueltos en
      ruido permanente.
- [ ] _(caso borde — reservas)_ Dado un producto que acepta reservas con stock 0 en ambas
      tiendas, cuando se venden 3 unidades en A, entonces el pool queda en **-3** en todas
      las tiendas, y cada una muestra el estado que corresponda según su propia
      configuración de reservas.
- [ ] _(caso borde — salir del negativo)_ Dado ese producto en -3, cuando se reponen 10
      unidades, entonces el pool queda en **7** en las dos tiendas: las reservas pendientes
      se descuentan una sola vez.

**Fuera de este caso:** las devoluciones y los reembolsos, la edición manual de stock desde
el admin, y la reconciliación periódica. Cada uno es su propio `UC`.

### UC-02 — Una devolución o cancelación repone stock en las dos tiendas

> Estado: Aprobada · 2026-09-13

Como dueño de dos tiendas quiero que la mercadería que vuelve al depósito reaparezca
disponible en las dos, para no dejar de vender unidades que sí tengo.

La regla es la misma que en UC-01: **se propaga cuando WooCommerce ya repuso el stock
local**. La causa contractual es la reposición, nunca el estado del pedido: WooCommerce no
repone en todo cambio a _cancelado_ o _reembolsado_, sino según la acción concreta que se
haya aplicado. Si el operador marcó _"Reponer artículos reembolsados"_, WooCommerce repone y
viaja el `+N`; si no la marcó, no repone y no viaja nada. El criterio sobre la mercadería que
vuelve rota queda donde corresponde: en la persona que recibe la devolución.

**Criterios de aceptación:**

- [ ] Dado un producto vinculado con stock 9 en A y en B, cuando en A se reembolsa 1
      unidad **con** _"Reponer artículos reembolsados"_ marcado, entonces se **registra un
      movimiento `+1` en el libro** y se encola su entrega; una vez que la cola la procesa, el
      stock en B queda en 10.
- [ ] Dado ese mismo reembolso **sin** la casilla marcada, entonces el stock en B **no
      cambia** (tampoco cambió en A).
- [ ] Dado un pedido de 3 unidades, cuando se reembolsan 2 con reposición, entonces en B
      el stock sube exactamente 2.
- [ ] _(caso borde — reembolsos parciales sucesivos)_ Dado un pedido de 3 unidades, cuando se
      reembolsa 1 con reposición y **más tarde** otra 1 con reposición, entonces viajan dos
      movimientos `+1` y el stock de B sube exactamente 2: ni 3 ni 4.
- [ ] _(caso borde — mezcla de reembolsos)_ Dado un pedido de 3 unidades, cuando se reembolsa
      1 **sin** reposición y después otra **con** reposición, entonces solo se propaga el
      segundo `+1`.
- [ ] _(caso borde — cancelar después de reponer)_ Dado un pedido de 3 unidades del que ya se
      reembolsó 1 **con** reposición, cuando el pedido se **cancela**, entonces se reponen
      solo las 2 restantes, nunca las 3. WooCommerce descuenta lo ya repuesto de la marca
      `_reduced_stock` del ítem, y como el detector observa el valor del stock, el plugin
      hereda ese comportamiento. Es el borde que protege contra **inventar stock**.
- [ ] Dado un pedido en A cuya reposición WooCommerce aplica al cancelarlo, entonces esa
      reposición se propaga a B como cualquier otra: **lo que se observa es la reposición, no
      el estado que la causó**.
- [ ] _(caso borde — variación)_ Dado un reembolso con reposición de la variación _Talle M_,
      entonces en B sube el stock de _Talle M_ y no se modifica ninguna otra variación **ni el
      producto padre**.
- [ ] _(caso borde — idempotencia)_ Dado un movimiento de reposición ya aplicado en B,
      cuando vuelve a llegar, entonces el stock de B no vuelve a subir.
- [ ] _(caso borde — orden con signos mezclados)_ Dado que en A se vende 1 unidad y después se
      repone 1, cuando B recibe primero el `+1` y después el `-1`, entonces termina con el
      mismo stock que A. UC-01 ya prueba el desorden entre dos descuentos; este criterio prueba
      la conmutatividad del libro con signos distintos, que es el caso que de verdad la exige.
- [ ] _(límite con UC-04)_ Dado que el operador gestionó el reembolso y **después** edita el
      stock a mano en el admin, entonces ese cambio viaja como un movimiento aparte con motivo
      **ajuste**, no como otra devolución: el libro conserva la causa real de cada delta, que
      es lo que después lee el historial de UC-05.
- [ ] _(fallo — tienda inaccesible)_ Dado que B no responde cuando se procesa el reembolso en
      A, entonces la cola reintenta un número **finito** de veces; si los agota, abandona el
      envío y lo registra en el log. El movimiento sigue en el libro de A y lo entrega la
      reconciliación de UC-06 cuando B vuelve. Por cualquiera de los dos caminos, el stock de
      B queda en 10 y la reposición se aplica **una sola vez**. No hay reintento eterno (§3).

**Fuera de este caso:** decidir si la mercadería devuelta está en condiciones de volver a
venderse, la reposición por ingreso de compra al depósito, y **toda edición manual de stock
posterior al reembolso** — aunque el operador la haga "para completar la devolución", es un
ajuste de UC-04 y así se registra.

### UC-03 — Vinculación de productos

> Estado: Aprobada · 2026-09-13

Como dueño de dos tiendas quiero decidir yo qué producto es el mismo en cada una, con
el plugin sugiriéndome los pares obvios, para que la sincronización arranque sobre una lista
que revisé y no sobre una coincidencia que nunca vi.

**El SKU sugiere, el dueño vincula, y el par es la identidad.** El plugin busca en la otra
tienda el mismo SKU y lo propone en la pantalla de UC-05. Nada sincroniza hasta que el dueño
acepta la sugerencia; ese acto de aceptar **es** el bootstrap (UC-07). De ahí en adelante el
producto queda identificado por el par, no por su SKU: renombrarlo, vaciarlo o dárselo a otro
producto no cambia absolutamente nada.

**Por qué no se vincula solo.** El SKU es un campo editable del catálogo del cliente, y entre
que el plugin propone un par y alguien lo fija hay una ventana en la que el dueño puede estar
corrigiendo justamente ese SKU. Como el bootstrap es la única escritura de valor absoluto del
plugin (§3), un par fijado contra un catálogo viejo no es un error cosmético: **pisa el stock
del producto equivocado, y el par no se vuelve a revisar nunca.** Con una persona
confirmando, la ventana no existe: lo que ve en pantalla es el catálogo de ese instante.

**El arranque no es de a uno: la vinculación masiva tiene umbral.** Instalar el plugin sobre
dos catálogos de miles de productos y pedir que se apareen a mano sería pedir que no se
instale. La pantalla ofrece vincular en bloque, pero **solo las coincidencias inequívocas**:
un único producto con ese SKU de cada lado, los dos publicados. Todo lo demás —SKU duplicado,
producto en papelera, sin coincidencia— queda en una lista aparte para resolver de a uno.
Antes de confirmar, el dueño ve cuántos pares se van a fijar y con qué stock arranca cada
uno: el de la maestra (UC-07).

Es automatización igual, pero con dos diferencias que son todo: la lanza el dueño **una vez,
a sabiendas**, y corre contra el catálogo de ese momento. Los casos donde el SKU miente son
exactamente los que quedan frenados.

**El par se guarda; el estado, no.** Son dos cosas distintas y conviene no confundirlas:

- **El par es un dato.** Vive junto al stock base, se escribe al vincular y se borra al
  desvincular. Es lo que permite que un SKU editado no rompa nada.
- **El estado de sincronización es una vista.** _Vinculado_, _suspendido_, _sin vincular_ y
  el motivo de cada uno se calculan al mostrarlos, mirando el catálogo y el libro. No se
  persisten.

Con una sola excepción, y conviene tenerla anotada: **el descarte sí se guarda** —pero no
como un estado. "Este producto no me interesa" no se puede derivar de ningún lado, así que
queda **registrado como una acción en el log del producto**, con fecha y usuario, junto con
el registro de qué sugerencias ya se avisaron por email (UC-08). El estado que muestra la
pantalla sigue siendo _sin vincular_, que es lo que efectivamente pasa; **el log dice por
qué**.

Que sea una acción y no un estado no es un detalle de vocabulario: **un descarte no es un
movimiento de stock** —no tiene delta— así que no puede vivir en el libro. Es el mismo
registro de acciones de vinculación que necesitan vincular, desvincular y reasignar, **y los
intentos que fallan**, y va al ADR que ya está pendiente por el registro de avisos de UC-08.

Ese registro no es un lujo de auditoría: es **lo único que distingue un olvido de una falla**.
Un producto sin vincular y con el log vacío es un producto que nadie vinculó; el mismo
producto con un intento fallido anotado es un problema que hay que mirar. Sin registro, los
dos se ven igual (UC-05).

Y como es una decisión ya tomada y no un pendiente, **la pantalla oculta esas filas salvo
que el dueño active verlas** (UC-05): la lista de sin vincular es lo que falta resolver, no
un archivo de lo resuelto.

**El descarte es sobre el producto local, no sobre una pareja de SKU.** Si el dueño descartó
la sugerencia de `ZAP-001` en la tienda A, no vuelve a proponérsela aunque en B cambie el SKU
y aparezca otra coincidencia: lo contrario haría que cualquier edición del catálogo reviviera
trabajo ya resuelto. Y descartar **silencia sugerencias, no prohíbe vincular**: si después
quiere vincularlo a mano, puede, sin deshacer el descarte primero.

**Un error de SKU ya no corrompe stock: a lo sumo no genera una sugerencia.** Un SKU mal
escrito, duplicado o ausente deja al producto sin proponer —visible en la pantalla, y
resoluble vinculándolo a mano— en vez de romper un par que ya venía funcionando.

#### Ciclo de vida de un producto

Son tres estados, y la línea que los parte es **si existe par fijado**:

```
                        │
     sin vincular  ─────┼─────→  vinculado  ←──→  suspendido
          ↑             │            │         (el par se rompió)
          │             │            │
          └─────────────┼────────────┘
            desvincular │  (UC-05)
                        │
                 sin par │ con par
```

- **Sin vincular** — nadie fijó su par. Da lo mismo si no tiene SKU, si su SKU no coincide
  con nada, o si coincide con dos productos a la vez: en los tres casos el producto no
  sincroniza y espera una decisión del dueño. La pantalla **sí** distingue el motivo, porque
  cada uno se resuelve distinto, pero el estado es uno solo.
- **Vinculado** — tiene par y stock base, y aplica deltas.
- **Suspendido** — tenía par y el par se rompió: el producto del otro lado fue borrado, o la
  papelera se vació. Se suspenden sus movimientos, que quedan retenidos; **el par, el stock
  base y el historial se conservan**.

**Sin vincular no es sinónimo de error.** Un producto exclusivo de una tienda, un servicio o
una prueba se queda ahí para siempre y está bien. Por eso la lista de sin vincular es una
bandeja de trabajo ordenada por motivo, y no una lista de problemas: el dueño puede
**descartar** la sugerencia de un producto que no quiere sincronizar, y deja de
proponérsele. El producto no cambia de estado —sigue _sin vincular_—; lo que cambia es que
deja de pedir atención.

**Cambiar un SKU no tiene ningún efecto.** Es la promesa del plugin al dueño y conviene que
esté dicha así de seco: una vez vinculado un producto, el SKU es un dato informativo que se
muestra y por el que se busca. Renombrarlo, vaciarlo, duplicarlo o intercambiarlo con otro
producto no vincula, no desvincula y no mueve stock. **Todo cambio sobre los pares se hace
desde la pantalla de la maestra**: vincular, desvincular, reasignar (UC-05).

**Desvincular detiene la sincronización sin borrar historia.** El par se corta, el producto
deja de emitir y recibir movimientos y su stock deja de tocarse en las dos tiendas. El
historial y el stock base quedan registrados. Volver a vincularlo —al mismo producto o a
otro— es un **bootstrap nuevo**, y se resuelve con la misma regla de siempre: manda el stock
de la maestra (UC-07), con las consecuencias sobre el cálculo de UC-06.

**Y revincular pesa más que vincular por primera vez.** Mientras estuvo desvinculado, cada
tienda siguió vendiendo por su cuenta y los dos números se separaron. Al revincular, el de la
maestra **pisa el de la secundaria**, y lo que la secundaria vendió en el medio no se
recupera: eso ya no es historia del par. Por eso conviene mirar los dos valores que la
pantalla muestra antes de confirmar, y dejar el de la maestra en el número real **antes** de
apretar.

**Cada vinculación abre una generación nueva del par.** Es la consecuencia técnica de poder
desvincular y volver a vincular: los movimientos del vínculo anterior que hayan quedado en
vuelo llegan cuando el par ya es otro, y el receptor tiene que poder descartarlos. No son
duplicados —son de otra época— así que la deduplicación no alcanza y hace falta que cada
movimiento diga a qué generación pertenece (§3).

**Desvincular no es la herramienta para corregir un stock.** Si el par está bien y el número
está mal, se edita el stock en WooCommerce y el ajuste se propaga (UC-04): eso alcanza y es
lo que hay que hacer. Desvincular y volver a vincular para "reiniciar" el número solo agrega
un bootstrap al historial y corta el cálculo de UC-06 sin necesidad. **Se desvincula cuando
el par está mal, no cuando el stock está mal.**

**Volver de suspendido a vinculado no re-bootstrapea, y tampoco se pide.** El estado es
derivado: cuando el producto del otro lado vuelve a existir —se restaura de la papelera, la
tienda responde otra vez—, la fila vuelve a _vinculado_ sola, con el mismo par y el mismo
stock base, y los movimientos retenidos se aplican. No hay botón de "reanudar" porque
tenerlo obligaría a **persistir la suspensión** para poder levantarla, y es justamente el
estado que no queremos guardar.

Lo que sí necesita el dueño de una fila suspendida es **ver la causa** —el producto del otro
lado está borrado, o en la papelera— y poder **desvincular**, que es la salida cuando el par
no va a volver. La única forma de que un producto reciba un stock base nuevo sigue siendo que
**el dueño rehaga el par desde la pantalla**.

**Que la otra tienda no responda no suspende nada.** Es la distinción que evita el peor
malentendido de la pantalla: una caída es temporal y no tiene nada que ver con el producto,
así que los vinculados **siguen vinculados**, sus movimientos se acumulan en el libro y la
cola los entrega cuando la tienda vuelva. Marcarlos como suspendidos diría que el par se
rompió, mandaría al dueño a revisar producto por producto, y al volver la tienda habría que
"des-suspender" cientos de filas que nunca tuvieron nada malo. La caída se informa **una vez
y como lo que es** (UC-05, UC-08).

#### Cómo se comparan los SKU

**Se recortan los espacios al inicio y al final antes de comparar, y nunca se modifica el
valor guardado en WooCommerce.** El plugin no normaliza el catálogo del dueño: si un SKU
tiene un espacio de más, lo ignora para sugerir y lo muestra tal cual es.

Sobre la sensibilidad a mayúsculas: con la colación habitual de WordPress
(`utf8mb4_unicode_ci`) la consulta por `_sku` **ya es insensible en la base de datos**, así
que `ZAP-001` y `zap-001` se proponen solas. Queda en §6, pero dejó de ser crítico: lo que
está en juego es qué pares se **sugieren**, no la identidad de los que ya existen. Una
sugerencia de más se descarta de un clic.

**Criterios de aceptación:**

- [ ] Dado un producto con SKU `ZAP-001` en las tiendas A y B, cuando el dueño abre la
      pantalla, entonces aparece como **sugerencia de vinculación** — y **no sincroniza**
      hasta que la acepta.
- [ ] Dado que el dueño acepta esa sugerencia, entonces el par queda fijado, se emite el
      bootstrap de UC-07 y desde ese momento el producto aplica deltas.
- [ ] Dado un producto variable, cuando cada variación tiene su propio SKU presente en ambas
      tiendas, entonces cada variación se sugiere y se vincula por separado.
- [ ] Dado un producto **sin SKU**, entonces no genera sugerencia y figura como _sin
      vincular_ con el motivo **"sin SKU"**; el dueño **puede vincularlo igual** eligiendo a
      mano el producto del otro lado. No tener SKU no es un callejón sin salida.
- [ ] _(caso borde — SKU heredado del padre)_ Dado un producto variable con SKU `ZAP-001` y
      una variación **sin SKU propio**, entonces esa variación no se sugiere, aunque
      WooCommerce la reporte con el SKU del padre: `WC_Product_Variation::get_sku()` devuelve
      el del padre cuando la variación no tiene el suyo, y tomarlo como válido haría que una
      venta de la variación descontara el **producto padre**. Solo se sugieren variaciones
      con SKU propio; **el padre de un producto variable nunca se descuenta**.
- [ ] Dado un producto con SKU en A que no existe en B, entonces queda _sin vincular_ con el
      motivo **"sin coincidencia"** y sin sugerencia, y el dueño puede vincularlo a mano.
- [ ] _(caso borde — ambigüedad)_ Dado que dos productos de la **misma** tienda comparten el
      SKU `ZAP-001`, entonces **no se sugiere ninguno** y ambos figuran con el motivo **"SKU
      duplicado"**. El plugin nunca elige uno por su cuenta; el dueño resuelve cuál vincular.
- [ ] _(caso borde — producto y variación en colisión)_ Dado un producto simple y una
      variación de la **misma** tienda que comparten el SKU `ZAP-001`, entonces ambos quedan
      como **"SKU duplicado"**: el plugin no privilegia al producto sobre la variación ni al
      revés.
- [ ] _(caso borde — papelera y borradores)_ Dado un SKU que en B corresponde a un producto
      en la **papelera**, o en borrador o privado, entonces **no se sugiere**, con el motivo
      concreto (_"producto en papelera en B"_). Es un caso a verificar explícitamente porque
      `wc_get_product_id_by_sku()` sí devuelve productos en papelera.
- [ ] _(caso borde — espacios)_ Dado el SKU `ZAP-001 ` con un espacio final en A y `ZAP-001`
      en B, entonces se sugiere el par, y el valor guardado en A **no se modifica**.
- [ ] _(masiva — umbral)_ Dado un catálogo de 5.000 productos en el que 4.800 tienen SKU
      único y publicado de los dos lados y 200 son ambiguos, cuando el dueño lanza la
      vinculación masiva, entonces se vinculan **los 4.800** en una sola confirmación y los
      200 quedan listados para resolver de a uno. Ningún ambiguo entra en el bloque.
- [ ] _(masiva — previsualización)_ Dado que el dueño va a lanzar la vinculación masiva,
      cuando la pantalla se la muestra, entonces ve **cuántos pares** se van a fijar y **con
      qué stock** arranca cada uno, antes de confirmar.
- [ ] _(masiva — idempotente)_ Dado un catálogo ya vinculado, cuando el dueño vuelve a lanzar
      la vinculación masiva, entonces **no se re-bootstrapea nada** y ningún stock se toca:
      solo se ofrecen los pares nuevos.
- [ ] _(descartar)_ Dado un producto que el dueño no quiere sincronizar, cuando descarta su
      sugerencia, entonces **no vuelve a proponerse** ni entra en la vinculación masiva, el
      estado **sigue siendo _sin vincular_** y la acción queda en el log del producto. Es
      reversible. El descarte pesa sobre el **producto local**: un cambio de SKU del otro lado
      no lo revive (UC-05).
- [ ] _(renombrar no desincroniza)_ Dado un producto **ya vinculado**, cuando en A se cambia
      su SKU de `ZAP-001` a `ZAP-999` y en B sigue siendo `ZAP-001`, entonces **no pasa
      nada**: el par no se toca, el stock base y el historial se conservan, y los movimientos
      se siguen propagando en las dos direcciones. La pantalla muestra los dos SKU tal como
      están, porque es información que el dueño quiere ver.
- [ ] _(vaciar el SKU tampoco)_ Dado ese mismo producto vinculado, cuando se le borra el SKU
      por completo, entonces sigue sincronizando: la identidad es el par, no el SKU.
- [ ] _(caso borde — SKU reasignado a otro producto)_ Dado que el SKU `ZAP-001` quedó libre
      en A tras renombrarse, cuando se crea en A un producto **nuevo** con `ZAP-001`,
      entonces ese producto nuevo **no hereda nada**: queda _sin vincular_ —a lo sumo con una
      sugerencia que el dueño puede aceptar o descartar— y el par original sigue intacto con
      su stock base y su historial.
- [ ] _(caso borde — intercambio de SKU)_ Dado dos productos **ya vinculados** en A, cuando
      el dueño intercambia sus SKU entre sí, entonces **los dos pares quedan intactos** y
      nada se cruza: el stock de cada uno sigue su propio par. La pantalla muestra los SKU
      como están, aunque a simple vista parezcan cruzados.
- [ ] _(caso borde — duplicado sobrevenido)_ Dado un producto **ya vinculado** en B, cuando
      aparece en B otro producto con su mismo SKU, entonces **la sincronización no se
      interrumpe**: el par ya está fijado y el duplicado no lo pone en duda. El que queda sin
      poder vincularse es el producto nuevo, con el motivo **"SKU duplicado"**.
- [ ] _(caso borde — mapeo roto con movimiento en vuelo)_ Dado un movimiento que llega para
      un producto cuyo par apunta a algo que ya no existe —borrado, o la papelera vaciada—,
      entonces **no se descarta**: queda registrado como _no aplicable_, el producto figura
      como **suspendido**, y la reconciliación de UC-06 lo reintenta si el par se restablece.
      Descartarlo perdería stock de forma silenciosa.
- [ ] _(caso borde — no se re-vincula solo)_ Dado ese mismo producto suspendido, cuando en la
      otra tienda aparece un producto **nuevo** con el mismo SKU que tenía el borrado,
      entonces el plugin **no lo adopta como par**: lo ofrece como sugerencia y la confirma
      el dueño. Adoptarlo solo reintroduciría el agujero que ADR-004 vino a cerrar.
- [ ] _(desvincular)_ Dado un producto vinculado, cuando el dueño lo desvincula desde la
      pantalla, entonces deja de emitir y de recibir movimientos, su stock **no se toca** en
      ninguna de las dos tiendas, y el historial y el stock base quedan registrados.
- [ ] _(revincular)_ Dado un producto desvinculado, cuando el dueño lo vincula de nuevo,
      entonces se emite un **bootstrap nuevo** con el stock de la maestra, que reemplaza al
      stock base anterior, y la fase 2 de UC-06 pasa a sumar solo los movimientos posteriores
      a ese bootstrap.
- [ ] _(revincular — deltas viejos en vuelo)_ Dado un producto que se desvinculó y se volvió
      a vincular, cuando llega un movimiento **del vínculo anterior** que había quedado en
      cola, entonces **no se aplica**: pertenece a una generación cerrada. No alcanza con
      deduplicar por identificador —ese movimiento nunca se aplicó y no es un repetido— y
      aplicarlo dejaría el stock mal contra un libro que no lo cuenta (§3).
- [ ] _(revincular — divergencia)_ Dado un producto que estuvo desvinculado y quedó en 20 en
      la maestra y 14 en la secundaria porque cada una vendió por su lado, cuando el dueño lo
      revincula, entonces las dos quedan en **20**: el bootstrap escribe un valor absoluto y
      lo que la secundaria vendió en el medio no se recupera. La pantalla muestra los dos
      números antes de confirmar (UC-05).
- [ ] Dado el listado de la pantalla de estado, cuando el dueño lo consulta, entonces puede
      distinguir los productos vinculados de los que no lo están, y ver el motivo de cada uno.

**Fuera de este caso:** crear en la otra tienda el producto que falta, que quedó fuera de v1
(§2). El plugin vincula productos que ya existen; no los da de alta.

### UC-04 — Un cambio manual de stock se propaga como ajuste

> Estado: Aprobada · 2026-09-13

Como dueño quiero que cualquier cambio de stock que haga fuera de una venta —una recepción
de mercadería, una corrección, una importación— llegue a las dos tiendas, para que el pool
refleje siempre lo que hay en el depósito.

**Se propaga la diferencia, no el valor.** Si el producto pasa de 10 a 60, viaja un
movimiento `+50`, igual que una venta o una devolución. Hay un solo tipo de movimiento en
todo el plugin. La consecuencia buscada es que un ajuste manual **nunca pisa una venta
simultánea**: si mientras tanto se vendió 1 en otra tienda, el resultado es 59, que es lo
correcto.

**Se detecta el cambio venga de donde venga**, siempre que pase por el CRUD de productos de
WooCommerce: el admin, la API REST, WP-CLI y el importador CSV nativo. La detección compara
el valor anterior contra el nuevo al guardarse el producto.

**Sin cantidad local que cambiar, no hay movimiento.** Un producto que no tiene la gestión
de stock activada en WooCommerce no participa: no hay valor anterior ni posterior que
comparar, así que ni una venta ni un reembolso ni una edición generan movimiento. La regla
vale para todo el plugin, no solo para los ajustes, y evita escribir en el libro deltas que
no tienen reflejo local. (Que **una** tienda gestione stock de un producto vinculado y la
otra no es un problema distinto, todavía sin resolver: §6.)

**Hay un único punto de detección para todo el plugin.** Las ventas (UC-01) y las
devoluciones (UC-02) también cambian el stock a través de WooCommerce, así que pasan por
este mismo detector: **un cambio de stock genera exactamente un movimiento**, nunca dos. Lo
que varía es el **motivo**, que queda guardado en el libro para el historial de UC-05. Sin
esta regla, una venta correría el riesgo de registrarse dos veces: una por el evento del
pedido y otra por la comparación de valores.

**El motivo se declara, no se adivina.** Quien provoca el cambio le pasa al detector el
motivo y la referencia —UC-01 declara `venta` con el número de pedido, UC-02 `devolución` con
el del reembolso—, y **el detector usa `ajuste` por defecto** cuando nadie declaró nada.
Deducirlo del contexto no funciona: si el stock se reduce en un hook diferido el pedido ya no
está a mano, y una corrección administrativa hecha mientras hay un pedido abierto no es una
venta. Un motivo mal puesto no rompe el stock —el delta es el mismo— pero sí el historial,
que es lo único que después explica de dónde salió cada número.

**Importaciones masivas.** Ante una ráfaga (muchos cambios en poco tiempo), el plugin entra
en **modo importación**: registra los movimientos en su libro local igual que siempre, pero
deja de enviarlos de a uno y los **agrupa en lotes que despacha la cola**. Al terminar la
ráfaga dispara además una reconciliación de cierre, que **no entrega nada: verifica** que el
lote haya llegado completo (UC-06).

**Quién entrega sigue siendo la cola, también acá.** El modo importación cambia el tamaño del
envío, no quién lo hace: la reconciliación es la red de seguridad del plugin y no una vía de
transporte alternativa que se enciende cuando hay volumen. Si el lote llegó bien —el caso
normal—, el cierre no tiene nada que reparar.

**Y cambia el transporte, nunca el contenido.** Se siguen generando y propagando **deltas**,
agrupados en lotes; en ningún caso se envía el stock total. Si algo del lote se perdiera, la
reconciliación entrega los movimientos que a la otra tienda le faltan, no una foto del
inventario.

Ejemplo de por qué: el producto X está en 10 en A y en B; una importación en A lo lleva a
50 (movimiento `+40`) y, mientras corre, en B se vende 1 unidad (B queda en 9). Con delta,
B aplica `+40` sobre 9 y termina en **49**: la venta se conservó. Enviando el total, B
quedaría en 50 y esa venta se habría perdido — la sobreventa que el plugin viene a evitar,
justo en el momento de mayor cantidad de escrituras.

**Criterios de aceptación:**

- [ ] Dado un producto vinculado con stock 10 en A y B, cuando en el admin de A se cambia
      el stock a 60, entonces se encola un movimiento `+50` y, una vez que la cola lo
      procesa, B queda en 60.
- [ ] Dado ese mismo cambio de 10 a 60 en A, cuando simultáneamente se vende 1 unidad en B,
      entonces ambas tiendas terminan en 59 y **ninguna de las dos operaciones se pierde**.
- [ ] Dado un cambio de stock hecho por la **API REST** de WooCommerce, entonces se propaga
      igual que si se hubiera hecho desde el admin.
- [ ] Dado un cambio hecho con **WP-CLI**, entonces se propaga igual.
- [ ] _(volumen)_ Dada una importación CSV de 1.000 productos en A, entonces el plugin entra
      en modo importación y **no** emite una petición por producto: los movimientos viajan
      agrupados en lotes.
- [ ] _(volumen — contenido)_ Dada esa misma importación, cuando se inspecciona lo que se
      envía a B, entonces son **deltas** por producto y en ningún caso el stock total.
- [ ] _(volumen — venta concurrente)_ Dado el producto X en 10 en ambas tiendas, cuando una
      importación en A lo lleva a 50 y durante la importación se vende 1 unidad en B,
      entonces ambas tiendas terminan en **49** y la venta no se pierde.
- [ ] _(fallo — invisible)_ Dado un cambio de stock escrito directamente en la base de datos
      sin pasar por WooCommerce, entonces el plugin **no lo detecta en el momento** y no
      propaga nada; la siguiente reconciliación periódica **detecta y reporta** el desvío,
      sin corregirlo por su cuenta (UC-06).
- [ ] _(caso borde — sin cambio real)_ Dado que se guarda un producto sin modificar su
      stock, entonces no se genera ningún movimiento ni se propaga nada.

**Fuera de este caso:** la pantalla de estado (UC-05) y el mecanismo de reconciliación en
sí (UC-06). El stock se edita en el admin de WooCommerce: el plugin no ofrece ninguna
interfaz propia para cambiarlo.

**Riesgo conocido, sin defensa automática:** importar **el mismo CSV en dos tiendas** genera
dos ajustes independientes que se suman (de 10 a 50 en cada una deja 90, no 50). El plugin
puede detectar importaciones masivas simultáneas y avisar, pero no puede impedirlo ni
deducir que era el mismo archivo. La regla de uso correspondiente está en §5.

### UC-05 — Pantalla de vinculación y estado

> Estado: Aprobada · 2026-09-13

Como dueño quiero **un** lugar donde vincular productos y ver cuáles se están sincronizando y
por qué los demás no, para no tener que acordarme de en qué tienda estaba parado cuando hice
cada cosa.

**Se administra solo desde la maestra.** Vincular, desvincular, reasignar y descartar existen
únicamente en su pantalla (§3). La secundaria tiene la misma pantalla **en modo lectura**: el
dueño ve todo el estado, y cuando intenta tocar algo la pantalla le dice, con todas las
letras, que la vinculación se hace desde la otra tienda —nombrándola, no "la maestra"— y le
da el enlace directo.

Concentrarlo no agrega ninguna capacidad: el par que se fija es el mismo desde cualquier
lado. Lo que saca es la pregunta *"¿desde cuál estoy vinculando?"*, que tenía una respuesta
distinta cada vez y cambiaba con qué stock arrancaba el producto. Con un solo puesto de
trabajo, **el stock que vale es siempre el de la maestra** y el dueño sabe de antemano dónde
cargarlo.

El plugin no cambia un par por su cuenta ni reacciona a lo que pase con los SKU (UC-03). Y lo
que **no** se administra desde esta pantalla, en ninguna de las dos tiendas, es el stock: se
edita en el admin de WooCommerce como siempre, y el plugin propaga el cambio (UC-04).

La organización sigue el modelo de la pantalla de _Productos_ de `woonderful-sync`: tabla
paginada, buscador, filtros por estado y una acción de vinculación por fila.

**Cada fila se ancla en el catálogo de la maestra, y el otro lado se alcanza desde ahí.** La
fila muestra el par o la sugerencia si los hay, y para vincular a mano el dueño **busca el
producto de la secundaria** sin salir de la fila. Es lo mismo que ya hacía antes de
centralizar: la pantalla siempre tuvo que llegar al catálogo remoto, porque vincular sin SKU
coincidente no se puede hacer de otra forma.

**Un producto que existe solo en la secundaria no aparece en este listado, y está bien.** No
tiene con qué aparearse —vincular necesita un producto de cada lado— así que no hay ninguna
acción que ofrecerle: es el _producto exclusivo_ de UC-07, y su lugar es el listado de solo
lectura de la secundaria, donde figura como _sin vincular · sin coincidencia_. Si el dueño
quiere que sincronice, el paso que falta es **crearlo en la maestra**, y ahí sí aparece con
su sugerencia.

**Cada fila muestra los dos lados.** Un producto vinculado se ve con su SKU y su stock en
cada tienda, y **los SKU pueden no coincidir**: eso es normal y no es una inconsistencia. La
pantalla los muestra tal como están porque es información que el dueño quiere ver, no un
problema que tenga que corregir.

**Si la otra tienda no responde, la pantalla lo dice arriba de todo.** Mientras dura la caída
los stocks de los dos lados se separan —lo que se vende de un lado no llega al otro— y **el
listado entero se ve como si estuviera roto**, producto por producto. No lo está: es una
caída, es temporal, y la cola y la reconciliación lo reparan solas cuando vuelva. Sin ese
cartel, el dueño va a leer decenas de filas descuadradas como decenas de problemas distintos
y va a salir a corregir un catálogo que está perfecto (UC-08).

**Los productos variables se agrupan, pero se vinculan por variación.** El padre es una fila
que se despliega y no tiene acciones propias: **la unidad vinculable es la variación**, con
su SKU propio y su stock propio (UC-03). Vincular dos padres variables entre sí no se
permite; sería un par que no tiene dónde aplicar un delta.

**Lo descartado se oculta, no desaparece.** Los motivos por los que un producto no tiene
sugerencia —sin SKU, sin coincidencia, SKU duplicado, producto en papelera— son **hechos del
catálogo**: se miran seguido y se arreglan en WooCommerce. Que el dueño haya descartado una
sugerencia es otra cosa, una decisión ya tomada, y no tiene por qué ocupar lugar en la
bandeja de trabajo. Por eso las filas descartadas **están ocultas salvo que se active verlas**,
y recién entonces el filtro por motivo ofrece esa opción. La búsqueda, en cambio, las
encuentra siempre.

> **Pendiente de definir:** dónde vive ese interruptor en la UI —ajustes de la pantalla, un
> control en la barra de filtros, o la pantalla de ajustes del plugin—. Lo que ya está
> decidido es el comportamiento: apagado por defecto, y con él apagado no hay ni filas ni
> motivo.

**Todo lo que pasa con un par queda registrado, aunque no mueva stock.** Vincular,
desvincular, reasignar, descartar y deshacer un descarte **no son movimientos** —no tienen
delta, no van en el libro—, pero sí van al log del producto con fecha y usuario. Y **los
intentos que fallan también**, con su error.

La razón es concreta: sin ese registro, un producto que no sincroniza tiene dos explicaciones
posibles y el dueño no puede distinguirlas. **Log vacío** quiere decir que nunca se vinculó —
un olvido, y se arregla vinculándolo. **Un intento fallido registrado** quiere decir que sí se
intentó y algo anda mal, y eso se arregla de otra manera. Sin log, las dos se ven igual: una
fila que no sincroniza, sin nada que explique por qué.

**Reasignar no es un vínculo de otro tipo: es cortar y volver a vincular.** La pantalla lo
ofrece como una sola acción por comodidad, pero por dentro son los dos pasos, **en ese
orden**: se corta el par vigente —que libera también al producto de la otra tienda— y recién
entonces se fija el nuevo, con su bootstrap. Si el segundo paso falla, el producto queda
_sin vincular_: un estado válido, visible y reintentable. Lo que nunca puede pasar es que un
producto termine con dos pares, y eso no depende de la pantalla sino del `UNIQUE` sobre el
par (ADR-004).

**Criterios de aceptación:**

- [ ] Dado el listado en la **maestra**, cuando el dueño lo abre, entonces cada fila muestra
      el nombre, el SKU y el stock de **cada lado**, el estado (_vinculado_, _sin vincular_,
      _suspendido_) y, si no está vinculado, el motivo (_sin SKU_, _sin coincidencia_, _SKU
      duplicado_, _producto en papelera_). Los cuatro son **hechos del catálogo**: se
      recalculan solos cuando el dueño lo corrige.
- [ ] _(tienda caída — el listado se explica)_ Dado que la otra tienda no responde, cuando el
      dueño abre la pantalla, entonces ve **un aviso destacado** de que está caída y desde
      cuándo, y los stocks descuadrados quedan explicados por ahí en vez de parecer errores
      de cada producto (UC-08).
- [ ] _(alcanza el catálogo remoto)_ Dado un producto de la maestra sin sugerencia, cuando el
      dueño **busca el producto de la secundaria** desde su fila, entonces lo encuentra y
      puede vincularlo. La pantalla llega al catálogo de la otra tienda, no solo al propio.
- [ ] _(producto exclusivo de la secundaria)_ Dado un producto que existe **solo en la
      secundaria**, entonces **no** figura en el listado de la maestra —no hay con qué
      aparearlo— y sí en el de solo lectura de la secundaria, como _sin vincular · sin
      coincidencia_ (UC-07).
- [ ] _(la secundaria es de solo lectura)_ Dado el listado en la **secundaria**, cuando el
      dueño lo abre, entonces ve el mismo estado y el mismo detalle, **sin ninguna acción
      disponible**, y un mensaje que dice que la vinculación se hace desde la otra tienda,
      nombrándola y con el enlace a su pantalla.
- [ ] _(la secundaria no escribe, aunque se la llame directo)_ Dada una petición de vincular,
      desvincular, reasignar o descartar que llega a la **secundaria**, entonces se rechaza y
      nada se modifica. La restricción no depende de que la pantalla oculte los botones: se
      verifica al procesar cada acción, igual que los permisos.
- [ ] Dado un producto **vinculado cuyos SKU ya no coinciden** entre las dos tiendas —o que
      se quedó sin SKU—, entonces figura como **vinculado** y no como problema: se muestran
      los dos valores y nada más.
- [ ] Dado el listado, cuando el dueño filtra por estado (_todos_, _vinculados_,
      _sugerencias_, **_sin vincular_**, _suspendidos_), entonces solo se muestran las filas
      que coinciden. El de _sin vincular_ es el que más se usa: es la lista de lo que todavía
      no sincroniza y hay que resolver.
- [ ] _(filtro por motivo)_ Dado el filtro de _sin vincular_, cuando el dueño afina por motivo
      (_sin SKU_, _sin coincidencia_, _SKU duplicado_, _producto en papelera_), entonces ve
      solo ese grupo.
- [ ] _(las descartadas están ocultas por defecto)_ Dado un producto cuya sugerencia se
      descartó, cuando el dueño mira el listado con la configuración por defecto, entonces
      **no aparece**: ya lo resolvió y no tiene que volver a decidir. Tampoco existe el motivo
      _sugerencia descartada_ entre las opciones del filtro.
- [ ] _(ver descartadas es una opción)_ Dado que el dueño activa **ver sugerencias
      descartadas**, entonces esas filas aparecen en el listado y el filtro por motivo suma la
      opción _sugerencia descartada_, que las lista juntas para deshacer el descarte. Al
      desactivarlo, vuelven a ocultarse.
- [ ] _(buscar siempre encuentra)_ Dado un producto con la sugerencia descartada y la opción
      desactivada, cuando el dueño lo busca por nombre o por SKU, entonces **aparece igual**.
      Ocultarlo de la bandeja de trabajo no puede volverlo inencontrable: sigue siendo un
      producto que quizá quiera vincular a mano.
- [ ] Dado el listado, cuando el dueño busca por nombre o por SKU, entonces solo se muestran
      las filas que coinciden. La búsqueda mira **los dos lados** cuando la fila tiene par o
      sugerencia, y solo el local cuando no: buscar el nombre que el producto tiene en la otra
      tienda tiene que encontrarlo.
- [ ] _(vincular desde la sugerencia)_ Dado un producto con sugerencia, cuando el dueño la
      acepta, entonces se fija el par, se emite el bootstrap de UC-07 y la fila pasa a
      _vinculado_ sin recargar la pantalla entera.
- [ ] _(el stock de arranque es el de la maestra)_ Dado que el producto tiene 10 en la
      maestra y 7 en la secundaria, cuando el dueño lo vincula, entonces el pool arranca en
      **10** y ese valor se escribe en las dos tiendas. No hay nada que elegir ni desde dónde
      hacerlo (UC-07).
- [ ] _(el número se ve antes de confirmar)_ Dado ese mismo producto, cuando el dueño está
      por vincularlo, entonces la pantalla muestra los dos stocks y **cuál va a quedar**,
      antes de que confirme. La regla es fija, pero el resultado nunca es una sorpresa.
- [ ] _(variaciones)_ Dado un producto variable, cuando el dueño despliega su fila, entonces
      ve una fila por variación y **las acciones están en las variaciones**, no en el padre.
- [ ] _(validación — padres variables)_ Dado dos productos variables, cuando el dueño intenta
      vincularlos entre sí, entonces la pantalla lo rechaza: se vinculan las variaciones
      (UC-03).
- [ ] _(vincular a mano)_ Dado un producto sin sugerencia —sin SKU, con SKU distinto, o con
      un duplicado que el plugin no quiso resolver—, cuando el dueño busca el producto de la
      otra tienda y confirma, entonces el par queda fijado igual. **El SKU no es condición
      para vincular.**
- [ ] _(vinculación masiva)_ Dado un catálogo recién instalado, cuando el dueño lanza la
      vinculación masiva, entonces le muestra cuántos pares inequívocos va a fijar y le avisa
      que **todos arrancan con el stock de la maestra**; los ambiguos quedan listados aparte
      para resolver de a uno (UC-03).
- [ ] _(masiva — revalidación al confirmar)_ Dado que entre que la previsualización se cargó
      y el dueño confirmó alguien tocó el catálogo, entonces **cada candidato se vuelve a
      validar en el momento de escribir**: que los dos sigan publicados, que ninguno se haya
      vinculado mientras tanto, que el SKU siga siendo inequívoco y que su sugerencia no se
      haya descartado.
      Sin esto la masiva sería el emparejamiento automático con un clic de por medio.
- [ ] _(masiva — resultado parcial)_ Dado que 40 candidatos ya no pasan la revalidación,
      cuando la masiva termina, entonces devuelve **los vinculados y los omitidos con su
      motivo actual**, y el lote no se cae entero por eso. Cada vínculo se escribe por
      separado y es idempotente (ADR-001): reintentar no duplica ni re-bootstrapea.
- [ ] _(descartar)_ Dado un producto que el dueño no quiere sincronizar, cuando descarta su
      sugerencia, entonces **el estado sigue siendo _sin vincular_** —no aparece un estado
      nuevo—, la sugerencia deja de ofrecerse, el producto deja de entrar en la vinculación
      masiva, y **la acción queda registrada en el log del producto** con la fecha y el
      usuario. Es reversible desde la misma fila.
- [ ] _(descartar — dónde se ve)_ Dado un producto cuya sugerencia se descartó, cuando el
      dueño abre su log, entonces ve **cuándo y quién** lo descartó. Si la fila está a la
      vista, se lee _sin vincular · sugerencia descartada_: el estado dice qué pasa hoy, el
      log dice por qué.
- [ ] _(descartar — alcance)_ Dado un producto local cuya sugerencia se descartó, cuando en
      la otra tienda cambia el SKU y aparecería otra coincidencia, entonces **no vuelve a
      sugerirse**: el descarte es sobre el producto local, no sobre una pareja de SKU. Lo
      contrario haría que un cambio de catálogo reviviera trabajo que el dueño ya resolvió.
- [ ] _(descartar no bloquea)_ Dado un producto con la sugerencia descartada, cuando el dueño
      decide vincularlo a mano, entonces puede hacerlo sin deshacer el descarte primero.
      Descartar silencia sugerencias, no prohíbe vincular.
- [ ] _(desvincular)_ Dado un producto vinculado, cuando el dueño lo desvincula, entonces la
      pantalla **le advierte que el producto deja de sincronizar** y le pide confirmación
      antes de cortar el par; su stock no se toca en ninguna de las dos tiendas (UC-03).
- [ ] _(reasignar)_ Dado un producto mal apareado, cuando el dueño lo reasigna a otro
      producto de la otra tienda, entonces **primero se corta el par vigente** —lo que libera
      al producto que estaba apareado— y después se fija el nuevo con un **bootstrap nuevo**
      que toma el stock de **la maestra** y reemplaza al stock base anterior, con las
      consecuencias de UC-06 sobre el cálculo (UC-07).
- [ ] _(reasignar — el segundo paso falla)_ Dado que el par viejo ya se cortó y la otra tienda
      deja de responder, entonces el producto queda **sin vincular**, la pantalla lo muestra
      así y el dueño reintenta. Un estado válido y visible, nunca dos pares a la vez.
- [ ] _(suspendido — causa visible)_ Dado un producto suspendido, cuando el dueño mira su
      fila, entonces ve **por qué**: el producto de la otra tienda fue borrado o está en la
      papelera. Sin la causa, el estado no le dice qué hacer.
- [ ] _(caída no es suspensión)_ Dado que la otra tienda no responde, entonces los productos
      vinculados **siguen figurando como vinculados**, no como suspendidos: la caída es
      temporal y no rompió ningún par. Lo que la pantalla muestra es el aviso de la caída
      (UC-03, UC-08).
- [ ] _(suspendido — salida)_ Dado un producto suspendido que no va a volver, cuando el dueño
      lo desvincula, entonces queda libre para vincularlo a otro producto; sus movimientos
      retenidos dejan de esperar.
- [ ] _(suspendido — vuelve solo)_ Dado un producto suspendido porque el otro lado estaba en
      la papelera, cuando se lo restaura, entonces la fila vuelve a **vinculado** sin
      intervención y **sin bootstrap nuevo**: el par sigue siendo el mismo ID y el stock base
      vigente no se toca. No hay botón de "reanudar" porque no hay nada que reanudar — el
      estado es derivado (UC-03) y la reconciliación aplica lo retenido (UC-06).
- [ ] _(validación — par ya tomado)_ Dado un producto de la otra tienda que **ya está
      vinculado** a un tercero, cuando el dueño intenta vincularlo, entonces la pantalla lo
      rechaza indicando con quién está vinculado. Un producto no puede tener dos pares.
- [ ] _(validación — mismo lado)_ Dado un producto de la **misma** tienda, cuando el dueño
      intenta vincularlo, entonces la pantalla lo rechaza: un par es siempre entre tiendas
      distintas.
- [ ] Dado un producto, cuando el dueño abre su log, entonces ve **dos clases de evento en
      una sola línea de tiempo**: los movimientos de stock con fecha, tienda de origen,
      cantidad y motivo (bootstrap, venta, devolución, ajuste manual, reconciliación) —
      incluidos los de vinculaciones anteriores—, y las **acciones de vinculación** (vincular,
      desvincular, reasignar, descartar, deshacer un descarte) con fecha y usuario. **Se
      registran todas, hayan movido stock o no, y también las que fallaron.**
- [ ] _(permisos — se avisa antes)_ Dado un usuario sin `manage_woocommerce`, cuando abre la
      pantalla, entonces **ve el listado en modo lectura y un mensaje que dice que no tiene
      permisos para modificar**, con las acciones deshabilitadas. No se entera después de
      elegir un producto y apretar el botón: se entera antes de empezar.
- [ ] _(permisos — la comprobación real está en el servidor)_ Dada cualquier escritura
      —vincular, desvincular, reasignar, descartar, deshacer un descarte, masiva—, cuando
      llega de un usuario sin `manage_woocommerce`, entonces se rechaza y nada se modifica.
      El aviso de arriba es comodidad; **la verificación que cuenta se repite al procesar cada
      acción** y no depende de que el botón esté deshabilitado ni de que el menú esté oculto.
- [ ] _(solo lectura del stock)_ Dado cualquier producto del listado, cuando el dueño lo
      consulta, entonces **no** existe forma de modificar su cantidad de stock desde esta
      pantalla.
- [ ] _(fallo)_ Dado que la vinculación falla —la otra tienda no responde al fijar el par—,
      entonces la fila pasa por un estado **guardando…** y termina mostrando el error, con la
      acción reintentable; al salir bien, la fila se refresca con el estado real que devolvió
      el servidor, no con uno optimista.
- [ ] _(fallo — queda registrado)_ Dado ese intento fallido, cuando el dueño abre el log del
      producto más tarde, entonces **el intento figura con su error y su fecha**, aunque no
      haya movido stock. Un fallo que no deja rastro es indistinguible de no haberlo
      intentado nunca.
- [ ] _(distinguir olvido de falla)_ Dado un producto que no sincroniza, cuando el dueño abre
      su log, entonces puede decidir qué hacer: **si está vacío**, nunca se vinculó y lo
      vincula; **si tiene un intento fallido o un descarte**, ya pasó algo y sabe qué.

**Fuera de este caso — y esto es un límite, no un recorte:** que un par **no pueda quedar
fijado de un solo lado** es una garantía del protocolo entre tiendas, no de la pantalla. La
UI puede mostrar _guardando…_, un error recuperable y refrescar la fila; no puede prometer
una transacción distribuida desde React. La garantía se define en el **ADR de transporte**,
que todavía no existe, junto con el ACK y la idempotencia de los movimientos.

**Fuera de este caso:** editar cantidades de stock, editar el SKU desde acá —se edita en
WooCommerce, y ya no es condición para sincronizar—, conteo físico, edición en lote de otra
cosa que no sea la vinculación masiva, alertas de stock bajo y exportación: todo eso quedó
fuera de v1 (§2).

### UC-06 — Reconciliación

> Estado: Aprobada · 2026-09-13

Como dueño quiero que las tiendas se revisen entre sí cada cierto tiempo y reparen lo que se
haya perdido, para que un mensaje caído no me deje desalineado para siempre sin que yo me
entere.

**Reconcilia por par, nunca por SKU.** Las dos fases recorren los productos **vinculados** y
los aparean por su par fijado; los que no están vinculados no entran en la comparación. Que
dos productos compartan un SKU, o que un vinculado lo haya cambiado, no altera en nada lo que
la reconciliación compara (UC-03).

**La reconciliación no inventa ni copia un stock absoluto: reentrega movimientos ya
registrados y después verifica.** La distinción importa. Sí vuelve a enviar cosas —esa es la
fase 1— pero solo movimientos que ya están en el libro de quien los emitió, con su
identificador de siempre. Lo que nunca hace es escribir el total de una tienda sobre la otra,
que es como un "sincronizador" ingenuo resolvería el problema y como se pierde una venta.

El stock viaja por la cola, movimiento por movimiento, y llega solo (§3). La reconciliación
es la **red de seguridad** que comprueba que llegó: si todo funcionó, no tiene nada que hacer.
Vale la pena dejarlo dicho porque los tres disparadores de abajo se leen mal si se los toma
por la vía de entrega normal.

La reconciliación se dispara de tres maneras:

- **Periódica:** la lanza la tienda maestra una vez por día, en su madrugada.
- **De cierre de importación** (UC-04): la lanza la tienda que importó, sea cual sea, apenas
  termina la ráfaga. La importación ya se propagó por la cola como cualquier otro cambio; lo
  que se verifica es que el lote **entero** haya llegado. Se corre acá y no en la pasada
  nocturna porque una ráfaga es el momento de mayor riesgo de pérdida —mucho volumen, lotes
  grandes, timeouts— y porque un desvío introducido ahí no afecta a un producto sino a
  cientos. Si algo se perdió, conviene enterarse en minutos y no al otro día.
- **Manual:** el dueño la lanza desde la pantalla de la **maestra**, en cualquier momento, sin
  esperar al cron. Es la salida cuando algo se ve raro y no se quiere esperar hasta la
  madrugada, y la forma de reparar apenas vuelve una tienda que estuvo caída. Va en la
  maestra por la misma razón que todo lo demás: un solo puesto de trabajo (§3). La secundaria
  muestra el resultado de la última pasada, pero no la dispara.

**Los tres disparadores ejecutan exactamente el mismo trabajo.** La pantalla de UC-05 lo
lanza y muestra el resultado, pero no es parte del mecanismo: la reconciliación tiene que
poder correr con la UI rota, sin sesión de navegador y desde el cron. Si la red de seguridad
del plugin dependiera de React, el día que la pantalla falle se apagaría justo lo que existe
para cuando algo falla.

En los tres casos corre en **dos fases, donde el orden importa**.

**Antes de empezar: las dos tiendas acuerdan hasta dónde miran.** Cada una le dice a la otra
cuál es su **última secuencia propia** en ese instante, y esas dos marcas delimitan la pasada.
La fase 1 garantiza que las dos conocen todo **hasta esos límites**; lo que entre después
—una venta que ocurre mientras la reconciliación corre— pertenece a la pasada siguiente y no
se considera faltante.

Sin esa marca, la reconciliación tendría que exigir que los dos libros sean idénticos, y eso
es imposible en una tienda que sigue vendiendo: siempre habría un movimiento recién nacido del
otro lado. O reportaría falsos positivos, o reintentaría sin terminar nunca. **Con el límite
acordado no hace falta congelar las ventas ni pausar la cola.**

**Fase 1 — Reparar lo explicable.** Las dos tiendas comparan qué movimientos conoce cada una
dentro de esos límites. Todo movimiento que a una le falte se le entrega y se aplica. Esto
repara el caso normal —una propagación que falló— con el dato real, sin inventar ningún
ajuste.

**Fase 2 — Detectar lo inexplicable.** Con los libros ya completos hasta el límite acordado,
**cada tienda se compara contra su propio libro**: su `_stock` de WooCommerce contra
`stock base + suma de los movimientos aplicados posteriores al bootstrap vigente`. Si no
coincide, hubo una escritura que nadie registró (el caso invisible de UC-04). Ahí el plugin
**no toca nada**: registra la discrepancia y la muestra, porque no tiene forma de saber qué
pasó ni cuál de los dos números es el correcto.

**Lo que la fase 2 nunca hace es comparar el stock de A contra el de B.** Parece el chequeo
obvio y es el equivocado: una venta que todavía está en vuelo deja los dos números distintos
sin que nada esté mal. Cada tienda contra su propio libro responde la pregunta que importa
—"¿alguien escribió acá sin registrarlo?"— y no se confunde con el tránsito normal.

**Un par suspendido queda fuera de la fase 2.** Si el producto del otro lado desapareció
(UC-03), hay movimientos retenidos sin aplicar y el stock local no tiene por qué coincidir con
un libro que incluye lo retenido. La fase 1 sí sigue corriendo —guardar y deduplicar lo que
llegue nunca hace daño— pero **no se abre ninguna discrepancia para ese par**. Cuando el par
se restablece, se aplican los retenidos y la pasada siguiente vuelve a evaluarlo con
normalidad.

**Salvaguarda — entregar de más nunca duplica.** El caso a tener presente es este: un
movimiento estuvo reintentando, la cola terminó entregándolo, y del lado del emisor quedó
marcado como pendiente igual —porque el ACK se perdió, o porque la reconciliación arrancó con
el envío todavía en vuelo—. La fase 1 lo vuelve a ofrecer. **Se aplica una sola vez**, y no
por cuidado del emisor sino porque **el receptor no puede aplicar dos veces el mismo
movimiento**: cada uno viaja con su identificador global —tienda de origen más secuencia— y
el receptor lo deduplica de forma durable antes de tocar el stock (§3, ADR-001).

Que la garantía viva del lado que recibe es deliberado. Si dependiera de que el emisor sepa
qué llegó, cada ACK perdido sería un descuento duplicado, y los ACK se pierden. Acá el peor
caso de un estado de reintento desactualizado es **trabajo de más**: se reenvía algo que ya
estaba, el receptor lo descarta por repetido y el stock no se mueve.

Con una condición, que está en §3 y conviene repetir acá: **el receptor confirma igual el
movimiento repetido**. Descartarlo en silencio dejaría al emisor reintentando para siempre
justo lo que ya está aplicado. Y en la reconciliación hay una segunda vía para lo mismo: la
fase 1 compara qué movimientos tiene cada una, así que el emisor se entera de que el otro ya
lo tiene aunque el ACK original se haya perdido, y deja de considerarlo pendiente.

Dos consecuencias que conviene no perder de vista:

- **Un movimiento en vuelo no produce un falso positivo en la fase 2.** Las fases corren en
  orden: para cuando se comparan los totales, la fase 1 ya entregó y aplicó todo lo que
  faltaba. Por eso la fase 2 nunca se ejecuta sobre un libro que todavía se está completando.
- **Que dos pasadas se pisen no corrompe nada.** Ninguna puede aplicar un movimiento que ya
  está aplicado. Aun así **no se permite que corran dos a la vez**, y conviene entender que
  son dos cosas distintas: la deduplicación es la que garantiza que el stock no se rompa, y
  el candado de abajo solo evita trabajo duplicado y resultados confusos. El candado nunca es
  la garantía de corrección.

**Una sola reconciliación por vez, con un candado.** Antes de arrancar, la pasada toma un
candado para esa pareja de tiendas. Si ya está tomado: la **manual** responde que hay una
reconciliación en curso, y la **periódica** y la de **cierre de importación** se saltean sin
hacer ruido — ya hay alguien mirando lo mismo, y la siguiente pasada las cubre.

El candado **vence solo**. Una pasada que muere a la mitad —un timeout de PHP, un reinicio—
no puede dejar la reconciliación bloqueada para siempre; sin expiración, el primer corte de
luz desactiva la red de seguridad del plugin y nadie se entera hasta que hay un desvío.

**Salvaguarda — si la otra tienda no responde, no se reconcilia.** Con dos tiendas no hay
"reparar lo que se pueda": si la otra no contesta, **no hay con quién comparar libros** y
ninguna de las dos fases tiene sentido. La pasada se aborta, queda registrada en el log, y se
reintenta en la siguiente —o el dueño lanza una manual cuando ve que la otra volvió—. El
stock no se toca y no se reporta ninguna discrepancia: un movimiento que no llegó porque la
tienda estaba caída se vería como un desvío inexplicable, y reportarlo sería un falso
positivo.

> Con tres o más tiendas esto cambia: ahí sí la fase 1 puede repararse entre las que están
> disponibles y solo la fase 2 queda bloqueada hasta tenerlas a todas. Es una de las cosas
> que se simplifican por ser dos (§2), y conviene recordarla si algún día se abre la malla.

**Criterios de aceptación:**

- [ ] Dado que a la tienda B le falta el movimiento `M-A-77`, cuando corre la reconciliación,
      entonces B lo recibe y lo aplica, y ambas tiendas quedan con el mismo stock.
- [ ] Dado que el movimiento faltante ya había sido aplicado en B por otra vía, cuando la
      reconciliación se lo entrega, entonces B lo ignora por su ID y el stock no cambia.
- [ ] Dado que no falta ningún movimiento y los stocks coinciden, cuando corre la
      reconciliación, entonces no se genera ningún cambio ni ninguna alerta.
- [ ] _(caída prolongada)_ Dado un producto en 10 en ambas tiendas, cuando la tienda A queda
      caída una hora y durante ese lapso B vende 3 unidades (B queda en 7 y A sigue en 10),
      entonces al volver y correr la reconciliación A **recibe y aplica los tres movimientos**
      y queda en 7. En ningún caso impone su propio valor de stock sobre B: el número de una
      tienda no es una verdad, es el resultado de los movimientos que conoce. Da igual cuál de
      las dos sea la maestra — en la reconciliación no tiene ningún privilegio.
- [ ] _(caída prolongada — bidireccional)_ Dado ese mismo escenario, cuando además A registró
      ventas propias durante la caída, entonces tras la reconciliación ambas tiendas reflejan
      **todos** los movimientos de los dos lados y ninguno se pierde.
- [ ] Dado un stock local de 47 con un libro completo que suma 50, cuando corre la fase 2,
      entonces el plugin **no modifica el stock de ninguna tienda** y registra una
      discrepancia indicando producto, tienda, valor esperado y valor encontrado.
- [ ] _(salvaguarda)_ Dado que la tienda B no responde, cuando corre la reconciliación,
      entonces **no se ejecuta ninguna de las dos fases** —no hay con quién comparar—, el
      stock no se toca, no se reporta ninguna discrepancia y queda registrado en el log que la
      pasada no pudo correr.
- [ ] _(salvaguarda — se repara después)_ Dado que B vuelve, cuando corre la siguiente pasada
      —la periódica o una manual—, entonces la fase 1 entrega lo que quedó pendiente durante
      la caída y las dos tiendas quedan alineadas.
- [ ] _(cierre de importación)_ Dada una importación masiva que acaba de terminar (UC-04) y
      cuyo lote **ya despachó la cola**, cuando se dispara la reconciliación de cierre,
      entonces comprueba que la otra tienda tenga los movimientos del lote y **no repara nada
      si llegaron todos**. La alineación la produjo la cola; el cierre confirma que ocurrió.
- [ ] _(cierre de importación — lote incompleto)_ Dado que 12 movimientos del lote no
      llegaron, cuando corre el cierre, entonces la fase 1 se los entrega y las tiendas
      quedan alineadas sin una petición por producto.
- [ ] _(reintento vencido — no duplica)_ Dado un movimiento `M-A-77` que la cola ya entregó y
      B ya aplicó, pero que en A quedó marcado como pendiente porque se perdió el ACK, cuando
      corre la reconciliación y A se lo vuelve a ofrecer, entonces B **lo descarta por
      repetido** y su stock no se mueve. El descuento se aplicó una sola vez.
- [ ] _(el repetido se confirma igual)_ Dado ese mismo movimiento repetido, cuando B lo
      descarta, entonces **responde que lo tiene** y A deja de considerarlo pendiente. Sin esa
      confirmación, A reintentaría hasta agotar, escribiría un error falso en el log y la
      reconciliación volvería a ofrecerlo todas las noches (§3).
- [ ] _(retenido también se confirma)_ Dado un movimiento que llega para un producto
      **suspendido**, cuando B lo retiene sin aplicarlo (UC-03), entonces **lo confirma
      igual**: quedó guardado, que es lo que el emisor necesita saber. Un producto suspendido
      no genera reintentos eternos por cada venta.
- [ ] _(entrega en vuelo — no duplica)_ Dado que la reconciliación arranca mientras la cola
      está entregando `M-A-78`, cuando los dos caminos lo depositan en B, entonces **se aplica
      una sola vez**: la deduplicación es durable y no depende de cuál llegó primero.
- [ ] _(reconciliaciones solapadas)_ Dada la reconciliación de cierre de importación
      corriendo, cuando el dueño lanza una manual al mismo tiempo, entonces ningún movimiento
      se aplica dos veces y ninguna de las dos corrompe el stock.
- [ ] _(sin falso positivo por movimiento en vuelo)_ Dado un movimiento que la fase 1 acaba de
      entregar, cuando corre la fase 2, entonces **no se reporta discrepancia**: el total ya
      incluye lo recién aplicado, porque el orden de las fases lo garantiza.
- [ ] Dado que existe una discrepancia reportada, cuando el dueño consulta la pantalla de
      estado, entonces la ve listada con su detalle.
- [ ] _(manual)_ Dado que el dueño pulsa _reconciliar ahora_ en la pantalla de estado,
      entonces la reconciliación corre de inmediato con las mismas dos fases y las mismas
      salvaguardas que la periódica, y la pantalla informa el resultado.
- [ ] _(manual — la otra tienda no responde)_ Dado que la otra tienda no responde, cuando el
      dueño lanza la reconciliación manual, entonces **no corre ninguna fase** y la pantalla
      se lo dice con claridad: no es que no haya nada que reparar, es que no se pudo mirar.
      La distinción importa porque las dos cosas terminan sin cambios.
- [ ] _(solapamiento — manual)_ Dado que ya hay una reconciliación en curso, cuando el dueño
      lanza una manual, entonces no se inicia una segunda y la pantalla informa que hay una
      corriendo.
- [ ] _(solapamiento — automática)_ Dado que ya hay una reconciliación en curso, cuando toca
      la periódica o la de cierre de importación, entonces **se saltea sin ruido**: no hay
      error que avisar, ya hay alguien mirando lo mismo.
- [ ] _(candado vencido)_ Dada una reconciliación que murió a la mitad por un timeout, cuando
      pasa el vencimiento del candado, entonces la siguiente pasada **puede arrancar**. Un
      candado que no vence apagaría la red de seguridad del plugin sin que nadie se entere.
- [ ] _(watermark — venta durante la pasada)_ Dado que la reconciliación ya acordó los
      límites y B vende 1 unidad mientras corre, entonces ese movimiento **no se cuenta como
      faltante** ni dispara discrepancia: queda para la pasada siguiente y viaja por la cola
      como cualquier otro.
- [ ] _(fase 2 — cada una contra su libro)_ Dado que A está en 50 y B en 49 porque una venta
      de B todavía viaja, cuando corre la fase 2, entonces **ninguna de las dos reporta
      discrepancia**: cada una coincide con su propio libro. Comparar A contra B habría
      señalado un error donde solo hay tránsito.
- [ ] _(fase 2 — par suspendido)_ Dado un par suspendido con movimientos retenidos sin
      aplicar, cuando corre la fase 2, entonces **no se abre discrepancia para ese par**; la
      fase 1 sí guarda y deduplica lo que llegue.
- [ ] _(discrepancia — no se duplica)_ Dada una discrepancia de esperado 50 / real 47 que el
      dueño todavía no resolvió, cuando corre la pasada de la noche siguiente, entonces **no
      se abre una segunda**: se actualiza cuándo se la vio por última vez.
- [ ] _(discrepancia — se cierra sola)_ Dado que el dueño corrigió el stock y el ajuste se
      propagó (UC-04), cuando la pasada siguiente vuelve a comparar, entonces la discrepancia
      **se cierra automáticamente**, sin que él tenga que marcarla como resuelta.
- [ ] _(independiente de la UI)_ Dada la reconciliación periódica, cuando corre desde el cron
      sin ninguna pantalla abierta, entonces hace exactamente el mismo trabajo que la manual.
      La pantalla la lanza y muestra el resultado; no es parte del mecanismo.
- [ ] _(manual — permisos)_ Dado un usuario sin la capacidad requerida, cuando intenta lanzar
      la reconciliación, entonces la petición se rechaza y no se ejecuta nada.

**Una discrepancia se abre una vez y se cierra sola.** Hay **una discrepancia abierta por par
y por tienda**, no una por pasada: si el desvío de esperado 50 / real 47 sigue ahí la noche
siguiente, no se crea otra, se actualiza **cuándo se la vio por última vez**. Y cuando una
pasada posterior encuentra que los números vuelven a coincidir, **se cierra automáticamente**:
el dueño corrigió el stock, el ajuste se propagó por UC-04, y no tiene que ir a marcar nada.

Sin esto, un desvío que el dueño todavía no resolvió generaría una alerta nueva cada noche
hasta volver ruido toda la pantalla — el mismo problema que UC-08 resuelve para las
sugerencias, y con la misma respuesta.

**Comportamiento durante una discrepancia abierta:** el producto **sigue sincronizando
normalmente** — las ventas y los ajustes se propagan como siempre. El desvío se mantiene
constante hasta que alguien lo resuelva, pero no crece ni bloquea la operación.

**Fuera de este caso:** resolver la discrepancia. Corregirla es una decisión humana: el
dueño edita el stock en la tienda que corresponda y ese ajuste se propaga por UC-04.

### UC-07 — Un producto entra a la malla (bootstrap)

> Estado: Aprobada · 2026-09-13

Como dueño quiero que un producto empiece a sincronizarse con el stock correcto, para que
sumar un artículo al catálogo no me duplique ni me esconda mercadería.

> **Bootstrap** (_alta en la malla_): el momento en que se declara el punto de partida de un
> producto. Todo el resto del plugin trabaja con diferencias, y una diferencia solo tiene
> sentido si existe un número desde el cual contar. Antes del bootstrap hay dos cantidades
> independientes que nadie sabe si son el mismo depósito; después hay un pool con un valor
> inicial —el **stock base**— y una historia de movimientos sobre él.

**El bootstrap es el acto de vincular.** No es un proceso aparte que ocurra después: cuando
el dueño confirma un par en la pantalla de UC-05, esa misma operación fija las dos cosas a la
vez —"estos dos productos son el mismo, y arrancan en N"—. Son inseparables: un stock base
sin par no se puede aplicar a nada, y un par sin stock base no puede contar deltas.

**Un producto entra a la malla de una sola manera: cuando el dueño lo vincula desde la
pantalla.** No hay otra. Crear el producto en las dos tiendas, o ponerle el mismo SKU, no lo
hace entrar — solo lo vuelve un candidato que la pantalla va a sugerir. **Si el dueño no lo
vincula, ese producto no sincroniza**, y no importa cuánto tiempo pase ni cuántas veces se
guarde.

Lo que cambia entre un caso y otro es solo desde dónde arranca el dueño:

- Al **instalar el plugin** sobre tiendas que ya tienen catálogo, con la vinculación masiva
  resuelve el grueso en una confirmación (UC-03).
- Cuando **da de alta un producto nuevo** en las dos tiendas, lo vincula desde la sugerencia
  que aparece en la pantalla. El alta no lo vincula: la sugerencia espera.
- Cuando **decide vincular dos productos** que hasta ahora estaban sueltos, con o sin
  sugerencia de por medio.

**Cuál es el stock inicial: el de la maestra.** El pool arranca con el valor que el producto
tenga en la tienda maestra, y la secundaria queda **alineada a ese valor**. No se suman: es el
mismo depósito visto desde dos lugares, no dos depósitos. No hay selector, ni regla
configurable, ni depende de desde dónde se haga: **la vinculación solo se hace desde la
maestra** (UC-05), así que la pregunta no existe.

En los términos de §3, la maestra es la **tienda de origen** de todo bootstrap, igual que la
tienda donde se vende es el origen de la venta. Es el único movimiento con un emisor fijo, y
justamente el único que escribe un valor absoluto.

**Vale para todo bootstrap, no solo para el primero.** Revincular un producto que se había
desvinculado, o reasignarlo a otro par (UC-03, UC-05), emite un bootstrap nuevo y se resuelve
igual. No hay un caso "primera vez" y otro "las siguientes".

**Que sea siempre la maestra es lo que vuelve la regla enunciable.** El dueño no tiene que
recordar desde qué pantalla vinculó cada producto ni qué número quedó: sabe que el stock que
vale es el que carga en la maestra, y eso es lo mismo el primer día y dos años después.

**La regla es la misma que la del resto del plugin, y eso es el punto.** Un selector de "¿con
cuál arranco?" sería la única decisión del plugin que no se resuelve por origen, un caso
especial que habría que explicar, testear y recordar. Si el dueño quiere que mande la otra
tienda, la respuesta es la que ya conoce: **vincular desde la otra tienda.**

**Lo que la pantalla sí hace es mostrar el número antes de confirmar.** Los dos stocks están
a la vista, y en la masiva el total. La regla es fija; lo que no puede ser es sorpresa.

Este es el **único momento en que el plugin escribe un valor absoluto**. Queda registrado en
el libro como un movimiento de tipo _bootstrap_, con el par fijado, el valor de la tienda de
origen y el stock que cada tienda tenía antes, para poder auditarlo después.

**Si la vinculación falla, no se reintenta sola.** Es deliberado y es la única excepción a
cómo viaja todo lo demás: los movimientos se reintentan por la cola, el bootstrap no. Un
reintento en segundo plano fijaría el par **sin nadie mirando**, minutos u horas después,
contra un catálogo que pudo cambiar en el medio — que es exactamente la carrera que ADR-005
cerró al poner a una persona a confirmar. El bootstrap es el único movimiento que inicia
alguien y espera; si falla, falló ahora.

**Pero "no reintentar" no es lo mismo que "no averiguar".** Un timeout no dice si la
secundaria aplicó el bootstrap o no, y las dos posibilidades piden cosas opuestas: si no lo
aplicó hay que reintentar, y si lo aplicó, reintentar duplica. Por eso un vínculo cuyo
resultado quedó **incierto** no se resuelve adivinando: se marca como tal, de forma durable, y
el plugin **le pregunta a la secundaria si lo aplicó**. Preguntar no fija nada ni decide nada
—la decisión ya la tomó el dueño— así que no toca la regla de arriba. Lo que no puede
ocurrir
sin nadie mirando es **fijar** un par; **enterarse** de si quedó fijado, sí.

**Qué ve el dueño mientras tanto:** el producto figura en un estado que no promete nada —ni
vinculado ni sin vincular— y el plugin resuelve solo en cuanto la secundaria conteste. Si no
contesta, sigue incierto y a la vista, que es mucho mejor que un estado inventado.

Entonces no hay "cuántas veces reintentar" que decidir. Lo que sí hay que garantizar es que
**el dueño se entere de que quedó pendiente**, y para eso el fallo deja tres rastros:

- El producto sigue **sin vincular**, con su sugerencia intacta, en la bandeja de trabajo.
- El **intento fallido queda en el log del producto** con su error y su fecha (UC-05). Es lo
  que distingue "nunca lo vinculé" de "lo intenté y se cayó".
- **UC-08 lo avisa por email** como error, junto a los pares rotos — no como sugerencia
  pendiente. Un intento que falló no es una decisión que el dueño no tomó: es una decisión
  que tomó y que el plugin no pudo cumplir, y esas dos cosas se resuelven distinto.

**Mientras espera, no pasa absolutamente nada.** El producto candidato no emite ni recibe
movimientos y su stock no se toca en ninguna de las dos tiendas. El plugin no bootstrapea por
su cuenta: ni al instalarse, ni en un barrido nocturno, ni cuando se guarda un producto.

**La contracara honesta:** si el dueño no entra a la pantalla, ese producto no sincroniza
nunca. Es el precio de que el plugin no escriba un stock absoluto sobre un producto que nadie
eligió (ADR-005), y por eso UC-08 avisa por email cuando hay sugerencias esperando.

**Criterios de aceptación:**

- [ ] Dado un producto con SKU `ZAP-001` y stock 10 en la maestra y 7 en la secundaria,
      cuando el dueño lo vincula, entonces ambas quedan en **10** y el pool queda en 10 —
      nunca en 17 y nunca en 7.
- [ ] _(el número se ve antes)_ Dado ese mismo producto, cuando el dueño está por vincularlo,
      entonces la pantalla muestra los dos stocks y **cuál va a quedar**. La regla es fija; el
      resultado no puede sorprenderlo (UC-05).
- [ ] _(precondición de stock)_ Dado que la pantalla mostró 10 en la maestra y 7 en la
      secundaria, cuando **entre el dibujo y la confirmación se vende 1 en la secundaria**,
      entonces la vinculación **se rechaza**, la pantalla se refresca con 10 y 6, y el dueño
      confirma de nuevo. Escribir el 10 sobre un 6 que acababa de cambiar habría pisado una
      venta real sin dejar rastro.
- [ ] _(precondición de stock — la maestra también)_ Dado que la pantalla mostró 10 en la
      maestra y ahí se recibe mercadería que la lleva a 30 antes de confirmar, entonces la
      vinculación **se rechaza** en vez de fijar el pool en el 10 que el dueño había visto.
      La precondición vale para las dos puntas.
- [ ] _(precondición de catálogo)_ Dado que entre el dibujo y la confirmación el producto de
      la secundaria pasó a la papelera, o alguien lo vinculó a otro, entonces la vinculación
      **se rechaza** con el motivo concreto. Se comprueba lo mismo que revalida la masiva
      (UC-03): no puede exigirse menos al par que el dueño está mirando que al lote de 4.800.
- [ ] _(orden de alta)_ Dado que el producto se crea primero en una tienda y días después en
      la otra, entonces queda _sin vincular_ todo ese tiempo y no sincroniza; cuando existe
      en las dos, aparece como sugerencia y entra a la malla al confirmarla.
- [ ] _(nada automático)_ Dado un producto nuevo con SKU coincidente en ambas tiendas, cuando
      el plugin evalúa el catálogo, entonces **no** se bootstrapea solo: su stock no se toca
      en ninguna tienda hasta que el dueño lo vincula.
- [ ] _(el alta no vincula)_ Dado un producto que el dueño crea en las dos tiendas con el
      mismo SKU, cuando pasan días y varias ediciones sin que entre a la pantalla, entonces
      **sigue sin sincronizar**: las ventas de cada tienda no se propagan a la otra y los dos
      stocks corren por separado. Crear el producto no lo vincula; solo lo vuelve candidato.
- [ ] _(sin par posible)_ Dado un producto que existe solo en una tienda y nunca va a tener
      par, entonces permanece _sin vincular_ sin generar reintentos indefinidos ni ruido en
      el log.
- [ ] Dado ese bootstrap, cuando se consulta el libro, entonces existe un movimiento de tipo
      _bootstrap_ que registra el valor fijado y el stock previo de cada tienda.
- [ ] Dado un producto ya vinculado, cuando el plugin vuelve a evaluar el catálogo, entonces
      **no** se repite el bootstrap y su stock no se toca.
- [ ] _(fallo — la otra tienda no responde)_ Dado que la otra tienda no responde, cuando el
      dueño confirma una vinculación, entonces **el par no queda fijado**, la pantalla muestra
      el error, el stock no se toca en ninguna tienda y el resto de los productos sigue
      sincronizando con normalidad.
- [ ] _(fallo — no se reintenta solo)_ Dado ese intento fallido, cuando pasan horas sin que
      el dueño vuelva a la pantalla, entonces **el plugin no lo reintenta**: el producto sigue
      sin vincular y su stock intacto en las dos tiendas. Fijar un par sin que nadie esté
      mirando es justo lo que ADR-005 no permite.
- [ ] _(fallo — no se pierde de vista)_ Dado ese mismo intento fallido, entonces queda
      registrado en el log del producto con su error (UC-05) y **entra en el aviso por email
      de UC-08 como error**, no como sugerencia pendiente. El dueño tiene que poder enterarse
      sin volver a entrar a buscarlo.
- [ ] _(fallo — resultado incierto)_ Dado que la secundaria aplicó el bootstrap pero la
      respuesta se perdió en el camino, entonces la maestra **no da el vínculo por fijado ni
      por fallido**: lo deja en un estado **incierto y durable**, y la pantalla lo muestra
      como tal. Dar por fallido algo que sí se aplicó sería el camino directo a un bootstrap
      duplicado.
- [ ] _(consultar no es reintentar)_ Dado un vínculo en estado incierto, cuando el plugin le
      **pregunta a la secundaria si aplicó ese bootstrap**, entonces resuelve el estado con la
      respuesta —fijado o no— sin volver a escribir nada. Preguntar por el resultado de una
      decisión que el dueño ya tomó no viola la regla de que el bootstrap no se reintenta
      solo (ADR-005): lo que no puede pasar sin nadie mirando es **fijar** un par, no
      **enterarse** de si quedó fijado.
- [ ] _(caso borde — rehacer el par)_ Dado un producto mal apareado, cuando el dueño lo
      desvincula y lo vincula a otro producto, entonces se emite un **nuevo bootstrap** y el
      nuevo stock base reemplaza al anterior. Es la única vía a un stock base nuevo: cambiar
      o borrar un SKU no saca a nadie de la malla (UC-03).
- [ ] _(caso borde — reingreso y cálculo)_ Dado ese producto revinculado, cuando la fase 2 de
      UC-06 calcula su stock esperado, entonces suma **solo los movimientos posteriores al
      bootstrap vigente**: los del período anterior siguen visibles en el historial pero no
      entran en el cálculo.

**Riesgo conocido:** el pool arranca con el valor de la maestra, sea cual sea. Si el producto
se creó ahí con stock 0 y la mercadería se carga después, el pool arranca en 0 y la secundaria
pierde el stock que tenía cargado.

**Lo que se pierde es el número de la secundaria, y no vuelve solo.** El bootstrap escribe el
valor en las dos tiendas, así que las unidades que la secundaria tenía cargadas desaparecen
del pool. El movimiento de _bootstrap_ guarda el stock previo de cada una, que es lo que
permite darse cuenta después de lo que había.

**Se arregla como cualquier otro desvío, y por eso no tiene criterio propio acá:** el dueño
carga el stock real en la maestra y UC-04 lo propaga como el ajuste que es. **No hay que
desvincular ni revincular** — el par quedó bien, lo que quedó mal fue el número, y revincular
es para un par equivocado, no para un valor equivocado.

El plugin no lo impide, pero tampoco lo deja pasar en silencio: **los dos números están a la
vista antes de confirmar** (UC-05). La regla de uso está en §5.

**Fuera de este caso:** crear el producto en la otra tienda, que quedó fuera de v1 (§2). El
dueño da de alta el producto en cada tienda; el plugin solo resuelve con qué stock arranca el
pool cuando los vincula.

### UC-08 — Aviso por email de lo que no está sincronizando

> Estado: Aprobada · 2026-09-13

Como dueño quiero enterarme por email de **cualquier** producto que no esté sincronizando,
aunque el plugin no sepa con qué aparearlo, para no descubrirlo semanas después por una venta
perdida.

**Nada entra a la malla solo (UC-07), así que el riesgo cambió de forma.** Ya no es que un
SKU mal escrito rompa algo: es que un producto nuevo se quede esperando una vinculación que
nadie hizo. El email existe para eso — **es el recordatorio de entrar a la pantalla**, no un
reporte de errores.

**El disparador es el tiempo, no la evidencia.** Cualquier producto que lleve más de un
**plazo configurable —24 horas por defecto—** sin sincronizar entra en el aviso, **tenga o no
una sugerencia**. Que el plugin no le encuentre par no lo vuelve menos preocupante: un
producto sin SKU, o con un SKU que no coincide con nada, puede ser tanto una decisión del
dueño como un error de carga que nadie notó, y **desde afuera las dos se ven igual**. El único
que puede distinguirlas es él, y para eso tiene que enterarse.

Se avisa de tres cosas, y de ninguna más:

- **Productos que no sincronizan** desde hace más del plazo. Dentro de esta categoría conviene
  separar **los que tienen sugerencia** —un clic y listo— de **los que no** —hay que corregir
  el catálogo o vincularlos a mano—, porque el trabajo que piden es distinto. Pero las dos
  cosas se avisan.
- **Vinculaciones que fallaron** — el dueño sí decidió, apretó el botón, y el plugin no pudo
  cumplir (UC-07). Va separado porque se resuelve distinto: no hay nada que decidir, hay algo
  que reintentar. Y como el bootstrap **no se reintenta solo**, sin este aviso el producto se
  quedaría esperando indefinidamente a que el dueño se acuerde.
- **Pares rotos** — productos que estaban vinculados y pasaron a _suspendido_ porque el
  producto del otro lado se borró. También es un error y tampoco lo resuelve el tiempo.

Los dos últimos son errores y el primero no necesariamente. Conviene que el email los muestre
separados.

**Cada producto se avisa una sola vez, y ahí está la clave.** Lo que volvería insoportable al
email no es avisar de más: es **repetir**. Un producto que no sincroniza se informa una vez y
no se vuelve a mencionar, aunque siga así durante meses. Por eso conviene avisar de todo: el
costo de un producto que el dueño ya sabía que no iba a sincronizar es **una línea, una vez**,
y el beneficio es enterarse del que se quedó afuera por error.

**Descartar es la forma de decir "ya sé, dejame en paz".** Silencia la sugerencia y también
este aviso (UC-03), y el producto no vuelve a aparecer ni en la bandeja de trabajo ni en el
email. Eso cierra el circuito: cada producto que no sincroniza recibe exactamente un aviso, y
el dueño lo resuelve vinculándolo o descartándolo.

**Lo que no sincroniza espera a la pasada nocturna; los errores, no.** Son dos ritmos
distintos y a propósito. Un producto sin vincular puede esperar hasta la madrugada: nadie
decidió nada y no se está rompiendo nada. Un par roto o una vinculación que falló son
problemas que empezaron **recién** y que el dueño querría atender hoy, no mañana.

**El riesgo de avisar en el momento es la ráfaga**, y la respuesta no es solo limitar el
ritmo: es **agrupar por causa**. Si se vacía la papelera con 300 productos, no son 300
problemas — es uno: "se borraron 300 productos que estaban vinculados". Si la otra tienda no
responde, todo lo que se le mande va a fallar. El email nombra **la causa** y dice a cuántos
productos afecta, con un listado acotado; nunca un email por producto.

Sobre eso, dos frenos:

- **Un error suelto sale al instante.** El primero de una causa dispara el email enseguida:
  para eso existe el aviso inmediato.
- **Después se abre una ventana de silencio de 10 minutos para esa causa**, durante la cual
  todo lo que siga pasando se acumula. Al cerrarse, si hubo más, sale **un** resumen. Si
  sigue habiendo, la ventana se renueva, así que el ritmo máximo es de un email por causa cada
  10 minutos, pase lo que pase.

**Una tienda caída es una causa, no muchos errores.** Es el caso que más ensucia si no se
distingue: mientras la otra tienda no responde, **todo** falla —los movimientos no se
entregan, las vinculaciones no se pueden fijar, la reconciliación no corre— y cada síntoma,
mirado de cerca, parece un problema de un producto. No lo es: es un problema **temporal y
único**, y el producto no tiene nada de malo.

Así que en cuanto la cola agota los reintentos de un envío (§3), el plugin da a la otra tienda
por **no disponible** y a partir de ahí:

- Sale **un** email: la otra tienda no responde desde tal hora, y qué queda en espera.
- **Todo lo que falle por esa razón se atribuye a ella**, no se reporta como error propio del
  producto. No hay "falló la vinculación de ZAP-001": hay una tienda caída.
- Cuando vuelve, sale **un aviso de que se normalizó**. Es la única vez que el plugin avisa de
  algo bueno, y se justifica: sin eso el dueño se queda sin saber si tiene que hacer algo,
  cuando en realidad la cola y la reconciliación ya repararon todo solas.

**Y el freno más importante: lo que ocurre dentro de una operación que el dueño está mirando
no se avisa por email.** Si lanza la vinculación masiva y falla, eso se lo dice **el resultado
de la masiva** —vinculados y omitidos con su motivo (UC-05)—, no la casilla. Es lo que
descarta de raíz el caso peor: 4.800 vinculaciones fallando con la otra tienda caída son cero
emails, porque el dueño está parado frente a la pantalla viéndolo. **El email es para lo que
pasa cuando no hay nadie mirando.**

El aviso de lo que no sincroniza se arma junto con la reconciliación nocturna y sale como un
único resumen. El destinatario es configurable —por defecto el administrador del sitio— y la
notificación se puede desactivar.

**Criterios de aceptación:**

- [ ] Dado que hay 3 productos que llevan más del plazo sin sincronizar, entonces se envía
      **un** email que los lista, indica el total pendiente y enlaza a la pantalla de UC-05.
- [ ] _(sin sugerencia también se avisa)_ Dado un producto **sin SKU** y otro **cuyo SKU no
      coincide con nada**, cuando pasan del plazo sin sincronizar, entonces **los dos entran
      en el aviso**, agrupados aparte de los que tienen sugerencia porque piden otro trabajo.
      No tener par posible no es motivo para callarlo: puede ser una decisión o un error de
      carga, y desde afuera se ven igual.
- [ ] _(no se repite)_ Dado que al día siguiente siguen esos mismos productos sin resolver y
      no apareció ninguno nuevo, entonces **no se envía ningún email**. Un producto se avisa
      una sola vez, aunque siga sin sincronizar durante meses.
- [ ] Dado que al día siguiente aparece 1 producto nuevo además de los 3 anteriores, entonces
      se envía un email que detalla **solo el nuevo** e indica que hay 4 pendientes.
- [ ] _(el plazo se respeta)_ Dado un producto creado hace 2 horas y un plazo configurado en
      24, cuando corre la pasada nocturna, entonces **todavía no se avisa**: el dueño puede
      estar en medio del alta, y avisarle de algo que está haciendo ahora mismo es ruido.
- [ ] Dado un producto que estaba vinculado y cuyo par fue borrado, entonces el aviso sale
      **en el momento** y lo incluye como **par roto**, separado de lo que no sincroniza,
      porque requiere otra acción.
- [ ] _(error — sale en el momento)_ Dado un intento de vincular que falló porque la otra
      tienda no respondía (UC-07), entonces el aviso sale **enseguida**, sin esperar a la
      pasada nocturna: es un problema que empezó recién. Como el bootstrap no se reintenta
      solo, este es el único empujón que va a recibir el dueño.
- [ ] _(ráfaga — se agrupa por causa)_ Dado que se vacía la papelera de la secundaria y 300
      productos vinculados pierden su par de golpe, entonces se envía **un solo** email que
      nombra la causa y dice que afecta a 300 productos, con un listado acotado. Nunca 300
      emails: son 300 síntomas de un problema.
- [ ] _(ventana de silencio)_ Dado que ya salió el aviso de una causa, cuando siguen llegando
      errores de **esa misma causa** dentro de la ventana, entonces **no se envía nada más**
      hasta que la ventana cierre; ahí sale un único resumen de lo acumulado. El ritmo máximo
      es de un email por causa y por ventana.
- [ ] _(tienda caída — un solo aviso)_ Dado que la secundaria deja de responder y por eso
      fallan 300 entregas, 12 vinculaciones y la reconciliación, entonces se envía **un** email
      que dice que la tienda no responde desde tal hora, y **ninguno** por producto. Los 312
      no son errores distintos: son síntomas de uno.
- [ ] _(tienda caída — no se culpa al producto)_ Dado ese mismo escenario, cuando el dueño
      abre el log de uno de esos productos, entonces el intento figura atribuido a **la tienda
      caída**, no como un fallo propio del producto. Lo contrario lo mandaría a revisar un
      catálogo que está perfecto.
- [ ] _(tienda caída — se avisa que volvió)_ Dado que la secundaria vuelve a responder,
      entonces sale **un aviso de que se normalizó**, indicando que la cola y la
      reconciliación se encargan de lo pendiente. Sin eso el dueño no sabe si tiene que hacer
      algo, y la respuesta es que no.
- [ ] _(tienda caída — no se repite)_ Dado que la tienda sigue sin responder durante horas,
      entonces **no se envía un email por cada intento fallido** ni uno por cada ventana: el
      estado ya fue informado y el siguiente aviso es el de que volvió.
- [ ] _(causas distintas no se tapan)_ Dado que dentro de la ventana de una causa aparece un
      error de **otra** causa, entonces ese sí se avisa: la ventana silencia una causa, no el
      email entero.
- [ ] _(operación a la vista — no se avisa)_ Dado que el dueño lanza la vinculación masiva
      con la otra tienda caída y fallan 4.800 vinculaciones, entonces **no se envía ningún
      email**: el resultado de la masiva ya se lo dice en la pantalla (UC-05). El email es
      para lo que pasa cuando no hay nadie mirando.
- [ ] _(vinculación fallida — se resuelve)_ Dado que el dueño vuelve a intentarlo y esta vez
      funciona, entonces deja de avisarse, igual que cualquier otro problema que se resolvió.
- [ ] Dado que un producto se vincula y tiempo después el par se rompe, entonces el par roto
      cuenta como nuevo y se avisa, aunque ese producto ya hubiera sido avisado antes por no
      sincronizar: son dos problemas distintos.
- [ ] _(volumen)_ Dada una importación que deja 400 productos sin sincronizar de una vez,
      entonces se envía **un solo** email, con un listado acotado y el total, nunca un email
      por producto.
- [ ] _(instalación nueva)_ Dado un catálogo recién instalado con 5.000 productos sin
      vincular, cuando pasa el plazo, entonces sale **un** email con el total y un listado
      acotado — y después silencio, porque ya se avisaron. El primer aviso de una instalación
      es grande por definición y no puede repetirse todas las noches.
- [ ] Dado que no hay nada nuevo, entonces no se envía ningún email — el silencio es el
      estado normal.
- [ ] _(descartar silencia)_ Dado un producto **con la sugerencia descartada**, entonces
      **nunca genera aviso**, ni antes ni después del plazo. Descartar es la forma de decir
      "ya sé, dejame en paz" (UC-03).
- [ ] _(descartar después del aviso)_ Dado un producto que ya generó su aviso, cuando el
      dueño lo descarta, entonces tampoco vuelve a aparecer — lo que ya era cierto, porque no
      se repite, pero además desaparece de la bandeja de trabajo de UC-05.
- [ ] Dado que la notificación está desactivada en los ajustes, entonces no se envía nada,
      aunque todo se siga registrando y mostrando en la pantalla (UC-05).

**Fuera de este caso:** avisar de cada error de sincronización suelto y de las discrepancias
de reconciliación (UC-06). En v1 esos viven en el log y en la pantalla — y el error suelto,
además, casi siempre termina siendo un síntoma de la tienda caída, que sí se avisa.

---

## 5. Supuestos y restricciones

| Tema                               | Valor                                                 | Notas                                                                                |
| ---------------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Número de tiendas                  | Exactamente dos                                       | v1. El diseño admite más sin cambios de esquema; queda fuera de alcance (§2)         |
| WordPress mínimo                   | 6.5                                                   |                                                                                      |
| PHP mínimo                         | 8.1                                                   |                                                                                      |
| WooCommerce mínimo                 | 8.2                                                   | Versión en la que HPOS pasa a ser el almacenamiento de pedidos por defecto           |
| HPOS                               | Compatible, declarado explícitamente                  |                                                                                      |
| Multisite                          | A definir (§6)                                        |                                                                                      |
| Cantidades                         | Enteras                                               | El libro guarda `delta` entero. Ver abajo: `wc_stock_amount` es filtrable            |
| Stock negativo                     | Admitido                                              | Solo en productos configurados para aceptar reservas; el pool refleja lo que se debe |
| HTTPS                              | Requerido                                             | Las tiendas se comunican entre sí con credenciales; sin TLS viajarían en claro       |
| Rate limits externos               | N/A                                                   | No hay servicios de terceros: las tiendas hablan solo entre ellas                    |
| Volumen esperado                   | A definir (§6)                                        | Define el tamaño de los lotes y la retención                                         |
| Retención del libro de movimientos | 90 días                                               | Con ~200 movimientos diarios son unos 18.000 registros                               |
| Reconciliación periódica           | Una vez por día, en la madrugada de la tienda maestra | Más la de cierre de importación (UC-04, UC-06)                                       |
| Plazo para avisar de un producto sin sincronizar | 24 horas, configurable            | Se evalúa en la pasada nocturna; cada producto se avisa una sola vez (UC-08)          |
| Aviso de errores                   | En el momento, agrupado por causa                     | Ventana de silencio por causa tras el primer email, para que una ráfaga no inunde (UC-08) |
| Ventana de silencio por causa      | 10 minutos                                            | Tras el primer email de una causa; lo acumulado sale en un resumen al cerrarla (UC-08) |

**Latencia de propagación — sin compromiso de tiempo.** El plugin entrega los movimientos
**lo antes posible** a través de una cola de procesamiento, pero **no promete un plazo
máximo**: depende del volumen del catálogo y del servidor de cada cliente. En consecuencia,
ningún criterio de aceptación de §4 se expresa en minutos; se expresan como _"una vez que la
cola procesa el movimiento"_, que es determinista y testeable.

**Solo se sincronizan cantidades enteras.** El libro guarda el `delta` como entero. El stock
de WooCommerce normalmente lo es, pero `wc_stock_amount` es un filtro y hay extensiones que
lo pasan a decimal para vender por peso o por metro. Con una de esas instalada, un cambio
fraccionado se truncaría al registrarse y el pool se iría desviando sin que nadie lo note.
El plugin **detecta esa configuración y avisa** en vez de sincronizar mal en silencio; dar
soporte a decimales es una migración del esquema y queda fuera de v1.

**Supuestos que, si son falsos, invalidan el diseño:**

- **Las dos tiendas despachan del mismo depósito físico.** Si cada una tuviera su propio
  stock separado, el pool único no tiene sentido y el plugin no aplica.
- **Las dos son WooCommerce y del mismo dueño**, con capacidad de instalar el plugin en cada
  una. La malla exige que las dos puntas sean pares que llevan libro.
- **Los sitios se alcanzan entre sí por HTTP.** Una tienda detrás de un firewall que no
  acepte peticiones entrantes no puede participar de la malla.

**Restricciones de uso — reglas que el dueño debe respetar:**

- **Un mismo archivo de importación se carga en una sola tienda, y esa tienda es la maestra.**
  Técnicamente la propagación es simétrica y una importación en la secundaria llegaría igual
  (UC-04), pero fijar un solo lugar evita el único error que el plugin no puede deshacer:
  cargar **el mismo CSV en las dos**, que genera dos ajustes independientes que se suman y
  duplican el stock. El plugin puede detectar importaciones simultáneas y avisar, pero no
  puede impedirlo ni deducir que era el mismo archivo. La maestra ya es donde se administran
  los pares y se carga el stock, así que no hay nada extra que recordar.
- **Conviene que el SKU sea único dentro de cada tienda y la misma cadena entre tiendas**,
  al menos **en el momento de vincular**. No es un supuesto del diseño —desde ADR-005 el
  dueño puede vincular a mano dos productos con SKU distinto, o sin SKU— pero es lo que hace
  que la vinculación masiva resuelva el catálogo entero en un clic. Cuanto peor el SKU, más
  trabajo manual en el arranque; después de vincular, el SKU puede cambiar libremente
  (ADR-004).
- **Un producto nuevo no sincroniza hasta que el dueño lo vincula desde la maestra.** El
  plugin lo sugiere y lo avisa por email (UC-08), pero no entra a la malla solo. Hay que darlo
  de alta en las dos tiendas y **cargar el stock real en la maestra antes de vincularlo**: el
  pool arranca con el valor que tenga ahí, así que
  vincularlo antes de cargar ahí la mercadería arranca el pool en 0 y la secundaria pierde lo
  que tenía (UC-07). La pantalla muestra los dos números antes de confirmar; después se
  arregla cargando el stock real en la maestra, que se propaga como un ajuste cualquiera
  (UC-04), pero el momento de mirar es ese.
- **Un desvío por mensaje perdido puede durar hasta 24 horas.** Con la reconciliación diaria
  elegida, ese es el peor caso entre que un movimiento se pierde y la pasada nocturna lo
  repara. Es el costo aceptado a cambio de no correr reconciliaciones en horario de venta.

---

## 6. Preguntas abiertas

Ninguna impide cerrar el SPEC, pero cada una bloquea una parte concreta.

- [ ] **¿Se soporta WordPress multisite?** Bloquea: la instalación y la pantalla de ajustes.
      Si dos tiendas viven en la misma red multisite comparten base de datos y podrían
      hablarse sin HTTP, que es un camino distinto al de §3.
- [ ] **¿Qué volumen tiene el catálogo real y cuántos movimientos por día?** Bloquea: el
      tamaño de los lotes de propagación, los índices del libro y la validación de que 90
      días de retención sea razonable.
- [ ] **¿Cómo se agrupa una importación masiva?** Bloquea: el modo importación de UC-04.
      Hace falta un criterio concreto de ráfaga (N cambios en M segundos) y decidir si es
      configurable, pero el problema es anterior: **detectar la ráfaga por umbral llega
      tarde**, porque para cuando se superó el umbral los primeros cambios ya salieron de a
      uno. La alternativa a evaluar es invertirlo — **debounce de salida**: registrar siempre
      en el libro, esperar una ventana corta de silencio y enviar el lote; si en esa ventana
      se supera el umbral, marcar la sesión de importación y disparar el cierre al vencerla.
      Así el agrupamiento no depende de adivinar a tiempo.

      De ahí cuelga lo que hoy no está definido y hace falta antes de codear: **identidad del
      lote, ACK por movimiento, y que el cierre sea un job diferido e idempotente** y no
      dependa del request que hizo la importación. Si el importador muere a mitad, o el evento
      de "fin" nunca llega, hoy no pasa nada y nadie se entera.

- [ ] **¿Qué pasa cuando las dos tiendas están configuradas distinto para el mismo producto
      vinculado?**
      Bloquea: el comportamiento de UC-01, UC-02 y UC-04 ante asimetrías, y el ruido que eso
      genera en la reconciliación de UC-06. El SPEC hoy asume configuración simétrica y no
      dice nada de los dos casos concretos: que A **gestione stock** de un SKU y B no —B no
      tiene dónde aplicar el movimiento—, y que A acepte reservas y B no —B no puede ir a
      negativo y queda desalineada de forma permanente, reportando discrepancia en cada
      reconciliación—. Hay que decidir tres cosas: si la asimetría **bloquea** la aplicación
      del movimiento o lo aplica a medias, si se **registra** como inconsistencia visible en
      la pantalla de UC-05, y si **se recupera sola** cuando el dueño corrige la
      configuración. La restricción dura es que el plugin **nunca debe normalizar en silencio
      el comportamiento de B**: cambiarle la configuración a una tienda para que el movimiento
      entre sería una escritura que el dueño no pidió y no vería.
- [ ] **¿La comparación de SKU distingue mayúsculas de minúsculas?** Bloquea: las
      sugerencias de UC-03 y la detección de duplicados. Con la colación habitual de
      WordPress (`utf8mb4_unicode_ci`) la consulta por `_sku` ya es insensible, así que
      `ZAP-001` y `zap-001` se proponen solas y **hoy la respuesta de hecho es "no
      distingue"**, sin que nadie lo haya decidido. Hacerla sensible exige forzar `BINARY` en
      la comparación, con su costo en índices. Hay que elegir entre respetar el comportamiento
      de WooCommerce o ser estricto. **Dejó de ser riesgoso con ADR-004 y ADR-005:** lo único
      en juego es qué pares se **sugieren**; un cambio de criterio no puede romper un par ya
      vinculado ni desincronizar nada — a lo sumo cambia una lista de sugerencias.
- [ ] **¿Cómo se autentican las tiendas entre sí?** No bloquea el SPEC pero sí el primer
      código de transporte. Candidatos a evaluar en un ADR: claves de la REST API de
      WooCommerce y _Application Passwords_ de WordPress core.
- [ ] **Estado del arte de los plugins existentes.** Revisar antes de la Fase 2 qué hacen
      hoy las soluciones de multi-tienda del ecosistema y qué se les critica, para
      contrastar las decisiones de §3.
