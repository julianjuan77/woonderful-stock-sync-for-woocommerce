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
Cubre: includes/Transport/*.php, includes/Rest/*.php

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
secundaria. La secundaria lo canjea contra la maestra en un único request, y en esa respuesta
las dos quedan con: la URL de la otra, su identificador de tienda, y un **secreto compartido
de 256 bits** generado por la maestra. El código de emparejamiento se invalida al canjearse.

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

### 2. Cola — el libro es la cola, Action Scheduler es el disparador

§3 lo fija: **el libro es la garantía, la cola es el camino rápido**. Se toma al pie de la
letra: no hay una segunda tabla de cola. Cada movimiento propio lleva en el libro su estado
de entrega, su contador de intentos y su próximo reintento, y eso es lo único que dice qué
falta entregar.

Action Scheduler —presente siempre, porque §5 exige WooCommerce 8.2+, y sin dependencia nueva
que declarar— agenda una acción recurrente **"vaciar pendientes hacia la otra tienda"** que
toma un lote de movimientos pendientes y los envía. No se agenda una acción por movimiento.

**Por qué no una acción por movimiento.** El estado de entrega quedaría en la tabla de Action
Scheduler y el del movimiento en el libro: dos verdades sobre lo mismo, y la reconciliación
lee el libro. Además una ráfaga de importación (UC-04) crearía miles de acciones para algo que
el lote resuelve en una.

**Por qué no un cron propio.** Action Scheduler aporta el disparo por loopback, la
recurrencia y el no solapamiento de su propia acción, que es trabajo real y ya está instalado.
No aporta más que eso.

**Lo que Action Scheduler no resuelve, y es código propio: el claim de las filas.** Que no
corran dos instancias de la misma acción no equivale a marcar atómicamente qué movimientos
tomó un worker, y AS puede tener runners concurrentes en requests distintos. Sin claim, dos
pasadas envían el mismo lote. El emisor no rompe nada por eso —el receptor deduplica por
identificador (§3)— pero es trabajo duplicado y un ACK que llega dos veces sobre una fila que
otro worker ya está moviendo.

El claim va en el libro y es un `UPDATE` condicional que pasa las filas de `pendiente` a
`en-vuelo` marcando quién las tomó y cuándo; sólo las filas que ese `UPDATE` efectivamente
cambió se envían. Un worker que muere a mitad deja filas en `en-vuelo` para siempre, así que
el estado **vence**: pasado un plazo vuelven a `pendiente` y otra pasada las toma. Es la misma
lógica del candado de UC-06 y por el mismo motivo: sin vencimiento, el primer timeout de PHP
apaga la entrega y nadie se entera.

**Reintentos finitos, como exige UC-01.** El envío reintenta con backoff un número finito de
veces; agotados, el movimiento pasa a **abandonado** y queda registrado en el log. No se
pierde: sigue en el libro y lo entrega la fase 1 de la reconciliación cuando la otra tienda
vuelve (UC-06). El número concreto de intentos y la curva de backoff son configuración, no
contrato.

### 3. Estados — dos ejes, no uno

Un movimiento propio y uno recibido no responden la misma pregunta. Mezclarlos en un campo
obliga a toda consulta a preguntar antes de quién es el movimiento. Se separan:

| Eje | Valores | A qué filas aplica |
| --- | --- | --- |
| **Entrega** | `pendiente` · `en-vuelo` · `confirmado` · `abandonado` | Sólo a los movimientos **propios**. Un movimiento recibido no tiene entrega que hacer. |
| **Aplicación** | `aplicado` · `retenido` | A todo movimiento que deba tocar el stock local. |

**Los dos ejes no conviven en la misma fila: conviven en la misma malla.** Un movimiento
existe como dos filas, una en cada libro, y cada una usa el eje que le toca. La del emisor
lleva estado de entrega y ninguno de aplicación; la del receptor, al revés.

Con eso, el caso que §3 dejó abierto —el movimiento que llega para un par suspendido— deja de
ser especial: queda **`confirmado` en el libro del emisor y `retenido` en el del receptor**.
El emisor recibió su ACK porque cumplió y deja de reintentar; el receptor lo guardó
durablemente y lo aplicará cuando el par se restablezca (UC-03). Las dos filas dicen la
verdad sobre lo que le consta a su tienda, que es todo lo que cada una puede saber.

Consultas que esto deja triviales, y que son las que el diseño realmente hace:

- Qué falta entregar → eje de entrega, sobre movimientos propios.
- Qué aplicar cuando un par se desbloquea → eje de aplicación, `retenido`.
- Qué suma el total esperado en la fase 2 de UC-06 → eje de aplicación, `aplicado`.
- Qué queda fuera de la fase 2 → un par con algún `retenido`.

**Por qué no un quinto valor `held` en un solo campo.** `held` y `pendiente` significarían
cosas opuestas según de quién sea el movimiento, y eso se paga en cada consulta de la
reconciliación.

**Por qué no redefinir `rejected` como reintentable.** Se pierde la forma de expresar un
rechazo definitivo, y el nombre pasa a mentir sobre lo que guarda.

### 4. Superficie REST — `wss/v1`

Cuatro endpoints, todos firmados salvo el canje de emparejamiento, que se autentica con el
código de un solo uso:

| Endpoint | Quién lo llama | Para qué |
| --- | --- | --- |
| `POST /pair` | La secundaria, una vez | Canjea el código de emparejamiento. Ver abajo. |
| `POST /movements` | Cualquiera de las dos | Entrega un **lote** de movimientos. |
| `POST /reconcile/digest` | Quien lanza la pasada | Fase 1 de UC-06: intercambia los límites acordados y qué identificadores conoce cada una dentro de ellos. |
| `GET /health` | Cualquiera | Verifica canal, firma y versión del plugin del otro lado. Es lo que consulta la pantalla de ajustes. |

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

**La fase 2 de UC-06 no necesita endpoint.** Cada tienda se compara contra su propio libro, no
contra el stock de la otra. Es deliberado y está en el SPEC: comparar A contra B daría falsos
positivos por movimientos en vuelo.

### Alcance

Este ADR cubre **cómo viaja un movimiento y quién lo firma**. No cubre: la forma exacta de las
tablas (ADR del esquema), cómo se genera el identificador de tienda y la secuencia (ADR de
identidad), ni cómo se detecta el cambio de stock que origina un movimiento, incluido el guard
anti-eco (ADR del detector).

### Consecuencias para otros ADRs

- El **esquema** tiene que materializar los dos ejes de estado, más intentos y próximo
  reintento en el eje de entrega.
- La **identidad de tienda** tiene que quedar fijada en el emparejamiento: el identificador
  que firma es el mismo que numera los movimientos.
- La **retención** del libro se apoya en el eje de entrega: una fila propia no se purga
  mientras no esté `confirmada`, porque purgarla borra la única copia que la fase 1 de UC-06
  podía reentregar (§3). Eso choca de frente con los 90 días de §5 cuando una tienda estuvo
  caída más tiempo, y la precedencia hay que declararla en el ADR del esquema: **90 días es el
  mínimo, no el máximo**, y lo no confirmado se conserva hasta que se confirme. Queda abierto
  ahí qué se hace con un libro que crece sin techo porque la otra tienda no vuelve nunca: es
  una condición que hay que avisar (UC-08), no purgar en silencio.
- Las **opciones** guardan por cada tienda remota: URL, identificador, secreto compartido y
  fecha del emparejamiento. El secreto es material sensible y `uninstall.php` tiene que
  borrarlo.
