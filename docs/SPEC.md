# Woonderful Stock Sync for WooCommerce — Especificación funcional

> Estado: Estable
> Última actualización: 2026-09-05

Este documento se completa **antes** de escribir la primera línea de código. El gate de
edición exige que exista con al menos un caso de uso (`UC-01`) antes de permitir escribir
archivos `.php`/`.js` del plugin.

Regla de oro: si un campo no se puede completar, no está listo para codear. Cada campo que
falta es una iteración de corrección garantizada.

---

## 1. Problema y usuario

**Quién:** el dueño de un comercio que opera **dos o más tiendas WooCommerce propias**
(por marca, por país, o mayorista/minorista) y despacha todas desde **un mismo depósito
físico**. Es dueño de todos los sitios: no hay terceros involucrados.

**Qué hace hoy a mano:** reparte el stock real entre las tiendas para no sobrevender —
10 unidades reales se publican como 5 y 5, o se deja un colchón fijo por tienda. Cada
reposición o corrección obliga a recalcular ese reparto y cargarlo tienda por tienda.

**Qué pasa si no existe el plugin:** **lucro cesante.** El colchón evita la sobreventa a
costa de esconder stock que sí existe: una tienda muestra "agotado" con unidades
disponibles en el depósito, y esa venta se pierde. El costo no se ve en ningún reporte —
son ventas que nunca ocurrieron — y crece con cada tienda que se suma, porque el mismo
stock se divide entre más canales.

---

## 2. Alcance

### Entra en v1

- **Sincronización de la cantidad de stock** y del estado derivado (en stock / agotado /
  disponible bajo pedido) entre dos o más tiendas WooCommerce propias. La cantidad puede ser
  negativa en los productos que aceptan reservas.
- **Pool único, sin reparto:** todas las tiendas publican el stock real completo. Si hay
  10 unidades, las 10 se ven en cada tienda; una venta en cualquiera de ellas las deja en
  9 en todas.
- **Productos simples y variaciones.** Cada variación lleva su propio stock.
- **Propagación ante cualquier cambio de stock**: una venta, una devolución, una edición
  manual en el admin, o un cambio hecho por la API REST, WP-CLI o el importador CSV.
- **Log de sincronizaciones y de errores recientes**, en texto, consultable desde el admin.
- **Aviso por email de inconsistencias de SKU nuevas**, para enterarse sin entrar al panel.
- **Reconciliación manual** disparable desde la pantalla, sin esperar a la tarea programada.
- **Pantalla de ajustes** para dar de alta y conectar las tiendas entre sí, y para designar
  cuál es la **tienda maestra**.
- **Pantalla de estado de sincronización** (React), **de solo lectura**: una tabla con
  filtros y buscador que muestra qué productos se sincronizan y cuáles no, con el motivo de
  cada exclusión y el historial de movimientos por producto. La única escritura que permite
  es **cargar el SKU** de un producto que no lo tiene.

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
- **Emparejamiento manual de productos** (vincular a mano dos productos con SKU distinto,
  como hace la pantalla de *Productos* de woonderful-sync) — **por qué:** el SKU es la
  clave común entre tiendas propias, y derivar el par del SKU evita mantener una tabla de
  mapeo, que sería un segundo problema de sincronización encima del de stock. La pantalla
  de v1 es informativa: muestra qué quedó afuera para que se corrija el SKU.
- **Administrar stock desde el plugin** (editar cantidades en línea, conteo físico,
  edición en lote, alertas de stock bajo, exportación) — **por qué:** convierte el plugin
  en un gestor de inventario, que es otro producto y otro tamaño. El stock se sigue
  editando en el admin de WooCommerce, y el plugin se limita a propagar el cambio. La
  pantalla es de diagnóstico, no de gestión.
- **Avisos por email de errores de sincronización y de tiendas caídas** — **por qué:** el
  único email de v1 es el de inconsistencias de SKU (UC-08). Sumar notificaciones de fallos
  sin un criterio de agrupación pensado termina llenando la casilla del usuario y logrando
  que filtre todos los mails del plugin. Se resolverá aparte, con su propio diseño.
- **Sincronizar pedidos y clientes** (panel unificado de ventas) — **por qué:** es otro
  producto. Este plugin sincroniza stock, no gestiona ventas.

---

## 3. Fuente de verdad

**La topología es una malla entre pares.** Para propagar los cambios de stock todas las
tiendas son iguales y se avisan entre sí: ninguna manda sobre las demás y ninguna es punto
único de falla. Una de ellas se designa **maestra**, pero su papel se limita al arranque de
cada producto y a la coordinación —se detalla más abajo— y no interviene en los movimientos.

Lo que sostiene esa decisión es **qué viaja por el cable**: no el stock resultante, sino el
**movimiento** que lo cambió.

**Unidad de sincronización — el movimiento.** Cada cambio de stock genera un movimiento con
un **identificador único global** (tienda de origen + secuencia) y un delta: `venta #123 en
la tienda B → -1`. Las tiendas se propagan movimientos, nunca totales.

De ahí salen tres propiedades que la malla necesita para funcionar:

- **Los deltas no se pisan.** Dos ventas simultáneas en tiendas distintas dan `-2`, que es
  lo correcto. Con valores absolutos, ambas escribirían "quedan 9" y se perdería una unidad.
- **Se pueden aplicar en cualquier orden.** No hace falta que los mensajes lleguen
  ordenados.
- **Son idempotentes por su ID.** Un movimiento ya aplicado se ignora si vuelve a llegar.
  Eso es lo que corta los bucles de la malla (A avisa a B, B avisa a A) sin necesidad de
  un contador de saltos.

**Reconciliación periódica.** El punto débil de los deltas es el mensaje perdido: si no
llega, el desvío queda pegado para siempre. Por eso, cada cierto tiempo las tiendas comparan
qué movimientos conoce cada una.

**Desempate — el libro de movimientos.** Cuando dos tiendas discrepan, **no gana ninguna por
ser quien es**. El valor correcto se reconstruye: es el stock base más la suma de los
movimientos únicos conocidos entre todas. La reconciliación no adivina un número: identifica
**exactamente qué movimiento le falta a quién** y se lo entrega.

**El SKU es el identificador global.** El movimiento no viaja con el ID interno del
producto, que es local a cada instalación, sino con el SKU: *"SKU `ZAP-001` → -1"*. Cada
tienda lo resuelve contra su propio catálogo. Así ninguna necesita conocer los IDs de las
otras, y no hace falta mantener una tabla de emparejamiento sincronizada además del stock.
El emparejamiento se define en UC-03.

**La tienda maestra — bootstrap y coordinación, no movimientos.** Una de las tiendas se
designa como maestra. Su papel es acotado y deliberado:

- **Fija el stock base.** Cuando un producto entra a la malla por primera vez, su stock
  inicial es el que tiene en la maestra; las demás tiendas se **alinean a ese valor**, no lo
  suman (UC-07).
- **Es la tienda donde conviene importar los CSV**, por convención más que por necesidad
  técnica: cualquier tienda puede importar y propagar, pero fijar una sola evita cargar el
  mismo archivo dos veces (§5).
- **Programa la reconciliación nocturna**, lo que evita el problema de qué huso horario es
  "la madrugada" cuando hay tiendas en países distintos. La reconciliación manual y la de
  cierre de importación las puede lanzar cualquier tienda.

**La maestra no interviene en los movimientos.** Ventas, devoluciones y ajustes siguen
viajando entre pares: si la maestra está caída, las demás tiendas se siguen sincronizando
entre sí con total normalidad.

**Qué se suspende mientras la maestra está caída.** Dos cosas, y conviene tenerlas
presentes porque son el precio de haberle dado un rol:

- **La entrada de productos nuevos a la malla** (UC-07), porque solo ella fija el stock base.
- **La reconciliación periódica** (UC-06), que solo ella programa. La red de seguridad
  automática queda suspendida: un movimiento que la cola no logró entregar espera a que la
  maestra vuelva. El dueño sí puede lanzar una **reconciliación manual** desde cualquier
  tienda mientras tanto (UC-06).

Técnicamente la reconciliación no requiere autoridad —la fase 1 es un intercambio entre
pares y la fase 2 solo necesita que todas las tiendas respondan—; que la dispare la maestra
es una decisión de coordinación, para que no corran todas a la vez haciendo el mismo
trabajo.

**Entrega en dos niveles: la cola es el camino rápido, el libro es la garantía.** Cada
movimiento se propaga por una cola con reintentos. Si la cola agota los reintentos —una
tienda apagada, por ejemplo— **abandona ese envío y lo registra en el log**, sin reintentar
indefinidamente. El movimiento no se pierde: sigue en el libro de la tienda de origen y la
reconciliación lo entrega cuando la otra vuelva. Insistir para siempre sería acumular
trabajo en el cron para hacer algo que la reconciliación resuelve igual.

**El bootstrap es la única escritura de valor absoluto de todo el plugin.** Ocurre una sola
vez por producto, al entrar a la malla. De ahí en adelante, solo deltas.

Consecuencias directas, que van a ADR antes de codearse:

- Cada tienda mantiene una **tabla propia de movimientos** (no basta con el `_stock` de
  WooCommerce, que solo guarda el resultado).
- Esa tabla necesita una **política de retención**: no puede crecer para siempre.
- El **stock base** de un producto es el valor fijado en su bootstrap; a partir de ahí, el
  stock esperado es `stock base + suma de los movimientos posteriores al bootstrap vigente`.
- **Purgar movimientos obliga a consolidar el stock base.** Si a los 90 días los movimientos
  se borran sin más, la fórmula de arriba deja de reconstruir el total y la fase 2 de UC-06
  reportaría discrepancias falsas en todos los productos con historia. Al purgar, el stock
  base tiene que absorber la suma de lo purgado, de modo que el resultado no cambie. Es una
  restricción dura sobre el diseño de la retención, y va al ADR del esquema de BD.

### Prior art — de dónde viene este diseño

Ninguna de las piezas de arriba es nueva. Cada una tiene nombre propio en la literatura y
está probada fuera de WordPress; lo específico de este plugin es la combinación.

| Decisión de §3 | Nombre en inglés | Origen |
|---|---|---|
| El stock se deriva de un libro de movimientos | **Stock ledger** / *event sourcing* | Contabilidad de inventario; modelo estándar en ERPs (Odoo, NetSuite, SAP) |
| Movimientos con ID único que no se aplican dos veces | **Idempotent consumer** sobre entrega *at-least-once* | *Enterprise Integration Patterns* (Hohpe & Woolf, 2003) |
| Reconciliación periódica que repara desvíos | **Anti-entropy** / *read repair* | Amazon Dynamo (2007); Cassandra, Riak |
| Deltas que convergen sin importar el orden de llegada | **PN-Counter**, un *CRDT* | Shapiro, Preguiça, Baquero & Zawirski (2011) |
| No perder el movimiento si falla el envío | **Outbox pattern** | Práctica estándar en arquitecturas de microservicios |

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

### UC-01 — Una venta descuenta stock en todas las tiendas

Como dueño de varias tiendas quiero que una venta en cualquiera de ellas descuente el stock
en las demás, para publicar el stock real completo en todas sin sobrevender.

El descuento se propaga **cuando WooCommerce ya lo aplicó localmente**, es decir al pasar el
pedido a *procesando* (pago confirmado), no antes.

**Criterios de aceptación:**

- [ ] Dado un producto emparejado con stock 10 en las tiendas A y B, cuando un pedido de 1
      unidad en A pasa a *procesando*, entonces se encola un movimiento `-1` y, una vez que la
      cola lo procesa, el stock en B queda en 9.
- [ ] Dado ese mismo producto, cuando se crea en A un pedido que queda *pendiente de pago*,
      entonces el stock en B **no cambia**.
- [ ] Dado un producto variable, cuando se vende la variación *Talle M*, entonces en B baja
      el stock de *Talle M* y ninguna otra variación se modifica.
- [ ] *(caso borde — idempotencia)* Dado que el movimiento `M-A-77` ya fue aplicado en B,
      cuando ese mismo movimiento vuelve a llegar, entonces el stock de B no vuelve a bajar.
- [ ] *(fallo — tienda inaccesible)* Dado que B no responde en el momento de la venta en A,
      entonces el movimiento queda pendiente y se reintenta; cuando B vuelve a estar
      disponible, su stock queda en 9 y el descuento se aplica **una sola vez**.
- [ ] *(caso borde — sin par)* Dado un producto que existe en A pero no está emparejado en
      B, cuando se vende en A, entonces no se propaga nada y **no** se escribe una entrada de
      log por cada venta: el producto ya figura como inconsistencia en la pantalla (UC-03) y
      un log por venta convertiría un catálogo con productos sin par en ruido permanente.
- [ ] *(caso borde — reservas)* Dado un producto que acepta reservas con stock 0 en ambas
      tiendas, cuando se venden 3 unidades en A, entonces el pool queda en **-3** en todas
      las tiendas, y cada una muestra el estado que corresponda según su propia
      configuración de reservas.
- [ ] *(caso borde — salir del negativo)* Dado ese producto en -3, cuando se reponen 10
      unidades, entonces el pool queda en **7** en todas las tiendas: las reservas
      pendientes se descuentan una sola vez.

**Fuera de este caso:** las devoluciones y los reembolsos, la edición manual de stock desde
el admin, y la reconciliación periódica. Cada uno es su propio `UC`.

### UC-02 — Una devolución o cancelación repone stock en todas las tiendas

Como dueño de varias tiendas quiero que la mercadería que vuelve al depósito reaparezca
disponible en todas las tiendas, para no dejar de vender unidades que sí tengo.

La regla es la misma que en UC-01: **se propaga lo que WooCommerce ya aplicó localmente.**
Si el operador marcó *"Reponer artículos reembolsados"*, viaja el `+N`; si no la marcó,
no viaja nada. El criterio sobre la mercadería que vuelve rota queda donde corresponde:
en la persona que recibe la devolución.

**Criterios de aceptación:**

- [ ] Dado un producto emparejado con stock 9 en A y en B, cuando en A se reembolsa 1
      unidad **con** *"Reponer artículos reembolsados"* marcado, entonces se encola un
      movimiento `+1` y, una vez que la cola lo procesa, el stock en B queda en 10.
- [ ] Dado ese mismo reembolso **sin** la casilla marcada, entonces el stock en B **no
      cambia** (tampoco cambió en A).
- [ ] Dado un pedido de 3 unidades, cuando se reembolsan 2 con reposición, entonces en B
      el stock sube exactamente 2.
- [ ] Dado un pedido en A que WooCommerce repone al pasar a *cancelado*, entonces esa
      reposición se propaga a B como cualquier otra.
- [ ] *(caso borde — idempotencia)* Dado un movimiento de reposición ya aplicado en B,
      cuando vuelve a llegar, entonces el stock de B no vuelve a subir.
- [ ] *(fallo — tienda inaccesible)* Dado que B no responde cuando se procesa el reembolso
      en A, entonces el movimiento queda pendiente y se reintenta; al volver B, su stock
      queda en 10 y la reposición se aplica **una sola vez**.

**Fuera de este caso:** decidir si la mercadería devuelta está en condiciones de volver a
venderse, y la reposición por ingreso de compra al depósito (eso es edición manual).

### UC-03 — Emparejamiento de productos por SKU

Como dueño de varias tiendas quiero que el plugin sepa solo qué producto es el mismo en cada
tienda, y que me muestre claramente cuáles quedaron afuera, para no descubrir tarde que algo
no se estaba sincronizando.

**La regla es única y sin excepciones: mismo SKU, mismo producto.** No hay vinculación
manual en v1. Lo que sí hay es una pantalla que explica, producto por producto, por qué algo
no se sincroniza.

**El emparejamiento es derivado, no almacenado.** No existe una tabla de pares que haya que
mantener sincronizada: como el movimiento viaja identificado por SKU (§3), la tienda que lo
recibe simplemente busca ese SKU en su propio catálogo y lo aplica si lo encuentra. El
"estado de emparejamiento" que muestra la pantalla es una vista calculada para diagnóstico,
no un dato que el plugin necesite para funcionar.

**Criterios de aceptación:**

- [ ] Dado un producto con SKU `ZAP-001` en las tiendas A y B, cuando el plugin evalúa el
      catálogo, entonces queda emparejado sin ninguna intervención del usuario.
- [ ] Dado un producto variable, cuando cada variación tiene su propio SKU presente en ambas
      tiendas, entonces cada variación se empareja por separado.
- [ ] *(caso borde — sin SKU)* Dado un producto con el SKU vacío, entonces no se sincroniza
      y aparece en la pantalla de estado con el motivo **"sin SKU"**. Esto aplica también a
      la variación sin SKU aunque el producto padre sí tenga.
- [ ] *(caso borde — sin par)* Dado un producto con SKU en A que no existe en B, entonces no
      se sincroniza y aparece con el motivo **"SKU sin coincidencia en la tienda B"**,
      clasificado como **inconsistencia** y no como simple exclusión: un SKU cargado que no
      matchea casi siempre es un error de tipeo o un SKU cargado en una sola tienda, y el
      dueño cree que ese producto sincroniza cuando no lo hace.
- [ ] Dado un producto **sin SKU**, entonces **no** se clasifica como inconsistencia: es un
      producto que no participa de la sincronización, un estado normal que no genera avisos.
- [ ] *(caso borde — ambigüedad)* Dado que dos productos de la **misma** tienda comparten el
      SKU `ZAP-001`, entonces **ninguno de los dos se sincroniza** y ambos aparecen con el
      motivo **"SKU duplicado"**. El plugin nunca elige uno por su cuenta.
- [ ] Dado un producto que figuraba como "sin SKU", cuando el dueño le carga un SKU que
      existe en la otra tienda, entonces en la siguiente evaluación queda emparejado y
      empieza a sincronizar, sin ningún paso adicional.
- [ ] Dado el listado de la pantalla de estado, cuando el dueño lo consulta, entonces puede
      distinguir los productos sincronizados de los excluidos y ver el motivo de cada
      exclusión.

**Fuera de este caso:** vincular a mano dos productos con SKU distinto, marcar un producto
como "no sincronizar", y romper un par mal armado. Las tres quedaron fuera de v1 (§2).

### UC-04 — Un cambio manual de stock se propaga como ajuste

Como dueño quiero que cualquier cambio de stock que haga fuera de una venta —una recepción
de mercadería, una corrección, una importación— llegue a todas las tiendas, para que el pool
refleje siempre lo que hay en el depósito.

**Se propaga la diferencia, no el valor.** Si el producto pasa de 10 a 60, viaja un
movimiento `+50`, igual que una venta o una devolución. Hay un solo tipo de movimiento en
todo el plugin. La consecuencia buscada es que un ajuste manual **nunca pisa una venta
simultánea**: si mientras tanto se vendió 1 en otra tienda, el resultado es 59, que es lo
correcto.

**Se detecta el cambio venga de donde venga**, siempre que pase por el CRUD de productos de
WooCommerce: el admin, la API REST, WP-CLI y el importador CSV nativo. La detección compara
el valor anterior contra el nuevo al guardarse el producto.

**Hay un único punto de detección para todo el plugin.** Las ventas (UC-01) y las
devoluciones (UC-02) también cambian el stock a través de WooCommerce, así que pasan por
este mismo detector: **un cambio de stock genera exactamente un movimiento**, nunca dos. Lo
que varía es el **motivo**, que se deduce del contexto —si hay un pedido asociado, es venta
o devolución; si no lo hay, es un ajuste manual— y queda guardado en el libro para el
historial de UC-05. Sin esta regla, una venta correría el riesgo de registrarse dos veces:
una por el evento del pedido y otra por la comparación de valores.

**Importaciones masivas.** Ante una ráfaga (muchos cambios en poco tiempo), el plugin entra
en **modo importación**: registra los movimientos en su libro local pero deja de propagarlos
de a uno, y al terminar la ráfaga dispara una reconciliación que alinea todas las tiendas de
una sola vez.

**El modo importación cambia el transporte, nunca el contenido.** Se siguen generando y
propagando **deltas**, agrupados en lotes; en ningún caso se envía el stock total. La
reconciliación del final entrega los movimientos que a la otra tienda le faltan, no una
foto del inventario.

Ejemplo de por qué: el producto X está en 10 en A y en B; una importación en A lo lleva a
50 (movimiento `+40`) y, mientras corre, en B se vende 1 unidad (B queda en 9). Con delta,
B aplica `+40` sobre 9 y termina en **49**: la venta se conservó. Enviando el total, B
quedaría en 50 y esa venta se habría perdido — la sobreventa que el plugin viene a evitar,
justo en el momento de mayor cantidad de escrituras.

**Criterios de aceptación:**

- [ ] Dado un producto emparejado con stock 10 en A y B, cuando en el admin de A se cambia
      el stock a 60, entonces se encola un movimiento `+50` y, una vez que la cola lo
      procesa, B queda en 60.
- [ ] Dado ese mismo cambio de 10 a 60 en A, cuando simultáneamente se vende 1 unidad en B,
      entonces ambas tiendas terminan en 59 y **ninguna de las dos operaciones se pierde**.
- [ ] Dado un cambio de stock hecho por la **API REST** de WooCommerce, entonces se propaga
      igual que si se hubiera hecho desde el admin.
- [ ] Dado un cambio hecho con **WP-CLI**, entonces se propaga igual.
- [ ] *(volumen)* Dada una importación CSV de 1.000 productos en A, entonces el plugin entra
      en modo importación y **no** emite una petición por producto: los movimientos viajan
      agrupados en lotes.
- [ ] *(volumen — contenido)* Dada esa misma importación, cuando se inspecciona lo que se
      envía a B, entonces son **deltas** por producto y en ningún caso el stock total.
- [ ] *(volumen — venta concurrente)* Dado el producto X en 10 en ambas tiendas, cuando una
      importación en A lo lleva a 50 y durante la importación se vende 1 unidad en B,
      entonces ambas tiendas terminan en **49** y la venta no se pierde.
- [ ] *(fallo — invisible)* Dado un cambio de stock escrito directamente en la base de datos
      sin pasar por WooCommerce, entonces el plugin **no lo detecta en el momento** y no
      propaga nada; la siguiente reconciliación periódica **detecta y reporta** el desvío,
      sin corregirlo por su cuenta (UC-06).
- [ ] *(caso borde — sin cambio real)* Dado que se guarda un producto sin modificar su
      stock, entonces no se genera ningún movimiento ni se propaga nada.

**Fuera de este caso:** la pantalla de estado (UC-05) y el mecanismo de reconciliación en
sí (UC-06). El stock se edita en el admin de WooCommerce: el plugin no ofrece ninguna
interfaz propia para cambiarlo.

**Riesgo conocido, sin defensa automática:** importar **el mismo CSV en dos tiendas** genera
dos ajustes independientes que se suman (de 10 a 50 en cada una deja 90, no 50). El plugin
puede detectar importaciones masivas simultáneas y avisar, pero no puede impedirlo ni
deducir que era el mismo archivo. La regla de uso correspondiente está en §5.

### UC-05 — Pantalla de estado de sincronización

Como dueño quiero ver de un vistazo qué productos se están sincronizando y cuáles no, y por
qué, para poder confiar en el plugin y corregir lo que quedó afuera.

**Es una vista de solo lectura.** El stock no se administra desde acá: se edita en el admin
de WooCommerce como siempre, y el plugin propaga el cambio (UC-04). La única escritura que
la pantalla permite es **cargar el SKU** de un producto que no lo tiene, porque es la
condición para que ese producto entre a sincronizar (UC-03).

La organización sigue el modelo de la pantalla de *Productos* de `woonderful-sync`: tabla
paginada, buscador y filtros por estado.

**Criterios de aceptación:**

- [ ] Dado el listado, cuando el dueño lo abre, entonces cada fila muestra el SKU, el
      nombre, el stock actual y el estado de sincronización, y las filas excluidas muestran
      su motivo (*sin SKU*, *SKU sin coincidencia*, *SKU duplicado*).
- [ ] Dado el listado, cuando el dueño filtra por estado (*todos*, *sincronizados*, *sin
      sincronizar*, *inconsistencias de SKU*), entonces solo se muestran las filas que
      coinciden.
- [ ] Dado el filtro de *inconsistencias de SKU*, cuando el dueño lo aplica, entonces ve
      únicamente los productos que **tienen SKU cargado y no encuentran coincidencia** del
      otro lado, sin mezclarlos con los que simplemente no tienen SKU.
- [ ] Dado el listado, cuando el dueño busca por nombre o por SKU, entonces solo se muestran
      las filas que coinciden.
- [ ] Dado un producto con el motivo *sin SKU*, cuando el dueño le carga un SKU desde la
      pantalla y guarda, entonces el SKU queda escrito en el producto de WooCommerce y, si
      coincide con uno de la otra tienda, en la siguiente evaluación el producto queda
      emparejado (UC-03).
- [ ] Dado un producto, cuando el dueño abre su historial, entonces ve sus movimientos con
      fecha, tienda de origen, cantidad y motivo (bootstrap, venta, devolución, ajuste
      manual, reconciliación).
- [ ] *(caso borde)* Dado que el SKU cargado ya existe en otro producto de la **misma**
      tienda, cuando el dueño intenta guardarlo, entonces la pantalla lo rechaza indicando
      que generaría un *SKU duplicado*, y no lo escribe.
- [ ] *(solo lectura)* Dado cualquier producto del listado, cuando el dueño lo consulta,
      entonces **no** existe forma de modificar su cantidad de stock desde esta pantalla.
- [ ] *(fallo)* Dado que la escritura del SKU falla en WooCommerce, cuando el dueño guarda,
      entonces la pantalla muestra el error en la fila afectada y el listado no queda en un
      estado inconsistente.

**Fuera de este caso:** editar cantidades de stock, conteo físico, edición en lote, alertas
de stock bajo y exportación — todo eso quedó fuera de v1 (§2). Tampoco entra vincular a mano
dos productos con SKU distinto (§2, UC-03).

### UC-06 — Reconciliación

Como dueño quiero que las tiendas se revisen entre sí cada cierto tiempo y reparen lo que se
haya perdido, para que un mensaje caído no me deje desalineado para siempre sin que yo me
entere.

La reconciliación se dispara de tres maneras:

- **Periódica:** la lanza la tienda maestra una vez por día, en su madrugada.
- **De cierre de importación** (UC-04): la lanza la tienda que importó, sea cual sea, apenas
  termina la ráfaga. Esperar a la pasada nocturna dejaría desalineado al resto durante horas.
- **Manual:** el dueño la lanza desde la pantalla de estado, en cualquier momento y desde
  cualquier tienda, sin esperar al cron. Es la salida cuando algo se ve raro y no se quiere
  esperar hasta la madrugada, y también la única forma de reconciliar mientras la maestra
  está caída.

En los tres casos corre en **dos fases, donde el orden importa**.

**Fase 1 — Reparar lo explicable.** Las tiendas comparan qué movimientos conoce cada una.
Todo movimiento que a alguna le falte se le entrega y se aplica. Esto repara el caso normal
—una propagación que falló— con el dato real, sin inventar ningún ajuste.

**Fase 2 — Detectar lo inexplicable.** Solo cuando todas las tiendas tienen ya el mismo
libro completo, se compara el stock local contra `stock base + suma de los movimientos
posteriores al bootstrap vigente`. Si
no coincide, hubo una escritura que nadie registró (el caso invisible de UC-04). Ahí el
plugin **no toca nada**: registra la discrepancia y la muestra, porque no tiene forma de
saber qué pasó ni cuál de los dos números es el correcto.

**Salvaguarda — libro incompleto, no se concluye nada.** Si alguna tienda no respondió, la
**fase 1 se ejecuta igual** con las tiendas disponibles —reparar lo que se pueda nunca hace
daño— pero la **fase 2 no corre**. Un movimiento que no llegó por una tienda caída se vería
como un desvío inexplicable, y reportarlo sería un falso positivo.

**Criterios de aceptación:**

- [ ] Dado que a la tienda B le falta el movimiento `M-A-77`, cuando corre la reconciliación,
      entonces B lo recibe y lo aplica, y ambas tiendas quedan con el mismo stock.
- [ ] Dado que el movimiento faltante ya había sido aplicado en B por otra vía, cuando la
      reconciliación se lo entrega, entonces B lo ignora por su ID y el stock no cambia.
- [ ] Dado que no falta ningún movimiento y los stocks coinciden, cuando corre la
      reconciliación, entonces no se genera ningún cambio ni ninguna alerta.
- [ ] *(caída prolongada)* Dado un producto en 10 en ambas tiendas, cuando la maestra queda
      caída una hora y durante ese lapso la tienda B vende 3 unidades (B queda en 7 y la
      maestra sigue en 10), entonces al correr la reconciliación la maestra **recibe y aplica
      los tres movimientos** y queda en 7. En ningún caso impone su propio valor de stock
      sobre B: el número de la maestra no es una verdad, es el resultado de los movimientos
      que conoce.
- [ ] *(caída prolongada — bidireccional)* Dado ese mismo escenario, cuando además la maestra
      registró ventas propias durante la caída, entonces tras la reconciliación ambas tiendas
      reflejan **todos** los movimientos de ambos lados y ninguno se pierde.
- [ ] Dado un stock local de 47 con un libro completo que suma 50, cuando corre la fase 2,
      entonces el plugin **no modifica el stock de ninguna tienda** y registra una
      discrepancia indicando producto, tienda, valor esperado y valor encontrado.
- [ ] *(salvaguarda)* Dado que la tienda B no responde, cuando corre la reconciliación,
      entonces se aplica lo que se pueda de la fase 1 con las tiendas disponibles, la fase 2
      **no se ejecuta**, y no se reporta ninguna discrepancia.
- [ ] *(cierre de importación)* Dada una importación masiva que acaba de terminar (UC-04),
      cuando se dispara la reconciliación de cierre, entonces las tiendas quedan alineadas
      sin haber recibido una petición por producto.
- [ ] Dado que existe una discrepancia reportada, cuando el dueño consulta la pantalla de
      estado, entonces la ve listada con su detalle.
- [ ] *(manual)* Dado que el dueño pulsa *reconciliar ahora* en la pantalla de estado,
      entonces la reconciliación corre de inmediato con las mismas dos fases y las mismas
      salvaguardas que la periódica, y la pantalla informa el resultado.
- [ ] *(manual — maestra caída)* Dado que la maestra no responde, cuando el dueño lanza la
      reconciliación manual desde una tienda secundaria, entonces la fase 1 se ejecuta con
      las tiendas disponibles y la fase 2 **no** corre, igual que en la periódica.
- [ ] *(manual — solapamiento)* Dado que ya hay una reconciliación en curso, cuando se lanza
      otra, entonces no se inicia una segunda: la pantalla informa que hay una corriendo.
- [ ] *(manual — permisos)* Dado un usuario sin la capacidad requerida, cuando intenta lanzar
      la reconciliación, entonces la petición se rechaza y no se ejecuta nada.

**Comportamiento durante una discrepancia abierta:** el producto **sigue sincronizando
normalmente** — las ventas y los ajustes se propagan como siempre. El desvío se mantiene
constante hasta que alguien lo resuelva, pero no crece ni bloquea la operación.

**Fuera de este caso:** resolver la discrepancia. Corregirla es una decisión humana: el
dueño edita el stock en la tienda que corresponda y ese ajuste se propaga por UC-04.

### UC-07 — Un producto entra a la malla (bootstrap)

Como dueño quiero que un producto nuevo empiece a sincronizarse con el stock correcto, para
que sumar un artículo al catálogo no me duplique ni me esconda mercadería.

> **Bootstrap** (*alta en la malla*): el momento en que se declara el punto de partida de un
> producto. Todo el resto del plugin trabaja con diferencias, y una diferencia solo tiene
> sentido si existe un número desde el cual contar. Antes del bootstrap hay dos cantidades
> independientes que nadie sabe si son el mismo depósito; después hay un pool con un valor
> inicial —el **stock base**— y una historia de movimientos sobre él.

Un producto entra a la malla en tres situaciones: al **instalar el plugin** sobre tiendas
que ya tienen catálogo y stock, al **dar de alta un producto nuevo** con el mismo SKU en
ambas tiendas, y cuando un producto excluido **consigue par** al cargarle el SKU que le
faltaba (UC-03).

En las tres, el plugin se encuentra con un producto que tiene stock en cada tienda y ningún
movimiento previo. **El stock inicial es el de la tienda maestra**, y las demás se **alinean
a ese valor**. No se suman: es el mismo depósito visto desde dos lugares, no dos depósitos.

Este es el **único momento en que el plugin escribe un valor absoluto**. Ocurre una sola vez
por producto y queda registrado en el libro como un movimiento de tipo *bootstrap*, con el
valor fijado y el stock que cada tienda tenía antes.

**Quién lo emite: siempre la tienda maestra.** Una tienda secundaria nunca da de alta un
producto en la malla; puede pedirlo, no hacerlo. Con un único emisor posible no existe la
carrera de dos bootstraps concurrentes, que generarían dos stocks base para el mismo
producto y dejarían a la reconciliación reportando un desvío fantasma para siempre.

**Cuándo: aviso inmediato, con barrido diario de respaldo.** Cuando en cualquier tienda
aparece un SKU que todavía no tiene par, esa tienda **avisa a la maestra**. Si la maestra ya
tiene ese producto de su lado, fija el stock inicial en el acto. Además, junto con la
reconciliación nocturna, la maestra **barre los productos sin par** e incorpora los que ya
estén en condiciones, por si algún aviso se perdió.

**Criterios de aceptación:**

- [ ] Dado un producto con SKU `ZAP-001` y stock 10 en la maestra y 7 en la tienda B, cuando
      entra a la malla, entonces ambas quedan en **10** y el pool queda en 10 — nunca en 17.
- [ ] *(orden de alta — secundaria primero)* Dado que el producto se crea primero en la
      tienda B, entonces queda *sin par* y no sincroniza; cuando después se crea en la
      maestra con el mismo SKU, la maestra lo detecta al guardarse y fija el stock inicial.
- [ ] *(orden de alta — maestra primero)* Dado que el producto se crea primero en la maestra,
      entonces queda *sin par*; cuando después se crea en la tienda B, **B avisa a la
      maestra** y la maestra fija el stock inicial.
- [ ] *(aviso perdido)* Dado que el aviso de B no llega a la maestra, entonces el producto
      sigue *sin par* hasta el barrido diario, que lo incorpora igual sin intervención.
- [ ] *(carrera)* Dado que dos tiendas avisan a la maestra por el mismo SKU casi al mismo
      tiempo, entonces se emite **un solo** movimiento de bootstrap y el producto queda con
      un único stock base.
- [ ] *(solo la maestra emite)* Dado un movimiento de bootstrap recibido desde una tienda que
      no es la maestra, entonces se rechaza y queda registrado en el log.
- [ ] *(producto exclusivo)* Dado un producto que existe solo en una tienda y nunca va a
      tener par, entonces permanece listado como *sin par* sin generar reintentos indefinidos
      ni ruido en el log.
- [ ] Dado ese bootstrap, cuando se consulta el libro, entonces existe un movimiento de tipo
      *bootstrap* que registra el valor fijado y el stock previo de cada tienda.
- [ ] Dado un producto ya incorporado a la malla, cuando el plugin vuelve a evaluar el
      catálogo, entonces **no** se repite el bootstrap y su stock no se toca.
- [ ] Dado un producto que existe solo en la maestra, entonces no entra a la malla y aparece
      como *sin par* (UC-03); su stock no se modifica en ninguna tienda.
- [ ] Dado un producto sin SKU al que el dueño le carga uno que existe en la maestra, cuando
      entra a la malla, entonces adopta el stock de la maestra y desde ese momento sincroniza
      con normalidad.
- [ ] *(fallo — maestra inaccesible)* Dado que la maestra no responde, cuando un producto
      nuevo estaría en condiciones de entrar, entonces **el bootstrap se pospone**, el
      producto no entra a la malla, su stock no se toca en ninguna tienda, y el resto de los
      productos ya incorporados sigue sincronizando con normalidad.
- [ ] *(caso borde — salida y reingreso)* Dado un producto que salió de la malla porque se le
      borró el SKU, cuando vuelve a tener un SKU con par, entonces se hace un **nuevo
      bootstrap** desde la maestra y el nuevo stock base reemplaza al anterior.
- [ ] *(caso borde — reingreso y cálculo)* Dado ese producto reincorporado, cuando la fase 2
      de UC-06 calcula su stock esperado, entonces suma **solo los movimientos posteriores al
      bootstrap vigente**: los del período anterior siguen visibles en el historial pero no
      entran en el cálculo.

**Riesgo conocido:** el pool arranca con el valor de la maestra, sea cual sea. Si el producto
se crea en la maestra con stock 0 y la mercadería se carga después, el pool arranca en 0 y
las demás tiendas pierden el stock que tenían cargado. El movimiento de *bootstrap* guarda el
stock previo de cada tienda justamente para poder detectarlo y corregirlo, pero el plugin no
lo impide. La regla de uso está en §5.

**Fuera de este caso:** crear el producto en las otras tiendas, que quedó fuera de v1 (§2).
El dueño da de alta el producto en cada tienda con el mismo SKU; el plugin solo resuelve con
qué stock arranca.

### UC-08 — Aviso por email de inconsistencias de SKU

Como dueño quiero enterarme por email cuando un producto que creo que sincroniza en realidad
no lo hace, para no descubrirlo semanas después por una venta perdida.

**Solo se avisa de las inconsistencias nuevas.** Si una inconsistencia ya fue informada y
sigue sin resolverse, **no se vuelve a avisar**. Un recordatorio diario de lo mismo termina
en la carpeta de spam y el aviso pierde todo su valor.

El aviso se arma junto con el barrido nocturno de la maestra y sale como un único resumen.
El destinatario es configurable —por defecto el administrador del sitio— y la notificación
se puede desactivar.

**Criterios de aceptación:**

- [ ] Dado que el barrido detecta 3 inconsistencias nuevas, entonces se envía **un** email
      que las lista e indica el total de inconsistencias pendientes.
- [ ] Dado que al día siguiente siguen esas mismas 3 y no apareció ninguna nueva, entonces
      **no se envía ningún email**.
- [ ] Dado que al día siguiente aparece 1 inconsistencia nueva además de las 3 anteriores,
      entonces se envía un email que detalla **solo la nueva** e indica que hay 4 pendientes.
- [ ] Dado que una inconsistencia se resuelve y tiempo después vuelve a aparecer, entonces
      cuenta como nueva y se avisa otra vez.
- [ ] *(volumen)* Dada una importación que genera 400 inconsistencias nuevas de una vez,
      entonces se envía **un solo** email, con un listado acotado y el total, nunca un email
      por producto.
- [ ] Dado que no hay inconsistencias nuevas, entonces no se envía ningún email — el silencio
      es el estado normal.
- [ ] Dado un producto **sin SKU**, entonces nunca genera un aviso por email.
- [ ] Dado que la notificación está desactivada en los ajustes, entonces no se envía nada,
      aunque las inconsistencias sí se sigan registrando y mostrando en la pantalla (UC-05).

**Fuera de este caso:** avisar de errores de sincronización, de tiendas caídas o de
discrepancias de reconciliación (UC-06). En v1 esos viven en el log y en la pantalla.

---

## 5. Supuestos y restricciones

| Tema | Valor | Notas |
|---|---|---|
| WordPress mínimo | 6.5 | |
| PHP mínimo | 8.1 | |
| WooCommerce mínimo | 8.2 | Versión en la que HPOS pasa a ser el almacenamiento de pedidos por defecto |
| HPOS | Compatible, declarado explícitamente | |
| Multisite | A definir (§6) | |
| Stock negativo | Admitido | Solo en productos configurados para aceptar reservas; el pool refleja lo que se debe |
| HTTPS | Requerido | Las tiendas se comunican entre sí con credenciales; sin TLS viajarían en claro |
| Rate limits externos | N/A | No hay servicios de terceros: las tiendas hablan solo entre ellas |
| Volumen esperado | A definir (§6) | Define el tamaño de los lotes y la retención |
| Retención del libro de movimientos | 90 días | Con ~200 movimientos diarios son unos 18.000 registros |
| Reconciliación periódica | Una vez por día, en la madrugada de la tienda maestra | Más la de cierre de importación (UC-04, UC-06) |

**Latencia de propagación — sin compromiso de tiempo.** El plugin entrega los movimientos
**lo antes posible** a través de una cola de procesamiento, pero **no promete un plazo
máximo**: depende del volumen del catálogo y del servidor de cada cliente. En consecuencia,
ningún criterio de aceptación de §4 se expresa en minutos; se expresan como *"una vez que la
cola procesa el movimiento"*, que es determinista y testeable.

**Supuestos que, si son falsos, invalidan el diseño:**

- **Todas las tiendas despachan del mismo depósito físico.** Si cada tienda tuviera su
  propio stock separado, el pool único no tiene sentido y el plugin no aplica.
- **Todas las tiendas son WooCommerce y del mismo dueño**, con capacidad de instalar el
  plugin en cada una. La malla exige que las dos puntas sean pares que llevan libro.
- **El SKU es único dentro de cada tienda y es la misma cadena entre tiendas.** Es lo que
  hace que el emparejamiento de UC-03 funcione sin tabla de mapeo.
- **Los sitios se alcanzan entre sí por HTTP.** Una tienda detrás de un firewall que no
  acepte peticiones entrantes no puede participar de la malla.

**Restricciones de uso — reglas que el dueño debe respetar:**

- **Un mismo archivo de importación se carga en una sola tienda.** Puede ser cualquiera: la
  propagación de movimientos es simétrica y una importación en una tienda secundaria llega
  igual al resto (UC-04). Lo que no se puede hacer es cargar **el mismo CSV en dos tiendas**,
  porque genera dos ajustes independientes que se suman y duplican el stock. El plugin puede
  detectar importaciones simultáneas y avisar, pero no puede impedirlo ni deducir que era el
  mismo archivo. Por convención conviene usar siempre la maestra, para que sea fácil
  recordar dónde se importa.
- **Los productos nuevos conviene darlos de alta primero en la maestra**, con su stock ya
  cargado. El pool arranca con el valor que tenga la maestra: si el producto se crea allí
  con stock 0 y recién después se carga la mercadería, el pool arranca en 0 (UC-07). Un
  producto que un CSV crea solo en una tienda secundaria queda *sin par* y no sincroniza
  hasta existir también en la maestra.
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
- [ ] **¿A partir de qué umbral se considera una ráfaga?** Bloquea: el modo importación de
      UC-04. Hace falta un criterio concreto (N cambios en M segundos) y decidir si es
      configurable.
- [ ] **¿Cómo se autentican las tiendas entre sí?** No bloquea el SPEC pero sí el primer
      código de transporte. Candidatos a evaluar en un ADR: claves de la REST API de
      WooCommerce y *Application Passwords* de WordPress core.
- [ ] **Estado del arte de los plugins existentes.** Revisar antes de la Fase 2 qué hacen
      hoy las soluciones de multi-tienda del ecosistema y qué se les critica, para
      contrastar las decisiones de §3.
