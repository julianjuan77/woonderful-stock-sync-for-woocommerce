# Manual de Woonderful Stock Sync

## 1. Qué hace y qué no hace

Tenés **dos tiendas WooCommerce** y **un solo depósito**. Woonderful Stock Sync hace que las
dos publiquen el mismo número, el real, y que una venta en cualquiera de las dos descuente en
las dos.

### Pool único: nada de repartir

Si en el depósito hay 10 tazas, las dos tiendas muestran **10**. No 5 y 5, no 7 y 3.

```
            Depósito: 10 tazas
                     │
            ┌────────┴────────┐
    Tienda Minorista   Tienda Mayorista
       muestra 10         muestra 10
```

Se vende 1 en la minorista → las dos quedan en **9**.
Entran 20 de mercadería (las cargás en una sola tienda) → las dos quedan en **29**.

### Lo que viaja es la diferencia, no el número

Esto es lo que hace que no se pierdan ventas. Cuando vendés 1, a la otra tienda no le llega
*"quedan 9"*: le llega *"restá 1"*. Si justo en ese mismo segundo se vendió otro en la otra
tienda, las dos terminan en **8**, que es lo correcto. Con un plugin que mandara el número
entero, las dos habrían escrito "9" y habrías vendido una unidad que no tenés.

### Lo que NO hace

| | |
|---|---|
| **No sincroniza precios** | Son distintos a propósito entre mayorista y minorista |
| **No sincroniza títulos, fotos, categorías ni descripciones** | Sólo stock |
| **No crea productos** | El producto tiene que existir en las dos tiendas; vos lo das de alta |
| **No vincula productos solo** | Te los sugiere; vincular lo confirmás vos (y hay buenos motivos, § 3) |
| **No edita stock** | El stock se sigue cargando en WooCommerce, como siempre. El plugin sólo lo propaga |
| **No funciona con tres tiendas** | Exactamente dos |

> **Si cada tienda tiene su propio depósito separado, este plugin no es para vos.** Todo el
> diseño asume que las dos venden la misma mercadería física.

---

## 2. Puesta en marcha

### 2.1 Las dos tiendas, y cuál manda

El plugin se instala en **las dos** tiendas. Al configurarlo elegís un rol para cada una:

- **Maestra** — tu puesto de trabajo. Es donde vinculás los productos, donde cargás el stock
  y donde importás los CSV. Su número es el que vale cuando un producto entra a sincronizar.
- **Secundaria** — ve todo lo mismo, pero en modo lectura. Si intentás vincular algo ahí, la
  pantalla te manda a la maestra con un enlace.

**Que una sea maestra no la hace más importante para las ventas.** Una venta en la secundaria
descuenta en las dos igual que una venta en la maestra. La maestra manda en lo que se decide
**una vez por producto** (qué producto es cuál, con qué número arranca), no en lo que pasa
todos los días.

Elegí como maestra la tienda donde ya cargás la mercadería. En los ejemplos de este manual:

| | Tienda | Rol |
|---|---|---|
| **A** | Tienda Minorista | **Maestra** |
| **B** | Tienda Mayorista | Secundaria |

### 2.2 Los requisitos

La primera pantalla que vas a ver es **Configuración inicial**, y arranca revisando siete
cosas. Las que fallan te dicen cómo arreglarlas.

| Requisito | Si falla |
|---|---|
| PHP 8.1 o más y WooCommerce 9.1 o más | Actualizá desde el panel del hosting o desde Plugins |
| Las tablas del plugin, en InnoDB | Depende de cuál de los dos problemas sea: ver abajo |
| No es una copia de otro sitio | Es un staging o una restauración: no sincroniza. Si es la tienda real que cambió de dirección, emparejala de nuevo |
| HTTPS | Instalá el certificado **antes** de emparejar. Pasar a https después suspende la sincronización |
| La API REST responde desde afuera | Algo la bloquea: un plugin de seguridad, el firewall del hosting. La otra tienda le habla a `/wp-json/wss/v1/` |
| La pasada corrió hace poco | Sin visitas, WordPress no dispara su cron. Pedile al hosting un cron real cada minuto |
| El correo sale | Mandá el email de prueba. Si no llega, configurá un SMTP o no vas a recibir avisos |

Los dos últimos no son críticos —el plugin funciona igual— pero sin cron todo va más lento y
sin correo no te enterás de nada sin entrar al panel.

#### Si falla "Las tablas del plugin, en InnoDB"

El plugin guarda lo suyo en cuatro tablas propias —`wp_wss_movements`, `wp_wss_links`,
`wp_wss_nonces` y `wp_wss_events`, con el prefijo que tenga tu instalación— y necesita que
sean **InnoDB**, que es el motor de base de datos que sabe hacer transacciones. Sin
transacciones, dos ventas a la vez pueden llevarse el mismo número de orden y la cuenta sale
mal **en silencio**, que es lo peor que puede pasarle a un stock.

Ese aviso tapa dos problemas distintos. La pantalla te dice **qué tabla** es la que falla, y
según cuál sea el caso se arregla de una manera o de la otra:

**1. La tabla no existe.** WordPress no la pudo crear, casi siempre porque el usuario de la
base de datos no tiene permiso para crear tablas. Andá a **Plugins**, desactivá Woonderful
Stock Sync y volvé a activarlo: al activarse lo intenta de nuevo. Si el aviso sigue ahí, es
el permiso, y eso lo da el hosting.

**2. La tabla existe pero es MyISAM.** Acá desactivar y volver a activar **no cambia nada**,
y es la parte que confunde: WordPress crea las tablas en InnoDB, pero no convierte una que ya
existe con otro motor, así que al reactivar se las encuentra hechas y las deja como están.
Hay que convertirlas. Desde phpMyAdmin o desde la consola de la base:

```sql
ALTER TABLE wp_wss_movements ENGINE=InnoDB;
ALTER TABLE wp_wss_links     ENGINE=InnoDB;
ALTER TABLE wp_wss_nonces    ENGINE=InnoDB;
ALTER TABLE wp_wss_events    ENGINE=InnoDB;
```

Si no tenés acceso, pedile al hosting que convierta esas cuatro tablas a InnoDB. Es un pedido
de rutina, no toca nada del resto del sitio, y los datos no se pierden.

> Hoy esto es raro: InnoDB es el motor por defecto de MySQL desde 2010 y de MariaDB desde
> siempre. Si aparece, suele ser un hosting viejo o una migración que arrastró tablas MyISAM.

### 2.3 Emparejar las dos tiendas

Es un código que se genera de un lado y se pega del otro.

1. **En la maestra:** Configuración inicial → "Esta es la maestra: generar el código" →
   **Generar el código**. Copialo.
2. **En la secundaria:** Configuración inicial → "Esta es la secundaria: pegar el código" →
   pegalo → **Emparejar**.

El código **vale 15 minutos y sirve una sola vez**. La pantalla de la maestra se actualiza
sola cuando la otra lo canjea.

Después ponele **nombre** a cada tienda ("Tienda Minorista", "Tienda Mayorista"): es con el
que la nombra la otra pantalla y con el que firman los emails.

### 2.4 Los avisos por email

En el último paso elegís a quién le llegan. Vacío = al administrador del sitio.

Te avisa de tres cosas, y de nada más (§ 5.7).

---

## 3. Los productos de ejemplo

Todo el manual usa este catálogo. Son ocho productos elegidos porque cada uno muestra una
situación distinta de las que te vas a encontrar.

| # | Producto | SKU | Situación |
|---|---|---|---|
| 1 | Taza de cerámica 350 ml | `MUG-350` | El caso fácil: mismo SKU en las dos |
| 2 | Botella térmica 500 ml | `BTL-500-NE/RO/VE` | Variable: se vincula variación por variación |
| 3 | Cuaderno A5 tapa dura | `NOT-A5` | El SKU está repetido en la mayorista |
| 4 | Vela aromática 200 g | *(sin SKU en la mayorista)* | Sin SKU: se vincula a mano |
| 5 | Juego de posavasos de corcho | `COA-040` | Existe sólo en la minorista |
| 6 | Mochila de lona | `BAG-220` | Acepta reservas: el stock puede ser negativo |
| 7 | Grabado láser (servicio) | `GRA-001` | No gestiona stock: no participa |
| 8 | Caja de regalo | `GFT-900` | No querés sincronizarla: se descarta |

---

## 4. Vincular los productos

### 4.1 Por qué hay que vincular

El plugin **no empareja productos solo**, aunque tengan el mismo SKU. Te los **sugiere**, y
vos confirmás.

El motivo es concreto: al vincular, **el número de la maestra se escribe en la secundaria**.
Es la única vez en toda la vida del producto que el plugin pisa un número en vez de sumar o
restar. Si lo hiciera solo, contra un SKU que estabas justo corrigiendo, te pisaría el stock
del producto equivocado — y ese par no se vuelve a revisar nunca más.

Con vos confirmando, lo que ves en pantalla es el catálogo de ese instante.

> ⚠️ **La regla más importante del plugin:**
> **cargá el stock real en la maestra ANTES de vincular.**
> Si vinculás la Taza con 0 en la minorista y 40 en la mayorista, las dos quedan en **0** y
> esos 40 se pierden. El plugin te muestra los dos números antes de confirmar — ese es el
> momento de mirarlos.

### 4.2 El arranque: vinculación masiva

Si ya tenés catálogo en las dos tiendas, no las vincules de a una. En **Productos** de la
maestra está **Vinculación masiva**, que fija de golpe todos los pares **inequívocos**: un
único producto con ese SKU de cada lado, los dos publicados.

Antes de confirmar te dice:

```
Se va a vincular 1.248 pares con el mismo SKU en las dos tiendas.
Todos arrancan con el stock de la maestra: el de la otra tienda
se reemplaza por el de ésta.
37 productos con el SKU repetido quedan aparte: se vinculan a mano.
```

Con el catálogo de ejemplo, la masiva resuelve **la Taza (1)** y **las variaciones de la
Botella (2)** que tengan SKU de los dos lados. El resto queda para resolver de a uno.

Lanzarla de nuevo más adelante **no rompe nada**: los que ya están vinculados no se tocan,
sólo se ofrecen los pares nuevos.

### 4.3 Producto 1 — Taza de cerámica: el caso fácil

Mismo SKU `MUG-350` en las dos, los dos publicados.

En **Productos** de la maestra la fila se ve así:

```
Taza de cerámica 350 ml         Stock: 12
MUG-350                         La otra tienda: Taza de cerámica · MUG-350 · 9
                                Sin vincular · Sugerido · mismo SKU      [ Aceptar ]
```

Apretás **Aceptar**. La pantalla te muestra los dos números y cuál va a quedar:

```
Stock acá ................ 12   (el de la maestra)
Stock en la otra tienda ... 9
Las dos quedan con ....... 12
```

Confirmás, y listo: estado **Vinculado**, las dos en 12, y desde ese momento cada venta de
cualquiera de las dos descuenta en las dos.

### 4.4 Producto 2 — Botella térmica: un variable

Un producto variable **no se vincula como producto**. Se vincula **cada variación**, porque
cada una lleva su propio stock. El padre es una fila que se despliega y no tiene botones.

```
▸ Botella térmica 500 ml · Variable · 3 variaciones · 2/3 vinculadas
    Negro   BTL-500-NE   Stock 8    ↔  Mayorista 8    Vinculado
    Rojo    BTL-500-RO   Stock 5    ↔  Mayorista 5    Vinculado
    Verde   BTL-500-VE   Stock 3    ↔  —              Sin vincular · En la otra
                                                      tienda no gestiona stock
```

Qué hacer con el Verde: en la mayorista, abrir esa variación en WooCommerce y **activar
"Gestionar stock"**. En cuanto lo tenga, aparece la sugerencia y la vinculás.

**Dos trampas de los variables:**

- **"El stock está en el padre: llevalo a cada variación"** — si en WooCommerce pusiste la
  cantidad en el producto padre en vez de en cada variación, el plugin no puede sincronizar:
  no sabría a qué variación restarle. Hay que pasar el control de stock a las variaciones.
- **Una variación sin SKU propio** no se sugiere, aunque WooCommerce muestre el del padre.
  Vinculala a mano.

### 4.5 Producto 3 — Cuaderno A5: SKU duplicado

En la mayorista hay **dos** productos con el SKU `NOT-A5` (quedó una copia vieja).

```
Cuaderno A5 tapa dura    NOT-A5    Stock 22
Sin vincular · SKU duplicado
```

El plugin **no elige por vos** cuál de los dos es. Tenés dos salidas:

1. **Arreglar el catálogo** en la mayorista —borrar o renombrar el duplicado— y la sugerencia
   aparece sola.
2. **Vincularlo a mano:** en la fila, **Vincular** → buscás por nombre o SKU en la otra
   tienda → elegís el correcto → confirmás.

### 4.6 Producto 4 — Vela aromática: sin SKU

En la mayorista nunca le cargaron SKU.

```
Vela aromática 200 g    CND-200    Stock 60
Sin vincular · Sin coincidencia
```

Igual que arriba: le ponés el SKU en la mayorista, o la vinculás a mano. **El SKU no es
condición para vincular** — es sólo lo que permite sugerir.

Y una vez vinculado, **el SKU deja de importar del todo**: podés cambiarlo, borrarlo o
pasárselo a otro producto y el par sigue funcionando igual. La identidad es el par, no el
SKU.

### 4.7 Producto 5 — Juego de posavasos: existe en una sola tienda

`COA-040` sólo está en la minorista.

```
Juego de posavasos de corcho    COA-040    Stock 4
Sin vincular · Sin coincidencia
```

No hay nada que vincular: hacen falta dos productos. O lo creás en la mayorista —y ahí
aparece la sugerencia— o lo **descartás** (§ 4.9).

> Un producto que existe **sólo en la secundaria** no aparece en la lista de la maestra, y
> está bien: no hay con qué aparearlo. Lo vas a ver en el listado de lectura de la
> secundaria. El paso que falta es crearlo en la maestra.

### 4.8 Producto 7 — Grabado láser: no gestiona stock

Un servicio no tiene unidades que contar. En WooCommerce no tiene "Gestionar stock" activado,
así que **no participa de la sincronización** y, por defecto, **ni siquiera se lista**.

Si querés verlo: la **rueda dentada** al lado del buscador → **"Mostrar los que no gestionan
stock"**.

La distinción que hace el plugin:

| Situación | ¿Se lista? |
|---|---|
| No gestiona stock en **ninguna** de las dos | Sólo con la rueda dentada. Es a propósito: un servicio, un combo, un envío |
| Gestiona en **una** y en la otra no | **Siempre**, con su motivo. Casi siempre es un error de carga, y hay que activarlo del otro lado |

### 4.9 Producto 8 — Caja de regalo: descartar

La caja de regalo la armás sólo para la minorista y no querés verla nunca más en la lista de
pendientes. En su fila: **Más acciones → Descartar**.

Qué pasa:

- Deja de sugerirse y **deja de entrar en el email** de la noche.
- No entra en la vinculación masiva.
- El estado **sigue siendo "Sin vincular"** (que es lo que efectivamente pasa); lo que cambia
  es que deja de pedir atención.
- Queda registrado quién la descartó y cuándo.

Es reversible (**Deshacer descarte**) y **no te prohíbe vincularla** más adelante si cambiás
de idea. Las descartadas están ocultas salvo que filtres por **Descartados**, pero el
buscador las encuentra siempre.

### 4.10 Los filtros de Productos

Arriba de la tabla:

**Todos · Sin vincular · Vinculados · Suspendidos · Stock distinto**

Al entrar en **Sin vincular**, esa fila se reemplaza por los motivos, que es como vas a
trabajar el arranque:

**Todos · Con sugerencia · Sin coincidencia · Sin SKU · Stock en el padre · SKU duplicado ·
No gestiona stock · En la papelera · Descartados**

El orden natural para dejar el catálogo limpio: primero **Con sugerencia** (un clic cada
uno), después **SKU duplicado** y **Sin SKU** (arreglás el catálogo o vinculás a mano), y al
final **Sin coincidencia** (creás el producto que falta, o descartás).

### 4.11 Desvincular, reasignar

- **Desvincular** — el par se corta, el producto deja de sincronizar y **su stock no se toca
  en ninguna de las dos tiendas**. El historial queda. Podés volver a vincularlo cuando
  quieras.
- **Reasignar** (*Elegir otro*) — lo vinculás a otro producto de la otra tienda. Por dentro
  es cortar y volver a vincular, con el número de la maestra escribiéndose de nuevo.

> ⚠️ **Desvincular no es la forma de corregir un número.**
> Si el par está bien y el número está mal, editás el stock en WooCommerce y listo (§ 5.1).
> Si los dos números quedaron distintos, usás **Igualar stock** (§ 5.5).
> Desvincular es para cuando **el par está mal**, no cuando el número está mal.

Y ojo con **revincular**: mientras estuvo desvinculado cada tienda vendió por su cuenta, así
que los números se separaron. Al revincular, el de la maestra **pisa** el de la secundaria y
lo que ésta vendió en el medio no se recupera. Mirá los dos números antes de confirmar.

---

## 5. El día a día

Una vez vinculado el catálogo, **no hay nada que hacer**. Esta sección es para saber qué está
pasando por abajo y qué mirar cuando algo llama la atención.

### 5.1 Qué se propaga

| Qué hacés | Qué viaja | Se lee en el historial como |
|---|---|---|
| Se vende 1 taza en cualquiera de las dos | `-1` | **Venta** |
| Reembolsás 1 **con** "Reponer artículos reembolsados" | `+1` | **Devolución** |
| Reembolsás 1 **sin** esa casilla | nada | — |
| Se cancela un pedido y WooCommerce repone | `+N` | **Cancelación** |
| Editás el stock a mano en WooCommerce: 12 → 60 | `+48` | **Ajuste** |
| Entra mercadería y la cargás | la diferencia | **Ajuste** |
| Importás un CSV | las diferencias, en lotes | **Ajuste** |
| Cambiás el SKU, el precio, el título, la foto | **nada** | — |

Da igual **desde dónde** hagas el cambio: el admin de WooCommerce, la API REST, WP-CLI o el
importador de CSV. Todos pasan por el mismo punto.

> **La excepción:** un cambio escrito **directamente en la base de datos**, sin pasar por
> WooCommerce, el plugin no lo ve en el momento. La revisión de la noche lo detecta y te lo
> reporta como discrepancia (§ 5.4).

### 5.2 Producto 6 — Mochila de lona: reservas y stock negativo

`BAG-220` está configurada en WooCommerce para **aceptar reservas** (pedidos con stock 0).

Con 0 en el depósito se venden 3: el pool queda en **-3** en las dos tiendas. No es un
error: son tres mochilas que debés.

Llegan 10 del proveedor y las cargás en la maestra: el pool pasa a **7**. Las reservas se
descontaron una sola vez, como corresponde.

### 5.3 Cuánto tarda

Una venta sale **enseguida** (si el hosting tiene PHP-FPM o LiteSpeed) o **en menos de un
minuto** (con la pasada). No hay promesa de tiempo exacto: depende de tu servidor.

Si la otra tienda no contesta, el movimiento **no se pierde**: queda anotado y se reintenta.
A las 6 horas la cola deja de insistir, y entonces lo entrega la revisión de la noche.

### 5.4 La revisión de la noche

Cada noche, la maestra dispara una **reconciliación**. Hace dos cosas:

1. **Compara los dos libros y reentrega lo que falte.** Si algún movimiento no llegó, se lo
   manda. Nunca copia un número entero de una tienda a la otra: reenvía el movimiento que
   faltaba, con su diferencia original.
2. **Revisa que el stock de cada tienda coincida con su propio libro.** Si no coincide, hubo
   una escritura que nadie registró: te la reporta como **discrepancia** y **no toca nada**,
   porque no tiene forma de saber cuál de los dos números es el bueno.

Además, la maestra **compara su stock con el de la otra tienda** y te avisa de los pares que
quedaron con números distintos (§ 5.5).

Podés lanzarla cuando quieras: en **Resumen** de la maestra, **Reconciliar ahora**. Es lo que
conviene hacer apenas vuelve una tienda que estuvo caída, si no querés esperar a la noche.

**Si la otra tienda no responde, no se reconcilia nada** y te lo dice. No es que no haya nada
que reparar: es que no se pudo mirar.

### 5.5 Las dos cosas que pueden salir mal con un número

Se parecen y se arreglan distinto. Esta es la diferencia:

#### a) Discrepancia — una tienda contra su propio libro

Aparece en **Resumen**, bajo *Discrepancias*:

```
Taza de cerámica 350 ml   #412
Hay 47    ·   Libro 50    ·   Diferencia -3
                                           [ Resolver ▾ ]
```

Alguien escribió un número sin pasar por WooCommerce. Elegís:

- **"El stock está bien"** — el 47 es el real. La diferencia se anota como un ajuste y viaja
  a la otra tienda.
- **"El libro está bien"** — el 50 es el real. El stock vuelve a 50 y la otra tienda no se
  entera (su número ya era el correcto).

Mientras la discrepancia está abierta **el producto sigue sincronizando normalmente**. No
crece y no bloquea nada. Y si los números vuelven a coincidir solos, se cierra sola.

#### b) Stock distinto — una tienda contra la otra

Aparece en **Productos**, filtro **Stock distinto**, y te llega por email.

```
Taza de cerámica 350 ml   Stock 12   ·   La otra tienda 15
```

Las dos tiendas cierran bien contra su propio libro, pero no coinciden entre ellas. Pasa, por
ejemplo, cuando volvés a activar "Gestionar stock" en un producto vinculado (§ 5.6).

> ⚠️ **Corregirlo a mano no sirve.** Si en la minorista cambiás el 12 por 15, viaja un `+3` y
> la otra pasa a 18. La distancia entre las dos no cambia nunca.

Lo que iguala es el botón: **Más acciones → Igualar stock**. El modal pide los dos números en
el momento y te deja elegir:

```
Stock en esta tienda ....... 12
En la otra tienda .......... 15

Contá lo que hay en el depósito y elegí cuál de los dos números es
el correcto: las dos tiendas quedan con ese.

[ Vale el de esta tienda (12) ]   [ Vale el de la otra (15) ]
```

### 5.6 Volver a activar "Gestionar stock"

Cuando a un producto vinculado le desactivás la gestión de stock, WooCommerce le borra el
número. Al volver a activarla, el número que cargás es **un recuento tuyo del depósito**, así
que **el plugin no lo manda a la otra tienda**: se queda en ésta.

Queda anotado en Registros, y si el número no coincide con el de la otra, lo vas a ver como
**Stock distinto** y lo resolvés con **Igualar stock**.

Y un detalle que ahorra un dolor de cabeza: si además entró mercadería, **hacelo en dos
pasos**. Primero reactivás con lo que hay, guardás, y después cargás la entrada. Si lo hacés
todo en el mismo guardado, la entrada no viaja.

### 5.7 Los emails

Te avisa de **tres cosas**:

1. **Productos que no sincronizan** desde hace más de **24 horas**. Van separados
   los que tienen sugerencia —un clic— de los que no —hay que arreglar el catálogo—.
2. **Vinculaciones que fallaron.** Vos apretaste el botón y el plugin no pudo. Sale **en el
   momento**, porque el plugin **no reintenta una vinculación solo** y si no te avisa, ese
   producto se queda esperando para siempre.
3. **Pares rotos** — un producto vinculado cuyo par del otro lado se borró. También en el
   momento.

Y las reglas que hacen que no te llene la casilla:

- **Cada producto se avisa una sola vez.** Si sigue sin sincronizar seis meses, no te lo
  vuelve a mencionar. Por eso conviene **descartar** lo que no querés sincronizar: es la
  forma de decir "ya sé, dejame en paz".
- **Se agrupa por causa.** Si vaciás la papelera y 300 productos vinculados pierden su par,
  es **un** email que dice "300 productos", no 300 emails. Eso es un problema, no trescientos.
- **Una tienda caída es una causa sola.** Mientras no responde, todo falla: las entregas, las
  vinculaciones, la reconciliación. Sale **un** email y nada por producto.
- **Lo que pasa mientras estás mirando la pantalla no se avisa.** Si lanzás la masiva y falla,
  te lo dice la pantalla. El email es para lo que pasa cuando no hay nadie mirando.
- **Tope de 10 emails por día.**

El plazo de las 24 horas y el destinatario se pueden cambiar por consola:
`wp wss notify --email=deposito@ejemplo.com --after=48`. En la pantalla de Configuración
están el interruptor de los avisos y el destinatario.

### 5.8 Las cuatro pantallas

| Pantalla | Para qué |
|---|---|
| **Resumen** | De un vistazo: si está todo sincronizado, qué hay en camino, las discrepancias abiertas, cuántos productos faltan vincular y cuándo fue la última reconciliación. El botón **Reconciliar ahora** está acá |
| **Productos** | La lista de qué sincroniza y qué no, con el motivo. En la maestra es donde se vincula; en la secundaria, sólo lectura |
| **Registros** | Dos pestañas: **Avisos** (vínculos, reconciliaciones, cortes, discrepancias) y **Movimientos** (cada cambio de stock: cuál, cuánto, por qué, cómo terminó). Se filtra por período, tipo, nivel y producto |
| **Configuración** | El emparejamiento, el nombre de la tienda, la pausa, los avisos por email y qué pasa con los datos al desinstalar |

Y en cada producto, **Ver el historial**: sus movimientos y lo que le pasó, de lo más nuevo a
lo más viejo, en una sola línea de tiempo. Es lo primero que conviene abrir cuando un producto
no hace lo que esperabas.

---

## 6. Cuando algo no cuadra

### 6.1 "La otra tienda no responde"

El cartel aparece arriba de todo, en Resumen y en Productos:

```
Sin conexión con Tienda Mayorista
Desde las 14:30. Los cambios quedan en la cola y se mandan solos
cuando vuelva: no hace falta hacer nada.
```

**No hagas nada.** Es la respuesta correcta y es literal:

- Los productos vinculados **siguen vinculados**. No se rompió ningún par.
- Las ventas se siguen anotando y salen solas cuando la otra vuelva.
- Los números de las dos tiendas se van a ver distintos mientras dure. Es normal: lo que se
  vendió de un lado todavía no llegó al otro.
- Cuando vuelve, te llega un email avisando que se normalizó. **Es la única vez que el plugin
  te avisa de algo bueno**, y es para que sepas que no tenés que hacer nada.

Si querés acelerar: **Reconciliar ahora**, desde la maestra.

### 6.2 "Rechaza los pedidos de esta tienda"

Distinto del anterior, y **esperar no lo arregla**: la otra tienda contesta, pero no reconoce
a ésta. Casi siempre es que se desemparejó de un solo lado. Revisá el emparejamiento en
Configuración y, si hace falta, volvé a emparejar con un código nuevo.

### 6.3 Un producto quedó "Suspendido"

Significa que el par se rompió: el producto del otro lado se borró o está en la papelera. La
fila te dice cuál de las dos cosas.

- **Si está en la papelera:** restauralo, y la fila vuelve a **Vinculado** sola, con el mismo
  par y el mismo stock base. No hay botón de "reanudar" porque no hay nada que reanudar. Las
  ventas que llegaron mientras tanto quedaron guardadas y **se aplican cuando el producto
  vuelve** — así que el stock puede bajar de golpe al restaurarlo. Es correcto: esas ventas
  pasaron.
- **Si se borró y no va a volver:** desvinculalo. Queda libre para vincularlo a otro producto.

### 6.4 Un producto quedó "Sin confirmar"

Vinculaste, y la respuesta de la otra tienda se perdió en el camino. El plugin **no adivina**:
no lo da por vinculado ni por fallido. Le pregunta solo a la otra tienda dentro de 10 minutos
y resuelve con lo que conteste. No tenés que hacer nada; si en un rato sigue igual, revisá la
conexión.

### 6.5 Pausar la sincronización

En **Configuración → Emparejamiento**, el interruptor **"Sincronización activa"**.

Es para un inventario, una migración, o cuando algo raro está pasando y querés frenar sin
desarmar nada.

Mientras está en pausa:

- **Las ventas se siguen anotando** en las dos tiendas. **No se pierde ninguna.**
- No se manda ni se aplica nada, no se reconcilia y no se puede vincular.
- La pausa es **del par**: se avisa a la otra tienda y las dos quedan pausadas.

**Al reanudar**, todo lo anotado sale en la próxima pasada, en orden. Si fue una pausa larga,
lanzá una reconciliación manual para quedarte tranquilo.

### 6.6 Desemparejar

Corta del todo: las dos tiendas olvidan la dirección y la clave de la otra. Para volver hace
falta un código nuevo.

**Los vínculos y el historial se guardan.** Si volvés a emparejar con **la misma** tienda,
todo queda como estaba y lo que se vendió mientras tanto se manda ahí. Si emparejás con
**otra**, los vínculos viejos no se usan y hay que vincular de nuevo.

### 6.7 Desinstalar

En **Configuración → Datos** elegís qué pasa:

- **Sin marcar** (por defecto): si borrás el plugin, los vínculos y los registros quedan
  guardados por si lo volvés a instalar.
- **Marcado**: al borrar el plugin se pierden para siempre los vínculos, el libro de
  movimientos, los registros y la configuración.

---

## 7. Las cinco reglas de oro

1. **Cargá el stock real en la maestra antes de vincular.** Al vincular, su número pisa el de
   la otra tienda.
2. **El stock se edita en WooCommerce, nunca en el plugin.** El plugin propaga; no es un
   gestor de inventario.
3. **Un mismo CSV se importa en una sola tienda, y esa tienda es la maestra.** Importarlo en
   las dos genera dos ajustes que se suman: de 10 a 50 en cada una te deja 90, no 50. Es el
   único error que el plugin no puede deshacer.
4. **Un producto nuevo no sincroniza hasta que lo vinculás.** Darlo de alta en las dos tiendas
   con el mismo SKU sólo lo vuelve candidato.
5. **Si los dos números no coinciden, usá "Igualar stock".** Corregirlo a mano mueve las dos y
   la diferencia no cambia.

---

## 8. Preguntas frecuentes

#### ¿Puedo tener precios distintos en cada tienda?
Sí, y es lo normal. El plugin sólo toca el stock.

#### Cambié un SKU. ¿Se desvinculó?
No. Una vez vinculado, el SKU es informativo: podés cambiarlo, borrarlo o pasárselo a otro
producto y el par sigue intacto. Hasta podés intercambiar los SKU de dos productos vinculados
y no se cruza nada.

#### ¿Por qué un producto que creé en las dos tiendas no sincroniza?
Porque crearlo no lo vincula. Entrá a **Productos** en la maestra, filtro **Con sugerencia**,
y aceptalo. A las 24 horas te va a llegar un email recordándotelo.

#### Un producto está en "Sin vincular" y no sé si me olvidé o falló algo.
Abrí su historial. **Vacío** = nunca se intentó, vinculalo. **Con un intento fallido** = ya se
intentó y algo anduvo mal, y el error está ahí.

#### ¿Qué pasa si vendo la última unidad en las dos tiendas al mismo tiempo?
Las dos ventas se cobran y el pool queda en **-1**: debés una unidad. El plugin **no bloquea
ventas** — mientras el descuento viaja, las dos tiendas todavía muestran 1 — así que la
ventana de sobreventa se vuelve muy chica pero no desaparece. Lo que sí garantiza es que
ninguna de las dos ventas se pierda de la cuenta: nunca vas a terminar en 0 habiendo vendido
dos.

#### ¿Cuánto historial guarda?
90 días de movimientos. Al purgar, el punto de partida de cada producto absorbe lo borrado,
así que las cuentas siguen dando.

#### Restauré un backup de una de las tiendas y dejó de sincronizar.
Es a propósito. El plugin detecta que el sitio es una copia del que se emparejó y se suspende,
para no sincronizar desde un staging. Si es la tienda real y cambió de dirección, emparejala
de nuevo.

#### ¿Puedo usarlo con tres tiendas?
No. Exactamente dos.

#### ¿Sirve si cada tienda tiene su propio depósito?
No. Todo el plugin asume un solo depósito compartido.

#### ¿Y si vendo por peso o por metro, con decimales?
El plugin sincroniza cantidades enteras. Si detecta una extensión que pasa el stock a
decimales, **te avisa** en vez de sincronizar mal en silencio.
