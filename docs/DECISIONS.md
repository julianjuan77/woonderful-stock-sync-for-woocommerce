# Woonderful Stock Sync for WooCommerce — Decisiones de arquitectura (ADRs)

> Cada decisión de diseño vive acá y **necesita aprobación humana explícita** antes de que
> se pueda escribir el código que la implementa.

## Cómo funciona

1. Claude propone el ADR con `Estado: Propuesta` y 2-3 opciones con trade-offs.
2. El humano lo lee y decide. Si aprueba, el `Estado:` pasa a `Aprobada`. **Claude nunca lo
   marca por su cuenta**: la decisión es del humano y ponerlo solo produce una señal falsa.
3. Recién entonces se escribe el código que el ADR declara en `Cubre:`.
4. Si un ADR aprobado cambia de contenido después, hay que volver a mirarlo: la decisión que
   se aprobó ya no es la que está escrita.

**Los tres campos de cabecera son obligatorios.**

- `Estado:` — `Propuesta` · `Aprobada` · `Rechazada` · `Reemplazada-por-ADR-N`
- `Temas:` — uno o más del vocabulario cerrado, separados por coma:
  `db-schema` · `rest-api` · `hooks-publicos` · `opciones` · `capabilities` · `uninstall` ·
  `api-externa` · `dependencias` · `modulos` · `scaffold`
- `Cubre:` — globs de path relativos a la raíz del plugin, separados por coma

Los ADRs no se renumeran ni se borran: un ADR superado pasa a
`Estado: Reemplazada-por-ADR-N`, y así queda el registro de por qué se cambió de idea.

**Orden de trabajo.** Este índice arranca por el transporte y no por el scaffold. El motivo
es que el transporte es lo que condiciona al resto: el esquema del libro depende de qué
estados necesita la entrega, y la identidad de la tienda depende de qué identidad autentica
el canal. `ADR-000` queda reservado para el scaffold, que es convención del entorno y se
aprueba en bloque.

---

## ADR-001 — Transporte entre tiendas

Estado: Propuesta
Temas: rest-api, api-externa, db-schema, opciones
Cubre: includes/Transport/_.php, includes/Rest/_.php

**Decisión:** las dos tiendas se hablan por una **API REST propia bajo el namespace
`wss/v1`**, autenticada con **HMAC-SHA256 y un secreto compartido por par de tiendas**. Lo
que falta entregar vive en el **libro de movimientos**, y **Action Scheduler** —que ya viene
dentro de WooCommerce— solo agenda la tarea que lo vacía. Cada movimiento lleva **dos
estados independientes**: uno de entrega y uno de aplicación.

### 1. Autenticación — HMAC con secreto compartido

La identidad que se autentica es **la tienda**, no una persona. Es lo que §3 necesita:
un movimiento se identifica por tienda de origen más secuencia, así que quien firma el
request tiene que ser la misma entidad que numera los movimientos.

**Emparejamiento, una sola vez.** La maestra genera un **código de emparejamiento** de un
solo uso y con vencimiento corto, que el dueño copia a la pantalla de ajustes de la
secundaria. La secundaria lo canjea contra la maestra en un único request. Cuando ese request termina,
cada tienda guardó tres cosas de la otra: su **URL de REST**, su **identificador de tienda** y
un **secreto compartido de 256 bits** que genera la maestra. El código se invalida al
canjearse.

**Firma, en cada request.** Cada llamada lleva las cabeceras `X-WSS-Store` (identificador de
la tienda emisora), `X-WSS-Timestamp`, `X-WSS-Nonce` y `X-WSS-Signature`, donde la firma es
`HMAC-SHA256( secreto, método + "\n" + ruta + "\n" + timestamp + "\n" + nonce + "\n" + sha256(cuerpo) )`.
El receptor rechaza un request con timestamp fuera de una ventana de **±5 minutos**, o con un
nonce ya visto dentro de esa ventana. El secreto nunca viaja.

**Por qué no Application Passwords de WP core.** Autentican a un **usuario**, no a una
tienda. Que alguien borre ese usuario, le cambie el rol o le revoque la contraseña deja la
malla caída sin ninguna señal de que el problema es ese. Se suma que hay hostings que filtran
la cabecera `Authorization` y sitios que desactivan la función entera, dos fallas
intermitentes difíciles de diagnosticar desde el otro lado.

**Por qué no las claves REST de WooCommerce.** Pertenecen al controlador `wc/v3`: para usarlas
en endpoints propios hay que validarlas a mano contra una tabla interna de WooCommerce que no
es contrato público. Es tanto código como el HMAC, apoyado en algo más frágil, y sigue atado
a un usuario.

**Costo aceptado:** el HMAC es código propio —firma, verificación, almacén de nonces y
tolerancia de reloj— con sus tests. Se acepta porque es la única opción cuya identidad
coincide con la del diseño, y porque §5 ya exige HTTPS, así que no carga un requisito nuevo.

**Detección de clon — la tienda que cambió de sitio se apaga sola.** Al emparejar, cada
tienda guarda también **su propia URL** (`home_url()`). En cada carga la compara con la
actual: si no coinciden, **suspende la sincronización** —no emite movimientos ni acepta los
que lleguen— y la pantalla de ajustes pide re-emparejar.

El motivo es que restaurar una copia de la base en otro sitio se lleva el identificador de
tienda y el secreto compartido adentro. Ese clon firma requests perfectamente válidos contra
la tienda viva del cliente, y numera movimientos con la misma secuencia que el original: dos
movimientos distintos con el mismo identificador global, que es justo lo que la deduplicación
toma por repetido. La firma prueba que quien la hizo tiene el secreto, no dónde corre; la URL
es lo único que el clon no puede llevarse.

**Re-emparejar es también el mecanismo de rotación.** El mismo canje sirve para el secreto
filtrado y para el cambio legítimo de dominio: la maestra emite un código nuevo y el canje
reemplaza el secreto anterior. El identificador de tienda no cambia, así que el libro y las
secuencias ya escritas siguen valiendo.

**Lo que cuesta y lo que no cubre.** Un cambio de dominio querido suspende la malla igual que
un clon, porque desde adentro son indistinguibles. Y queda un caso sin cubrir: el clon que
corre **en la misma URL** —un dump servido en el dominio original, por ejemplo apuntándolo por
`hosts`— pasa el control. No se cubre en v1; es un escenario deliberado, no un accidente de operación, que es
lo que este control ataja.

### 2. Cola — el libro es la cola, Action Scheduler es el disparador

**El libro** es la tabla donde cada tienda registra los movimientos que genera y los que
recibe. §3 fija su papel: **el libro es la garantía, la cola es el camino rápido**.

Se toma al pie de la letra: **no hay una segunda tabla de cola**. Cada movimiento propio lleva
en el libro su estado de entrega, su contador de intentos y su próximo reintento. Eso es lo
único que dice qué falta entregar.

**Action Scheduler sólo dispara.** Agenda una sola acción recurrente —"vaciar pendientes
hacia la otra tienda"— que en cada pasada toma del libro un lote de movimientos pendientes y
los envía. No se agenda una acción por movimiento.

Es gratis en dependencias: §5 exige WooCommerce 8.2+, que ya lo trae.

**Por qué no una acción por movimiento.** El estado de entrega quedaría en la tabla de Action
Scheduler y el del movimiento en el libro: dos verdades sobre lo mismo, y la reconciliación
lee el libro. Además una ráfaga de importación (UC-04) crearía miles de acciones para algo que
el lote resuelve en una.

**Por qué no un cron propio.** Action Scheduler aporta el disparo por loopback, la
recurrencia y el no solapamiento de su propia acción, que es trabajo real y ya está instalado.
No aporta más que eso.

**Claim** es reservar filas antes de trabajarlas: marcarlas como tomadas por un worker, de
forma que otra pasada ya no las vea disponibles.

**Lo que Action Scheduler no resuelve, y es código propio: el claim de las filas.** AS
garantiza que no corran dos instancias de la misma acción a la vez. Eso no es lo mismo que
marcar qué filas tomó cada worker, y AS puede tener runners corriendo en requests distintos.

Sin claim, dos pasadas leen las mismas filas pendientes y envían el mismo lote. No se rompe
nada —el receptor deduplica por identificador (§3)— pero es trabajo duplicado, y el ACK llega
dos veces sobre una fila que el otro worker ya está moviendo.

El claim va en el libro y es un `UPDATE` condicional que pasa las filas de `pendiente` a
`en-vuelo` marcando quién las tomó y cuándo; sólo las filas que ese `UPDATE` efectivamente
cambió se envían. Un worker que muere a mitad deja filas en `en-vuelo` para siempre, así que
el estado **vence**: pasado un plazo vuelven a `pendiente` y otra pasada las toma. Es la misma
lógica del candado de UC-06 y por el mismo motivo: sin vencimiento, el primer timeout de PHP
apaga la entrega y nadie se entera.

**Lo vencido lo libera la misma pasada**, como primer paso antes de reclamar: un `UPDATE` que
devuelve a `pendiente` todo lo que lleva demasiado en `en-vuelo`. No hay barrido aparte ni
acción propia en Action Scheduler — una tarea más que agendar y vigilar para algo que la
pasada ya tiene que hacer igual antes de mirar qué hay pendiente.

**Que una fila vencida se reenvíe ya entregada es el caso normal, no una anomalía.** Si el
worker murió después de que el receptor guardó el movimiento pero antes de anotar el ACK, la
fila revive y el lote sale otra vez. El receptor deduplica por identificador y **vuelve a
confirmar** (§3), así que la segunda vuelta cierra la fila. No hace falta ningún mecanismo
para evitarlo: evitarlo cuesta más que pagarlo.

**El techo del reintento es de tiempo, no de intentos.** El envío reintenta con backoff hasta
que pasan **6 horas desde el primer intento**; ahí el movimiento pasa a **abandonado** y queda
registrado en el log. No se pierde: sigue en el libro y lo entrega la fase 1 de la
reconciliación cuando la otra tienda vuelve (UC-06). Los intentos siguen siendo finitos, como
exige UC-01, porque el backoff crece.

El techo se mide en tiempo porque es contra tiempo que hay que leerlo: §5 acepta que un
desvío dure hasta 24 horas, que es lo que tarda la reconciliación nocturna en repararlo. Un
movimiento abandonado por una caída de diez minutos gastaría ese plazo entero por nada. Con
un techo en cantidad de intentos, el compromiso queda escondido dentro de la curva de backoff
y un cambio de configuración lo rompe sin que nadie lo note; con un techo en horas se lee de
un vistazo y se prueba con un test. La curva de backoff y las 6 horas son configuración; que
el techo se exprese en tiempo, no.

### 3. Estados — dos ejes, no uno

Un movimiento propio y uno recibido no responden la misma pregunta. Mezclarlos en un campo
obliga a toda consulta a preguntar antes de quién es el movimiento. Se separan:

| Eje            | Valores                                                | A qué filas aplica                                                                     |
| -------------- | ------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| **Entrega**    | `pendiente` · `en-vuelo` · `confirmado` · `abandonado` | Sólo a los movimientos **propios**. Un movimiento recibido no tiene entrega que hacer. |
| **Aplicación** | `aplicado` · `retenido` · `descartado`                 | A todo movimiento que deba tocar el stock local.                                       |

**En prosa se nombran en español; en el código y en la base, en inglés.** Es lo que espera
cualquiera que audite la tabla y lo que pide la guía de WordPress.org:

| Eje        | En prosa      | En la base   |
| ---------- | ------------- | ------------ |
| Entrega    | pendiente     | `pending`    |
| Entrega    | en vuelo      | `in_flight`  |
| Entrega    | confirmado    | `confirmed`  |
| Entrega    | abandonado    | `abandoned`  |
| Aplicación | aplicado      | `applied`    |
| Aplicación | retenido      | `held`       |
| Aplicación | descartado    | `discarded`  |

**Ninguna fila lleva los dos ejes.** Un movimiento existe como **dos filas**, una en el libro
de cada tienda, y cada una usa el eje que le toca: la del emisor lleva estado de entrega y
ninguno de aplicación; la del receptor, al revés.

Con eso, el caso que §3 dejó abierto —el movimiento que llega para un par suspendido— deja de
ser especial: queda **`confirmado` en el libro del emisor y `retenido` en el del receptor**.
El emisor recibió su ACK porque cumplió y deja de reintentar; el receptor lo guardó
durablemente y lo aplicará cuando el par se restablezca (UC-03). Las dos filas dicen la
verdad sobre lo que le consta a su tienda, que es todo lo que cada una puede saber.

**`descartado` es el estado terminal del eje de aplicación: lo que no se va a aplicar
nunca.** §3 fija que cada vínculo tiene una **generación** y que el receptor sólo aplica los
movimientos de la generación vigente. Un delta que quedó en cola del vínculo anterior y llega
después de un bootstrap nuevo no es `aplicado` ni es `retenido`: no espera nada, porque la
época a la que pertenece ya no corre. Se confirma igual —el ACK dice "lo tengo", no "lo
apliqué"— y el motivo queda en el log.

Sin ese tercer valor esos movimientos se quedarían en `retenido` para siempre y
envenenarían las dos consultas de abajo: la de qué aplicar al desbloquear un par los vería
en cada pasada, y la de qué queda fuera de la fase 2 dejaría ese par afuera de la
reconciliación para siempre.

**La única causa de `descartado` hoy es la generación no vigente.** Sumar otra es una
decisión de diseño y va a un ADR: el estado dice qué hacer con la fila, y la tentación de
usarlo como cajón de lo que no se supo aplicar es justo lo que rompe la consulta de
`retenido`.

Consultas que esto deja triviales, y que son las que el diseño realmente hace:

- Qué falta entregar → eje de entrega, sobre movimientos propios.
- Qué aplicar cuando un par se desbloquea → eje de aplicación, `retenido`.
- Qué suma el total esperado en la fase 2 de UC-06 → eje de aplicación, `aplicado`.
- Qué queda fuera de la fase 2 → un par con algún `retenido`.


### 4. Superficie REST — `wss/v1`

Cuatro endpoints, todos firmados salvo el canje de emparejamiento, que se autentica con el
código de un solo uso:

| Endpoint                 | Quién lo llama         | Para qué                                                                                                  |
| ------------------------ | ---------------------- | --------------------------------------------------------------------------------------------------------- |
| `POST /pair`             | La secundaria, una vez | Canjea el código de emparejamiento. Ver abajo.                                                            |
| `POST /movements`        | Cualquiera de las dos  | Entrega un **lote** de movimientos.                                                                       |
| `POST /reconcile/digest` | Quien lanza la pasada  | Fase 1 de UC-06: intercambia los límites acordados y qué identificadores conoce cada una dentro de ellos. |
| `GET /health`            | Cualquiera             | Verifica canal, firma y versión del plugin del otro lado. Es lo que consulta la pantalla de ajustes.      |

**El canje de emparejamiento, en detalle.** La secundaria llama a `POST /pair` de la maestra
con el código, **su propia URL de REST y su identificador de tienda**. Antes de responder
nada, la maestra llama a `GET /health` de esa URL firmando con el **código de emparejamiento**
—lo único que las dos conocen todavía— y sólo sigue si responde y declara el mismo
identificador que vino en el request. Recién entonces genera el secreto compartido, lo guarda
junto a la URL e identidad de la secundaria, invalida el código y lo devuelve en la respuesta
al canje.

Sin ese callback, la maestra guardaría una URL que nadie comprobó: un error de tipeo o un
sitio detrás de un firewall que no acepta entrantes (§5) recién se descubriría con la primera
venta. Y de paso prueba el canal en la dirección maestra → secundaria, que si no quedaría sin
probar hasta ese mismo momento. La secundaria acepta un `GET /health` firmado con el código
**sólo mientras tiene un canje en curso**; fuera de esa ventana, el código no autentica nada.

**El ACK es por movimiento, no por lote.** La respuesta de `POST /movements` devuelve una
entrada por cada identificador recibido. Un lote de 50 donde uno falla confirma los otros 49;
si el ACK fuera por lote, ese único fallo obligaría a reenviar los 50 y el emisor no tendría
cómo saber cuál fue.

**El ACK confirma recepción durable, no efecto**, como fija §3. Un movimiento repetido se
descarta y **se confirma igual**; un movimiento retenido se guarda y **se confirma igual**. La
confirmación se emite después de que la fila está escrita, no antes: con la deduplicación
apoyada en el `UNIQUE` del identificador global, que el movimiento ya esté presente significa
que ya está guardado.

**Qué campos lleva cada movimiento del cuerpo de `POST /movements`.** Este ADR fija **qué
información tiene que viajar**; cómo se escribe cada campo lo fija el ADR que manda sobre esa
pieza:

| Campo        | Qué es                                                           | Quién fija su formato  |
| ------------ | ---------------------------------------------------------------- | ---------------------- |
| `id`         | Identificador global: tienda de origen + secuencia               | ADR de identidad       |
| `generacion` | Generación del vínculo vigente cuando se emitió el movimiento    | ADR de vinculación     |
| `delta`      | Entero con signo. §5 no admite decimales                         | Este ADR               |
| `producto`   | ID local del producto **en la tienda de origen**                 | ADR de identidad       |
| `motivo`     | `venta`, `devolución`, `ajuste`, `bootstrap`                     | ADR del detector       |
| `referencia` | El dato que permite auditarlo después: el número de pedido, etc. | ADR del detector       |

**El que no puede faltar es `generacion`.** §3 fija que el receptor sólo aplica los
movimientos de la generación vigente del par. Si el número no viaja, el receptor no tiene con
qué decidir: aplica un delta de una época que ya no corre y el stock queda mal contra un libro
que no lo cuenta. Es lo que deja sin uso al `descartado` de §3 de este ADR.

El **bootstrap** viaja como un movimiento más, con campos adicionales que §3 exige para poder
auditarlo —los dos IDs apareados y el stock previo de cada tienda—. Los define el ADR de
vinculación.

**El receptor declara un máximo de movimientos por lote: 200.** Un lote más grande se rechaza
con un error explícito, no se intenta procesar. Sin tope, la ráfaga de importación de UC-04
puede mandar miles de movimientos en un request y hacerle timeout al receptor; el emisor lee
ese timeout como caída y reintenta el mismo lote imposible hasta agotar las 6 horas. El
tamaño que usa el emisor es configuración, pero el techo del receptor es contrato.

**Lo que la reconciliación repara vuelve por la cola de siempre.** La fase 1 de UC-06 no
entrega por su cuenta: devuelve las filas a `pendiente` **con el reloj del techo en cero** y
las manda la pasada normal. Un solo camino de salida en vez de dos, y sin reiniciar el reloj
un movimiento reparado nacería abandonado, porque su primer intento fue hace días.

**La fase 2 de UC-06 no necesita endpoint.** Cada tienda se compara contra su propio libro, no
contra el stock de la otra. Es deliberado y está en el SPEC: comparar A contra B daría falsos
positivos por movimientos en vuelo.

**El contrato es el namespace, y dentro del namespace el receptor es tolerante.** Las dos
tiendas se actualizan por separado —son dos sitios distintos y nadie los actualiza en el mismo
segundo—, así que convivir con una versión de diferencia es lo normal, no la excepción:

- **Dentro de `wss/v1`, el receptor ignora los campos que no conoce.** Una versión nueva puede
  sumar campos al movimiento y el otro lado los descarta sin romperse. Quitar un campo o
  cambiarle el significado, no: eso es `wss/v2`.
- **Contra un namespace que no se sirve, se suspende y se avisa.** Si la otra tienda ya sólo
  responde `wss/v2`, sus endpoints `wss/v1` devuelven 404: la sincronización se detiene y la
  pantalla de ajustes dice que hay que actualizar, en vez de acumular movimientos abandonados
  sin explicación.

`GET /health` devuelve la versión del plugin del otro lado, y es lo que la pantalla de ajustes
usa para mostrar el desfasaje antes de que moleste.

### Alcance

Este ADR cubre **cómo viaja un movimiento, qué campos lleva y quién lo firma**. No cubre: el
formato de cada uno de esos campos (ver la tabla de §4), la forma exacta de las tablas (ADR
del esquema), cómo se genera el identificador de tienda y la secuencia (ADR de
identidad), ni cómo se detecta el cambio de stock que origina un movimiento, incluido el guard
anti-eco (ADR del detector).

### Consecuencias para otros ADRs

- El **esquema** tiene que materializar los dos ejes de estado, más intentos y próximo
  reintento en el eje de entrega.

- El **esquema** guarda en cada fila propia la **hora del primer intento**, que es contra lo
  que se mide el techo de 6 horas; el contador de intentos sigue haciendo falta para la curva
  de backoff, pero ya no decide el abandono.

- El **esquema** carga además con el **almacén de nonces**: la tabla de nonces vistos y su
  purga por fuera de la ventana de ±5 minutos, que sin purga crece sin techo.
- La **identidad de tienda** tiene que quedar fijada en el emparejamiento: el identificador
  que firma es el mismo que numera los movimientos.
- La **retención** del libro se apoya en el eje de entrega: una fila propia no se purga
  mientras no esté `confirmado`. Purgarla borraría la única copia que la fase 1 de UC-06
  podía reentregar (§3).

  Eso choca con los 90 días de §5 en cuanto una tienda estuvo caída más tiempo. La precedencia
  la declara el ADR del esquema: **90 días es el mínimo, no el máximo**, y lo no confirmado se
  conserva hasta que se confirme.

  Ahí queda abierto el caso del libro que crece sin techo porque la otra tienda no vuelve
  nunca. Es una condición que hay que avisar (UC-08), no purgar en silencio.

- Las **opciones** guardan por cada tienda remota: URL, identificador, secreto compartido y
  fecha del emparejamiento; y del lado propio, la **URL del sitio al momento de emparejar**,
  que es lo que detecta el clon. El secreto es material sensible y `uninstall.php` tiene que
  borrarlo.
