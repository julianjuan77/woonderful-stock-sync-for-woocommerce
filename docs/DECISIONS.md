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
---

## ADR-000 — Scaffold

Estado: Aprobada · 2026-09-24 · aprobada por Julián
Temas: scaffold, dependencias
Cubre: woonderful-stock-sync-for-woocommerce.php, composer.json, phpcs.xml, includes/Plugin.php

Convención del entorno, se aprueba en bloque. Va numerado 000 aunque se haya escrito último:
el orden de trabajo arrancó por el transporte porque era lo que condicionaba al resto.

### La estructura

```
woonderful-stock-sync-for-woocommerce.php   cabecera, constantes, autoload, hooks de arranque
uninstall.php                               ADR-003
composer.json                               PSR-4 → Woonderful\StockSync\ = includes/
phpcs.xml                                   WordPress-Core/Docs/Extra + PHPCompatibilityWP
includes/                                   Schema · Identity · Ledger · Transport · Reconciliation
admin/                                      sólo con is_admin()
tests/
```

### Lo que el archivo principal hace, y nada más

```
define WSS_PLUGIN_FILE, WSS_PLUGIN_DIR
require vendor/autoload.php   (si falta → aviso en el admin y no arranca)
register_activation_hook → Plugin::activate()
plugins_loaded            → Plugin::boot()
before_woocommerce_init   → declarar compatibilidad con HPOS
```

**El autoload es el de Composer, sin plan B.** El zip de distribución trae `vendor/`; un
checkout de git no, y en ese caso el plugin avisa en vez de morirse con un fatal. Escribir un
autoloader propio de respaldo sería mantener dos mecanismos para lo mismo.

**HPOS se declara en `before_woocommerce_init`** porque WooCommerce exige que la declaración
llegue antes de su propio init: hacerlo en `plugins_loaded` a secas llega tarde según el orden
de carga.

**La activación genera la identidad antes de crear las tablas** (ADR-002): es lo primero que
el plugin escribe.

### Requisitos

Los declara la cabecera y los verifica WordPress, no el plugin:

```
Requires at least: 6.5      Requires PHP: 8.1      Requires Plugins: woocommerce
```

`Requires Plugins` existe desde WordPress 6.5, que es justo el mínimo: WordPress impide
activar el plugin sin WooCommerce y no hace falta escribir esa verificación. El `boot()` la
repite igual, porque WooCommerce puede desactivarse después.

### Consecuencias

| ADR          | Qué le queda                                                                    |
| ------------ | --------------------------------------------------------------------------------- |
| **Todos**    | El `Cubre:` de cada ADR es un path dentro de `includes/`, con el namespace que le corresponde |

---

## ADR-001 — Transporte entre tiendas

Estado: Aprobada · 2026-09-20 · aprobada por Julián
Revisado: 2026-09-20 — los nombres del contrato (campos del JSON y valores de `reason`)
pasaron a inglés, para no mezclar idiomas con los estados de §3. Sin cambios de diseño.
Revisado: 2026-09-21 — ADR-005 suma un quinto endpoint, `GET /products`, y dos valores de
`reason`: `bootstrap` y `unlink` viajan como movimientos. Ver §4.
Revisado: 2026-09-27 — §3 dice qué pasa cuando el producto local no gestiona stock: bloquea y
queda `held`. Responde la primera de las tres preguntas de la asimetría de configuración (§6
del SPEC); las otras dos siguen abiertas.
Revisado: 2026-09-27 — §3 nombra las **dos** causas de `discarded`: la generación no vigente
y la ausencia de vínculo, que ADR-004 §1 ya daba por decidida. Y deja dicho que el `bootstrap`
y el `unlink` se atienden antes de buscar el vínculo, porque lo crean y lo borran.
Revisado: 2026-09-27 — los ejemplos de §4 mostraban `"id": "A-77"`, de antes de que ADR-002
resolviera el formato. El identificador viaja como dos campos, `origin` y `seq`: `A-77` es
sólo la forma de mostrarlo. No es un cambio de diseño — §4 ya delegaba el formato.
Revisado: 2026-09-26 — ADR-007 cambia la huella a `sha256( get_option( 'home' ) )`:
`home_url()` varía con el request y con quien la filtre. Ver §1, detección de clon.
Revisado: 2026-09-28 — aprobado por Julián. §1 fija qué se firma exactamente: la ruta
REST y no la URL, que cambia con los permalinks; la query ordenada, que la fórmula no cubría y
en `GET /products` decide qué se devuelve; y el formato de cada campo. Ver §1, "Qué entra en
la firma, exactamente".
Revisado: 2026-09-28 — aprobado por Julián. Los nonces se guardan **10 minutos**, no 5:
la ventana acepta timestamps adelantados, así que un request puede seguir pasándola hasta 10
minutos después de recibido, y con el nonce ya borrado el reenvío entraba. Y el nonce se
anota **después** de verificar la firma. Ver §1, "El almacén de nonces".
Revisado: 2026-09-28 — aprobado por Julián. §4 fija cómo responde `POST /movements`: el
`origin` de cada movimiento tiene que ser la tienda que firmó; sólo se rechaza lo que impide
identificar o aplicar un movimiento, y el `reason` desconocido se guarda como llega; los
resultados vuelven en el orden del lote; y cada rechazo del lote entero tiene su código. Ver
§4, "Cómo responde `POST /movements`".
Revisado: 2026-09-29 — aprobado por Julián. El tamaño de lote del emisor, la curva de
backoff, las 6 horas del techo y los 5 minutos del claim son **constantes**, no configuración:
nadie pidió moverlos, y el código ya los tenía así. El lote es 200, igual al techo del
receptor: un valor mayor haría que el receptor rechace todos los lotes. Ver §2 y §4.
Revisado: 2026-09-29 — aprobado por Julián. §4 fija qué responde `GET /health`: la
identidad, la etiqueta, la versión, la API y si está suspendida y por qué. Responde aunque la
sincronización esté suspendida, y no anota el nonce: sólo lee. Ver §4, "Qué responde
`GET /health`".
Revisado: 2026-09-30 — aprobado por Julián. §1 fija el emparejamiento en detalle: el código
y su vencimiento, cómo se firman `POST /pair` y el callback, HTTPS en los dos lados salvo en
desarrollo, qué guarda cada tienda, que funciona aunque el sitio esté suspendido y los
errores. Ver §1, "El emparejamiento, en detalle".
Revisado: 2026-09-30 — corrige la anterior: la maestra no puede guardar sólo el hash del
código, porque para verificar un HMAC hace falta la clave con que se firmó. Las dos tiendas
guardan el código mismo, con su vencimiento. Ver §1, "El emparejamiento, en detalle".
Revisado: 2026-09-30 — aprobado por Julián. El emparejamiento rechaza a una tienda con el
mismo identificador, como pide ADR-002: `wss_same_store`, 409. Lo miran las dos puntas. Ver
§1, "El emparejamiento, en detalle".
Temas: rest-api, api-externa, db-schema, opciones
Cubre: includes/Transport/_.php, includes/Rest/_.php

**La decisión, en cuatro líneas:**

1. Las dos tiendas se hablan por una **API REST propia**, namespace `wss/v1`.
2. Cada request va firmado con **HMAC-SHA256 y un secreto compartido**. Lo que se autentica es
   **la tienda**, no una persona.
3. **Lo que falta entregar vive en el libro de movimientos.** No hay tabla de cola aparte.
   Action Scheduler sólo agenda la tarea que lo vacía.
4. Cada movimiento lleva **dos estados independientes**: uno de entrega y uno de aplicación.

**Los casos de uso del SPEC que se citan acá**, para no tener que abrirlo:

| UC        | De qué se trata                                                                     |
| --------- | ------------------------------------------------------------------------------------ |
| **UC-01** | Una venta descuenta stock en las dos tiendas. Es de donde sale que no haya reintento eterno |
| **UC-03** | Vinculación de productos. Un par cuyo producto del otro lado desaparece queda **suspendido**: sus movimientos se retienen hasta que vuelva |
| **UC-04** | Un cambio manual de stock se propaga como ajuste. Incluye la carga de un CSV, que es una ráfaga de miles |
| **UC-06** | **Reconciliación.** Fase 1: los libros se comparan y cada una entrega lo que a la otra le falta. Fase 2: cada tienda compara su stock real contra lo que dice su libro |
| **UC-08** | Aviso por email de lo que no está sincronizando                                     |

> Los valores de los ejemplos (`tienda-a`, `A-77`, nombres de columna) son **ilustrativos**.
> El formato de cada campo lo fijan los ADR de identidad y de esquema; acá se fija qué tiene
> que existir, no cómo se escribe.

---

### 1. Autenticación — HMAC con secreto compartido

#### Así se ve un request entre tiendas

```http
POST /wp-json/wss/v1/movements HTTP/1.1
Host: secundaria.com
Content-Type: application/json
X-WSS-Store:     tienda-a
X-WSS-Timestamp: 1758300000
X-WSS-Nonce:     7f3c1b9e4a2d...
X-WSS-Signature: 9a4e6c...

{"movements":[ ... ]}
```

La firma se calcula así:

```
HMAC-SHA256( secreto,
             método + "\n" + ruta + "\n" + query + "\n" + timestamp + "\n" + nonce + "\n" + sha256(cuerpo) )
```

#### Qué entra en la firma, exactamente

El texto que se firma tiene **seis líneas**, separadas por `\n` y sin salto al final:

```
POST                                   ← método, en mayúsculas
/wss/v1/products                       ← la ruta REST
page=3&per_page=100                    ← la query, ordenada; vacía si no hay
1758300000                             ← timestamp, en segundos enteros
7f3c1b9e4a2d…                          ← el nonce, tal como viaja en la cabecera
e3b0c44298fc1c149afbf4c8996fb924…      ← sha256 del cuerpo crudo, en hex; el de "" si no hay
```

La firma es `hash_hmac( 'sha256', $texto, $secreto )` en hexadecimal en minúsculas, y se compara
con `hash_equals()`.

**La ruta es la ruta REST, no la URL.** Es lo que WordPress entrega como `get_route()`:
`/wss/v1/products`. La misma llamada llega como `/wp-json/wss/v1/products` o como
`/?rest_route=/wss/v1/products` según los permalinks del sitio, y con un prefijo si WordPress
vive en un subdirectorio. Firmar la URL haría que cambiar los permalinks rompa el canal, con
un error de firma que no dice por qué.

**La query entra en la firma, ordenada.** En `GET /products` la página que se pide va en la
query, y es parte de lo que el receptor usa para decidir qué devolver: fuera de la firma, se
podría cambiar sin romperla. Se arma así, igual de los dos lados:

1. Los parámetros **tal como los ve el receptor**: `get_query_params()`, que WordPress llena con
   `wp_unslash( $_GET )`.
2. **Sin `rest_route`**: con permalinks sin formato WordPress lo mete en la query, y es la ruta,
   que ya va en la segunda línea.
3. Ordenados por nombre, en orden de bytes.
4. Nombre y valor codificados con `rawurlencode()` (RFC 3986), unidos con `=`, y los pares con
   `&`.

**Los parámetros son escalares.** Ninguno de los endpoints usa listas (`ids[]=1&ids[]=2`), y
normalizarlas es donde este tipo de esquema se desincroniza entre los dos lados: un request
con un parámetro que no es escalar no verifica.

**Es lo que hace AWS SigV4**, que §*Prior art* ya cita: la query canónica ordenada y codificada
es parte del texto firmado.

**Por qué no la query sin firmar.** Con HTTPS y un nonce de un solo uso, cambiarla en tránsito
es difícil, y `GET /products` sólo lee. Pero quedaba un hueco en la firma que había que
declarar, para ahorrarse un orden y una codificación.

**El secreto no viaja en ninguna llamada.** Vive en la base de las dos tiendas y sólo se usa
para calcular la firma. La única vez que cruza la red es en la respuesta del canje (paso 5 de
abajo), que es por lo que §5 exige HTTPS.

#### El receptor rechaza el request si

| Condición                                    | Qué significa                                    |
| -------------------------------------------- | ------------------------------------------------ |
| La firma no coincide                         | Quien llamó no tiene el secreto                  |
| El timestamp está fuera de **±5 minutos**    | Es un request viejo, capturado y reenviado       |
| El nonce ya se vio dentro de esa ventana     | Es el mismo request mandado dos veces            |
| `X-WSS-Store` no es una tienda emparejada    | No hay secreto con qué verificar nada            |

La única excepción es el `GET /health` del paso 4 del emparejamiento: va firmado con el
código, contra una tienda que todavía no está emparejada, y sólo se acepta mientras hay un
canje en curso.

#### El almacén de nonces

Es una tabla propia. **La purga la hace la misma pasada recurrente que vacía pendientes** (§2),
que corre en las dos tiendas: borra todo nonce anotado hace **más de 10 minutos**.

**Por qué 10 y no 5.** Un nonce sirve mientras un request con ese nonce pueda pasar la ventana,
y la ventana vale para los dos lados: acepta un timestamp hasta 5 minutos **adelantado**.

```
El reloj de A adelanta 5 min: su request dice 12:05 y en B son las 12:00.

12:00  B lo acepta (5 min de diferencia) y anota el nonce
12:06  con 5 min de retención, la purga lo borra
12:09  el mismo request, reenviado: 12:05 contra 12:09 → 4 min, pasa la ventana;
       el nonce ya no está → se acepta de nuevo  ❌
```

El peor caso es un timestamp 5 minutos adelantado, que la ventana acepta hasta 5 minutos
después de esa hora: 10 minutos desde que se recibió. Guardado ese tiempo, mientras el request
pueda pasar la ventana su nonce sigue anotado. Son **dos ventanas**, y así se escribe en el
código, para que no se separen si la ventana cambia.

**Por qué no guardar el timestamp del request y purgar por él.** Cada nonce se borraría justo
cuando deja de servir, pero `seen_at` pasaría a significar otra cosa: un cambio de esquema con
migración para ahorrarse unas filas de más, en una tabla que con dos tiendas tiene pocas.

**El nonce se anota después de que la firma verifica, no antes.** Anotarlo antes dejaría que
cualquiera sin el secreto llene la tabla con requests de firma inventada. El orden del
receptor es: tienda emparejada → firma y ventana → nonce.

#### El emparejamiento, paso a paso (ocurre una sola vez)

```
1. Maestra    → genera un código de un solo uso, con vencimiento corto, que lleva
                adentro su propia URL de REST y su identificador
2. El dueño   → lo copia a la pantalla de ajustes de la secundaria
3. Secundaria → lo decodifica: ya sabe a dónde llamar y con quién se está emparejando
                → POST /pair a la maestra { código, su URL de REST, su identificador }
4. Maestra    → GET /health a esa URL, firmando con el código
                (¿responde? ¿declara el mismo identificador que dijo en el paso 3?)
5. Maestra    → genera el secreto de 256 bits, guarda, invalida el código, y responde
                al paso 3 con { secreto, su identificador }
```

**El código es autocontenido y el dueño copia una sola cosa:**

```
wss_eyJ1cmwiOiJodHRwczovL21hZXN0cmEuY29tL3dwLWpzb24vd3NzL3YxIiwiaWQiOiJ0aWVuZGEtYSIsImMiOiIuLi4ifQ
       └─ URL de REST de la maestra + su identificador + el código propiamente dicho
```

Sin eso, el dueño tendría que tipear la URL de la maestra en la secundaria: el paso 4 cubre
el error de tipeo en una dirección, pero nada cubriría el de la otra. De paso el código queda
largo y opaco, así que adivinarlo a fuerza de intentos deja de ser una preocupación.

Al terminar, cada tienda tiene guardado de la otra:

```
url_rest:      https://secundaria.com/wp-json/wss/v1
identificador: tienda-b
secreto:       <256 bits>
emparejado_el: 2026-09-20
```

**Por qué el paso 4 (el callback) y no confiar en la URL que vino en el request.** Sin él, la
maestra guardaría una dirección que nadie comprobó:

```
URL con un error de tipeo        → se descubre con la primera venta, días después
Sitio detrás de un firewall
que no acepta entrantes (§5)     → se descubre con la primera venta, días después
Con callback                     → falla en el momento del emparejamiento, con el dueño mirando
```

De paso prueba el canal en la dirección maestra → secundaria, que si no quedaría sin probar.
La secundaria acepta un `GET /health` firmado con el código **sólo mientras tiene un canje en
curso**; fuera de esa ventana, el código no autentica nada.

#### El emparejamiento, en detalle

**El código.** `wss_` seguido de un JSON en base64url con tres campos:

```json
{ "url": "https://maestra.com/wp-json/wss/v1", "id": "8f4e2a91-…", "c": "<256 bits en hex>" }
```

**Vence a los 15 minutos y vale para un solo canje exitoso.** Si el callback falla, el dueño
puede reintentar con el mismo código mientras no venza; al primer canje que termina bien se
invalida. La maestra lo guarda con su vencimiento en `wss_pairing`.

**Se guarda el código mismo, no su hash.** Para verificar una firma HMAC hace falta la clave
con que se firmó: con el hash, la maestra no tendría con qué verificar el `POST /pair`, y si el
hash fuera la clave, una copia de la base se lo llevaría igual. Lo que acota el riesgo es la
vida corta —15 minutos, y se borra al primer canje exitoso—: una copia de la base sólo se lleva
un código usable si se hace en esa ventana.

**`POST /pair` se firma con el código.** La misma firma de seis líneas de "Qué entra en la
firma", con el código en el lugar del secreto y `X-WSS-Store` con el identificador de la
secundaria. El cuerpo:

```json
{ "url": "https://secundaria.com/wp-json/wss/v1", "id": "b1b1…", "label": "Mayorista" }
```

La maestra responde, si todo sale bien:

```json
{ "secret": "<256 bits en hex>", "id": "8f4e2a91-…", "label": "Minorista" }
```

**El callback es un `GET /health` firmado con el código.** La secundaria lo acepta **sólo
mientras está canjeando**: antes de mandar el `POST /pair` guarda el código y el
identificador de la maestra en `wss_redeeming`, con un vencimiento de 2 minutos, y lo borra al
terminar, salga bien o mal. Mientras dura, verifica con el código aunque ya tenga un secreto de
esa maestra: si no, re-emparejar —que es también la rotación— no pasaría nunca el callback. Fuera de esa ventana, el código no autentica nada. La maestra
comprueba que `health` responda y que su `store` sea el `id` del cuerpo.

**HTTPS en los dos lados.** Cada tienda exige que su propia opción `home` sea `https` antes de
emitir o canjear un código —ADR-007: si no, la huella queda en `http` y pasar a HTTPS después
la suspende—, y la maestra exige que la `url` de la secundaria sea `https`. Con HTTPS, la
respuesta de `health`, que no va firmada, no se puede cambiar en el camino, así que no hace
falta firmarla.

**La excepción es desarrollo.** Con `wp_get_environment_type()` en `local` o `development` se
acepta `http`: dos sitios locales no podrían emparejarse nunca, y ahí no hay nadie en el medio
ni una migración a HTTPS que suspenda.

**La secundaria exige también que la `url` de la maestra sea `https`**: el secreto vuelve en la
respuesta del `POST /pair`, por esa dirección.

**Qué guarda cada tienda al terminar**, en `wss_peers[<id de la otra>]`: su `rest_url`, el
`secret`, su `label` y `paired_at`. Y su propia huella en `wss_site_fingerprint`, que es lo que
levanta la suspensión de un sitio que cambió de URL. **Emparejar borra la `rest_url` y el
secreto de cualquier otra entrada**: en v1 hay una sola socia. El `purged_through` se conserva.

**Funciona aunque el sitio esté suspendido.** Re-emparejar es la forma de salir de la
suspensión por un cambio de URL, y es un acto deliberado: el dueño copia el código de una
tienda a la otra.

**Los errores de `POST /pair`:**

| Qué pasó | HTTP | Código |
| --- | --- | --- |
| El código no existe, venció, ya se usó, o la firma no coincide | 401 | `wss_pairing_invalid` |
| Alguna de las dos no es `https` | 400 | `wss_insecure` |
| El cuerpo no trae `url`, `id` o `label` | 400 | `wss_bad_request` |
| La otra tienda tiene el mismo identificador: es una copia de esta base | 409 | `wss_same_store` |
| El callback no respondió, o declaró otro identificador | 502 | `wss_callback_failed` |

**Una tienda con el mismo identificador es una copia de ésta, y se rechaza** (ADR-002). Es un
caso probable: armar la mayorista a partir de una copia de la base de la minorista. El callback
pasaría —`health` dice la verdad: ese es su identificador—, la maestra se guardaría a sí misma
como socia, borraría a la real, y la huella nueva levantaría la suspensión del clon. Lo miran
las dos puntas: la maestra al recibir el `POST /pair`, y la secundaria antes de mandarlo, para
cortar sin salir a la red. **409 y no 400**: el request está bien; lo que choca es el estado de
las dos. La salida es que la copia genere su propio identificador —instalar el plugin desde cero
en ella—; cómo se ofrece en la pantalla lo decide el ADR de interfaz.

**Mientras no hay pantalla** (UC-05, tarea 8.1), el emparejamiento se dispara desde WP-CLI:
`wp wss pairing-code` en la maestra y `wp wss pair <código>` en la secundaria.

#### Detección de clon: la tienda que cambió de sitio se apaga sola

**Un clon es una tienda levantada a partir de una copia de la base de otra**: un entorno de
prueba armado con el backup de producción, un staging, una migración a medio hacer. Nadie lo
crea con mala intención —es la forma normal de probar algo sin tocar la tienda viva— y por eso
mismo es el caso que hay que atajar: la copia se lleva adentro todo lo que hace falta para
hablar como si fuera la original.

Cada tienda guarda **el hash de su propia URL** al emparejar, y lo compara en cada carga:

```
Al emparejar:   sha256( get_option( 'home' ) ) = 3f7a9c2e...   → guardado

En cada carga:  sha256( get_option( 'home' ) ) = 3f7a9c2e...   → todo normal
                sha256( get_option( 'home' ) ) = b41d08e5...   → SUSPENDE: no emite ni acepta nada,
                                                       y la pantalla pide re-emparejar
```

**Se guarda el hash y no la URL porque restaurar una base reescribe las URLs.** Es lo que hace
cualquier herramienta de copia a un entorno de prueba, `wp search-replace --all-tables` entre
ellas: cambia la opción `home` de WordPress **y de paso la nuestra**, las deja coincidiendo, y
el clon se da por sano. Sobre un hash no hay cadena que reemplazar:

```
                    En producción           Después de restaurar en otro sitio
opción 'home'       https://tienda-a.com  → http://localhost:8130
guardando la URL    https://tienda-a.com  → http://localhost:8130   coinciden → NO suspende
guardando el hash   3f7a9c2e...           → 3f7a9c2e...             ≠ del actual → suspende
```

El costo es que la pantalla de ajustes no puede mostrar desde qué URL se emparejó esta tienda:
un hash no se lee al revés. La URL de la **otra** tienda se sigue guardando en claro, porque
hace falta para llamarla.

**Por qué.** Restaurar una copia de la base en otro sitio se lleva adentro el identificador de
tienda y el secreto. Ese clon firma requests perfectamente válidos contra la tienda viva del
cliente, y numera movimientos con la misma secuencia que el original:

```
Original: movimiento A-78 = venta de 1 unidad
Clon:     movimiento A-78 = lo que sea que hiciste probando
          → el receptor ve dos veces "A-78", deduplica, y se queda con el equivocado
```

La firma prueba que quien la hizo **tiene el secreto**, no **dónde corre**. La URL es lo único
que el clon no puede llevarse — siempre que se guarde como hash.

**Re-emparejar es también la rotación.** El mismo canje del paso 1-5 sirve para el secreto
filtrado y para el cambio legítimo de dominio. El identificador de tienda no cambia, así que
el libro y las secuencias ya escritas siguen valiendo.

**Lo que cuesta y lo que no cubre:**

| Caso                                              | Qué pasa                                  |
| ------------------------------------------------- | ----------------------------------------- |
| Cambio de dominio querido                         | Suspende igual. Se resuelve re-emparejando |
| Clon servido **en la misma URL** (por `hosts`)    | Por `http`, suspende: el esquema es parte de la huella (ADR-007). Por `https`, **pasa el control**. No se cubre en v1 |

El segundo es un escenario deliberado, no un accidente de operación, que es lo que este
control ataja.

#### Por qué no las alternativas

| Alternativa                      | Qué falla                                                                                                                         |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Application Passwords** de WP  | Autentican un **usuario**, no una tienda. Que le borren el usuario, le cambien el rol o le revoquen la contraseña deja la malla caída sin señal de por qué. Además hay hostings que filtran la cabecera `Authorization` y sitios que desactivan la función entera |
| **Claves REST de WooCommerce**   | Pertenecen al controlador `wc/v3`. En endpoints propios hay que validarlas a mano contra la tabla interna de WooCommerce, que no es contrato público: es tanto código como el HMAC, apoyado en algo más frágil, y sigue atado a un usuario |

**Por qué no un esquema de firma ya escrito.** Hay dos candidatos y ninguno encaja:

| Candidato | Qué falla |
| --- | --- |
| [RFC 9421](https://datatracker.ietf.org/doc/html/rfc9421/), *HTTP Message Signatures* | Es el estándar de verdad y lo usan Cloudflare y OpenAI, pero la adopción todavía es incipiente y no hay implementación en el ecosistema WordPress: habría que sumar una dependencia para firmar cuatro endpoints propios |
| El de los webhooks de WooCommerce | Firma **sólo el cuerpo** (`base64( hash_hmac( 'sha256', $payload, $secret ) )` en `X-WC-Webhook-Signature`), así que un request capturado se puede reenviar tal cual. Nosotros firmamos además método, ruta, timestamp y nonce |

**Costo aceptado:** el HMAC es código propio —firma, verificación, almacén de nonces y
tolerancia de reloj— con sus tests. Se acepta porque es la única opción cuya identidad
coincide con la del diseño, y porque §5 ya exige HTTPS: no agrega un requisito nuevo.

---

### 2. Cola — el libro es la cola, Action Scheduler es el disparador

#### La decisión

```
NO:  tabla de movimientos  +  tabla de cola     → dos verdades sobre lo mismo
SÍ:  tabla de movimientos con estado de entrega → una sola
```

§3 del SPEC lo dice así: **el libro es la garantía, la cola es el camino rápido.** Se toma al
pie de la letra. Cada movimiento propio lleva en el libro su estado de entrega, su contador de
intentos, la hora de su primer intento y su próximo reintento. Eso es lo único que dice qué
falta entregar.

**Action Scheduler sólo dispara:** una acción recurrente, "vaciar pendientes hacia la otra
tienda", que toma un lote por pasada. No se agenda una acción por movimiento. Es gratis en
dependencias: §5 exige WooCommerce 9.1+, que ya lo trae.

#### Qué hace cada pasada, en orden

```sql
-- 1. Libera lo que quedó colgado de una pasada anterior
UPDATE wss_movements SET delivery = 'pending'
 WHERE delivery = 'in_flight' AND claimed_at < NOW() - INTERVAL 5 MINUTE;

-- 2. Reclama un lote para este worker  ← esto es el "claim"
UPDATE wss_movements SET delivery = 'in_flight', claimed_by = <worker>, claimed_at = NOW()
 WHERE delivery = 'pending' AND next_attempt_at <= NOW()
 LIMIT <tamaño de lote>;

-- 3. Envía SÓLO las filas que el paso 2 cambió, y anota el ACK de cada una
```

**Claim** es eso: reservar filas antes de trabajarlas, para que otra pasada ya no las vea
disponibles. **El claim vence a los 5 minutos**: alcanza para un lote que va lento y es corto
frente a las 6 horas del techo de reintento, así que una fila colgada no cuesta casi nada. Es
una constante, como el resto de los tiempos de la entrega (ver *El techo del reintento*).

**Por qué el paso 2 es código propio y Action Scheduler no lo resuelve.** AS garantiza que no
corran dos instancias de la misma acción a la vez. Eso no es lo mismo que marcar qué filas
tomó cada worker, y AS puede tener runners en requests distintos:

```
Sin claim:  pasada 1 lee las 50 filas pendientes → envía
            pasada 2 lee las MISMAS 50           → envía de nuevo
            → no se rompe nada (el receptor deduplica) pero es trabajo al pepe,
              y el ACK llega dos veces sobre una fila que el otro worker está moviendo
```

**Por qué el paso 1, y por qué lo hace la misma pasada.** Un worker que muere a mitad deja
filas en `in_flight` para siempre. Sin vencimiento, el primer timeout de PHP apaga la entrega
y nadie se entera — es la misma lógica del candado de la reconciliación (UC-06), que también
vence solo para que una pasada muerta a mitad no la apague para siempre. Va como primer paso
de la pasada
y no como acción aparte: una tarea más que agendar y vigilar para algo que la pasada tiene que
hacer igual antes de mirar qué hay pendiente.

#### Que algo ya entregado se reenvíe es normal, no una anomalía

```
08:00:00  worker  → envía el lote
08:00:01  receptor → lo guarda y responde ACK
08:00:02  worker  → MUERE antes de anotar el ACK
08:06:00  pasada siguiente → el claim venció (5 min), vuelve a 'pending', se reenvía
08:06:01  receptor → "ya lo tengo": lo descarta y CONFIRMA igual (§3)
          → la fila cierra en la segunda vuelta
```

No hace falta ningún mecanismo para evitarlo. Evitarlo cuesta más que pagarlo.

#### El techo del reintento es de tiempo, no de intentos

```
08:00  primer intento  → falla (la otra tienda está caída)
       reintentos con backoff: 08:01, 08:03, 08:07, 08:15, 08:31, 09:03, ...
14:00  pasaron 6 horas desde el primer intento → 'abandoned' + log
       (no se pierde: sigue en el libro y lo entrega la reconciliación nocturna —UC-06,
        la pasada que compara los dos libros y reparte lo que a cada uno le falta)
```

Los intentos siguen siendo finitos —lo exige UC-01, el caso de la venta que descuenta en las
dos tiendas: sin reintento eterno— porque el backoff crece.

**Por qué en tiempo y no en cantidad de intentos.** Porque es contra tiempo que hay que leerlo:
§5 acepta que un desvío dure hasta 24 horas, que es lo que tarda la reconciliación nocturna en
repararlo.

| Techo                    | Qué pasa con una caída de 10 minutos                                       |
| ------------------------ | -------------------------------------------------------------------------- |
| **Por intentos (5)**     | Se agotan en minutos → `abandoned` → el desvío dura hasta la madrugada     |
| **Por tiempo (6 horas)** | A los 10 minutos la otra tienda vuelve y el movimiento entra. Sin desvío   |

Con un techo en cantidad de intentos el compromiso queda escondido dentro de la curva de
backoff, y un cambio lo rompe sin que nadie lo note. **Que el techo se exprese en tiempo es la
decisión; cuánto tiempo es un número.**

**La curva, las 6 horas y los 5 minutos del claim son constantes del código, no opciones**,
por lo mismo que el retardo de entrega de ADR-006 §4: nadie pidió moverlos, y una opción hay
que ponerla en una pantalla, validarla y bancarla para siempre. Si el uso real mostrara que
alguno no sirve, se cambia en una versión.

#### Qué se registra, y dónde

**El estado vive en la fila; el log registra lo que cambia.** Un reintento fallido no escribe
una línea de log: actualiza la fila.

```
Fila del libro (la parte de entrega):

  created_at:        lun 08:00:00    ← cuándo se emitió el movimiento
  first_attempt_at:  lun 08:00:03    ← contra esto se mide el techo de 6 h
  attempts:          7
  last_error:        "cURL 28: Operation timed out"
  next_attempt_at:   lun 14:31:00
  confirmed_at:      mar 03:01:12    ← cuándo la otra tienda lo confirmó
```

Con eso, **cuánto tardó en sincronizar es una resta**: `confirmed_at − created_at`, 19 horas y
1 minuto en el ejemplo del rescate de §4. No hace falta ninguna línea de log para contestarlo,
y se puede mostrar en la pantalla sin leer un archivo.

**El log recibe una línea por pasada y por evento, nunca por movimiento reintentado:**

```
lun 08:00  ERROR  lote de 200 hacia tienda-b: cURL 28 timeout
lun 14:00  ERROR  200 movimientos abandonados hacia tienda-b tras 6 h sin entregar
mar 03:00  INFO   reconciliación: 200 movimientos reabiertos hacia tienda-b
mar 03:01  INFO   200 movimientos entregados; no queda nada pendiente hacia tienda-b
```

**Por qué no una línea por reintento.** Una sola caída de 6 horas con 200 movimientos en cola
daría unas 2.000 líneas, todas diciendo lo mismo, y habría que purgarlas aparte. Los 7
intentos individuales no agregan nada que el contador no diga, y de los errores el único que
sirve para diagnosticar es el último. Es el mismo criterio con el que UC-01 descarta escribir
una entrada por cada venta de un producto sin vincular: un log que se llena de ruido tapa
justo lo que hay que ver.

#### Por qué no las alternativas

| Alternativa                            | Qué falla                                                                                                                                  |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| **Una acción de AS por movimiento**    | El estado de entrega quedaría en la tabla de AS y el del movimiento en el libro: dos verdades, y la reconciliación lee el libro. Además una ráfaga de importación —UC-04, el cambio manual de stock que se propaga como ajuste; una carga de CSV son miles de golpe— crearía miles de acciones para algo que el lote resuelve en una |
| **Un cron propio**                     | AS ya aporta el disparo por loopback, la recurrencia y el no solapamiento de su propia acción, y ya está instalado. Escribirlo de nuevo no agrega nada |

---

### 3. Estados — dos ejes, no uno

Un movimiento propio y uno recibido no responden la misma pregunta. Mezclarlos en un campo
obliga a toda consulta a preguntar antes de quién es el movimiento. Se separan:

| Eje            | Valores                                   | A qué filas aplica                                     |
| -------------- | ----------------------------------------- | ------------------------------------------------------ |
| **Entrega**    | pendiente · en vuelo · confirmado · abandonado | Sólo a los movimientos **propios**                |
| **Aplicación** | aplicado · retenido · descartado          | A todo movimiento que deba tocar el stock local        |

**En prosa se nombran en español; en el código y en la base, en inglés:**

```
Entrega:     pending · in_flight · confirmed · abandoned
Aplicación:  applied · held · discarded
```

#### Ninguna fila lleva los dos ejes

Un movimiento existe como **dos filas**, una en el libro de cada tienda:

```
Venta de 1 unidad en la tienda A → movimiento A-77

   Libro de A (emisor)              Libro de B (receptor)
   ────────────────────             ─────────────────────
   id:          A-77                id:          A-77
   entrega:     confirmed           entrega:     —        (no tiene nada que entregar)
   aplicación:  —                   aplicación:  applied
```

Con eso, el caso que §3 del SPEC dejó abierto —el movimiento que llega para un par
suspendido— deja de ser especial:

```
   Libro de A (emisor)              Libro de B (receptor)
   entrega:    confirmed            aplicación: held
   → recibió su ACK, deja           → lo guardó durablemente y lo aplicará
     de reintentar                    cuando el par se restablezca (UC-03)
```

Un par queda **suspendido** cuando se rompe —borraron el producto del otro lado, o se vació la
papelera—: el vínculo y el stock base se conservan, y sus movimientos quedan retenidos hasta
que vuelva. Es UC-03, la vinculación de productos.

Las dos filas dicen la verdad sobre lo que le consta a su tienda, que es todo lo que cada una
puede saber.

#### El recorrido completo, de los dos lados

```
VENTA DE 1 UNIDAD EN LA TIENDA A

  Tienda A (emisor)                          Tienda B (receptor)
  ─────────────────                          ───────────────────
  Stock 10 → 9   (lo hace WooCommerce)
  El detector lo ve y escribe A-77:
      entrega:    pending
      aplicación: —
         │
         │  pasada, paso 2: claim
         ▼
      entrega:    in_flight
         │
         │  POST /movements  ───────────▶   escribe A-77 en su libro:
         │                                      entrega:    —
         │                                      aplicación: applied
         │                                  Stock 10 → 9
         │                                  (con el guard anti-eco, para que
         │                                   su detector no emita nada)
         │      ◀───────────  ACK
         ▼
      entrega:    confirmed                 Las dos quedan en 9
```

**Qué escribe B según con qué se encuentra:**

| Situación en B al llegar A-77           | Aplicación en B      | Stock de B  | Qué responde |
| --------------------------------------- | -------------------- | ----------- | ------------ |
| Todo normal                             | `applied`            | −1          | confirmado   |
| Ya lo tenía (es un reenvío)             | queda como estaba    | sin cambios | confirmado   |
| El par está suspendido: le borraron el producto del otro lado (UC-03) | `held` | sin cambios | confirmado |
| Vino de una generación vieja            | `discarded`          | sin cambios | confirmado   |

**La fila de B guarda cuándo lo aplicó** (`applied_at`), que no es lo mismo que cuándo lo
confirmó: un movimiento `held` se confirma enseguida y puede aplicarse días después, cuando el
par vuelve. Es un dato de auditoría y vive sólo en B:

```
En la pantalla de B   → gratis: B sabe cuándo aplicó, escribe su propia fila
En la pantalla de A   → costaría un mensaje más. El ACK ya salió hace días,
                        así que B tendría que avisar después o A preguntar
```

No se hace: el ACK confirma recepción y no efecto (§3), y la pregunta que el dueño realmente
se hace —"¿por qué este producto no sincroniza?"— ya la contesta otro camino, el email de
UC-08 sobre el par que quedó afuera de la fase 2.

**Y los caminos que puede tomar la fila de A:**

| Qué pasó con el envío                        | Entrega en A                      | Cuándo         |
| -------------------------------------------- | --------------------------------- | -------------- |
| Llegó el ACK                                 | `confirmed`                       | el mismo request |
| Falló: timeout, 500, o error de esa fila     | vuelve a `pending` + backoff      | enseguida      |
| El worker murió sin anotar nada              | sigue `in_flight` → `pending`     | a los 5 min    |
| Pasaron 6 h desde el primer intento          | `abandoned`                       | —              |
| La reconciliación nocturna lo reparó (UC-06) | `pending`, con el reloj en cero   | esa madrugada  |

Una venta en B hace exactamente lo mismo al revés: **la maestra no interviene en los
movimientos** (§3 del SPEC), así que los dos lados son simétricos.

#### `discarded`: lo que no se va a aplicar nunca

§3 del SPEC fija que cada vínculo tiene una **generación**, y que el receptor sólo aplica los
movimientos de la generación vigente:

```
generación 3   El dueño desvincula y vuelve a vincular los dos productos
generación 4   ← la que corre ahora

Llega A-77, emitido en la generación 3, que quedó en cola de antes
   → no es 'applied':   no se aplica, la época a la que pertenece ya no corre
   → no es 'held':      no espera nada, nunca va a poder aplicarse
   → es 'discarded', se confirma igual, y el motivo queda en el log
```

Sin ese tercer valor esos movimientos quedarían en `held` para siempre y envenenarían dos de
las consultas de abajo: la de qué aplicar al desbloquear un par los vería en cada pasada, y la
de qué queda fuera de la fase 2 dejaría ese par afuera de la reconciliación para siempre.

**Las causas de `discarded` son dos, y sólo dos:**

| Causa | Por qué no se va a aplicar nunca |
| --- | --- |
| La generación no es la vigente | La época a la que pertenece ya no corre |
| **No hay vínculo para ese producto** | El par se desvinculó: no hay a qué producto local aplicarlo, y nada lo va a volver a crear |

**Sumar una tercera es una decisión de diseño y va a un ADR**: la tentación de usarlo como
cajón de lo que no se supo aplicar es justo lo que rompe la consulta de `held`. Un movimiento
que **podría** aplicarse más tarde —el producto está en la papelera, el par está suspendido—
es `held`, no `discarded`.

**Una asimetría de configuración bloquea: `held`.** Si el producto local existe pero no
gestiona stock, no hay dónde aplicar el movimiento. Es la pregunta abierta de §6 del SPEC —las
dos tiendas configuradas distinto para el mismo par— y de las tres cosas que esa pregunta pide
decidir, ésta responde la primera:

| Qué se decide acá | |
| --- | --- |
| ¿Bloquea o aplica a medias? | **Bloquea.** `held`, nunca `discarded` y nunca una aplicación parcial |
| ¿Se le toca la configuración a la otra tienda? | **No.** Es la restricción dura de §6: activarle la gestión de stock sería una escritura que el dueño no pidió y no vería |

Quedan sin decidir las otras dos —que el dueño lo **vea** en la pantalla, y que los movimientos
retenidos se apliquen solos cuando corrija la configuración— y ninguna es propia de este caso:
**hoy ningún movimiento `held` se reintenta**, ni siquiera el del producto en la papelera,
porque la pieza que los desbloquea está sin escribir.

**El `bootstrap` y el `unlink` son la excepción, y se atienden antes de buscar el vínculo.**
Esos dos movimientos no usan el vínculo: uno lo **crea** y el otro lo **borra**. Un bootstrap
llega justamente cuando el vínculo todavía no existe (ADR-005 §2), así que preguntar por el
vínculo antes de mirarlos descartaría el movimiento que venía a crearlo — y como `discarded`
se confirma con ACK, el par no se crearía nunca y nadie se enteraría.

#### Las consultas que esto deja triviales

| Pregunta                                   | Consulta                                      |
| ------------------------------------------ | --------------------------------------------- |
| Qué falta entregar                         | eje de entrega, sobre movimientos propios     |
| Qué aplicar cuando un par se desbloquea    | eje de aplicación = `held`                    |
| Qué suma el total esperado en la fase 2 de UC-06 —la que compara el stock real contra el que dice el libro— | los **propios** de la generación vigente + los **recibidos** con `applied` |
| Qué par queda fuera de la fase 2           | el que tenga algún `held`                     |

**Ojo con la tercera.** La fila propia no tiene eje de aplicación, así que sumar sólo
`applied` deja afuera lo que la tienda hizo ella misma:

```
Tienda A, producto con stock base 10:
   A-77  venta local en A, -1   → aplicación: —          sólo 'applied' → NO lo cuenta
   B-42  venta en B, -1         → aplicación: applied                   → lo cuenta
   → daría 9, y el stock real es 8
```

Por eso la suma es **propios + recibidos `applied`**, y en las dos mitades sólo los de la
generación vigente del par.

---

### 4. Superficie REST — `wss/v1`

#### Los cuatro endpoints

| Endpoint                 | Quién lo llama         | Para qué                                                                    |
| ------------------------ | ---------------------- | --------------------------------------------------------------------------- |
| `POST /pair`             | La secundaria, una vez | Canjea el código de emparejamiento (§1). Se firma **con el código**, igual que el `GET /health` del callback; todo lo demás va firmado con el secreto |
| `POST /movements`        | Cualquiera de las dos  | Entrega un **lote** de movimientos                                          |
| `POST /reconcile/digest` | Quien lanza la pasada  | Fase 1 de UC-06 (la reconciliación): qué identificadores conoce cada una dentro de los límites acordados, para detectar cuál le falta a quién |
| `GET /health`            | Cualquiera             | Canal, firma y versión del plugin del otro lado. Es lo que consulta la pantalla de ajustes |
| `GET /products`          | La maestra             | Sólo lectura del catálogo del otro lado, paginado: la pantalla de vinculación lo necesita para sugerir pares y para mostrar los dos stocks antes de un bootstrap (ADR-005 §5) |

#### Qué campos lleva cada movimiento

Este ADR fija **qué información tiene que viajar**. Cómo se escribe cada campo lo fija el ADR
que manda sobre esa pieza:

```json
{ "movements": [
    {
      "origin":     "8f4e2a91-c7b3-4d05-8a1f-9c48b2703d17",
      "seq":        77,
      "generation": 4,
      "delta":      -1,
      "product":    123,
      "reason":     "sale",
      "reference":  "pedido #4512"
    }
] }
```

| Campo        | Qué es                                                        | Quién fija su formato |
| ------------ | ------------------------------------------------------------- | --------------------- |
| `origin`     | La tienda que lo emitió                                       | ADR de identidad      |
| `seq`        | Su número dentro de esa tienda. Con `origin`, el identificador global | ADR de identidad |
| `generation` | La generación del vínculo vigente cuando se emitió            | ADR de vinculación    |
| `delta`      | Entero con signo. §5 no admite decimales                      | Este ADR              |
| `product`    | ID local del producto **en la tienda de origen**              | ADR de identidad      |
| `reason`     | `sale`, `refund`, `adjustment`, `bootstrap`, `unlink`         | ADR del detector      |
| `reference`  | Con qué se audita después: el número de pedido, etc.          | ADR del detector      |

**Los nombres del contrato van en inglés**, igual que los estados de §3 y por el mismo motivo:
es lo que va a leer cualquiera que audite el tráfico entre las dos tiendas, y un JSON con
tildes adentro (`"devolución"`) es una fuente de problemas de codificación que no hace falta
tener.

**El que no puede faltar es `generation`:**

```
Sin generation: {"origin":"8f4e…","seq":77,"delta":-1,"product":123}
   → el receptor no tiene con qué saber si es de la época que corre
   → lo aplica, y el stock queda mal contra un libro que no lo cuenta
   → el 'discarded' de §3 queda sin uso, porque nunca hay con qué decidir descartar
```

El **bootstrap** viaja como un movimiento más, con campos adicionales que §3 exige para poder
auditarlo: los dos IDs apareados y el stock previo de cada tienda. Los define el ADR de
vinculación.

#### El ACK es por movimiento, no por lote

```json
{ "results": [
    {"origin": "8f4e…", "seq": 77, "status": "received"},
    {"origin": "8f4e…", "seq": 78, "status": "received"},
    {"origin": "8f4e…", "seq": 79, "status": "error", "reason": "..."}
] }
```

```
ACK por lote:       falla 1 de 50 → hay que reenviar los 50,
                    y el emisor no tiene cómo saber cuál falló
ACK por movimiento: falla 1 de 50 → se confirman 49 y se reintenta 1
```

**El ACK confirma recepción durable, no efecto**, como fija §3:

| Lo que le pasó al movimiento en el receptor | Qué responde  |
| ------------------------------------------- | ------------- |
| Se aplicó                                   | confirmado    |
| Llegó repetido y se descartó                | confirmado    |
| Quedó `held` porque el par está suspendido  | confirmado    |
| Quedó `discarded` por generación vieja      | confirmado    |

La confirmación se emite **después** de que la fila está escrita, no antes. Con la
deduplicación apoyada en el `UNIQUE` del identificador global, que el movimiento ya esté
presente significa que ya está guardado: confirmarlo es decir la verdad.

#### Cómo responde `POST /movements`

**Los resultados vuelven en el mismo orden y en la misma cantidad que los movimientos del
lote**, cada uno con su `origin` y su `seq` cuando se pudieron leer. El emisor empareja por
posición: un movimiento con el `seq` roto no se puede identificar de otra forma.

**El `origin` de cada movimiento tiene que ser la tienda que firmó el request** (`X-WSS-Store`).
Si no coincide, ese movimiento vuelve con `error` y no se escribe. Sin este control, B podría
mandar un movimiento "de A con seq 78": chocaría contra el 78 propio de A en el `UNIQUE` del
libro, y si ese número todavía no existe, entraría como fila recibida y el `record()` de A
chocaría después contra ella. La venta de A se perdería sin aviso.

**Se rechaza sólo lo que impide identificar o aplicar un movimiento.** Un `error` hace que el
emisor reintente durante 6 horas y después lo abandone: si reintentar no cambia nada, el delta
se pierde hasta la reconciliación. Así que:

| Campo | Qué se exige | Si no |
| --- | --- | --- |
| `origin` | La tienda que firmó | `error` |
| `seq` | Entero mayor que cero | `error` |
| `generation` | Entero, cero o más | `error` |
| `delta` | Entero | `error` |
| `product` | Entero mayor que cero | `error` |
| `reference` | Texto | Más de 100 caracteres **se recorta**, como al estamparla (ADR-006 §2) |
| `reason` | Texto | **Se guarda como llega**, recortado a los 20 caracteres de la columna |

**Por qué el `reason` desconocido no se rechaza.** Las dos tiendas se actualizan por separado
(ver *Compatibilidad entre versiones*): una versión nueva puede sumar un motivo que la vieja
todavía no conoce. Rechazarlo perdería stock por la etiqueta; guardarlo tal cual conserva el
delta y el historial dice lo que mandó el otro lado.

**Lo que rechaza el lote entero** corta antes de tocar el libro, con un código que dice qué
pasó. En el orden en que se mira (§1):

| Qué pasó | HTTP | Código |
| --- | --- | --- |
| La tienda no está emparejada, o no tiene secreto | 401 | `wss_unknown_store` |
| El timestamp está fuera de la ventana | 401 | `wss_stale_timestamp` |
| La firma no coincide | 401 | `wss_bad_signature` |
| El nonce ya se vio | 401 | `wss_replayed_nonce` |
| El cuerpo no es un JSON con una lista `movements` | 400 | `wss_bad_request` |
| Más de 200 movimientos | 413 | `wss_batch_too_large` |

Que el código diga cuál fue sirve para diagnosticar, sobre todo un reloj desfasado. Lo único
que revela es si un identificador de tienda está emparejado, y un UUID no se adivina.

**Una tienda sin secreto, o con el secreto vacío, es una tienda no emparejada.** `verify()`
ya rechaza el secreto vacío, pero el endpoint no depende de eso.

#### Qué responde `GET /health`

Firmado con el secreto, con la misma autenticación que `POST /movements` salvo el nonce:

```json
{ "store": "b1b1b1b1-0000-4000-8000-000000000000",
  "label": "Minorista",
  "version": "0.1.0",
  "api": "wss/v1",
  "suspended": null }
```

| Campo | Qué es |
| --- | --- |
| `store` | La identidad de la tienda que responde (ADR-002) |
| `label` | Su etiqueta, para que la pantalla de la otra se lea (ADR-002) |
| `version` | La versión del plugin, para mostrar el desfasaje antes de que moleste (*Compatibilidad entre versiones*) |
| `api` | El namespace que sirve: hoy `wss/v1` |
| `suspended` | `null`, o por qué está suspendida: `cloned_site` o `unhealthy_schema` |

Que responda prueba el canal; que pase la autenticación prueba la firma.

**Responde aunque la sincronización esté suspendida, y dice por qué.** Es lo único que deja a
la otra tienda explicar en su pantalla por qué no le llegan movimientos, en vez de una falla
muda. `POST /movements` no se registra en un sitio suspendido; `health` sólo lee.

**No anota el nonce.** Reenviar un `health` no hace nada: sólo lee. Y con la tabla de nonces
rota —una de las causas de `unhealthy_schema`— anotarlo haría responder `wss_replayed_nonce`
justo cuando lo que hay que decir es que las tablas están mal.

**La respuesta no va firmada.** Con HTTPS alcanza para la pantalla de ajustes. Si el paso 4 del
emparejamiento necesita más —ahí la maestra le cree a lo que declara `store`—, lo decide la
tarea del emparejamiento.

#### El receptor declara un máximo de 200 movimientos por lote

```
Lote de 200   → se procesa
Lote de 5000  → se rechaza con un error explícito
```

Sin tope, la ráfaga de importación de UC-04 —una carga de CSV que cambia el stock de miles de
productos de golpe— puede hacerle timeout al receptor; el emisor lee
ese timeout como caída y reintenta el mismo lote imposible hasta agotar las 6 horas. **El techo
del receptor es contrato.**

**El emisor manda lotes de ese mismo tamaño, y es una constante, no configuración.** Una opción
tendría que validarse contra el techo del otro lado: poner 500 en los ajustes haría que el
receptor rechace todos los lotes y no salga nunca ningún movimiento. Y nadie pidió moverla, por
lo mismo que el retardo de entrega de ADR-006 §4: si el volumen real del catálogo mostrara que
200 no sirve, se cambia en una versión.

#### Compatibilidad entre versiones

Las dos tiendas se actualizan por separado, así que convivir con una versión de diferencia es
lo normal, no la excepción:

```
Maestra nueva manda un campo que la secundaria vieja no conoce
   → la secundaria lo IGNORA, aplica el movimiento y confirma        ✅ sigue andando

Maestra ya sólo sirve wss/v2, la secundaria sigue en wss/v1
   → 404 en el endpoint → se suspende y la pantalla pide actualizar  ✅ falla ruidoso
```

Dentro de `wss/v1` se pueden **sumar** campos. Quitar uno o cambiarle el significado es
`wss/v2`. `GET /health` devuelve la versión del otro lado, y es lo que la pantalla de ajustes
usa para mostrar el desfasaje antes de que moleste.

#### Lo que repara la reconciliación vuelve por la cola de siempre

```
Lunes  08:00  Venta en A → A-77 sale a entregarse. B está apagada.
Lunes  08:00  reintentos con backoff: 08:01, 08:03, 08:07... todos fallan
   a   14:00
Lunes  14:00  Pasaron las 6 h → 'abandoned'. A-77 queda en el libro de A, sin entregar.
              A tiene stock 9, B sigue en 10: hay desvío.
Lunes  22:00  Vuelve B.
Martes 03:00  Reconciliación (UC-06, la pasada nocturna): comparan qué movimientos tiene cada una
              → "B no tiene A-77"
              → A devuelve la fila a 'pending' y le borra la hora del primer intento
Martes 03:01  La pasada de siempre la toma y la entrega. B aplica el −1 y queda en 9.
```

**La fase 1 no entrega ella misma**: sólo reabre la fila. Un movimiento sale de una sola
manera, por la cola, y no hay dos caminos que mantener.

**Y el reloj tiene que quedar en cero**, no sólo el estado. Si la fila vuelve a `pending`
conservando la hora de su primer intento:

```
Martes 03:00  entrega: pending, primer_intento = lunes 08:00
Martes 03:01  la pasada la toma y chequea el techo antes de mandar:
              ahora − primer_intento = 19 horas > 6 horas
              → 'abandoned' de nuevo, sin haberlo intentado ni una vez
              → y la noche siguiente lo mismo, para siempre
```

Volver a `pending` con el reloj viejo no rescata el movimiento: lo deja en un loop en el que
la reconciliación lo reabre cada noche y la pasada lo vuelve a abandonar sin intentarlo.

#### La fase 2 de UC-06 —comparar stock contra libro— no necesita endpoint

Cada tienda se compara contra su propio libro, no contra el stock de la otra. Es deliberado y
está en el SPEC: comparar A contra B daría falsos positivos por movimientos en vuelo.

---

### Prior art

Nada de acá es nuevo. Cada pieza tiene nombre y está probada fuera de WordPress; lo propio es
la combinación:

| Decisión de este ADR | Cómo se llama | Dónde se usa |
| --- | --- | --- |
| El libro es la cola | **Outbox pattern** | Práctica estándar en microservicios |
| El claim con vencimiento | **Lease** | Sistemas distribuidos; Action Scheduler lo usa para sus propias acciones |
| Firmar método + ruta + timestamp + nonce + hash del cuerpo | **Canonical request signing** | AWS SigV4 |
| Ventana de reloj más nonce de un solo uso | **Replay protection** | Cualquier API firmada |
| Techo de reintento por tiempo | — | Stripe reintenta sus webhooks hasta 3 días |
| Una respuesta por ítem del lote | **Partial success** | APIs de lote |
| El callback del emparejamiento | **Desafío de posesión** | ACME, el protocolo de Let's Encrypt |
| Apagarse si cambió la URL del sitio | **Detección de staging** | Los gestores de licencias de WordPress |

### Alcance

**Cubre:** cómo viaja un movimiento, qué campos lleva y quién lo firma.

**No cubre:**

| Qué                                                        | Dónde va             |
| ---------------------------------------------------------- | -------------------- |
| El formato de cada campo de la tabla de §4                 | ADR de identidad / vinculación / detector |
| La forma exacta de las tablas                              | ADR del esquema      |
| Cómo se genera el identificador de tienda y la secuencia   | ADR de identidad     |
| Cómo se detecta el cambio de stock, incluido el guard anti-eco | ADR del detector  |

### Consecuencias para otros ADRs

| ADR                | Qué le queda pendiente por culpa de este                                                                        |
| ------------------ | ---------------------------------------------------------------------------------------------------------------- |
| **Esquema**        | Materializar los dos ejes de estado; guardar en el eje de entrega `created_at`, `first_attempt_at` (contra el que se mide el techo de 6 h), `attempts`, `last_error`, `next_attempt_at` y `confirmed_at`; en el eje de aplicación, `applied_at`, que en un movimiento `held` es días posterior a la confirmación; crear el **almacén de nonces** con su purga |
| **Identidad**      | El identificador que firma tiene que ser el mismo que numera los movimientos, y queda fijado en el emparejamiento |
| **Retención**      | Una fila propia no se purga hasta que se confirme. Queda abierto el libro que crece sin techo porque la otra tienda no vuelve nunca: es una condición que hay que avisar por email (UC-08, el aviso de lo que no está sincronizando), no purgar en silencio |
| **Opciones**       | Por cada tienda remota: URL, identificador, secreto y fecha del emparejamiento. Del lado propio, **el hash de la URL del sitio al emparejar**, que es lo que detecta el clon: en claro lo reescribiría el `search-replace` de cualquier restore. El secreto es material sensible: `uninstall.php` tiene que borrarlo |


---

## ADR-002 — Identidad de tienda y numeración de movimientos

Estado: Aprobada · 2026-09-20 · aprobada por Julián
Revisado: 2026-09-24 — el identificador pasa a ser un UUID v4 de `wp_generate_uuid4()` en vez
de un hexa de 32 propio: mismo efecto, formato reconocible y una función menos que escribir.
Las columnas pasan a `CHAR(36)`.
Temas: db-schema, opciones, rest-api
Cubre: includes/Identity/_.php

**La decisión, en tres líneas:**

1. Cada tienda tiene un **identificador opaco de 128 bits**, generado al instalar, que no
   cambia nunca. Para las pantallas y el log lleva además una **etiqueta editable**.
2. La **secuencia** de un movimiento propio es el `AUTO_INCREMENT` de su fila en el libro.
   Tiene huecos, y eso es normal.
3. Tienda y secuencia viajan y se guardan **como dos campos separados**, nunca como un string
   `A-77`: eso es sólo la forma de mostrarlo.

ADR-001 ya fijó que **el identificador que firma un request es el mismo que numera los
movimientos**. Este ADR dice qué es ese identificador.

---

### 1. El identificador de tienda

```
Se genera al activar el plugin, una sola vez:
   wss_store_id  = wp_generate_uuid4()   → "8f4e2a91-c7b3-4d05-8a1f-9c48b2703d17"

Y una etiqueta, que el dueño puede cambiar cuando quiera:
   wss_store_label = "Minorista"
```

**Es un UUID v4, el que genera WordPress**, no un formato propio: `wp_generate_uuid4()` está
en el core desde la 4.7 y cualquiera que abra la base reconoce la forma. Inventar un hexa de
32 caracteres no habría agregado nada.

| | Identificador | Etiqueta |
| --- | --- | --- |
| Para qué sirve | Firmar, numerar, deduplicar | Que el log y las pantallas se lean |
| ¿Cambia? | **Nunca** | Cuando el dueño quiera |
| ¿Viaja? | Sí, en cada request | En el emparejamiento y en `GET /health` |

**Por qué opaco y no algo con sentido.** Porque cambiar de identidad invalida el libro: los
movimientos ya escritos quedan huérfanos y la marca de purga de la otra tienda deja de
apuntar a nada. Todo lo que sea legible por una persona es algo que una persona va a querer
corregir.

| Alternativa                    | Qué falla                                                                                      |
| ------------------------------ | ---------------------------------------------------------------------------------------------- |
| **Derivarlo del dominio**      | ADR-001 fija que re-emparejar por cambio de dominio **no** cambia la identidad, justamente para que el libro siga valiendo. Derivado del dominio, mudarse de dominio invalida el libro entero |
| **Que lo elija el dueño**      | Renombrar la tienda pasaría a ser cambiar su identidad. Y un identificador tipeado se puede tipear mal |

**Dos tiendas no pueden terminar con el mismo identificador**, ni por casualidad (128 bits al
azar) ni por el error que sí es probable: emparejar un **clon** —una tienda levantada con una
copia de la base de la otra, un staging o un entorno de prueba (ADR-001 §1)— con su original.
El canje lo rechaza:

```
POST /pair llega declarando  origin = 8f4e2a91-c7b3-...
La maestra ve que ese es SU PROPIO identificador
   → rechaza: "esta tienda es una copia de la maestra, no una segunda tienda"
```

Sin ese control, el dueño emparejaría una copia consigo misma y los movimientos se
deduplicarían contra su propio origen, que es un desastre silencioso.

---

### 2. La secuencia

**Es el `AUTO_INCREMENT` de la fila en el libro.** No hay contador propio ni tabla de
numeración: el número que MySQL le da a la fila al insertarla es la secuencia del movimiento.

```
Libro de la tienda A (una sola tabla: los propios y los recibidos)

  id   de quién es        secuencia del movimiento
   1   propia              A-1      ← venta
   2   recibida de B       —        ← consume el número 2
   3   propia              A-3      ← venta
   4   —                   —        ← INSERT que falló y se revirtió
   5   propia              A-5      ← venta
```

**La secuencia de A es 1, 3, 5: con huecos, y así está bien.** Los huecos tienen dos causas
—las filas recibidas consumen números, y un `INSERT` revertido gasta el suyo— y ninguna es un
síntoma de nada.

**De acá sale una regla, y conviene tenerla presente al escribir la reconciliación: nada puede
deducir de un hueco que falta un movimiento.** Lo único que dice qué falta es comparar listas
de identificadores, que es la fase 1 de UC-06.

Lo que sí se apoya en la secuencia sigue funcionando con huecos, porque son umbrales y no
conteos:

```
Marca de purga (§3 del SPEC):  "B confirmó todo hasta la 412"  → no purgar seq > 412
Límites de la fase 1:          "comparemos de la 300 a la 412"
```

| Alternativa                          | Qué falla                                                                                     |
| ------------------------------------ | ----------------------------------------------------------------------------------------------- |
| **Un contador propio, contiguo**     | Lo único que compraría es detectar un hueco sin esperar la reconciliación, y **la contigüidad no se puede garantizar igual**: un `INSERT` que falla después de tomar el número deja el hueco. Sería construir sobre algo falso, con código y locks propios para cuidar la concurrencia que `AUTO_INCREMENT` ya resuelve |

---

### 3. Cómo se escriben los dos

**Dos campos separados, en el JSON y en la base.**

```json
{ "origin": "8f4e2a91-c7b3-4d05-8a1f-9c48b2703d17", "seq": 77 }
```

```sql
origin_store CHAR(36),  origin_seq BIGINT UNSIGNED,
UNIQUE (origin_store, origin_seq)     ← la deduplicación de §3 del SPEC
```

**`A-77` existe, pero sólo como forma de mostrarlo** en el log, en la pantalla y en los
ejemplos de estos ADRs. Nunca se guarda ni se parsea.

**Por qué no un solo string.** Porque la secuencia se compara como número:

```
Con dos campos:  WHERE origin_store = ? AND origin_seq <= 412      ← usa el índice
Con un string:   hay que partir "8f4e...-77", castear a entero, y un índice
                 de texto no sirve para un rango
```

---

### Prior art

| Decisión de este ADR | Cómo se llama |
| --- | --- |
| Identificador opaco y aleatorio | **UUID v4**, el de `wp_generate_uuid4()` |
| Cada origen numera lo suyo | **Per-source sequence numbers**, como en cualquier replicación |
| (origen, secuencia) como clave de deduplicación | La clave del **idempotent consumer** |

### Alcance

**Cubre:** qué es el identificador de una tienda, cómo se numeran sus movimientos y cómo se
escriben los dos.

**No cubre:**

| Qué                                                           | Dónde va             |
| ------------------------------------------------------------- | -------------------- |
| Las tablas donde viven esas columnas                          | ADR del esquema      |
| La generación del vínculo y el mapeo entre productos          | ADR de vinculación   |
| Cómo se firma con el identificador                            | ADR-001 (aprobado)   |

### Consecuencias para otros ADRs

| ADR             | Qué le queda                                                                                                   |
| --------------- | ---------------------------------------------------------------------------------------------------------------- |
| **Esquema**     | `origin_store CHAR(36)` + `origin_seq BIGINT UNSIGNED` con su `UNIQUE`; la secuencia propia **es** el `AUTO_INCREMENT` de la tabla, así que el libro no lleva contador aparte |
| **Opciones**    | `wss_store_id` (inmutable) y `wss_store_label` (editable). Qué pasa con las dos al desinstalar lo decide ADR-003, junto con el resto de los datos |
| **Scaffold**    | El identificador se genera en la activación, antes de que exista nada más. Es lo primero que el plugin escribe |


---

## ADR-003 — Desinstalación y borrado de datos

Estado: Aprobada · 2026-09-20 · aprobada por Julián
Revisado: 2026-09-30 — suma `wss_pairing` y `wss_redeeming`, el código de un emparejamiento en
curso de cada lado (ADR-001 §1). Se borran **siempre**: son un código vivo, no un dato del
dueño.
Revisado: 2026-09-29 — aprobado por Julián. Suma `wss_pass_checked_at`, la hora del último
control de que la pasada recurrente esté agendada, y se borra **siempre**: no es un dato del
dueño. Y deja dicho que desactivar desagenda la pasada. Ver *Qué borra cada caso* y
*Desactivar no es desinstalar*.
Temas: uninstall, opciones
Cubre: uninstall.php

**Decisión:** una casilla en los ajustes, **desmarcada por defecto**, decide si al desinstalar
se borra todo. **El secreto compartido se borra siempre**, esté como esté la casilla.

```
Ajustes → [ ] Borrar todos los datos al desinstalar     ← desmarcada de fábrica
```

### Qué borra cada caso

| Dato                                      | Casilla desmarcada | Casilla marcada |
| ----------------------------------------- | ------------------ | --------------- |
| Libro de movimientos                      | queda              | se borra        |
| Identidad de la tienda (ADR-002)          | queda              | se borra        |
| Vínculos entre productos y su stock base  | queda              | se borra        |
| Ajustes del plugin                        | queda              | se borra        |
| Almacén de nonces                         | se borra           | se borra        |
| Hora del último control de la pasada      | se borra           | se borra        |
| Código de un emparejamiento en curso      | se borra           | se borra        |
| **Secreto compartido con la otra tienda** | **se borra**       | **se borra**    |

### Por qué desmarcada por defecto

El libro es el historial de stock del negocio, y el stock base de cada vínculo es lo único que
permite reconstruir el total. Perder eso por desinstalar el plugin un rato para probar algo es
irreversible y nadie lo espera:

```
Desinstalo para probar otra cosa, reinstalo a la media hora
   con la casilla desmarcada → el libro sigue ahí, re-emparejo y sigue todo
   con borrado automático    → el historial no vuelve más
```

### Por qué el secreto es la excepción

```
Desinstalás conservando datos:
   libro, identidad, vínculos  → quedan (siguen valiendo: la identidad no cambió)
   secreto                     → se borra
   → al reinstalar, re-emparejás. ADR-001: re-emparejar no cambia la identidad,
     así que el libro y las secuencias siguen sirviendo.
```

Conservar el libro te ahorra perder datos. Conservar el secreto no te ahorra casi nada —el
canje son dos clics— y deja una credencial compartida viva en la base de un plugin que ya no
está instalado: nadie la rota, nadie la mira, y sigue sirviendo para firmar movimientos
válidos contra la otra tienda.

### Desactivar no es desinstalar

Desactivar el plugin no borra nada, ni siquiera el secreto. Es la operación reversible: el
dueño la usa para frenar la sincronización un rato, y espera encontrar todo como estaba.

Lo único que hace es **desagendar la pasada recurrente** de Action Scheduler, que no es un
dato: al reactivar se vuelve a agendar sola. Si WooCommerce se desactivó antes, Action
Scheduler no está y la acción queda agendada; no rompe nada, porque al volver WooCommerce el
callback está enganchado y la pasada sigue.

**Por qué la hora del control se borra siempre.** `wss_pass_checked_at` dice cuándo se
comprobó por última vez que la pasada estuviera agendada, para no mirarlo más de una vez por
hora. No es un dato del dueño ni sirve después de reinstalar: conservarla sólo haría que el
primer control de la reinstalación espere hasta una hora.

### Alcance

**Cubre:** qué se borra al desinstalar y quién lo decide.

**No cubre:** la purga periódica del libro por retención, que es otra cosa y tiene sus propias
reglas duras (no purgar lo que la otra tienda no confirmó, consolidar el stock base al
purgar). Va al ADR del esquema.

### Consecuencias para otros ADRs

| ADR             | Qué le queda                                                                                      |
| --------------- | --------------------------------------------------------------------------------------------------- |
| **Opciones**    | La casilla es una opción más, y es la única que `uninstall.php` tiene que leer antes de borrar nada |
| **Retención**   | Si una tienda borra su libro al desinstalar y la otra no, la marca de purga de la que quedó apunta a una secuencia que del otro lado ya no existe. No se rompe —la fase 1 compara listas y no encuentra nada— pero el ADR de retención tiene que nombrar el caso |
| **Scaffold**    | `uninstall.php` existe desde el primer commit, aunque al principio no borre nada: agregarlo después es la clase de cosa que se olvida |


---

## ADR-004 — Esquema de la base de datos

Estado: Aprobada · 2026-09-20 · aprobada por Julián
Revisado: 2026-09-21 — se quita la columna `status` de `wss_links`. Contradecía UC-03, que
fija que el estado de sincronización es una vista y no se persiste. Ver §2.
Revisado: 2026-09-21 — entra `details TEXT NULL` en `wss_movements`, donde el bootstrap deja
lo que §3 del SPEC exige para poder auditarlo (ADR-005 §6).
Temas: db-schema
Cubre: includes/Ledger/_.php, includes/Schema/_.php

**La decisión, en cuatro líneas:**

1. **Tres tablas propias**, todas **InnoDB**: `wss_movements`, `wss_links`, `wss_nonces`.
2. El libro guarda **las dos formas de identificar el producto**: el vínculo y el dato crudo
   que vino en el JSON.
3. **Tres índices**, ni uno más: la deduplicación, la cola y el vínculo.
4. Al purgar por retención, **el stock base absorbe lo purgado**, y cada tienda declara hasta
   dónde purgó para que la reconciliación no le re-entregue lo que ya borró.

---

### 1. El libro — `wss_movements`

```sql
CREATE TABLE {prefix}wss_movements (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,  -- y la secuencia de las propias
  origin_store     CHAR(36)        NOT NULL,                 -- quién lo emitió (ADR-002)
  origin_seq       BIGINT UNSIGNED NULL,                     -- ver "el instante en NULL"
  generation       INT UNSIGNED    NOT NULL,
  delta            INT             NOT NULL,
  reason           VARCHAR(20)     NOT NULL,                 -- sale, refund, adjustment, bootstrap
  reference        VARCHAR(100)    NULL,                     -- "pedido #4512"
  created_at       DATETIME        NOT NULL,

  link_id          BIGINT UNSIGNED NULL,                     -- a qué par pertenece
  origin_product   BIGINT UNSIGNED NOT NULL,                 -- id del producto EN LA TIENDA DE ORIGEN

  -- eje de entrega: sólo en las filas propias
  delivery         VARCHAR(12)     NULL,                     -- pending, in_flight, confirmed, abandoned
  attempts         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  first_attempt_at DATETIME        NULL,
  next_attempt_at  DATETIME        NULL,
  last_error       VARCHAR(255)    NULL,
  claimed_by       CHAR(36)        NULL,
  claimed_at       DATETIME        NULL,
  confirmed_at     DATETIME        NULL,

  -- eje de aplicación: sólo en las filas recibidas
  application      VARCHAR(12)     NULL,                     -- applied, held, discarded
  applied_at       DATETIME        NULL,

  details          TEXT            NULL,                     -- sólo en bootstrap y unlink

  PRIMARY KEY (id),
  UNIQUE KEY dedup (origin_store, origin_seq),
  KEY delivery_queue (delivery, next_attempt_at),
  KEY by_link (link_id, generation)
) ENGINE=InnoDB {charset_collate};
```

#### `details` guarda lo que sólo existe en algunos movimientos

Una venta no lo usa. Un bootstrap deja ahí los dos IDs apareados y el stock previo de cada
tienda, que es lo que §3 del SPEC exige para poder auditarlo (ADR-005 §6):

```json
{ "target_product": 456, "base_stock": 12,
  "origin_previous_stock": 12, "target_previous_stock": 9 }
```

Columnas propias para eso serían cuatro `NULL` en el 99,9% de las filas, y cada tipo nuevo de
movimiento sumaría más.

#### Por qué guarda las dos formas de identificar el producto

```
link_id         → para las consultas: "todo lo de este par desde el bootstrap"
origin_product  → el dato crudo del JSON, que no depende de que el vínculo exista
```

Con sólo `link_id`, desvincular dos productos deja los movimientos apuntando a la nada: el
historial existe pero ya no se puede leer. Y hay un caso donde `link_id` no se puede llenar —un
movimiento que llega para un par que ya no existe, que queda `discarded`— y la fila tiene que
poder escribirse igual. Por eso `link_id` es `NULL`able y `origin_product` no.

#### El instante en que `origin_seq` está en NULL

La secuencia de un movimiento propio **es** el `AUTO_INCREMENT` de su fila (ADR-002), y ese
número no existe hasta después del `INSERT`:

```sql
START TRANSACTION;
  INSERT INTO wss_movements (origin_store, origin_seq, ...) VALUES ('8f4e…', NULL, ...);
  UPDATE wss_movements SET origin_seq = id WHERE id = LAST_INSERT_ID();
COMMIT;
```

**Va en NULL y no en 0, y eso no es cosmético:** en un índice `UNIQUE` de MySQL, `NULL` no
choca con `NULL`, pero dos ceros sí. Con 0, dos ventas simultáneas se pisarían en el instante
entre el `INSERT` y el `UPDATE`.

La transacción es lo que garantiza que no quede una fila con la secuencia sin llenar. Es la
primera de las dos razones por las que las tablas son **InnoDB explícito** y no lo que el
host tenga de default.

#### Los tres índices, y por qué no hay un cuarto

| Consulta                                                           | Índice           |
| ------------------------------------------------------------------ | ---------------- |
| Deduplicar un movimiento recibido                                  | `dedup`          |
| Reclamar el lote: `delivery='pending' AND next_attempt_at <= NOW()` | `delivery_queue` |
| Liberar claims vencidos: `delivery='in_flight' AND claimed_at < …` | `delivery_queue` (prefijo `delivery`; las filas `in_flight` son 200 como mucho) |
| Sumar la fase 2 de UC-06, por vínculo y generación                 | `by_link`        |
| Leer el historial de un producto                                   | `by_link`        |
| **Purgar por retención**                                           | **ninguno**      |

La purga corre **una vez por noche** sobre unas 18.000 filas (§5 del SPEC: 90 días a ~200
movimientos diarios). Un índice más para eso costaría en cada `INSERT` —o sea, en cada venta—
para ahorrar un escaneo por día. No se pone.

---

### 2. Los pares — `wss_links`

```sql
CREATE TABLE {prefix}wss_links (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  local_product  BIGINT UNSIGNED NOT NULL,   -- id del producto en ESTA tienda
  remote_store   CHAR(36)        NOT NULL,
  remote_product BIGINT UNSIGNED NOT NULL,
  generation     INT UNSIGNED    NOT NULL,   -- cada bootstrap abre una nueva
  base_stock     INT             NOT NULL,   -- el valor del bootstrap vigente
  base_set_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY local (local_product, remote_store),
  KEY remote (remote_store, remote_product)
) ENGINE=InnoDB {charset_collate};
```

Son las columnas que el libro necesita para funcionar. **Cómo se vincula y qué abre una
generación lo decide el ADR de vinculación**, que puede sumar columnas pero no quitar estas.

**No hay columna de estado, y es a propósito.** UC-03 fija que *vinculado*, *suspendido* y
*sin vincular* son una **vista**: se calculan al mostrarlos y no se persisten.

```
¿Existe la fila?                    no →  sin vincular
¿Existe el producto del otro lado?  no →  suspendido
sí a las dos                            →  vinculado
```

Guardar el estado obliga a mantenerlo al día, y nada en el plugin observa la papelera de
WooCommerce: si el dueño restaura el producto del otro lado, la fila quedaría `suspended`
para siempre con el par intacto. Derivado, la pregunta se responde sola la próxima vez que
alguien mire.

---

### 3. Los nonces — `wss_nonces`

```sql
CREATE TABLE {prefix}wss_nonces (
  nonce   CHAR(64) NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (nonce),
  KEY seen_at (seen_at)
) ENGINE=InnoDB {charset_collate};
```

**La `PRIMARY KEY` sobre el nonce es el control, no un adorno.** Verificar un nonce es una
carrera: dos requests con el mismo pueden preguntar "¿ya existe?" antes de que ninguno lo
escriba. Con la clave, el control es una sola sentencia:

```
INSERT → entra el primero, el segundo choca y se rechaza. Atómico, sin locks.
```

**Acá el índice por fecha sí se pone**, al revés que en el libro: esta purga corre **en cada
pasada**, no una vez por noche.

---

### 4. Retención

> §5 del SPEC fija 90 días. §3 pone dos restricciones duras sobre cómo purgar.

#### Qué se puede purgar

| Fila | ¿Se purga a los 90 días? |
| ---- | ------------------------- |
| Propia `confirmed` | Sí: la otra tienda acusó recibo, no hay nada que re-entregar |
| Propia `abandoned` | **No**: todavía no llegó. La entrega la reconciliación |
| Propia `pending` o `in_flight` | **No** |
| Recibida `applied` o `discarded` | Sí |
| Recibida `held` | **No**: está esperando que el par se restablezca |

#### Al purgar, el stock base absorbe lo purgado

El stock esperado es `base_stock + suma de los movimientos posteriores al bootstrap`. Borrar
movimientos sin más rompe la fórmula y la fase 2 reportaría discrepancias falsas en todos los
productos con historia:

```
Antes:    base_stock 10  +  movimientos [-1, -1, +3]  =  11
Purgo sin consolidar:
          base_stock 10  +  movimientos []            =  10   ❌ la fase 2 ve un desvío
Purgo consolidando:
          base_stock 11  +  movimientos []            =  11   ✅ igual que antes
```

La suma y el borrado van **en la misma transacción**. Es la segunda razón del `ENGINE=InnoDB`:
si se cae en el medio, o se consolidó y no se borró —y los movimientos se cuentan dos veces— o
se borró sin consolidar —y el stock esperado queda corto para siempre.

#### Cada tienda declara hasta dónde purgó

Purgar una fila recibida borra también **el registro que la deduplica**, y eso deja un agujero
silencioso:

```
B purga A-77, aplicado hace 91 días
Esa noche, fase 1 de UC-06:
   A dice "tengo A-77"  ·  B no lo encuentra en su libro
   → A se lo re-entrega  ·  B lo aplica de nuevo  →  descuenta dos veces
   → las dos tiendas creen tener razón, y nadie reporta nada
```

Por eso cada tienda guarda, **por tienda remota**, hasta qué secuencia de ella purgó, y la
fase 1 compara sólo por encima de esa marca. Es lo que ADR-001 llama *"los límites acordados"*
del digest:

```
B purgó todo lo de A hasta la seq 4120
   → en el digest declara: "de A, comparemos de la 4121 para arriba"
   → A no le ofrece nada de abajo de esa marca
```

**La marca vive en `wss_peers`** (la opción que ADR-001 ya guarda por tienda remota: URL,
identificador, secreto y fecha del emparejamiento), porque es un dato **por par de tiendas** y
no por vínculo. Una tabla nueva de una sola fila no se justifica.

#### El libro que no para de crecer

Si la otra tienda no vuelve nunca, sus movimientos quedan `abandoned` y **no se purgan**, por
diseño. El libro crece sin techo. Eso **se avisa** (UC-08) y no se resuelve purgando en
silencio: borrar lo que la otra tienda nunca recibió es perder stock sin dejar rastro.

---

### 5. Migraciones

La versión del esquema vive en la opción `wss_schema_version`. Se aplica con `dbDelta` en la
activación y en `plugins_loaded` si la guardada es menor que la del código.

`dbDelta` es quisquilloso y conviene tenerlo presente al escribir el SQL: dos espacios después
de `PRIMARY KEY`, los nombres de índice entre paréntesis, todo en minúsculas salvo los tipos.
El `{charset_collate}` sale de `$wpdb->get_charset_collate()`.

**La activación verifica el motor.** `dbDelta` crea las tablas con `ENGINE=InnoDB`, pero **no
convierte una tabla que ya existe con otro motor**. Si el motor de alguna de las tres no es
InnoDB, el plugin **no sincroniza y avisa**, en vez de hacerlo sin transacciones:

```
Tabla en MyISAM  →  START TRANSACTION se ignora EN SILENCIO
                    El código se ve idéntico y el bug aparece sólo cuando algo se corta
```

Es un caso raro —InnoDB es el default de MySQL desde la 5.5— y por eso mismo la verificación
es de una línea y el aviso es suficiente: no hay que escribir una migración de motor para algo
que casi nadie va a tener.

---

### Prior art

| Decisión de este ADR | Cómo se llama |
| --- | --- |
| No purgar por debajo de lo que la otra tienda conoce | Lo mismo que el `gc_grace_seconds` de Cassandra: borrar un tombstone antes de que todos lo hayan visto lo **resucita** |
| El stock base absorbe lo purgado | **Compactación** / snapshot en event sourcing |
| La `PRIMARY KEY` del nonce como control de concurrencia | **Insert-as-lock** |

### Alcance

**Cubre:** las tres tablas, sus índices y cómo se purga el libro.

**No cubre:**

| Qué                                                     | Dónde va               |
| -------------------------------------------------------- | ---------------------- |
| Qué significa cada estado de `wss_links` y qué abre una generación | ADR de vinculación |
| Qué escribe una fila en el libro                        | ADR del detector       |
| El formato de `origin_store` y `origin_seq`             | ADR-002 (aprobado)     |

### Consecuencias para otros ADRs

| ADR                | Qué le queda                                                                                      |
| ------------------ | --------------------------------------------------------------------------------------------------- |
| **Vinculación**    | Puede sumar columnas a `wss_links`, no quitar las de acá. Un bootstrap nuevo abre generación y reescribe `base_stock`, y eso pasa por la misma transacción que consolida |
| **Reconciliación** | La fase 1 tiene que leer la marca de purga de las dos tiendas y comparar sólo por encima de la más alta |
| **Detector**       | Un movimiento propio se escribe con el `INSERT` + `UPDATE` de la secuencia dentro de una transacción |


---

## ADR-005 — Vinculación de productos

Estado: Aprobada · 2026-09-21 · aprobada por Julián
Temas: db-schema, rest-api
Cubre: includes/Linking/_.php

**La decisión, en cinco líneas:**

1. **El vínculo es un dato; el estado, una vista.** `wss_links` guarda el par, su generación y
   su stock base. *Vinculado*, *suspendido* y *sin vincular* se calculan al mostrarlos.
2. **El alta viaja como el movimiento de bootstrap** y la **baja como un movimiento
   `unlink`**: un solo canal y un solo orden.
3. **La generación de un par es la secuencia del bootstrap que lo abrió.** Nunca se repite.
4. **Un quinto endpoint, `GET /products`**, para que la pantalla llegue al catálogo de la
   otra tienda (UC-05).
5. **Desvincular borra la fila.** No hay baja lógica.

---

### 1. Qué se guarda y qué se deriva

```
Se guarda (wss_links):   el par, la generación, el stock base y cuándo se fijó
Se deriva al mostrar:    vinculado · suspendido · sin vincular
```

```
¿Existe la fila?                    no  →  sin vincular
¿Existe el producto del otro lado?  no  →  suspendido
sí a las dos                            →  vinculado
```

**Por qué derivado y no guardado.** Nada en el plugin observa la papelera de WooCommerce. Con
una columna de estado, mandar el producto del otro lado a la papelera obliga a escribir
`suspended`, y restaurarlo obliga a escribir `linked` — y no hay quién lo haga:

```
El dueño restaura el producto de la papelera
  guardado:  la fila sigue diciendo 'suspended' para siempre, con el par intacto
  derivado:  la próxima vez que alguien mire, dice 'vinculado'
```

Es lo que UC-03 ya había decidido (*"el estado de sincronización es una vista […] no se
persisten"*), y la razón que da es la misma: persistir la suspensión obliga a tener un botón
de reanudar. Este ADR **quita la columna `status`** que ADR-004 había previsto.

---

### 2. El alta — el bootstrap

Lo hace el dueño desde la maestra, y es **la única escritura de valor absoluto del plugin**
(§3 del SPEC). Viaja como un movimiento más:

```json
{
  "id": "A-77", "generation": 77, "delta": 0,
  "product": 123, "reason": "bootstrap", "reference": "vinculado por julian",
  "target_product":        456,
  "base_stock":            12,
  "origin_previous_stock": 12,
  "target_previous_stock": 9
}
```

| Campo | Qué es |
| --- | --- |
| `target_product` | El producto del receptor: es lo que le dice a la secundaria cuál de los suyos es |
| `base_stock` | Con cuánto arranca el pool. Es el stock de la maestra (UC-07) |
| `origin_previous_stock` · `target_previous_stock` | Lo que tenía cada tienda antes. Sólo para auditar |

**`delta` es 0** porque el bootstrap no suma ni resta: fija. El stock esperado se calcula como
`base_stock + los movimientos posteriores al bootstrap vigente`, así que si el bootstrap
aportara delta se contaría dos veces.

**La secundaria crea su fila al recibirlo**, con el par al revés: su `local_product` es el
`target_product` que vino, y su `remote_product` es el `product` del emisor.

---

### 3. La baja — `unlink` también es un movimiento

```json
{ "id": "A-412", "generation": 77, "delta": 0, "product": 123, "reason": "unlink" }
```

La maestra borra su fila y emite el movimiento; la secundaria borra la suya al recibirlo.

**Por qué por la cola y no por un endpoint aparte: el orden.**

```
10:00  Venta en la maestra  →  movimiento M-500, queda en cola (la otra tienda está lenta)
10:01  El dueño desvincula

Por la cola:      llega M-500 y se aplica; llega el unlink y se borra el par   ✅
Por un endpoint:  el unlink no espera a la cola, llega primero, se borra el par,
                  y M-500 llega a un par que ya no existe → descartado          ❌ venta perdida
```

El costo es que el libro guarda filas con `delta: 0`, que no son movimientos de stock. Se
acepta porque el bootstrap ya está en la misma situación, y porque la alternativa pierde
ventas.

---

### 4. La generación es la secuencia del bootstrap

```
Bootstrap de Zapatilla X   →  movimiento A-77   →  la generación del par es 77
Se desvincula y se revincula →  movimiento A-412 →  la generación pasa a 412
```

**El número no puede reusarse nunca**, y con un contador por vínculo que arranca en 1 sí se
reusa:

```
10:00  Vincular      →  generación 1
10:05  Venta         →  movimiento con generación 1, queda en cola
10:10  Desvincular   →  se borra la fila
10:11  Revincular    →  generación 1 otra vez
10:12  Llega el movimiento de las 10:05, con generación 1
       → 1 == 1 → se aplica → descuenta stock de una época que ya no corre   ❌
```

Con la secuencia del bootstrap eso no puede pasar: `origin_seq` es el `AUTO_INCREMENT` del
libro (ADR-002) y **no se repite jamás**. De yapa, la generación dice qué fila del libro la
abrió: auditar un bootstrap es buscar ese número.

**El receptor sólo aplica lo de la generación vigente**, y lo que llega de una vieja queda
`discarded` (ADR-001 §3).

---

### 5. `GET /products` — el quinto endpoint

UC-05 pide que **la pantalla llegue al catálogo de la otra tienda**: para sugerir pares por
SKU y para mostrar los dos números de stock antes de confirmar un bootstrap. Los cuatro
endpoints de ADR-001 no lo cubren.

```
GET /wss/v1/products?page=1&per_page=100
→ { "products": [ { "id": 456, "sku": "ZAP-001", "name": "Zapatilla X",
                    "stock": 9, "status": "publish", "linked": false } ],
    "total": 4870 }
```

Firmado con HMAC como todo el resto, y **de sólo lectura**: no modifica nada del otro lado.

**Por qué en vivo y no un espejo local.** Un espejo resolvería la sugerencia masiva en una
consulta, pero el número de stock que muestra sería el de la última sincronización:

```
El dueño confirma el bootstrap mirando un stock de anoche
  → el pool arranca con un número que ya no es cierto
  → y el bootstrap es lo único que no se puede deshacer (UC-07)
```

**Costo aceptado:** si la secundaria no responde, la pantalla de vinculación no funciona y hay
que decirlo. Es lo mismo que ya pasa con la reconciliación, que también necesita a las dos.

---

### 6. Dónde se auditan los campos del bootstrap

> **Esta la elegí yo al escribir el ADR, no salió de una conversación: miralo con cuidado.**

§3 del SPEC exige que el libro pueda auditar un bootstrap entero —los dos IDs, el stock previo
de cada tienda y la generación—, y `wss_movements` no tiene dónde ponerlos.

**Una columna `details` en `wss_movements`**, `TEXT NULL`, con el JSON de los campos que sólo
existen en algunos movimientos. Vacía en una venta, llena en un bootstrap.

| Alternativa | Qué falla |
| --- | --- |
| Columnas propias (`target_product`, `base_stock`, …) | Cuatro columnas `NULL` en el 99,9% de las filas, y cada tipo nuevo de movimiento suma más |
| Una tabla `wss_bootstraps` | Una cuarta tabla para un evento raro, y un `JOIN` en la auditoría |
| Meterlo en `reference` | Son 100 caracteres y ya significa otra cosa: el número de pedido |

---

### Prior art

| Decisión de este ADR | Cómo se llama |
| --- | --- |
| El `unlink` viaja como un movimiento con `delta: 0` | Un **tombstone** en un log de eventos: así borran Kafka y los CRDT |
| La generación del par | Un **fencing token** (o *epoch*): el número que distingue una época de la anterior y hace que lo viejo se descarte solo |
| Que la baja viaje por el mismo canal que los datos | **Orden total dentro de la partición**, la razón por la que Kafka no tiene un canal de control aparte |

### Alcance

**Cubre:** cómo nace, viaja y muere un vínculo, y de dónde sale su generación.

**No cubre:**

| Qué | Dónde va |
| --- | --- |
| La pantalla de vinculación en sí (UC-05) | ADR de la interfaz |
| El registro de acciones de vinculación y de descartes (UC-03, UC-08) | ADR del log de acciones |
| Cómo se detecta el cambio de stock que dispara un movimiento | ADR del detector |

### Consecuencias para otros ADRs

| ADR | Qué le queda |
| --- | --- |
| **ADR-001** (aprobado) | Se le suma un quinto endpoint, `GET /products`. Y `reason` suma dos valores: `unlink`, además de `bootstrap` |
| **ADR-004** (aprobado) | Sale `status` de `wss_links`; entra `details TEXT NULL` en `wss_movements` |
| **Detector** | Un movimiento `bootstrap` o `unlink` **no lo emite el detector**: lo emite la pantalla. El guard anti-eco tiene que cubrir la escritura de stock del bootstrap |
| **Interfaz** | La pantalla consume `GET /products` y tiene que tolerar que la otra tienda no responda |


---

## ADR-006 — El detector de cambios de stock

Estado: Aprobada · 2026-09-24 · aprobada por Julián
Revisado: 2026-09-27 — el retardo de entrega es una constante y no configuración: el ADR
prometía una perilla que nadie pidió y que el código no tenía. Ver §4.
Revisado: 2026-09-27 — el guard anota **sólo el producto**, no el valor esperado. Leyendo el
código de WooCommerce se vio que el valor que llega a los hooks es calculado y no releído, así
que bajo concurrencia no coincide con lo anotado y el cambio propio se emitiría como una
venta. Ver §3.
Revisado: 2026-09-28 — aprobado por Julián. El delta sale de leer `_stock` de la base
en los dos hooks, no del objeto del producto. En una venta el objeto puede venir
desactualizado, y cuando se guarda un producto el `before` ya trae el valor nuevo: el delta
daría 0 y el cambio no se emitiría nunca. Ver §1.
Revisado: 2026-09-28 — aprobado por Julián. El agujero declarado del guard estaba mal
descrito: entre el `expect()` y el `set` sí corre código de terceros. La conclusión no cambia;
ahora dice qué se pierde. Ver §3.
Temas: hooks-publicos, db-schema
Cubre: includes/Detector/_.php

**La decisión, en cuatro líneas:**

1. El detector escucha los **cuatro hooks de stock de WooCommerce**, en pares `before`/`set`,
   y en cada uno **lee el stock de la base**: la diferencia entre las dos lecturas es el delta.
2. **El motivo no se declara: se estampa después.** El movimiento nace `adjustment` y el
   documento que lo causó lo completa un instante más tarde.
3. El **guard anti-eco es específico**: anota el producto, de un solo uso.
4. Un movimiento **no se entrega hasta unos segundos después de creado**, lo que cierra la
   carrera del estampado y agrupa las ráfagas sin modo especial.

---

### 1. Dónde engancha

```php
woocommerce_product_before_set_stock    ·  woocommerce_product_set_stock
woocommerce_variation_before_set_stock  ·  woocommerce_variation_set_stock
```

Se disparan en las **dos** rutas por las que WooCommerce cambia stock: `wc_update_product_stock()`
—ventas, devoluciones, cambios programáticos— y el data store cuando se guarda un producto.
En las dos, `before` corre **antes de escribir** y `set` **después**.

#### El delta sale de la base, no del objeto

Cada hook lee `_stock` con una consulta directa, y el delta es la diferencia:

```
before   →  SELECT meta_value FROM wp_postmeta WHERE post_id = 123 AND meta_key = '_stock'   → 9
         …  WooCommerce escribe
set      →  la misma consulta                                                                → 7
delta:      7 − 9 = −2
```

**Por qué no el objeto que entrega el hook.** Parecía que el par daba el delta sin consultar
nada, y en cada ruta falla de una manera distinta:

| Ruta | Qué tiene el objeto en `before` | Qué sale |
| --- | --- | --- |
| `wc_update_product_stock()` — una venta | El stock de cuando se cargó, que puede ser el principio del checkout | Si otro request vendió en el medio, el delta suma esa venta: la otra tienda descuenta de más |
| El data store — guardar el producto | **El valor nuevo**: se lo pusieron antes de guardar y sólo falta escribir el meta | `before` y `set` dan lo mismo, el delta es 0 y el cambio **no se emite nunca** |

```php
// Venta: la copia se cargó con 10; otro request vendió 1 (base 9); ahora se venden 2
before  → $product->get_stock_quantity() = 10     // viejo
set     → $product->get_stock_quantity() = 7      // calculado de la base
delta   = −3, y fueron −2
```

**Por qué SQL directo y no `get_post_meta()`.** `get_post_meta()` responde desde la caché de
meta del request, que pudo cargarse antes de que otro proceso cambiara el stock: es otra vez
un número viejo. WooCommerce borra esa caché después de su propia escritura, no antes.

**Es lo que hace el rubro.** *Stock Sync for WooCommerce* (WP Trio, 2.11.0) lee el stock con
un `SELECT` directo a `wp_postmeta` —su función se llama *"Get current stock directly from
the DB"*— y calcula el delta contra el número nuevo que calcula WooCommerce. Los snippets de
historial de stock que circulan (Business Bloomer, Webkul) leen `_stock` en el `before`.

**Por qué no copiar Stock Sync entero.** Para las ventas engancha el filtro
`woocommerce_update_product_stock_query` y la acción `woocommerce_updated_product_stock`, que
están pegados a la escritura misma y achican la ventana todavía más. Pero sólo existen en la
ruta de las ventas, así que hace falta el par `before`/`set` igual para la otra, y en una
venta se disparan los dos: hay que llevar la cuenta de lo ya procesado para no emitirla dos
veces. Para las ediciones a mano Stock Sync manda el número final y no un delta, y eso no nos
sirve: pisar con un absoluto borra la venta que entró en la otra tienda (§3 del SPEC). Se
eligió la versión simple: la ventana que queda es de milisegundos.

**La ventana que queda, declarada.** Si otro request escribe el stock del mismo producto entre
las dos lecturas, su cambio queda adentro del delta, y como ese request también emite el suyo,
**ese cambio se cuenta dos veces**. En una venta entre las dos lecturas corre el `save()` del
producto: son milisegundos. Lo corrige la fase 2 de la reconciliación, que compara el stock
real contra el libro.

**Por qué no el meta `_stock`.** Parece el punto más bajo y universal, y es el error: la
propia función de WooCommerce avisa que **no pasa por `update_post_meta`**.

> *Uses queries rather than `update_post_meta` so we can do this in one query (to avoid stock issues).*

Un detector colgado del meta no vería las ventas, que es el caso principal. Verificado contra
WooCommerce 11.0.1.

**Qué no genera movimiento:**

| Caso | Por qué |
| --- | --- |
| El producto no gestiona stock (`managing_stock()` falso) | No hay número que sincronizar |
| El producto no está vinculado | Nada entra a la malla hasta que el dueño lo vincula (UC-03) |
| El cambio lo provocó la aplicación de un movimiento remoto | El guard de §3 |
| El cambio lo provocó un bootstrap | Lo emite la pantalla, no el detector (ADR-005) |

**Variaciones:** el hook entrega el objeto que **realmente** lleva el stock, que puede ser el
padre (`get_stock_managed_by_id()`). El detector usa el ID de ese objeto y no el del producto
que se pidió cambiar.

---

### 2. El motivo se estampa, no se declara

WooCommerce avisa quién causó el cambio **un instante después** del cambio, en el mismo
request y con todo lo necesario:

```php
$new_stock = wc_update_product_stock( $product, $qty, 'decrease' );          // ① el detector escribe
…
do_action( 'woocommerce_reduce_order_item_stock', $item, $change, $order );  // ② llega el documento
```

Así que el movimiento nace con lo único que se sabe y se completa después:

```
①  producto 123 · delta −2 · reason: adjustment
②  producto 123 · delta −2 · reason: sale · reference: "pedido #4512"
```

Si nadie estampa nada, la fila se queda en `adjustment`, **que es la verdad**: el stock cambió
y no hay ningún documento detrás. Es lo que pasa cuando el dueño edita el stock a mano, cuando
entra un CSV o cuando lo toca otro plugin.

| Hook que estampa | Motivo | Referencia |
| --- | --- | --- |
| `woocommerce_reduce_order_item_stock` | `sale` | El número de pedido |
| `woocommerce_restore_order_item_stock` | `refund` | El número de pedido. Existe desde WooCommerce 9.1, y por eso es el mínimo (§5 del SPEC) |
| `woocommerce_restock_refunded_item` | `refund` | El número de pedido |

**Por qué así y no declarando el motivo por adelantado.** Es lo que hace el rubro: en Odoo
cada `stock.move` lleva un campo `origin` que apunta al documento que lo causó, y en Shopify
cada ajuste de inventario lleva `reason` y un `referenceDocumentUri` *"que representa por qué
ocurrió el cambio"*. En los dos, **el movimiento existe por su cuenta y la atribución viaja
encima**; ninguno pide declarar la intención antes de tocar el stock.

La alternativa —abrir un ámbito "lo que viene es una venta", mantenerlo mientras WooCommerce
toca varios productos y cerrarlo después— obliga a saber de antemano algo que el sistema va a
decir solo un milisegundo más tarde, y deja una pila que hay que cerrar aunque algo falle.

**El delta se registra siempre, al instante; el motivo es un dato del historial.** Si llega,
mejora la fila; si no llega, la fila ya era correcta. Un motivo mal puesto no rompe el stock
—el delta es el mismo— pero sí el historial, que es lo único que después explica de dónde
salió cada número (UC-04).

---

### 3. El guard anti-eco

Cuando B aplica un movimiento que vino de A, el stock local de B cambia, y su propio detector
lo ve como cualquier otro cambio. Sin guard: B emite un movimiento nuevo hacia A, A lo aplica,
su detector se dispara, y vuelve — **amplificando el descuento en cada vuelta** (§3 del SPEC).

**El guard anota el producto, y nada más. De un solo uso.**

```
Antes de aplicar:   "el próximo cambio del producto 123 lo provoco yo"
El detector ve:      un cambio del 123  → había anotación: la consume y no emite
El detector ve:      un cambio del 456  → no hay anotación: lo emite ✅
Al terminar el request, lo que quedó sin consumir se descarta.
```

#### De dónde sale el límite: el hook no tiene autor

WooCommerce avisa el cambio con un solo argumento:

```php
do_action( 'woocommerce_product_before_set_stock', $product_with_stock );
do_action( 'woocommerce_product_set_stock',        $product_with_stock );
```

**El producto. Nada más.** No viene quién lo cambió ni por qué. Y los hooks son globales: no
existe "escuchá sólo los cambios que provoque esta línea mía", así que todos los cambios del
request pasan por el mismo callback sin distintivo.

Como el evento no trae autor, la única forma de reconocer el propio es **dejar la marca
antes**. Y ahí está el límite:

| Lo que podemos anotar | ¿Es confiable? |
| --- | --- |
| **Qué producto vamos a tocar** | Sí: lo elegimos nosotros |
| Qué valor tiene ahora | No: lo leímos, y otro request pudo cambiarlo |
| A qué valor va a quedar | No: lo calcula WooCommerce con su propia lectura |

**Lo que elegimos es certero; lo que medimos, no.** Por eso el guard no anota números.

#### Por qué no anotar también los valores

Parecía más preciso, y es peor. `wc_update_product_stock()` escribe con una operación
relativa —`SET meta_value = meta_value - 1`, que es atómica y siempre deja bien la base— pero
a los hooks les entrega **un número calculado antes de escribir**, no releído:

```php
$new_stock = $data_store->update_product_stock( … );        // lee, calcula, escribe
$data_store->read_stock_quantity( $product, $new_stock );   // le mete el número calculado
```

Si otro request movió el stock en el medio, ese par de valores no es el real, no coincide con
lo anotado, y el cambio propio **se emite como si fuera una venta**: el eco que el guard tenía
que evitar, que es el peor de los finales porque amplifica el descuento en las dos tiendas.

**Ciego de más pierde una venta; no reconocerse a sí mismo duplica el descuento.** Entre esos
dos errores, el guard elige el primero.

#### Por qué no una bandera, ni desenganchar el detector

```
Aplicando el movimiento de A…
   ← adentro de esa llamada, otro plugin ajusta el stock del producto 456
   → el detector está apagado: ese cambio NO se registra   ❌ stock perdido, en silencio
```

Una bandera "estoy aplicando" y un `remove_action` alrededor de la llamada tienen el mismo
efecto: ciegan al detector para **todos** los productos. El guard por producto ciega uno solo,
al mismo costo de implementación. El SPEC lo pide explícitamente: el guard *"no debe tapar una
venta local genuina que caiga en el mismo request"*.

Y tiene que liberarse aunque la aplicación falle a mitad: por eso el descarte al final del
request, y no un `finally` del que dependa la corrección.

**El guard cubre también el bootstrap**, que escribe stock de la misma manera (ADR-005).

**Por qué no marcar el origen, como hace la replicación.** MySQL le pone un `server_id` a cada
escritura y cada réplica descarta lo que lleva el suyo. Acá la etiqueta no puede viajar con el
evento, porque el hook no tiene dónde ponerla. La forma posible es la bandera alrededor de
nuestra propia llamada, y es la que se descarta arriba por ciega. El guard por producto es esa
misma idea, acotada a un producto.

**El agujero que queda, declarado:** entre el `expect()` y el `set` que consume la anotación
corre código de terceros: los hooks de `before`, el `save()` del producto y lo que ese `save()`
dispara, como `woocommerce_update_product`. Si desde ahí alguien cambia el stock **del mismo
producto**, ese cambio consume la anotación y **no se emite**. El nuestro tampoco, porque su
`set` ya no encuentra la lectura previa: no hay eco, pero el cambio del tercero se pierde.
Verificado con WooCommerce 11.0.1. Una venta de otro request corre en otro proceso de PHP y no
pasa por nuestro callback, así que el agujero sólo lo abre un hook anidado dentro de nuestra
propia llamada.

---

### 4. El movimiento espera unos segundos antes de salir

```
created_at:       14:00:00
next_attempt_at:  14:00:10     ← la cola no lo toca antes
```

**Son 10 segundos, y es una constante del código: no una opción.** El valor equilibra
velocidad de propagación contra agrupamiento, y nadie pidió poder moverlo. Una opción no es
gratis —hay que ponerla en una pantalla, validarla, documentarla, probar los valores raros y
bancarla para siempre— y lo único que sabemos hoy es que 10 segundos parece razonable, no que
alguien vaya a querer otro número.

Si el volumen real del catálogo (§6 del SPEC, sin cerrar) mostrara que 10 no sirve, se cambia
la constante en una versión. Eso es un release, no una perilla.

Resuelve dos cosas de una:

**La carrera del estampado.** Entre que el detector escribe la fila y el hook del pedido la
completa pasan milisegundos, pero la pasada de la cola corre en otro request: sin la espera,
podría llevarse la fila con `reason: adjustment` antes de que le estampen `sale`.

**Las ráfagas, sin modo importación.** §6 del SPEC dejó abierto cómo agrupar una importación
masiva, y anotó por qué el camino obvio no sirve: *"detectar la ráfaga por umbral llega tarde,
porque para cuando se superó el umbral los primeros cambios ya salieron de a uno"*.

```
Con umbral:   los primeros 50 salen de a uno, y recién ahí se nota que era una ráfaga
Con espera:   ninguno sale en los primeros 10 segundos
              → cuando la pasada corre, ya están todos y se van en un lote   ✅
```

No hace falta detectar nada ni encender un modo: la ráfaga se agrupa sola porque nadie sale
apurado. El tope de 200 por lote de ADR-001 sigue mandando.

**Costo:** una venta tarda esos segundos de más en llegar a la otra tienda. §5 del SPEC no
promete un plazo máximo de propagación, justamente porque depende del volumen y del servidor,
pero es tiempo en el que la otra tienda puede sobrevender.

---

### Prior art

| Decisión de este ADR | Cómo se llama | Dónde se usa |
| --- | --- | --- |
| El movimiento existe primero y la atribución se le estampa encima | **Source document reference** | El campo `origin` de `stock.move` en Odoo; el `referenceDocumentUri` de cada ajuste en Shopify |
| Esperar antes de enviar para que la ráfaga se agrupe sola | **Debounce** | Cualquier cosa que agrupe eventos en lote |
| Cortar el eco de una réplica | **Loop prevention** | Lo estándar es marcar el origen (`server_id` en MySQL); acá la etiqueta no puede viajar con el evento, ver §3 |

### Alcance

**Cubre:** cómo se detecta un cambio de stock, de dónde sale su motivo y cómo se evita el eco.

**No cubre:**

| Qué | Dónde va |
| --- | --- |
| Qué hace la cola con el movimiento una vez escrito | ADR-001 (aprobado) |
| Quién emite el bootstrap y el `unlink` | ADR-005 (aprobado) |
| El aviso de un producto que no sincroniza | ADR del log de acciones y avisos (UC-08) |

### Consecuencias para otros ADRs

| ADR | Qué le queda |
| --- | --- |
| **ADR-001** (aprobado) | `next_attempt_at` nace con unos segundos de futuro, no en el momento. La cola no cambia: ya filtra por `next_attempt_at <= NOW()` |
| **Esquema** | Nada nuevo: `reason` y `reference` ya están, y el estampado es un `UPDATE` sobre la fila recién escrita |
| **Log de acciones** | El detector tiene que avisar cuando ve un cambio de un producto **sin vincular**: es la señal de UC-08 de que hay algo fuera de la malla |
| **Interfaz** | §5 del SPEC exige detectar que `wc_stock_amount` esté filtrado a decimales y avisar en vez de truncar en silencio. Lo ve el detector; lo muestra la pantalla |

---

## ADR-007 — Qué se hashea en la huella del sitio

Estado: Aprobada · 2026-09-26 · aprobada por Julián
Temas: opciones
Cubre: includes/Identity/Store.php

**La decisión, en una línea:** la huella es `sha256( get_option( 'home' ) )`, la opción tal
como está guardada, con esquema incluido, y no `sha256( home_url() )`.

Corrige ADR-001 §1. Todavía no hay huellas guardadas —el emparejamiento no está escrito—, así
que no hace falta migrar nada.

---

### 1. El problema: `home_url()` no es la URL del sitio, es la URL de este request

```php
// get_home_url(), en wp-includes/link-template.php
$url = get_option( 'home' );
if ( ! in_array( $scheme, array( 'http', 'https', 'relative' ), true ) ) {
	if ( is_ssl() ) {
		$scheme = 'https';                              // ← depende del request
	} else {
		$scheme = parse_url( $url, PHP_URL_SCHEME );
	}
}
$url = set_url_scheme( $url, $scheme );
return apply_filters( 'home_url', $url, … );        // ← y de quien lo filtre
```

Dos cosas cambian el resultado sin que el sitio cambie:

| Qué | Ejemplo | Resultado |
| --- | --- | --- |
| **El filtro `home_url`** | Jetpack le cambia el esquema durante sus requests de API (`class.json-api.php`, `ensure_http_scheme_of_home_url`) | Esos requests ven otra huella |
| **`is_ssl()`** | `home = http://tienda-a.com`, detrás de un proxy que termina TLS | Los requests por HTTPS dan una huella y el cron o WP-CLI dan otra |

El segundo sólo muerde con `home` en `http`, que con HTTPS obligatorio (§5 del SPEC) no
debería existir en una tienda emparejada. El primero muerde siempre.

En los dos casos **la tienda original se toma por clon y suspende**. Es el peor error posible
para este control: apaga la sincronización de la tienda viva, sin causa que el dueño pueda
ver.

---

### 2. Las opciones

**Opción A — `sha256( get_option( 'home' ) )`.** La opción tal como está guardada.

**Opción B — `sha256( host:puerto/ruta )` de la opción `home`.** La misma opción sin el
esquema, para que pasar de `http` a `https` no cuente como mudarse.

**Opción C — `home_url( '', 'https' )`.** Seguir con `home_url()` pero fijarle el esquema.

**Decisión:** Opción A.

**Por qué:** `get_option( 'home' )` no depende del request. WordPress ya le saca la barra final
al leerla, y si hay `WP_HOME` definido lo devuelve en su lugar. Eso último es justo lo que
tiene que pasar: un entorno de Docker que levanta la copia con `WP_HOME=http://localhost:8130`
**tiene** que ver otra huella.

Y el esquema, dentro del hash, detecta el clon más probable de los que ADR-001 §1 deja fuera:
la copia local que se sirve con el dominio de producción por `/etc/hosts`.

```
                  opción 'home'            huella
Original          https://tienda-a.com     sha256("https://tienda-a.com")
Clon por hosts    http://tienda-a.com      sha256("http://tienda-a.com")    → distintas: suspende
```

Una copia local casi nunca tiene certificado, así que se sirve por `http`. El clon por hosts
sólo pasa el control si además se le monta HTTPS.

**Por qué no B:** su única ventaja era no suspender al pasar a HTTPS. Con HTTPS obligatorio
para emparejar, la huella se toma cuando la tienda ya está en `https` y esa migración no
existe. A cambio, B deja pasar el clon por hosts servido por `http`: las dos huellas son
`sha256("tienda-a.com")`.

**Por qué no C:** fijar el esquema resuelve `is_ssl()`, pero el valor sigue pasando por el
filtro `home_url`, que es el problema que muerde siempre.

---

### Consecuencias

| Qué | Cómo queda |
| --- | --- |
| **ADR-001 §1** | Donde dice `sha256( home_url() )` pasa a decir `sha256( get_option( 'home' ) )`. En la tabla de lo que no cubre, el clon por hosts queda acotado al que se sirve por HTTPS |
| **`ARCHITECTURE.md`** | La fila de `wss_site_fingerprint` cambia la fórmula |
| **`Store::fingerprint()`** | Una línea: lee la opción en vez de llamar a `home_url()` |
| **Emparejamiento** | Tiene que rechazar una tienda cuya opción `home` no sea `https`. Si no, la huella se guarda en `http` y pasar a HTTPS después la suspende |
| **Tests** | ID-05 sigue igual. Casos nuevos: un filtro sobre `home_url` no cambia la huella, `is_ssl()` no la cambia, y el mismo host por `http` y por `https` da huellas distintas |

**Si el supuesto falla** —si aparece un plugin que filtre `option_home` por request, como hace
el core con `WP_HOME`—, la huella vuelve a variar. La salida sería leer la fila de
`wp_options` directo con `$wpdb`, que ignora todo filtro, y que a cambio también ignora
`WP_HOME`, así que habría que leer la constante aparte.

---

## ADR-008 — Cómo se vincula un producto

Estado: Aprobada · 2026-10-01 · aprobada por Julián
Temas: rest-api, opciones, modulos
Cubre: includes/Linking/_.php, includes/Ledger/Ledger.php, includes/Rest/Movements.php, includes/Transport/Pairing.php, includes/Transport/Peers.php, includes/Cli/Command.php

**De qué se trata.** Vincular es decir "este producto de la maestra y este de la secundaria son
el mismo, y arrancan con el stock de la maestra". A ese momento lo llamamos **bootstrap**. Este
ADR dice cómo se hace, quién puede hacerlo y qué pasa si algo sale mal.

**La decisión, en seis puntos:**

1. El bootstrap **se manda en el momento y se espera la respuesta**. No va a la cola.
2. **Sólo vincula la maestra**, que es la tienda que generó el código de emparejamiento.
3. **Antes de vincular se revisa que nada haya cambiado** desde que el dueño miró los números.
4. **La maestra arma su vínculo antes de mandar**, para no perder una venta de esos segundos.
5. **Si no llega respuesta, el vínculo queda "incierto"**, y el plugin le pregunta a la otra
   tienda qué pasó. Preguntar no cambia nada.
6. **Desvincular sí va por la cola**, como el resto de los movimientos.

---

### 1. El bootstrap se manda en el momento

El SPEC (UC-07) pide que **si una vinculación falla, no se reintente sola**: si se reintentara
horas después, fijaría un stock que nadie está mirando. ADR-005 §2 decía que el bootstrap iba por
la cola, que reintenta durante 6 horas. Este ADR lo corrige.

**Decisión:** la maestra anota el bootstrap en su libro, lo manda en el momento y espera:

| Qué pasa | Cómo queda |
| --- | --- |
| La otra tienda dice que sí | Vinculado |
| La otra tienda dice que no, y por qué (ver §3) | No vinculado. Queda anotado el motivo |
| No llega respuesta (se cortó la red, tardó demasiado) | **Incierto** (ver §5) |

En los tres casos el intento queda anotado en el libro, para poder revisarlo después.

**Opción descartada — mandarlo por la cola y esperar unos segundos.** Si la respuesta no llegaba,
la cola lo seguía reintentando 6 horas sin nadie mirando. Es justo lo que el SPEC prohíbe.

**Un cuidado: las ventas de ese producto esperan al bootstrap.** Mientras el bootstrap está en
camino, o incierto, una venta de ese producto **no sale todavía**:

```
10:00:00  la maestra manda el bootstrap
10:00:01  se vende 1 → queda en espera
10:00:04  la otra tienda vincula → recién ahí sale la venta   ✅
```

Si la venta saliera antes, llegaría a una tienda que todavía no sabe que el producto está
vinculado, la descartaría, y la venta se perdería. Cuando el bootstrap se resuelve, la venta sale
sola: si se vinculó, se aplica; si no, la otra tienda la descarta sin problema.

### 2. Sólo vincula la maestra

El SPEC dice que hay una **tienda maestra**: la única que vincula y la que pone el stock inicial.
Las dos tiendas tienen que estar de acuerdo en cuál es; si las dos se creyeran maestra, las dos
podrían pisar el stock del mismo producto.

**Decisión:** **la maestra es la tienda que generó el código de emparejamiento.** Al emparejarse,
cada tienda anota quién es la otra. Si la secundaria recibe un bootstrap que no viene de su
maestra, lo rechaza.

Para cambiar de maestra, se vuelve a emparejar generando el código desde la otra tienda.

**Opción descartada — un ajuste aparte "esta tienda es la maestra".** Habría que avisarle a la
otra tienda, y si el aviso se pierde pueden quedar dos maestras o ninguna. Es mucho riesgo para
algo que casi nunca cambia.

**Las tiendas que ya estaban emparejadas** antes de este ADR no saben quién es la maestra: tienen
que volver a emparejarse para poder vincular.

### 3. Antes de vincular se revisa que nada haya cambiado

El dueño vincula mirando dos números: el stock de cada tienda. Si mientras tanto alguien vendió o
cambió algo, vincular igual pisaría ese cambio sin que nadie se entere.

**Decisión:** el bootstrap lleva los dos números que vio el dueño, y cada tienda revisa lo suyo:

| Qué se revisa | Si falla |
| --- | --- |
| El stock sigue siendo el que vio el dueño | `stock_changed` |
| El producto de la otra tienda existe y no está en la papelera | `target_missing` |
| El producto gestiona stock | `target_not_managing_stock` |
| No está vinculado ya con otro | `target_already_linked` |
| El bootstrap viene de la maestra | `not_master` |

Si algo falla, no se vincula nada y el dueño vuelve a intentar con los números nuevos.

Si todo está bien, la secundaria hace todo junto, o no hace nada: anota el bootstrap, arma su
vínculo y pone el stock de la maestra. Esa escritura de stock no se manda de vuelta como si fuera
un cambio (es el mismo cuidado de ADR-006 §3).

**Un desvincular que no viene de la maestra** se descarta en silencio. Como va por la cola, si se
rechazara se reintentaría 6 horas para nada.

### 4. La maestra arma su vínculo antes de mandar

Si la maestra armara su vínculo recién al recibir el "sí", una venta en esos segundos no se
anotaría —para el plugin, el producto todavía no estaba vinculado— y las dos tiendas arrancarían
con números distintos.

**Decisión:** la maestra arma su vínculo y anota el bootstrap a la vez, y recién después lo manda.
Si al final no se vincula, borra su vínculo.

**Opción descartada — armar el vínculo al recibir el "sí".** Es más simple, pero pierde las
ventas de esos segundos.

### 5. Si no llega respuesta, se pregunta

Si la red se corta, la maestra no sabe si la otra tienda vinculó o no. Las dos cosas piden lo
opuesto: si no vinculó, hay que volver a intentar; si vinculó, volver a intentar lo duplica. Por
eso el vínculo queda **incierto** y se averigua.

**Decisión:** la otra tienda tiene una dirección nueva donde sólo se puede **preguntar**, no
cambiar nada: "¿recibiste el movimiento A-77?". Contesta lo que tiene: lo aplicó, o nunca le llegó.

Cada minuto la pasada revisa los inciertos que tengan **más de 10 minutos**, y pregunta:

| Respuesta | Cómo queda |
| --- | --- |
| Lo aplicó | Vinculado |
| Nunca le llegó, o le llegó y no lo pudo aplicar | No vinculado. El dueño vuelve a vincular si quiere |
| No contesta | Sigue incierto, y se pregunta en la próxima |

Se esperan 10 minutos para no preguntar mientras la otra tienda todavía lo está procesando.

Si la maestra se cae a mitad del envío y no llega a anotar nada, a los 10 minutos ese envío se
trata como incierto y también se pregunta.

**Opción descartada — volver a mandar el bootstrap y ver qué contesta.** Si ya lo había aplicado,
no pasa nada. Pero si nunca le había llegado, **lo aplica**: sería un reintento, que es lo que el
SPEC prohíbe.

### 6. Desvincular va por la cola

Acá el orden importa: una venta anterior tiene que llegar antes que el desvincular. Por eso va por
la cola, como dice ADR-005 §3.

La maestra anota el desvincular y después borra su vínculo. ADR-005 lo decía al revés; se cambia
porque el desvincular necesita leer datos del vínculo para anotarse. La otra tienda borra el suyo
al recibirlo.

### 7. Mientras no hay pantalla, por comandos

```
wp wss link <producto de acá> <producto de allá> --stock=<el de acá> --remote-stock=<el de allá>
wp wss unlink <producto de acá>
```

Los dos stocks se escriben a mano porque son los que vio el dueño, y eso es lo que se revisa (§3).

---

### Qué cambia en otros ADRs

| ADR | Qué cambia |
| --- | --- |
| ADR-005 | El bootstrap ya no va por la cola (§1). El desvincular se anota antes de borrar (§6) |
| ADR-001 | Suma la dirección para preguntar (§5), los motivos de rechazo (§3), el rol de cada tienda al emparejar (§2) y los estados "en camino", "incierto" y "fallido" |
| ADR-004 | Nada: los estados nuevos entran en la columna que ya existe |

### Lo que queda más difícil, y el riesgo

**Más difícil:** un movimiento propio puede salir por dos caminos —la cola o el envío del
bootstrap—, y la cola tiene que saber cuáles esperar.

**El riesgo que queda:** si la otra tienda tardara más de 10 minutos en procesar un bootstrap, la
pregunta diría "nunca llegó", la maestra borraría su vínculo, y la otra tienda lo armaría igual al
terminar. El producto quedaría vinculado de un solo lado. Lo detectaría la reconciliación
nocturna; si pasara, habría que esperar más antes de preguntar.

### En qué se parece a otras cosas

Revisar que el número siga siendo el que se vio antes de escribir es **control de concurrencia
optimista** (*compare-and-set*): se escribe sólo si nadie cambió el dato en el medio.
