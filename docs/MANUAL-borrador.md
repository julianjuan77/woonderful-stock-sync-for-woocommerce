# Manual de Woonderful Stock Sync

---

# Parte 1 — Inicio rápido

Cuatro pasos para dejar el plugin andando. Si los seguís en orden, al terminar una venta en
cualquiera de tus dos tiendas descuenta el stock en las dos.

**Antes de empezar necesitás:** las dos tiendas con WooCommerce funcionando, acceso de
administrador a cada una, y el plugin instalado en **las dos**.

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
  al instante. Funciona igual.
- **El correo sale** — mandá el email de prueba. Si no llega, no vas a recibir los avisos.

### 1.2 Esta tienda

Dos campos:

- **Nombre** — con el que la nombra la otra tienda y con el que firman los emails. Poné algo
  que distingas de un vistazo: "Tienda Minorista", "Mayorista", el nombre de la marca.
- **Rol** — y esta es **la decisión importante del plugin**.

> **📷 Captura —** el paso "Esta tienda", con el nombre cargado y las dos tarjetas de rol.

| Rol | Qué significa |
|---|---|
| **Maestra** | Tu puesto de trabajo. Desde acá vinculás los productos y desde acá se lanza todo lo que hay que decidir. **Al vincular, el stock que queda es el suyo.** |
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

## Paso 3 — Vincular un producto simple

Estar emparejadas no alcanza: **el plugin todavía no sabe qué producto de una tienda es cuál
en la otra**. Eso lo decidís vos, producto por producto, y se hace **desde la maestra**.

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

En la lista, el variable es una fila que se despliega. El padre no tiene botones.

> **📷 Captura —** la fila de un variable desplegada, con sus tres variaciones debajo.

1. Apretá la flecha para desplegarlo.
2. Cada variación con sugerencia tiene su propio **Aceptar**, igual que un producto simple.
3. Si varias están sugeridas, el padre muestra **Aceptar 2** (o las que sean) y las vinculás
   todas juntas.

> **📷 Captura —** el botón *Aceptar 2* en la fila del padre.

El padre muestra cómo va: **2/3 vinculadas**, y el estado **Parcial** mientras le falte alguna.

**Para que una variación se pueda vincular, tiene que tener su propio SKU y gestionar su
propio stock en las dos tiendas.** Si no, la fila te dice qué le falta, y eso se arregla en
WooCommerce. Los casos están en la Parte 2.

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
3. **Un mismo archivo CSV se importa en una sola tienda, y esa tienda es la maestra.**
   Importarlo en las dos suma dos veces y es el único error que el plugin no puede deshacer.

---

# Parte 2 — Sección por sección

> **Esta parte todavía no está escrita.** Es el índice propuesto, para validar el orden antes
> de redactarla. Cada sección se puede leer suelta: no hace falta haber leído la anterior.

## 5. Cómo funciona por abajo
Pool único, por qué viaja la diferencia y no el número, y qué pasa cuando las dos tiendas
venden al mismo tiempo. Corto y conceptual: explica por qué el plugin hace lo que hace.

## 6. Los estados de un producto
Vinculado, Sin vincular, Suspendido, Sin confirmar y Parcial. Qué significa cada uno, qué
hacer con cada uno, y los motivos de un "sin vincular" (sin SKU, sin coincidencia, SKU
duplicado, stock en el padre, no gestiona stock, en la papelera, descartado).

## 7. Vincular los casos difíciles
Lo que la sugerencia no resuelve: vincular a mano buscando en la otra tienda, el SKU
duplicado, el producto sin SKU, el que existe en una sola tienda, y descartar lo que no
querés sincronizar.

## 8. El día a día
Qué se propaga y qué no (venta, devolución, cancelación, ajuste, importación), cuánto tarda,
y qué pasa con las reservas y el stock negativo.

## 9. Desvincular, reasignar, igualar stock
Las tres acciones sobre un par que ya existe, qué hace cada una con el stock, y cuándo
corresponde cada una. Incluye la advertencia de que desvincular no es para corregir números.

## 10. Borrar un producto
El orden correcto —desvincular primero, borrar después—, qué pasa con las ventas retenidas,
y por qué borrarlo de las dos tiendas no alcanza.

## 11. Cuando algo no cuadra
La otra tienda no responde, rechaza los pedidos, discrepancia contra el propio libro, stock
distinto entre tiendas, y un producto suspendido. Con qué hacer en cada caso, que muchas
veces es nada.

## 12. Registros
Las dos pestañas, Avisos y Movimientos, los filtros, y el historial de un producto. Cómo leer
un historial para entender qué le pasó a un número.

## 13. Los avisos por email
De qué avisa, de qué no, y por qué no te llena la casilla.

## 14. Configuración
Emparejamiento, nombre, pausar, desemparejar y qué pasa con tus datos al desinstalar.

## 15. Preguntas frecuentes
