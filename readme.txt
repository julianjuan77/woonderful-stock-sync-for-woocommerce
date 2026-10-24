=== Woonderful Stock Sync for WooCommerce ===
Contributors: julianjuan77
Tags: woocommerce, stock, inventario, sincronizacion, multitienda
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 9.1
WC tested up to: 11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincroniza el stock entre dos tiendas WooCommerce que despachan del mismo depósito. Pool único, sin reparto.

== Description ==

Si tenés dos tiendas WooCommerce —por ejemplo, una mayorista y una minorista— y las dos venden de un mismo depósito, hoy probablemente repartís el stock entre ellas para no vender de más. Woonderful Stock Sync elimina ese reparto: las dos tiendas muestran el stock real completo, y lo que se vende en una se descuenta en la otra.

**Cómo funciona:**

* **Pool único.** Si hay 10 unidades, las 10 se ven en cada tienda. Una venta en cualquiera las deja en 9 en las dos.
* **Cualquier cambio de stock se propaga:** una venta, una cancelación, un reembolso, una edición en el admin, o un cambio por la API REST, WP-CLI o el importador CSV.
* **Productos simples y variaciones**, cada variación con su propio stock.
* **Una tienda maestra.** Es donde se vinculan los productos y la que fija el stock de arranque de cada par. La otra muestra la misma pantalla en modo lectura.
* **Vinculación con sugerencias por SKU**, a mano o en lote, mirando el stock de las dos tiendas antes de confirmar. Vincular es siempre una decisión del dueño: el plugin nunca fija un par por su cuenta.
* **Nada se pierde si la otra tienda se cae.** Cada movimiento queda anotado y se reintenta hasta 6 horas. Una reconciliación nocturna completa lo que falte y revisa que el stock de cada tienda coincida con lo que dicen sus movimientos.
* **Avisos por email**, agrupados: cuando la otra tienda no responde, cuando algo falla y, una vez por producto, lo que quedó sin sincronizar.
* **Historial por producto y registro** de lo que pasó entre las dos tiendas.
* **Pausar y desemparejar** desde Configuración, avisándole a la otra tienda.

**Lo que no hace:** no sincroniza precios, títulos, imágenes ni categorías; no crea productos en la otra tienda; no sincroniza pedidos ni clientes; y funciona con exactamente dos tiendas.

= Comunicación entre las tiendas =

El plugin no usa servicios de terceros. Cada tienda habla sólo con la otra tienda que vos emparejaste, por HTTPS, y cada pedido va firmado con un secreto que comparten las dos. Lo que viaja entre ellas:

* Los movimientos de stock: qué producto, cuántas unidades, el motivo (venta, cancelación, reembolso, ajuste) y la referencia del pedido.
* El catálogo para vincular: nombre, SKU, tipo, stock y estado de cada producto.
* El nombre de la tienda, la pausa y el desemparejado.

Nada de esto sale de las dos tiendas.

== Installation ==

1. Instalá y activá el plugin en **las dos** tiendas. Las dos necesitan WooCommerce activo y HTTPS.
2. En la tienda que va a ser la maestra, entrá a **Stock Sync** y seguí el asistente: revisa los requisitos, te pide el nombre de la tienda y genera un código de emparejamiento.
3. En la otra tienda, abrí **Stock Sync** y pegá ese código.
4. En la maestra, cargá el stock real de cada producto y vinculalos en **Stock Sync → Productos**. La vinculación masiva resuelve de una vez los que tienen el mismo SKU en las dos tiendas.

= Recomendado: un cron real del servidor =

La entrega de movimientos corre cada minuto con Action Scheduler, que depende de WP-Cron. Sin un cron real, WP-Cron sólo corre cuando alguien visita el sitio, y una tienda sin visitas puede tardar en mandar lo que vendió. En las dos tiendas, agregá a `wp-config.php`:

`define( 'DISABLE_WP_CRON', true );`

y programá en el servidor una llamada por minuto, por ejemplo:

`* * * * * curl -s https://tu-tienda.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1`

= Recomendado: PHP-FPM o LiteSpeed =

Con PHP-FPM o LiteSpeed, lo que vende un pedido sale hacia la otra tienda al terminar el pedido, sin esperar la pasada de cada minuto. Con otros servidores sale en la pasada siguiente.

== Frequently Asked Questions ==

= ¿Funciona con tres o más tiendas? =

No. Esta versión sincroniza exactamente dos.

= ¿Qué pasa si una de las tiendas se cae? =

Las ventas se siguen anotando en la tienda que funciona y salen cuando la otra vuelve, en el orden en que se hicieron. Si la caída dura más de 6 horas, la reconciliación nocturna completa lo que falte.

= ¿Puedo cargar el mismo archivo CSV en las dos tiendas? =

No. Cada importación se propaga a la otra tienda, así que cargar el mismo archivo en las dos duplica el stock. Importá sólo en la maestra.

= Agregué un producto nuevo. ¿Se sincroniza solo? =

No. Hay que darlo de alta en las dos tiendas y vincularlo desde la maestra. Mientras tanto, el plugin lo sugiere en Productos y lo incluye en el email de lo que no se sincroniza.

= ¿Qué pasa si mando a la papelera un producto vinculado? =

El par queda suspendido. Las ventas de la otra tienda se guardan sin tocar el stock y se aplican cuando lo restaurás.

= ¿Qué pasa si un producto deja de gestionar su stock y lo vuelvo a activar? =

El número que cargues al reactivarlo queda en esa tienda y no se manda a la otra: se toma como un recuento del depósito, no como mercadería nueva. Si las dos tiendas quedan con números distintos, se ve en Productos y la maestra lo avisa por email a la noche.

= Las dos tiendas tienen un stock distinto en el mismo producto. ¿Cómo lo arreglo? =

Corregirlo a mano en una tienda no alcanza: el cambio se manda a la otra como diferencia, y las dos se mueven lo mismo. En la maestra, en **Stock Sync → Productos**, abrí el menú del producto y elegí **Igualar stock**: te muestra los dos números y elegís cuál vale.

= ¿Se borran los datos al desinstalar? =

Sólo si lo pedís en **Configuración → Datos**. Si no, los vínculos y los registros quedan guardados por si lo volvés a instalar.

== Credits ==

La interfaz usa tres fuentes con licencia SIL Open Font License 1.1, incluidas en el plugin (`assets/fonts`, cada una con su licencia):

* Bricolage Grotesque — Copyright 2022 The Bricolage Grotesque Project Authors (https://github.com/ateliertriay/bricolage).
* DM Sans — Copyright 2014 The DM Sans Project Authors (https://github.com/googlefonts/dm-fonts).
* JetBrains Mono — Copyright 2020 The JetBrains Mono Project Authors (https://github.com/JetBrains/JetBrainsMono).

== Changelog ==

= 1.2.0 =
* Manual de usuario dentro del plugin: una pantalla propia, con un inicio rápido de cuatro pasos para dejarlo andando y once secciones por tema que se leen sueltas, con capturas de cada paso.
* El menú de WordPress deja de repetir las pantallas: queda una sola entrada, porque el plugin ya las lista en su propia barra lateral.
* Configuración pasa al final de esa barra.

= 1.1.0 =
* Registros tiene dos pestañas: Avisos y Movimientos, con todos los movimientos de stock de la tienda, filtrables por cómo terminaron (bien, esperando, con problemas), tipo, período y producto.
* Las listas de tipos de Registros muestran sólo los que hay, con su cantidad.
* Productos muestra lo que gestiona stock en una tienda y no en la otra; lo que no gestiona en ninguna, con la opción de la rueda dentada.
* Los filtros de Productos ya no quedan tapados por el buscador.
* Un vínculo rechazado o en curso se ve como tal en Movimientos.

= 1.0.0 =
Primera versión.

* Pool único entre dos tiendas WooCommerce: ventas, cancelaciones, reembolsos y cambios a mano o por importación se propagan a la otra.
* Vinculación en la tienda maestra, a mano o en lote, con sugerencias por SKU y los dos stocks a la vista antes de confirmar.
* Entrega con reintentos y en orden después de una caída; envío al terminar el pedido con PHP-FPM o LiteSpeed.
* Reconciliación nocturna: completa lo que no llegó, revisa cada tienda contra sus movimientos y compara las dos tiendas entre sí.
* Igualar stock: cuando las dos tiendas tienen un número distinto, el dueño elige cuál vale y quedan iguales.
* Papelera, productos borrados y gestión de stock reactivada: las ventas retenidas se aplican al volver o se descartan con aviso.
* Avisos por email agrupados, registro de lo que pasó e historial por producto.
* Pausar la sincronización y desemparejar las tiendas desde Configuración.
