# Manual de Woonderful Stock Sync

---

# Parte 1 — Inicio rápido

Cuatro pasos para dejar el plugin funcionando.

**Antes de empezar necesitás:** las dos tiendas con WooCommerce instalado, acceso de
administrador a cada una, y el plugin de sincronización instalado en **ambas**.

---

## Paso 1 — Configuración inicial

La primera vez que entrás a **Stock Sync**, el plugin abre solo la configuración inicial. Son
cinco pantallas y se hacen una vez.

> **📷 Captura —** la configuración inicial recién abierta, con los cinco pasos arriba:
> Requisitos · Esta tienda · Emparejar · Avisos · Listo.

### 1.1 Requisitos

Arranca revisando siete cosas de tu servidor. Las que fallan te dicen cómo arreglarlas.

> **📷 Captura —** la lista de requisitos con los tildes verdes.

**Los cinco primeros son obligatorios.** Si alguno está en rojo, el plugin no va a sincronizar
bien: arreglalo y apretá *Volver a revisar*.

**Los dos últimos son recomendaciones**, no bloquean nada:

- **PHP-FPM o LiteSpeed** — sin eso una venta tarda hasta un minuto en salir, en vez de salir
  al instante. Pero funciona igual.
- **El correo sale** — mandá el email de prueba. Si no llega, no vas a recibir los avisos,
  pero no impide el funcionamiento del plugin.

### 1.2 Esta tienda

Dos campos:

- **Nombre** — con el que la nombra la otra tienda y con el que firman los emails. Poné algo
  que distingas de un vistazo, por ej: "Tienda Minorista", "Mayorista", el nombre de la marca, etc.
- **Rol** — y esta es **la decisión importante del plugin**.

> **📷 Captura —** el paso "Esta tienda", con el nombre cargado y las dos tarjetas de rol.

| Rol | Qué significa |
|---|---|
| **Maestra** | Desde acá vinculás los productos y desde acá se lanza todo lo que hay que decidir. **Al vincular, el stock que manda es el de la maestra.** |
| **Secundaria** | Ve exactamente lo mismo, pero en modo lectura. |

**Elegí como maestra la tienda donde ya cargás la mercadería.** Es la regla que te va a
ahorrar problemas: el número que vale es siempre el de la maestra.

> Que una sea maestra **no la hace más importante para las ventas**. Una venta en la
> secundaria descuenta en las dos igual que una venta en la maestra.

### 1.3 Avisos por email

A quién le llegan. Vacío = al administrador del sitio. Podés cambiarlo después.

---

## Paso 2 — Emparejar las dos tiendas

Es un código que se genera de un lado y se pega del otro. Vas a necesitar las dos tiendas
abiertas, en dos pestañas.

**En la maestra:**

1. Elegí **"Esta es la maestra: generar el código"**.
2. Apretá **Generar el código** y copialo.

> **📷 Captura —** el código generado en la maestra, con el aviso de que vale 15 minutos.

**En la otra tienda:**

3. Hacé la configuración inicial igual que en el paso 1, pero en **Rol** elegí **Secundaria**.
4. En Emparejar, elegí **"Esta es la secundaria: pegar el código"**.
5. Pegá el código y apretá **Emparejar**.

> **📷 Captura —** el paso de emparejar en la secundaria, con el campo para pegar el código.

El código **vale 15 minutos y sirve una sola vez**. La pantalla de la maestra se actualiza
sola en cuanto la otra lo canjea.

Cuando terminan, las dos muestran **Emparejada** y la barra lateral dice *Conectada con…*

> **📷 Captura —** las dos tiendas emparejadas, con el punto verde en la barra lateral.

---

## Antes de vincular — el SKU

El **SKU** (*stock keeping unit*) es el código con el que identificás un producto en tu
catálogo: `MUG-350`, `TAZA-BLANCA`, `1024`. Lo elegís vos y WooCommerce no lo usa para nada
más que para buscar y para que vos lo reconozcas.

**En WooCommerce el SKU es opcional**, y en este plugin también: podés vincular dos productos
que no tengan SKU, o que lo tengan distinto en cada tienda.

**Pero conviene usarlo, y que sea el mismo de los dos lados.** Es lo único que le permite al
plugin **proponerte los pares solos**. La diferencia es concreta:

| | Con el mismo SKU en las dos | Sin SKU, o con SKU distinto |
|---|---|---|
| **Al arrancar** | Vinculación masiva: un catálogo entero en una confirmación | A mano, producto por producto |
| **Un producto nuevo** | Aparece sugerido y lo aceptás de un clic | Lo buscás a mano en la otra tienda |

Cuanto peor esté el SKU, más trabajo manual vas a tener **al arrancar**. Después no cambia
nada: una vez vinculado un producto, **el SKU deja de importar** — podés cambiarlo, borrarlo
o pasárselo a otro producto y el par sigue funcionando igual.

> **Cómo se carga un SKU en WooCommerce:** está en la pestaña **Inventario** de cada
> producto. La documentación oficial lo explica en
> [WooCommerce Product Editor settings](https://woocommerce.com/document/managing-products/product-editor-settings/)
> *(en inglés)*, donde además aclara que **cada SKU tiene que ser único dentro de tu tienda**
> — si repetís uno, el plugin no va a poder adivinar cuál es cuál.

---

## Paso 3 — Vincular un producto simple

Estar emparejadas no alcanza: **el plugin todavía no sabe qué producto de una tienda es cuál
en la otra**. Eso lo decidís vos, producto por producto, y se hace **desde la maestra**.

> **¿Qué es un producto simple?** El tipo por defecto de WooCommerce: un artículo único, sin
> variantes — una taza, un libro, una mochila. Lleva un solo precio y un solo stock. Si
> necesitás repasar cómo se crean, está en
> [Adding and Managing Products](https://woocommerce.com/document/managing-products/)
> *(en inglés)*.

Entrá a **Productos**. Vas a ver tu catálogo con el estado de cada uno.

> **📷 Captura —** la pantalla Productos con varias filas sin vincular, una con la sugerencia
> resaltada en amarillo.

Cuando un producto tiene **el mismo SKU en las dos tiendas**, el plugin te lo propone solo:
la fila muestra el producto de la otra tienda y la etiqueta **Sugerido · mismo SKU**.

> ⚠️ **Antes de apretar nada, mirá el stock de la maestra.**
> Al vincular, **el número de la maestra se escribe sobre el de la otra tienda**. Es la única
> vez en toda la vida del producto que el plugin pisa un número en vez de sumar o restar. Si
> en la maestra tenés 0 porque todavía no cargaste la mercadería, las dos van a quedar en 0.

1. Apretá **Aceptar** en la fila.
2. La pantalla te muestra los dos stocks y **con cuál van a quedar las dos**.

> **📷 Captura —** el modal de vincular: *Stock acá 12 · Stock en la otra tienda 9 · Las dos
> quedan con 12 (el de la maestra)*.

3. Si el número es el correcto, apretá **Confirmar**.

La fila pasa a **Vinculado** y desde ese momento cada venta de cualquiera de las dos tiendas
descuenta en las dos.

> **📷 Captura —** la misma fila, ahora en verde y con los dos stocks iguales.

### Si tenés muchos productos

No los vincules de a uno. Arriba a la derecha está **Vinculación masiva**: fija de golpe
todos los pares que tienen **el mismo SKU, único y publicado en las dos tiendas**.

Antes de confirmar te dice cuántos pares va a fijar, te recuerda que todos arrancan con el
stock de la maestra, y cuántos quedan afuera por ser ambiguos.

> **📷 Captura —** el modal de vinculación masiva, con el total y el aviso del stock.

Los que quedan afuera se resuelven de a uno, y de eso habla la Parte 2.

---

## Paso 4 — Vincular una variación

Un producto variable —una remera con talles, una botella con colores— **no se vincula como
producto**. Se vincula **cada variación por separado**, porque cada una lleva su propio stock.

> **¿Qué es un producto variable?** Uno que se vende en versiones distintas del mismo
> artículo: talles, colores, medidas. Cada combinación es una **variación**, y cada variación
> puede tener su propio precio, su propio SKU y su propio stock. Cómo se arman está en
> [Variable Products](https://woocommerce.com/document/variable-product/) *(en inglés)*.

En la lista, el variable es una fila que se despliega. El padre no tiene botones.

> **📷 Captura —** la fila de un variable desplegada, con sus tres variaciones debajo.

1. Apretá la flecha para desplegarlo.
2. Cada variación con sugerencia tiene su propio **Aceptar**, igual que un producto simple.
3. Si varias están sugeridas, el padre muestra **Aceptar 2** (o las que sean) y las vinculás
   todas juntas.

> **📷 Captura —** el botón *Aceptar 2* en la fila del padre.

El padre muestra cómo va: **2/3 vinculadas**, y el estado **Parcial** mientras le falte alguna.

**La condición para vincular una variación es que gestione su propio stock en las dos
tiendas.** Dos trampas habituales, las dos se arreglan en WooCommerce:

- **El stock está cargado en el producto padre** en vez de en cada variación. Hay que pasarlo
  a las variaciones: si no, el plugin no sabría a cuál descontarle una venta.
- **Una variación no gestiona stock en una de las dos tiendas.** Hay que activárselo del lado
  que le falta.

El SKU, en cambio, **no es condición**: sin SKU propio la variación no se sugiere sola, pero
la podés vincular a mano. Esos casos están en la Parte 2.

---

## Listo

Si llegaste hasta acá con tus productos en **Vinculado**, ya está: el plugin trabaja solo.

**De acá en adelante no tenés que hacer nada.** Seguí cargando stock y vendiendo como
siempre, en WooCommerce; el plugin propaga cada cambio a la otra tienda.

**Las tres cosas que conviene que sepas desde el día uno:**

1. **El stock se sigue editando en WooCommerce**, nunca desde el plugin. El plugin propaga,
   no administra inventario.
2. **Un producto nuevo no sincroniza hasta que lo vinculás.** Crearlo en las dos tiendas con
   el mismo SKU sólo lo vuelve candidato. A las 24 horas te llega un email recordándotelo.
3. **Cargá el stock siempre en la maestra.** También funciona si lo cargás en la
   secundaria, pero tener un solo lugar te evita confundirte. Cargar el mismo stock en las
   dos suma dos veces, y es el único error que el plugin no puede deshacer.

---

# Parte 2 — Sección por sección

> Cada sección se puede leer suelta: no hace falta haber leído la anterior. Entrá por la que
> te haga falta.

## 5. Cómo funciona por abajo

No hace falta leer esto para usar el plugin. Está acá porque explica **por qué** hace lo que
hace, y eso vuelve previsibles un par de comportamientos que de otro modo parecen caprichos.

### Pool único: nada de repartir

Las dos tiendas publican **el stock real completo**. Si en el depósito hay 10, las dos
muestran 10 — no 5 y 5. Es un solo depósito visto desde dos lugares.

### Lo que viaja es la diferencia, no el número

Cuando vendés una unidad, a la otra tienda **no le llega "quedan 9": le llega "restá 1"**.

Parece un detalle y es lo que sostiene todo lo demás. Si en ese mismo segundo se vendió otra
unidad en la otra tienda, las dos terminan en **8**, que es lo correcto. Un plugin que mandara
el número entero haría que las dos escribieran "9", y habrías vendido una unidad que no tenés.

Por eso hay cosas que el plugin **no** puede hacer, y conviene saberlo: no puede "copiar" el
stock de una tienda a la otra, porque copiar un número pisa lo que haya pasado en el medio.
Cuando de verdad hace falta igualar dos números, hay una herramienta aparte para eso (§ 9).

### El libro de movimientos

Cada cambio de stock queda anotado como un **movimiento**: cuánto, por qué y cuándo. Ese libro
es el que permite reconstruir de dónde salió cada número, y es lo que ves en el historial de
un producto (§ 12).

Tres propiedades que salen de ahí:

- **Los movimientos no se pisan entre sí.** Dos ventas simultáneas suman −2.
- **Pueden llegar en cualquier orden** y el resultado es el mismo.
- **No se aplican dos veces.** Si uno llega repetido, se ignora.

El libro guarda **90 días**. Al purgar lo viejo, el punto de partida de cada producto absorbe
lo borrado, así que las cuentas siguen dando.

### Dos caminos: la cola y la reconciliación

| | Qué es | Cuándo actúa |
|---|---|---|
| **La cola** | El camino rápido. Manda cada movimiento apenas ocurre | Siempre |
| **La reconciliación** | La red de seguridad. Compara los dos libros y reentrega lo que falte | Cada noche, y cuando la lanzás a mano |

Si la cola funcionó —el caso normal— la reconciliación no tiene nada que hacer. Si un mensaje
se perdió, lo repara. Por eso **un movimiento que no se entrega no se pierde**: queda en el
libro de quien lo emitió hasta que la otra tienda lo reciba.

## 6. Los estados de un producto

En **Productos**, cada fila termina con un estado. Es lo primero que mirás cuando algo no
sincroniza, y lo único que necesitás para saber si tenés que hacer algo.

> **📷 Captura —** la columna Estado con varias filas: un Vinculado, un Sin vincular con su
> motivo debajo, y un Suspendido.

**El estado no es un dato guardado: se calcula cada vez que abrís la pantalla**, mirando tu
catálogo y el de la otra tienda. Eso tiene una consecuencia práctica que conviene saber desde
el principio: **arreglás la causa y el estado se corrige solo.** No hay ningún botón de
"reanudar" ni de "volver a intentar" porque no hace falta.

---

### 6.1 Los cuatro estados

| Estado | Qué significa | ¿Hay que hacer algo? |
|---|---|---|
| **Vinculado** | Tiene par y está sincronizando. Es el estado normal. | No |
| **Sin vincular** | Nadie fijó su par todavía. **Su stock no se sincroniza.** | Sí: vincularlo, o descartarlo si no querés que sincronice |
| **Suspendido** | **Tenía** par y el par se rompió. | Sí, y depende del motivo (§ 6.3) |
| **Sin confirmar** | Se vinculó, pero la respuesta de la otra tienda se perdió. | No: el plugin lo resuelve solo |

Y uno que no es un estado del producto: **Parcial**, que sólo aparece en la fila de un
producto variable y resume cómo van sus variaciones — *2/3 vinculadas*.

> **Sin vincular no es sinónimo de error.** Un producto que existe sólo en una tienda, un
> servicio, una prueba: se quedan ahí para siempre y está bien. Por eso la lista de sin
> vincular es una bandeja de trabajo, no una lista de problemas.

---

### 6.2 Sin vincular: el motivo dice qué le falta

Debajo del estado, la fila te dice **por qué** ese producto todavía no tiene par. Cada motivo
se resuelve distinto:

| Motivo | Qué pasó | Qué hacer |
|---|---|---|
| **Con sugerencia** | Hay un producto con el mismo SKU en la otra tienda | Apretá **Aceptar**. Es el caso fácil |
| **Sin coincidencia** | Ningún producto de la otra tienda tiene ese SKU | Creálo allá, vinculalo a mano, o descartalo |
| **Sin SKU** | Este producto no tiene SKU cargado | Ponele uno, o vinculalo a mano |
| **SKU duplicado** | Dos productos comparten ese SKU | El plugin no elige por vos: arreglá el catálogo o vinculá a mano |
| **No gestiona stock** | Acá no lleva su propio stock y allá sí | Activale "Gestionar stock" en **esta** tienda |
| **En la otra tienda no gestiona stock** | Al revés: acá sí y allá no | Activáselo en **la otra** |
| **Stock en el padre** | Es un variable con el stock cargado en el producto padre | Pasá el stock a cada variación |
| **En la papelera** | El de la otra tienda está en la papelera | Restauralo allá, o vinculá con otro |
| **Sugerencia descartada** | Vos dijiste que no querés sincronizarlo | Nada. Si cambiás de idea, **Deshacer descarte** |

> **📷 Captura —** el filtro Sin vincular abierto, con la fila de motivos: Con sugerencia ·
> Sin coincidencia · Sin SKU · Stock en el padre · SKU duplicado · No gestiona stock · En la
> papelera · Descartados.

**Los motivos son también filtros.** Al entrar en **Sin vincular**, la fila de estados se
reemplaza por la de motivos, y ése es el orden natural para dejar un catálogo limpio: primero
**Con sugerencia**, que se resuelve de a un clic; después **SKU duplicado** y **Sin SKU**, que
se arreglan en WooCommerce; y al final **Sin coincidencia**, que casi siempre significa crear
el producto que falta o descartarlo.

> **Las descartadas están ocultas.** No aparecen en la lista salvo que filtres por
> **Descartados** — ya las resolviste, no tienen por qué seguir pidiendo atención. El buscador
> sí las encuentra siempre.

---

### 6.3 Suspendido: el par se rompió

Suspendido significa que el producto **tenía** par y algo lo rompió. **El par, el stock base y
el historial se conservan**: no perdiste nada, y en varios casos vuelve solo.

Mientras está suspendido, las ventas que llegan de la otra tienda **quedan retenidas sin
aplicarse**. No se pierden: esperan.

La fila te dice exactamente cuál de los siete motivos es:

| Lo que dice la fila | Qué hacer |
|---|---|
| **está en la papelera** | Restauralo. La fila vuelve a *Vinculado* sola |
| **se borró** | Desvinculalo: ese par no vuelve |
| **dejó de llevar su propio stock** | Volvé a activarle "Gestionar stock" |
| **el de la otra tienda está en la papelera** | Restauralo allá |
| **el de la otra tienda se borró** | Desvinculalo |
| **el de la otra tienda dejó de llevar su propio stock** | Activáselo allá |
| **el vínculo es con otra tienda, que ya no es la emparejada** | Quedó de un emparejamiento anterior: desvinculalo y vinculalo de nuevo |

> **📷 Captura —** una fila suspendida, con el motivo debajo del estado.

**Cuidado con restaurar un producto que estuvo tiempo en la papelera.** Las ventas que
quedaron retenidas se aplican todas juntas en la próxima pasada, así que el stock puede bajar
de golpe. Es correcto —esas ventas pasaron— y queda anotado en Registros, pero no te agarre
de sorpresa.

> ⚠️ **Que la otra tienda no responda NO suspende nada.**
> Es la confusión más fácil de hacerse. Una caída es temporal y no rompió ningún par: los
> productos **siguen vinculados**, sus ventas se acumulan y salen solas cuando la otra vuelva.
> La caída se avisa **una vez y arriba de todo**, no producto por producto (§ 11).

---

### 6.4 Sin confirmar

Vinculaste, la otra tienda probablemente lo aplicó, pero la respuesta se perdió en el camino.
El plugin **no adivina**: no lo da por vinculado ni por fallido, porque las dos suposiciones
hacen daño si son la equivocada.

**No tenés que hacer nada.** El plugin le pregunta solo a la otra tienda dentro de diez
minutos y resuelve con lo que conteste. Si pasa mucho tiempo y sigue igual, el problema es la
conexión: miralo en Configuración.

---

### 6.5 Stock distinto no es un estado

Arriba de la tabla hay un filtro **Stock distinto** que es fácil de confundir con un estado.
No lo es: son pares **vinculados y sanos** que simplemente quedaron con un número diferente en
cada tienda. Se resuelve con **Igualar stock** (§ 9), y por qué pasa está en § 11.

## 7. Vincular los casos difíciles

La sugerencia por SKU resuelve el grueso de un catálogo. Esta sección es para lo que queda.

### 7.1 Vincular a mano

Sirve siempre: sin SKU, con SKU distinto, o cuando el plugin no quiso elegir por vos.

1. En la fila, apretá **Vincular**.
2. Buscá el producto de la otra tienda por nombre o por SKU.
3. Elegilo y confirmá.

> **📷 Captura —** el modal de vincular a mano, con el buscador y un resultado elegido.

La búsqueda va contra **el catálogo de la otra tienda**, no contra el tuyo. Si creaste el
producto allá hace un rato y no aparece, apretá **Actualizar catálogo**: el plugin guarda una
copia y puede tener hasta un día.

**El SKU no es condición para vincular.** Dos productos con nombres y SKU completamente
distintos se pueden vincular sin problema: el par lo decidís vos.

### 7.2 SKU duplicado

Dos productos comparten el mismo SKU, en una tienda o en la otra. **El plugin no elige por
vos**, a propósito: elegir mal significa escribir el stock sobre el producto equivocado, y ese
par no se vuelve a revisar nunca.

Dos salidas: arreglá el catálogo —borrá o renombrá el duplicado— y la sugerencia aparece sola,
o vinculalo a mano (§ 7.1).

### 7.3 El producto existe en una sola tienda

No hay nada que vincular: hacen falta dos productos, uno de cada lado. O lo creás en la otra
tienda, o lo descartás (§ 7.5).

> **Un producto que existe sólo en la secundaria no aparece en la lista de la maestra**, y
> está bien: no hay con qué aparearlo. Lo vas a ver en el listado de lectura de la secundaria.
> El paso que falta es crearlo en la maestra.

### 7.4 Lo que no gestiona stock

Un servicio, un combo, un producto de envío: no tienen unidades que contar, así que **no
participan de la sincronización** y por defecto **ni siquiera se listan**.

Para verlos: la **rueda dentada** al lado del buscador → **"Mostrar los que no gestionan
stock"**.

> **📷 Captura —** el menú de la rueda dentada con la opción.

El plugin distingue dos situaciones, y sólo una es un problema:

| Situación | ¿Se lista? |
|---|---|
| No gestiona stock en **ninguna** de las dos | Sólo con la rueda dentada. Casi siempre es a propósito |
| Gestiona en **una** y en la otra no | **Siempre**, con su motivo. Casi siempre es un error de carga |

La opción queda guardada **en tu navegador**: en otra computadora arranca apagada.

### 7.5 Descartar lo que no querés sincronizar

Para un producto que nunca va a tener par —una prueba, algo exclusivo de una tienda— está
**Más acciones → Descartar**.

Qué cambia:

- Deja de sugerirse y **deja de entrar en el email** de la noche.
- No entra en la vinculación masiva.
- Desaparece de la lista, salvo que filtres por **Descartados**. El buscador lo encuentra igual.
- El estado **sigue siendo "Sin vincular"**: es lo que efectivamente pasa.

Es reversible con **Deshacer descarte**, y **no te prohíbe vincularlo** más adelante si
cambiás de idea.

> Descartar es la forma de decirle al plugin "ya sé, dejame en paz". Es lo que hace que el
> email de la noche no se vuelva ruido.

### 7.6 Vinculación masiva

Fija de golpe todos los pares **inequívocos**: un único producto con ese SKU de cada lado, los
dos publicados, los dos gestionando stock. Todo lo demás queda afuera para resolver de a uno.

Antes de confirmar te muestra cuántos pares va a fijar, te recuerda que **todos arrancan con
el stock de la maestra**, y cuántos quedan afuera.

**Lanzarla de nuevo más adelante no rompe nada:** los que ya están vinculados no se tocan,
sólo se ofrecen los pares nuevos. Es la forma normal de incorporar un lote de productos nuevos.

Si alguno falla —porque alguien tocó el catálogo mientras tanto— la masiva **no se cae
entera**: te devuelve cuántos vinculó y cuántos omitió, con el motivo de cada uno.

---

## 8. El día a día

Una vez vinculado el catálogo, **no hay nada que hacer**. Esta sección es para saber qué está
pasando por abajo.

### 8.1 Qué se propaga

| Qué hacés | Qué viaja | Se lee en el historial como |
|---|---|---|
| Se vende 1 en cualquiera de las dos | `-1` | **Venta** |
| Reembolsás 1 **con** "Reponer artículos reembolsados" | `+1` | **Devolución** |
| Reembolsás 1 **sin** esa casilla | nada | — |
| Se cancela un pedido y WooCommerce repone | `+N` | **Cancelación** |
| Editás el stock a mano: 12 → 60 | `+48` | **Ajuste** |
| Entra mercadería y la cargás | la diferencia | **Ajuste** |
| Importás un CSV | las diferencias, en lotes | **Ajuste** |
| Cambiás el SKU, el precio, el título, la foto | **nada** | — |

Da igual **desde dónde** hagas el cambio: el admin de WooCommerce, la API REST, WP-CLI o el
importador de CSV. Todos pasan por el mismo punto.

> **La excepción:** un cambio escrito **directamente en la base de datos**, sin pasar por
> WooCommerce, el plugin no lo ve en el momento. La revisión de la noche lo detecta y te lo
> reporta como discrepancia (§ 11.3).

### 8.2 Cuánto tarda

Una venta sale **al instante** si tu hosting tiene PHP-FPM o LiteSpeed, o **en menos de un
minuto** si no. No hay un plazo garantizado: depende de tu servidor.

Si la otra tienda no contesta, el movimiento **no se pierde**: se reintenta, y a las 6 horas
la cola deja de insistir y lo entrega la revisión de la noche.

### 8.3 Reservas y stock negativo

Si un producto acepta reservas, **el stock puede quedar negativo**, y eso no es un error: son
unidades que debés.

Con 0 en el depósito se venden 3 → el pool queda en **−3** en las dos tiendas. Llegan 10 del
proveedor y los cargás → el pool pasa a **7**. Las reservas se descontaron una sola vez.

### 8.4 Importar un CSV

> ⚠️ **Un mismo archivo se importa en una sola tienda, y esa tienda es la maestra.**
> Importarlo en las dos genera dos ajustes que **se suman**: de 10 a 50 en cada una te deja
> 90, no 50. Es el único error que el plugin no puede deshacer.

Cuando detecta una ráfaga de cambios, el plugin entra en **modo importación**: en vez de
mandar de a uno, los agrupa en lotes, y al terminar dispara una reconciliación que verifica
que el lote haya llegado completo.

### 8.5 Volver a activar "Gestionar stock"

Cuando a un producto vinculado le desactivás la gestión de stock, WooCommerce le borra el
número. Al volver a activarla, el número que cargás es **un recuento tuyo del depósito**, así
que **el plugin no lo manda a la otra tienda**: se queda en ésta.

Si no coincide con el de la otra, lo vas a ver como **Stock distinto** y lo resolvés con
**Igualar stock** (§ 9.4).

> **Si además entró mercadería, hacelo en dos pasos.** Primero reactivás con lo que hay y
> guardás; después cargás la entrada. Si hacés todo en el mismo guardado, la entrada no viaja.

---

## 9. Desvincular, reasignar, igualar stock

Tres acciones sobre un par que ya existe. Se parecen y hacen cosas muy distintas.

| Acción | Qué le hace al par | Qué le hace al stock |
|---|---|---|
| **Desvincular** | Lo corta | Nada, en ninguna de las dos tiendas |
| **Reasignar** | Lo corta y arma otro | El de la maestra se escribe en el nuevo par |
| **Igualar stock** | Lo deja igual | Las dos quedan con el número que elijas |

> **📷 Captura —** el menú *Más acciones* de una fila vinculada, con las tres opciones.

### 9.1 Desvincular

El par se corta, el producto deja de sincronizar y **su stock no se toca en ninguna de las dos
tiendas**. El historial y el stock base quedan guardados.

Las ventas que hubieran quedado retenidas se descartan: ese par ya no existe.

### 9.2 Volver a vincular pesa más que vincular la primera vez

Mientras estuvo desvinculado, cada tienda vendió por su cuenta y los dos números se separaron.
Al volver a vincular, **el de la maestra pisa el de la secundaria**, y lo que la secundaria
vendió en el medio no se recupera.

Mirá los dos números antes de confirmar, y dejá el de la maestra en el número real **antes**
de apretar.

### 9.3 Reasignar

Lo vinculás a otro producto de la otra tienda. Por dentro es cortar y volver a vincular, con
todo lo que dice el punto anterior.

Si el segundo paso falla, el producto queda **Sin vincular** — un estado válido y visible, que
reintentás. Lo que nunca pasa es que un producto termine con dos pares.

### 9.4 Igualar stock

Es la salida cuando las dos tiendas quedaron con un número distinto en un par que por lo demás
está sano.

> ⚠️ **Corregirlo a mano no sirve.** Si una tiene 12 y la otra 15 y vos cambiás el 12 por 15,
> viaja un `+3` y la otra pasa a 18. La distancia entre las dos no cambia nunca.

**Más acciones → Igualar stock.** El modal pide los dos números **en el momento** —no la copia
del catálogo, que puede tener un día— y te deja elegir cuál vale:

> **📷 Captura —** el modal de Igualar stock, con los dos números y los dos botones.

- **"Vale el de esta tienda (N)"** — la otra pasa a N.
- **"Vale el de la otra (M)"** — ésta pasa a M.

Contá lo que hay en el depósito antes de elegir. Si ya coinciden, el modal te lo dice y no
hace nada.

> **Desvincular no es la herramienta para corregir un número.** Si el par está bien y el
> número está mal, editás el stock en WooCommerce (§ 8.1) o usás Igualar stock. Se desvincula
> cuando **el par** está mal.

---

## 10. Borrar un producto

La respuesta corta: **desvinculalo primero, borralo después**. Y no, borrarlo de las dos
tiendas no alcanza.

### Por qué

El par es un dato del plugin, **independiente de los productos**. Si vaciás la papelera en las
dos tiendas sin desvincular, la fila del vínculo sobrevive apuntando a productos que ya no
existen, y el plugin la sigue mostrando como suspendida — a propósito, justamente para que
puedas desvincularla.

### El orden

1. En **Productos** de la maestra: **Más acciones → Desvincular**. Esto lo saca de la malla.
   No toca el stock de ninguna de las dos tiendas.
2. **Después** borralo en cada tienda desde WooCommerce, si querés que desaparezca del
   catálogo.

Al revés funciona igual, pero te deja un rato con una fila que apunta a la nada.

> **El plugin nunca crea ni borra productos.** El catálogo de cada tienda es tuyo y son
> independientes: borrar en una no borra en la otra.

### Si lo mandaste a la papelera sin querer

No pasó nada grave. **Restauralo** y la fila vuelve a *Vinculado* sola, con el mismo par y el
mismo stock base.

Lo único a tener en cuenta: mientras estuvo en la papelera, las ventas de la otra tienda
**quedaron retenidas**. Al restaurarlo se aplican todas juntas y el stock puede bajar de
golpe. Es correcto, y queda anotado en Registros.

---

## 11. Cuando algo no cuadra

### 11.1 "La otra tienda no responde"

Aparece un cartel arriba de todo, en Resumen y en Productos.

> **📷 Captura —** el cartel de tienda caída arriba de la pantalla Productos.

**No hagas nada.** Es la respuesta correcta y es literal:

- Los productos vinculados **siguen vinculados**. No se rompió ningún par.
- Las ventas se siguen anotando y salen solas cuando la otra vuelva.
- Los números de las dos tiendas se van a ver distintos mientras dure. Es normal: lo que se
  vendió de un lado todavía no llegó al otro.
- Cuando vuelve, te llega un email avisando que se normalizó. **Es la única vez que el plugin
  te avisa de algo bueno**, y es para que sepas que no tenés que hacer nada.

Si querés acelerar: **Reconciliar ahora**, desde la maestra.

### 11.2 "Rechaza los pedidos de esta tienda"

Distinto del anterior, y **esperar no lo arregla**: la otra tienda contesta, pero no reconoce a
ésta. Casi siempre es que se desemparejó de un solo lado.

Revisá el emparejamiento en Configuración y, si hace falta, volvé a emparejar con un código
nuevo.

### 11.3 Discrepancia: una tienda contra su propio libro

Aparece en **Resumen**, bajo *Discrepancias*. Significa que alguien escribió un número sin
pasar por WooCommerce, y el plugin **no toca nada** porque no sabe cuál de los dos es el bueno.

> **📷 Captura —** una discrepancia en Resumen, con *Hay 47 · Libro 50 · Diferencia −3* y el
> botón Resolver.

Elegís:

- **"El stock está bien"** — el número de la tienda es el real. La diferencia se anota como un
  ajuste y viaja a la otra tienda.
- **"El libro está bien"** — el valor del libro es el real. El stock vuelve a ese número y la
  otra tienda no se entera.

Mientras la discrepancia está abierta **el producto sigue sincronizando normalmente**. No crece
y no bloquea nada. Si los números vuelven a coincidir solos, se cierra sola.

### 11.4 Stock distinto: una tienda contra la otra

Se parece a lo anterior y es otra cosa. Acá las dos tiendas **cierran bien contra su propio
libro**, pero no coinciden entre ellas.

Lo ves en **Productos**, filtro *Stock distinto*, y te llega por email. Se resuelve con
**Igualar stock** (§ 9.4), nunca corrigiendo a mano.

### 11.5 Un producto quedó suspendido

Está en § 6.3, con los siete motivos y qué hacer con cada uno.

### 11.6 Pausar la sincronización

En **Configuración → Emparejamiento**, el interruptor **"Sincronización activa"**. Es para un
inventario, una migración, o cuando querés frenar sin desarmar nada.

Mientras está en pausa:

- **Las ventas se siguen anotando** en las dos tiendas. **No se pierde ninguna.**
- No se manda ni se aplica nada, no se reconcilia y no se puede vincular.
- La pausa es **del par**: se avisa a la otra tienda y las dos quedan pausadas.

**Al reanudar**, todo lo anotado sale en la próxima pasada, en orden. Si fue una pausa larga,
lanzá una reconciliación manual para quedarte tranquilo.

---

## 12. Registros

Todo lo que el plugin hace queda anotado. Es donde mirás cuando querés entender **por qué** un
número es el que es.

> **📷 Captura —** la pantalla Registros, pestaña Avisos.

### 12.1 Las dos pestañas

| Pestaña | Qué tiene |
|---|---|
| **Avisos** | Lo que pasó entre las dos tiendas: vínculos, reconciliaciones, discrepancias, cortes de conexión |
| **Movimientos** | Cada cambio de stock: cuál, cuánto, por qué y cómo terminó |

Las dos se filtran por **período** (hoy, 7 días, 30 días, todo), por **tipo** y por
**producto**.
Avisos suma un filtro por **nivel** —Info, Avisos, Errores— y Movimientos, uno por **cómo
terminó**.

### 12.2 El historial de un producto

Es lo primero que conviene abrir cuando un producto no hace lo que esperabas: en su fila,
**Ver el historial**.

> **📷 Captura —** el historial de un producto, con movimientos y eventos mezclados.

Muestra, en una sola línea de tiempo y de lo más nuevo a lo más viejo, **sus movimientos de
stock** y **lo que le pasó** —cuándo se vinculó, quién lo descartó, qué intento falló—.

### 12.3 Cómo leer un historial

Hay una pregunta que el historial contesta y nada más la contesta:

> **Un producto no sincroniza. ¿Me olvidé de vincularlo, o lo intenté y falló?**

- **Historial vacío** → nunca se intentó. Vinculalo.
- **Con un intento fallido anotado** → ya se intentó y algo anduvo mal, y el error está ahí.

Desde afuera los dos casos se ven igual: una fila que no sincroniza. Por eso **se registran
todos los intentos, hayan movido stock o no, y también los que fallaron**.

---

## 13. Los avisos por email

El email existe para que te enteres **sin entrar al panel**. Está pensado para que no lo
termines filtrando.

### 13.1 De qué te avisa

Tres cosas, y de ninguna más:

1. **Productos que no sincronizan** desde hace más de **24 horas**. Van separados los que
   tienen sugerencia —un clic— de los que no —hay que arreglar el catálogo—.
2. **Vinculaciones que fallaron.** Vos apretaste el botón y el plugin no pudo. Sale **en el
   momento**, porque el plugin **no reintenta una vinculación solo**: si no te avisa, ese
   producto se queda esperando para siempre.
3. **Pares rotos** — un producto vinculado cuyo par del otro lado se borró. También en el
   momento.

Más el aviso de **stock distinto** entre tiendas (§ 11.4) y el de que la otra tienda **volvió**.

### 13.2 Por qué no te llena la casilla

- **Cada producto se avisa una sola vez.** Si sigue sin sincronizar seis meses, no te lo vuelve
  a mencionar. Por eso conviene **descartar** lo que no querés sincronizar (§ 7.5).
- **Se agrupa por causa.** Si vaciás la papelera y 300 productos pierden su par, es **un** email
  que dice "300 productos", no 300 emails. Eso es un problema, no trescientos.
- **Una tienda caída es una causa sola.** Mientras no responde todo falla: las entregas, las
  vinculaciones, la reconciliación. Sale **un** email y nada por producto.
- **Lo que pasa mientras estás mirando la pantalla no se avisa.** Si lanzás la masiva y falla,
  te lo dice la pantalla. El email es para lo que pasa cuando no hay nadie mirando.
- **Tope de 10 emails por día.**

### 13.3 Dónde se configura

En **Configuración → Avisos por email**: el interruptor y el destinatario. Vacío = al
administrador del sitio.

Si los apagás, **todo se sigue registrando** en Registros: lo único que se apaga es el email.

El plazo de las 24 horas se cambia por consola:
`wp wss notify --email=deposito@ejemplo.com --after=48`.

---

## 14. Configuración

> **📷 Captura —** la pantalla Configuración completa.

### 14.1 Emparejamiento

Muestra con qué tienda estás emparejado, desde cuándo y en qué rol.

- **Probar conexión** — le pregunta a la otra tienda si responde. Útil cuando algo se ve raro.
- **Volver a emparejar** — reemplaza el emparejamiento actual. Si volvés a emparejar con **la
  misma** tienda, los vínculos se mantienen.
- **Sincronización activa** — el interruptor de la pausa (§ 11.6).
- **Desemparejar** — corta del todo.

### 14.2 Desemparejar

Las dos tiendas olvidan la dirección y la clave de la otra. Para volver hace falta un código
nuevo.

**Los vínculos y el historial se guardan.** Si volvés a emparejar con **la misma** tienda, todo
queda como estaba y lo que se vendió mientras tanto se manda ahí. Con **otra** tienda, los
vínculos viejos no se usan.

### 14.3 El nombre de esta tienda

Con el que la nombra la otra pantalla y con el que firman los emails. Si lo cambiás, la otra
tienda lo recibe al guardar.

### 14.4 Datos

Qué pasa si algún día borrás el plugin:

- **Sin marcar** (por defecto): los vínculos, el libro y los registros **quedan guardados** por
  si lo volvés a instalar.
- **Marcado**: al borrar el plugin se pierden para siempre.

---

## 15. Preguntas frecuentes

#### ¿Puedo tener precios distintos en cada tienda?
Sí, y es lo normal. El plugin sólo toca el stock.

#### Cambié un SKU. ¿Se desvinculó?
No. Una vez vinculado, el SKU es informativo: podés cambiarlo, borrarlo o pasárselo a otro
producto y el par sigue intacto. Hasta podés intercambiar los SKU de dos productos vinculados
y no se cruza nada.

#### ¿Por qué un producto que creé en las dos tiendas no sincroniza?
Porque crearlo no lo vincula. Entrá a **Productos** en la maestra, filtro **Con sugerencia**,
y aceptalo.

#### Un producto está en "Sin vincular" y no sé si me olvidé o falló algo.
Abrí su historial (§ 12.3). Vacío = nunca se intentó. Con un intento fallido = ya se intentó y
el error está ahí.

#### ¿Qué pasa si vendo la última unidad en las dos tiendas al mismo tiempo?
Las dos ventas se cobran y el pool queda en **−1**: debés una unidad. El plugin **no bloquea
ventas** — mientras el descuento viaja, las dos tiendas todavía muestran 1 — así que la ventana
de sobreventa se vuelve muy chica pero no desaparece. Lo que sí garantiza es que ninguna de las
dos ventas se pierda de la cuenta.

#### ¿Cuánto historial guarda?
90 días de movimientos. Al purgar, el punto de partida de cada producto absorbe lo borrado, así
que las cuentas siguen dando.

#### Restauré un backup de una de las tiendas y dejó de sincronizar.
Es a propósito. El plugin detecta que el sitio es una copia del que se emparejó y se suspende,
para no sincronizar desde un sitio de pruebas. Si es la tienda real y cambió de dirección,
emparejala de nuevo.

#### ¿Puedo usarlo con tres tiendas?
No. Exactamente dos.

#### ¿Sirve si cada tienda tiene su propio depósito?
No. Todo el plugin asume un solo depósito compartido. Si cada tienda tiene su stock separado,
el pool único no tiene sentido.

#### ¿Y si vendo por peso o por metro, con decimales?
El plugin sincroniza cantidades enteras. Si detecta una extensión que pasa el stock a
decimales, **te avisa** en vez de sincronizar mal en silencio.

#### ¿Hace falta que las dos tiendas estén en el mismo servidor?
No, pero **tienen que poder hablarse por internet**: cada una le hace pedidos a la otra. Una
tienda detrás de un firewall que no acepte pedidos entrantes no puede participar.
