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
Revisado: 2026-10-06 — aprobado por Julián con ADR-011 y ADR-012. La tabla `wss_events` es
historial del dueño: se borra con la casilla, como el libro; también las tres opciones de los
avisos, que son ajustes. `wss_peer_state` y `wss_notify_state` se borran siempre.
Revisado: 2026-10-05 — aprobado por Julián con ADR-009. Suma `wss_reconciling` y
`wss_last_reconciliation`, el candado y el último resultado de la reconciliación. Se borran
**siempre**: son estado de la sincronización, no datos del dueño.
Revisado: 2026-10-02 — aprobado por Julián. Suma el transient `wss_send_failed`, que frena el
envío al final del request después de un fallo (ADR-006 §5). Se borra **siempre**: es estado de
la entrega, no un dato del dueño.
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
| Último envío fallido (`wss_send_failed`)  | se borra           | se borra        |
| Candado y último resultado de la reconciliación | se borra     | se borra        |
| Estado de la otra tienda y de los avisos  | se borra           | se borra        |
| El log (`wss_events`) y los ajustes de los avisos | queda      | se borra        |
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
Revisado: 2026-10-06 — aprobado por Julián con ADR-010 y ADR-011. Una cuarta tabla,
`wss_events`, y en `wss_links` las cuatro columnas de la discrepancia y `broken_since`.
Esquema 3. Ver ADR-010 §4 y ADR-011 §2.
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
Revisado: 2026-10-02 — aprobado por Julián. §5 fija qué devuelve `GET /products`: qué tipos de
producto, que incluye la papelera, que se pueden pedir productos puntuales con `include`, el
tope de 100 por página y el orden, y que no anota el nonce. Ver §5, "Los detalles".
Revisado: 2026-10-02 — aprobado por Julián. §1 dice **todo lo que deja un par suspendido**, con
su motivo: también el producto de acá, uno que dejó de gestionar su propio stock, y un vínculo
de una tienda que ya no es la socia. Ver §1, "Los motivos de suspendido".
Revisado: 2026-10-02 — aprobado por Julián. Con `include` vuelve **cada producto pedido, de
cualquier tipo y estado**, sin páginas y hasta 100 IDs por request: así, que uno no vuelva
quiere decir que no existe. Ver §5, "Los detalles".
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

#### Los motivos de suspendido

Un par está suspendido cuando un movimiento no se le podría aplicar: es la misma condición con
que `apply()` lo retiene (`held`), así que la pantalla dice lo que está pasando con el stock.

| Motivo | Qué pasó |
| --- | --- |
| `remote_trashed` · `remote_missing` | El producto de la otra tienda está en la papelera, o ya no existe |
| `local_trashed` · `local_missing` | Lo mismo con el producto de acá |
| `remote_not_managing_stock` · `local_not_managing_stock` | Uno de los dos dejó de gestionar **su propio** stock: se le apagó, se apagó la gestión de stock de toda la tienda, o una variación pasó a tomar el del padre |
| `peer_changed` | El vínculo es de una tienda que ya no es la socia —se re-emparejó con otra—. Al catálogo de la nueva no se le pregunta por él: un ID igual sería otro producto |

**Si la otra tienda no responde, o no hay con quién emparejarse, no se suspende nada**: los
motivos de allá no se pueden saber, y la pantalla avisa una vez por qué no.

**El vínculo armado de un solo lado** —el catálogo de allá dice `linked: false` para un par que
acá está vinculado— no es un motivo: en la secundaria pasa por un momento mientras un `unlink`
está en la cola, y `linked` dice que el producto está vinculado con algo, no con qué. Lo
encuentra y lo repara la reconciliación (tarea 1.9).

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

#### Los detalles

| Qué | Cómo |
| --- | --- |
| **Qué productos** | Simples, variaciones y padres variables: lo que se puede vincular, y el padre que gestiona el stock de sus variaciones |
| **Sin stock gestionado** | Aparece igual, con `"stock": null`: la pantalla dice "no gestiona stock" en vez de que el producto desaparezca |
| **La papelera** | Se incluye, con `"status": "trash"`: es lo que deja decir "suspendido: el producto de la otra tienda está en la papelera" (UC-03) |
| **Productos puntuales** | `?include=456,457,458`, como texto separado por comas porque la firma no admite listas (ADR-001 §1). Vuelve **cada producto pedido, de cualquier tipo y estado**: los filtros de tipo y estado son para recorrer el catálogo, no para preguntar por productos vinculados —se puede vincular una suscripción, o un producto con un estado de otro plugin—. **Sin páginas**: vuelven todos los pedidos, hasta 100 IDs por request; con más, `wss_bad_request`, y el cliente los reparte de a 100. Así, **un ID pedido que no vuelve es un producto que ya no existe** |
| **Páginas** | Para recorrer el catálogo: `per_page` hasta 100, ordenados por ID. Una página no le hace timeout a la otra tienda, y recorrerlas dos veces da el mismo orden |
| **El stock** | Leído de la base, no del objeto: es el número que el dueño copia en el bootstrap, y la secundaria lo compara contra la base (ADR-008 §3). Con una caché desactualizada, el dueño vería un número viejo y no podría vincular nunca |
| **El nonce** | No se anota, como en `GET /health`: sólo lee |

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
Revisado: 2026-10-02 — aprobado por Julián. Lo que causó un pedido sale al terminar el
request de ese pedido, sin esperar los 10 segundos ni la pasada. Ver §5.
Revisado: 2026-10-02 — aprobado por Julián. Después de un envío fallido, un minuto sin mandar al
final del request; también con LiteSpeed; y qué pasa si el proceso muere a mitad. Ver §5.
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
5. **Lo que causó un pedido sale al terminar ese request**, con la conexión del cliente ya
   cerrada. Lo demás sigue esperando la pasada.

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
pero es tiempo en el que la otra tienda puede sobrevender. §5 lo achica para las ventas.

---

### 5. Lo que causó un pedido sale al terminar el request

Hoy una venta tarda en llegar a la otra tienda entre 10 y 70 segundos: los 10 de §4 más lo
que falte para la pasada, que corre cada 60 y **sólo si alguien visita el sitio**, porque la
dispara WP-Cron. En una tienda con poco tráfico puede ser bastante más. Es tiempo en el que la
otra tienda vende algo que ya no hay.

**La decisión:** los movimientos que un pedido estampó en este request —`sale` o `refund`— se
mandan al final del mismo request, después de cerrarle la conexión al cliente con
`fastcgi_finish_request()` o `litespeed_finish_request()`.

```
14:00:00.000  el cliente paga → se descuenta el stock → la fila nace → se le estampa `sale`
14:00:00.300  WordPress terminó la página → se cierra la conexión: el cliente ya la ve
14:00:00.301  se manda el movimiento a la otra tienda                              ← nuevo
```

**Por qué acá no hace falta la espera de §4.** Las dos cosas que la espera resuelve ya están
resueltas al final del request: el estampado corrió —es parte del mismo request—, y lo de ese
pedido sale junto, en un solo lote.

**Por qué sólo lo de un pedido, y no todo lo del request.** El problema es la sobreventa, y ésa
viene de los pedidos. Un ajuste a mano o una importación siguen esperando la pasada: una
importación por tandas manda un request por tanda, y si cada una saliera sola se perdería el
agrupamiento que §4 vino a dar.

**Las reglas:**

| Regla | Por qué |
| --- | --- |
| Sólo si existe `fastcgi_finish_request()` (PHP-FPM) o `litespeed_finish_request()` (LiteSpeed) | Sin ninguna —Apache con mod_php— el cliente esperaría el envío. Ahí lo lleva la pasada, como hoy. LiteSpeed tuvo la primera como alias y la sacó; la suya es la segunda |
| **Si un envío falló hace menos de un minuto, no se manda** | Con la otra tienda colgada, cada pedido ocuparía un proceso los 5 segundos enteros: una tanda de pedidos llenaría el pool de PHP-FPM y frenaría a esta tienda, que no tiene nada roto. El fallo lo anota el emisor —el de la pasada también cuenta— en el transient `wss_send_failed`, de 60 segundos, el intervalo de la pasada; un envío que sale bien lo borra |
| Se reclama como en la pasada, pero sólo esas filas y sin mirar `next_attempt_at` | El mismo `UPDATE` condicional: si una pasada corre a la vez, una sola se las lleva. Y se respeta lo retenido por un bootstrap sin resolver (ADR-008 §4) |
| Un solo lote, de 200 como mucho | El tope de ADR-001. Si hay más, el resto lo lleva la pasada |
| Espera la respuesta 5 segundos, no 30 | Corre dentro de un proceso de PHP-FPM que no atiende a nadie más mientras tanto. Si no contesta a tiempo, las filas vuelven a la cola como cualquier envío fallido. Si en realidad llegaron, la otra tienda las reconoce por su `seq` y no descuenta dos veces |
| Engancha en `shutdown` con la prioridad más baja | Otros plugins imprimen en `shutdown` —Query Monitor, por ejemplo—; cerrar la conexión antes les cortaría la salida |
| Nada si la sincronización está suspendida | Ya lo da el detector: no se engancha cuando está suspendida |

**Costo:** un proceso de PHP-FPM queda ocupado hasta 5 segundos más después de cada pedido, y
un envío que falla gasta un intento contra el techo de 6 horas —uno de muchos: el primer
reintento es al minuto—.

**Si el proceso muere a mitad del envío** —entre el claim y el ACK, por ejemplo porque PHP-FPM lo
corta con `request_terminate_timeout` un request que ya venía lento—, las filas quedan en vuelo
hasta que vence el claim, a los 5 minutos, y recién ahí las toma la pasada. No se pierde nada,
pero esa venta llega más tarde que sin este envío. Con 5 segundos de timeout es raro.

---

### Prior art

| Decisión de este ADR | Cómo se llama | Dónde se usa |
| --- | --- | --- |
| El movimiento existe primero y la atribución se le estampa encima | **Source document reference** | El campo `origin` de `stock.move` en Odoo; el `referenceDocumentUri` de cada ajuste en Shopify |
| Esperar antes de enviar para que la ráfaga se agrupe sola | **Debounce** | Cualquier cosa que agrupe eventos en lote |
| Cerrar la respuesta y seguir trabajando en el mismo proceso | `fastcgi_finish_request()` | woonderful-sync, para mandar a MercadoLibre la cola del request |
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
Revisado: 2026-10-02 — aprobado por Julián. El bootstrap de la maestra **reemplaza** un vínculo
viejo de la secundaria en vez de rechazarse con `target_already_linked`: ese vínculo es un resto
de un desvincular que no llegó. Ver §3, "Un vínculo viejo en la secundaria".
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
| El bootstrap viene de la maestra | `not_master` |

Si algo falla, no se vincula nada y el dueño vuelve a intentar con los números nuevos.

Si todo está bien, la secundaria hace todo junto, o no hace nada: anota el bootstrap, arma su
vínculo y pone el stock de la maestra. Esa escritura de stock no se manda de vuelta como si fuera
un cambio (es el mismo cuidado de ADR-006 §3).

**Un vínculo viejo en la secundaria se reemplaza, no se rechaza.** Puede quedar uno si un
desvincular no llegó —se abandonó a las 6 horas, o llegó a una tienda que después dejó de
considerar maestra a la otra—. Si la secundaria rechazara el bootstrap por eso, ese producto no se
podría volver a vincular nunca, y desde la secundaria tampoco se puede desvincular.

La maestra es la única que vincula, y antes de mandar revisa que **en su lado** los dos productos
estén libres. Así que un vínculo de la secundaria que choque —con su producto o con el de la
maestra— es, por definición, un resto. La secundaria lo borra y arma el nuevo, en la misma
transacción.

**Opción descartada — rechazar, y un comando en la secundaria para soltar el vínculo huérfano.**
No borra nada sin que el dueño lo pida, pero el dueño tiene que darse cuenta de que quedó
huérfano y saber qué comando correr; mientras tanto, ese producto no se puede volver a vincular.

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

---

## ADR-009 — Reconciliación, fase 1: completar los libros

Estado: Aprobada · 2026-10-05 · aprobada por Julián
Temas: rest-api, opciones, uninstall, modulos
Cubre: includes/Reconciliation/_.php, includes/Rest/Reconcile.php, includes/Cli/Command.php, uninstall.php

### En pocas palabras

Cada tanto, las dos tiendas se muestran qué movimientos tienen de la otra. Si a una le falta
alguno, la que lo emitió lo vuelve a poner en la cola, y sale como cualquier otro.

Esto es la **fase 1** de la reconciliación (UC-06). La **fase 2** —que cada tienda compare su
stock contra su propio libro— va en el ADR-010. No depende de esta: un movimiento que no llegó
no está ni en el stock ni en el libro de la tienda a la que le falta, así que no la descuadra.

---

### 1. Cómo se comparan

A la tienda que arranca la llamamos **L**, y a la otra **O**. Se hacen dos preguntas:

```
Primera:  L le pregunta a O "¿qué movimientos míos tenés?"
          O contesta con la lista de números de secuencia.
          L mira los suyos:
            - los que O no tiene        → los vuelve a poner en la cola
            - los que O tiene, pero L no sabía (se perdió la confirmación) → los marca confirmados

Segunda:  L le manda a O "estos movimientos tuyos tengo yo".
          O hace lo mismo con los suyos.
```

Un ejemplo:

```
L tiene sus movimientos 101, 102, 103 y 104.
O contesta: "de los tuyos tengo 101, 102 y 104".
→ A O le falta el 103. L lo vuelve a poner en la cola, y la pasada de siempre lo manda.
```

**Tener un movimiento es tener su fila**, sin importar qué hizo O con él: aplicarlo, retenerlo o
descartarlo. Lo que se pregunta es si llegó.

**Las listas van de a 5.000.** Cada tanda dice qué números cubre —"del 4.121 al 9.120"—, y quien
la recibe compara sólo ese tramo. Si la reconciliación se corta a mitad, lo que ya se comparó
queda bien hecho.

#### Las opciones que se miraron

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Pasarse la lista de números** ✅ | Simple y exacta. Encuentra cualquier movimiento que falte, incluso uno que había llegado y la otra tienda perdió al restaurar un backup. El libro tiene unas 18.000 filas en 90 días entre los dos sentidos (SPEC §5): como mucho unos 100 KB por noche |
| B — Un resumen (hash) por cada bloque de 1.000 | Casi no manda nada cuando todo está bien. Pero las dos tiendas tienen que calcular el resumen sobre exactamente las mismas filas, y las purgas y los huecos de la numeración lo vuelven frágil. Con 18.000 filas no vale la pena |
| C — Preguntar sólo por lo que no se confirmó | Lo más chico. Pero no ve el backup restaurado: un movimiento confirmado que la otra perdió no vuelve nunca. Y el SPEC pide comparar listas |
| D — Comparar sólo lo nuevo desde la última reconciliación | Manda menos. Pero tampoco ve el backup restaurado: lo que ya se había revisado no se vuelve a mirar. Y ahorra poco, porque la purga ya limita la lista a 90 días. Si algún día pesara, la variante es comparar lo nuevo cada noche y todo una vez por semana: la misma pregunta con otro piso |

### 2. Qué se mira y qué no

No se comparan todos los movimientos: hay un límite arriba y otro abajo.

**Arriba: lo que pasa mientras se compara no cuenta.**

```
03:00  arranca la reconciliación. El último movimiento de L es el 900.
03:01  L vende algo → movimiento 901. Todavía está en la cola, por salir.
```

Si se comparara el 901, parecería que le falta a O, y no le falta: está en camino. Por eso se
compara hasta el 900, el número que tenía L al arrancar. A eso le decimos el **techo**.

**Abajo: lo que la otra tienda ya borró no se compara.**

Cada tienda borra lo que recibió hace más de 90 días (la purga, ADR-004 §4), y anota hasta qué
número borró. A eso le decimos la **marca de purga**.

```
O recibió el 300 de L hace 100 días, lo aplicó y lo borró.
Si L preguntara "¿tenés el 300?", O no lo encontraría.
L creería que le falta, se lo mandaría de nuevo, y O lo descontaría otra vez      ❌
```

Por eso L no pregunta por lo que está por debajo de la marca: O ya lo tuvo y lo borró.

**La excepción: lo que nunca se confirmó se pregunta siempre.** Es un seguro para un caso raro:
que las reconciliaciones no hayan funcionado durante meses. Normalmente no hace falta, porque un
movimiento que no llegó lo encuentra la reconciliación de esa misma noche.

Un ejemplo de cuándo se usaría. La tienda O tiene un firewall que deja pasar las ventas, pero
bloquea la reconciliación, porque manda una lista grande y le parece sospechosa:

```
1 de marzo    O se cae 8 horas. La venta 480 de L no llega a tiempo y queda abandonada.
              O vuelve, y la venta 500 de L llega bien.
2 de marzo    La reconciliación quiere preguntar por el 480 → el firewall la bloquea.
...           Bloqueada todas las noches, durante tres meses.
30 de mayo    O borra lo que recibió hace más de 90 días, incluido el 500.
              Su marca de purga queda en 500.
10 de junio   Alguien arregla el firewall, y corre la primera reconciliación que funciona.
```

- **Sin la excepción:** el 480 está por debajo de la marca, así que L no pregunta. La venta no
  se descuenta nunca, y O muestra una unidad que no existe.
- **Con la excepción:** L sabe que el 480 nunca se confirmó, y pregunta igual. O no lo tiene,
  L lo manda, y O la descuenta.

Así que la regla queda:

| El movimiento de L, por debajo de la marca | ¿Se pregunta? |
| --- | --- |
| Confirmado | No: O lo tuvo y lo borró |
| Nunca confirmado | Sí: si O no lo tiene, es que nunca llegó |

Cuesta una condición en una consulta. Y su riesgo es el más chico de los dos: en el peor caso
descuenta dos veces, lo que deja el stock más bajo que el real, y no lleva a sobrevender (ver
"Los riesgos que quedan").

**Los techos y las marcas viajan en la primera respuesta.** O contesta la primera tanda con su
techo y con su marca de lo que borró de L. Con eso, cada tienda sabe qué tramo mirar.

### 3. Qué se hace con cada movimiento

| El movimiento propio… | …y la otra tienda **no** lo tiene | …y la otra tienda **sí** lo tiene |
| --- | --- | --- |
| Confirmado | Se vuelve a poner en la cola | Nada: está bien |
| Abandonado | Se vuelve a poner en la cola | Se marca confirmado |
| Pendiente | Nada: ya va a salir | Se marca confirmado |
| En camino | Nada: ya está saliendo | Nada: su confirmación está por llegar |
| Un bootstrap | Nada: no va por la cola (ADR-008) | Nada |

**Volver a ponerlo en la cola** es dejarlo `pending` y con el reloj en cero: sin intentos, sin hora
del primer intento y sin error. Si conservara la hora del primer intento, pasaría las 6 horas del
techo y se abandonaría de nuevo sin intentarlo (ADR-001 §4).

**La reconciliación no manda nada ella misma.** Sólo vuelve a poner en la cola. Así un movimiento
sale siempre por el mismo camino.

**Cada cambio se hace sólo si el movimiento sigue como estaba.** Por ejemplo: "marcalo confirmado,
pero sólo si sigue pendiente". Si no, la pasada podría llevárselo justo en el medio, y la
reconciliación lo marcaría confirmado mientras está en camino.

### 4. Una reconciliación por vez

Hay un **candado**: una opción, `wss_reconciling`, que dura 15 minutos. La reconciliación lo
toma al arrancar y lo suelta al terminar. Si se muere a mitad, el candado vence solo, y la
siguiente puede arrancar.

- **Vale para las dos tiendas.** Si O está en medio de su propia reconciliación, le contesta a L
  "estoy ocupada" (`409 wss_reconciling`).
- **Cada una suelta sólo el suyo.** El candado lleva una marca de quién lo tomó. Así, una
  reconciliación que tardó más de 15 minutos no le borra el candado a la que vino después.
- **Se lee directo de la base**, no de la caché, que podría tener una copia vieja.

Si el candado está tomado:

- **La manual** termina con un error: "hay una reconciliación en curso".
- **La de la madrugada y la de una importación** se saltean sin avisar. La próxima cubre lo mismo.

El candado sólo evita trabajo doble. Que no se descuente dos veces lo garantiza otra cosa: la
tienda que recibe nunca aplica dos veces el mismo movimiento (UC-06).

### 5. Quién la arranca

| Cuándo | Quién |
| --- | --- |
| **Todas las noches**, a las 3 de la hora del sitio | La maestra. La secundaria no |
| **A mano**, con `wp wss reconcile` (hasta que exista la pantalla, tarea 1.11) | La maestra. En la secundaria da error, como `wp wss link` |
| **Al terminar una importación grande** | Cualquiera de las dos |

**Cómo se sabe que terminó una importación:**

1. Una importación grande se nota porque la pasada manda **un lote lleno**: 200 movimientos.
2. Esa pasada agenda un **cierre** para dentro de 5 minutos, y anota el último número de ese
   momento. Si ya hay un cierre agendado, no agenda otro.
3. Cuando el cierre corre, mira lo propio **hasta ese número**:
   - Si queda algo que todavía no se intentó mandar, o que está en camino, espera 5 minutos más.
   - Si no, reconcilia.

Hasta ese número, para que las ventas nuevas no lo hagan esperar para siempre. Y sólo lo que no
se intentó, para no esperar a un movimiento que falló y está esperando su reintento: ése puede
tardar hasta 6 horas, y la reconciliación igual no lo tocaría (§3).

El cierre no depende de la importación: si el importador se corta a mitad, lo que alcanzó a
escribir sale por la cola, y el cierre corre cuando termine de salir. Una importación de menos de
200 entra en un solo lote, y cada movimiento ya tiene su propia confirmación.

### 6. Si la otra tienda no responde

No se toca nada: sin la lista de la otra, no hay con qué comparar. El resultado lo dice así: **"no
se pudo mirar"**, que no es lo mismo que "no había nada que arreglar" (UC-06). La de la noche
vuelve a intentarlo la noche siguiente. La manual se puede lanzar de nuevo cuando la otra vuelva.

### 7. El resultado

Cada tienda guarda cómo terminó la última reconciliación en la opción `wss_last_reconciliation`:
cuándo fue, quién la arrancó, cómo terminó —`done`, `unreachable` o `busy`—, cuántos movimientos
volvió a poner en la cola y cuántos marcó confirmados. Las dos lo guardan, cada una con lo suyo.
Así la secundaria lo puede mostrar aunque no la arranque. El aviso por email va al ADR del log
(tarea 1.10).

### 8. Los vínculos armados de un solo lado

A veces un vínculo queda en una tienda y no en la otra. Por ejemplo, un bootstrap que la maestra
dio por perdido, pero que la secundaria procesó tarde (ADR-008).

Cuando arranca la maestra, en la segunda pregunta manda también **la lista de sus vínculos**. La
secundaria la compara con los suyos:

| Qué encuentra | Qué hace |
| --- | --- |
| La secundaria tiene un vínculo que la maestra no | **Lo borra.** Sólo vincula la maestra, así que ése no tiene respaldo |
| La maestra tiene un vínculo que la secundaria no | **Lo informa, no lo arregla.** Arreglarlo sería mandar un bootstrap, que cambia el stock de la otra tienda. Eso lo decide el dueño, volviendo a vincular |
| Las dos lo tienen, pero con distinta generación | Lo informa |

**Sólo cuando arranca la maestra.** Cuando arranca la secundaria —al cerrar una importación— no
se comparan vínculos. La de la noche ya lo cubre.

**Sólo los vínculos que existían cuando arrancó.** La lista es una foto, y un vínculo armado un
segundo después no está en ella:

```
03:00:00  la maestra arma la lista de sus vínculos
03:00:02  se vincula el producto 123 → la secundaria arma su vínculo
03:00:03  la secundaria compara: "tengo uno que la maestra no" → lo borraría      ❌
```

Para evitarlo se usa la generación, que es el número del bootstrap en la maestra (ADR-005 §4).
Un vínculo armado después tiene una generación por encima del techo de la maestra, así que queda
para la próxima. Es la misma regla que con los movimientos.

---

### Qué cambia en otros ADRs

| ADR | Qué cambia |
| --- | --- |
| ADR-001 §4 | `POST /reconcile/digest` son dos preguntas, por tandas. Suma la respuesta `409 wss_reconciling` |
| ADR-003 | `wss_reconciling` y `wss_last_reconciliation` se borran siempre al desinstalar: no son datos del dueño |
| ADR-004 §4 | La marca de purga sólo deja afuera lo confirmado |

### Lo que se vuelve más difícil

El endpoint tiene dos preguntas y va por tandas, y cada tanda tiene que funcionar sola. Los
tests tienen que probar una reconciliación cortada a mitad.

### Los riesgos que quedan

- **Un movimiento descontado dos veces.** Es el costo de la excepción de §2. Pasaría si O
  recibió uno de L, lo borró a los 90 días, y L nunca se enteró de que había llegado: se perdió
  la confirmación y no corrió ninguna reconciliación en 90 días. Se acepta porque el error que
  evita es peor: sin la excepción, en ese mismo caso extremo se pierde una venta, y la otra
  tienda vende algo que no hay.
- **Un movimiento de una importación que falló y espera su reintento.** El cierre corre sin él y
  no lo toca. Si termina abandonado, lo recupera la reconciliación de la noche.
- **Una segunda importación mientras ya hay un cierre agendado.** No agenda otro. La cubre el
  próximo lote lleno después de que ese cierre corrió, o la reconciliación de la noche.

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| La fase 2: el stock contra el propio libro, y las discrepancias | ADR-010 |
| Aplicar solos los movimientos retenidos cuando un par vuelve a estar bien | Sin ADR (SPEC §6, pregunta abierta) |
| El aviso por email y el log | ADR del log (tarea 1.10) |
| El botón en la pantalla | ADR de la interfaz (tarea 1.11) |

### En qué se parece a otras cosas

Compararse listas y pedir lo que falta se llama **anti-entropy**: es lo que hacen Cassandra y
Dynamo para reparar sus réplicas. Ellos usan resúmenes por bloque (la opción B) porque comparan
millones de filas. Acá son miles.

---

## ADR-010 — Reconciliación, fase 2: el stock contra el propio libro

Estado: Aprobada · 2026-10-05 · aprobada por Julián
Temas: db-schema, rest-api, modulos
Cubre: includes/Reconciliation/_.php, includes/Schema/Installer.php, includes/Linking/Links.php, includes/Rest/Reconcile.php, includes/Cli/Command.php

### En pocas palabras

Cada tienda hace una cuenta por cada producto vinculado: **el stock con que arrancó, más todo lo
que su libro dice que pasó después**. Si el resultado no es el stock que tiene, alguien lo cambió
sin que el plugin se enterara. Eso es una **discrepancia**.

El plugin no la arregla: no sabe cuál de los dos números es el correcto. La guarda, la muestra y
le da al dueño dos formas de resolverla.

```
Producto 123. Al vincularlo arrancó con 50.
El libro dice: vendió 2, vendió 1, entró 5 de la otra tienda.
   Esperado: 50 − 2 − 1 + 5 = 52
   Real:     47
   → discrepancia: faltan 5 que nadie anotó
```

**Nunca compara una tienda con la otra.** Una venta que todavía viaja dejaría los dos números
distintos sin que nada esté mal (UC-06).

---

### 1. La cuenta

```
esperado = stock base del vínculo
         + los movimientos propios de ese vínculo y su generación
         + los recibidos de ese vínculo y su generación que se aplicaron
```

- **Los propios entran todos**, se hayan entregado o no: cada uno ya cambió el stock de acá en el
  momento en que pasó.
- **De los recibidos, sólo los aplicados.** Uno retenido o descartado no tocó el stock.
- **Sólo la generación vigente.** Un bootstrap nuevo fija otro stock base, y lo de antes ya no
  cuenta (ADR-005 §4).
- **El bootstrap y el `unlink` no suman**: su delta es 0.

La purga no rompe la cuenta: al borrar, el stock base absorbe lo borrado (ADR-004 §4).

### 2. Qué productos no se revisan

| Caso | Por qué |
| --- | --- |
| Tiene movimientos recibidos **retenidos** | El stock no tiene por qué coincidir: hay cosas que todavía no se aplicaron (UC-06) |
| Su bootstrap **sigue sin resolver** | No se sabe todavía con qué stock arrancó el par (ADR-008) |
| El producto de acá **no lleva su propio stock**, está en la papelera o no existe | No hay un stock propio contra el cual comparar |

Todo esto se mira acá, sin preguntarle a la otra tienda: la cuenta es local.

### 3. No confundir un cambio en curso con una discrepancia

WooCommerce escribe el stock, y el plugin anota el movimiento un instante después. Si la cuenta
cae justo en ese instante, ve el stock cambiado y el movimiento sin anotar:

```
03:00:00.000  una venta deja el stock en 46
03:00:00.002  la fase 2 lee: real 46, esperado 47        → parece una discrepancia
03:00:00.004  el plugin anota la venta: esperado 46      → no lo era
```

Por eso **una discrepancia se confirma antes de abrirse**: los productos que no cerraron se
vuelven a revisar **2 minutos después**, y se abre sólo si la diferencia sigue siendo la misma.
Una venta en curso ya se anotó para entonces; un cambio que nadie registró sigue ahí.

La segunda revisión es una acción de Action Scheduler: no deja la reconciliación esperando.

### 4. Dónde se guarda

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Cuatro columnas en `wss_links`** ✅ | Hay **una discrepancia por vínculo y por tienda** (UC-06), y el vínculo ya es la fila. Se borra sola con el vínculo. Leerlas para la pantalla es la misma consulta que ya lista los vínculos. Contra: no guarda historia; lo que pasó queda en el log (tarea 1.10) |
| B — Una tabla `wss_discrepancies` | Guarda la historia de cada una. Pero es una tabla más con su purga, y hay que garantizar a mano que no haya dos abiertas para el mismo vínculo, que en A sale gratis |
| C — Una opción con la lista | Simple al principio. Pero dos procesos que la escriben a la vez se pisan, y con cientos de productos se vuelve pesada en cada lectura |

Las columnas:

| Columna | Qué guarda |
| --- | --- |
| `expected_stock` | Lo que dice el libro |
| `found_stock` | Lo que había |
| `discrepancy_since` | Cuándo se vio por primera vez |
| `discrepancy_seen_at` | Cuándo se vio por última vez |

Las cuatro en `NULL` quieren decir "sin discrepancia".

**Una discrepancia es de una generación.** Si un bootstrap nuevo cambiara la generación de la
fila, las cuatro columnas se limpian en la misma escritura: si no, quedaría abierta una
discrepancia del par anterior. Hoy no pasa —un bootstrap nuevo siempre crea otra fila (ADR-008
§3)—, pero la regla queda escrita para el día que eso cambie.

### 5. Se abre una vez y se cierra sola

- **Si ya hay una abierta** y la diferencia sigue igual, no se abre otra: se actualiza
  `discrepancy_seen_at`. **Si la diferencia cambió**, la nueva pasa por la misma confirmación de
  2 minutos (§3) antes de reemplazar los números.
- **Si la cuenta vuelve a dar bien**, las cuatro columnas vuelven a `NULL`. El dueño no tiene que
  marcar nada.
- **Mientras está abierta, el producto sincroniza igual.** Las ventas se siguen propagando; la
  diferencia se mantiene, pero no crece (UC-06).

### 6. Cómo la resuelve el dueño

**Acá hay un problema en el SPEC.** UC-06 dice que la discrepancia se cierra cuando el dueño
corrige el stock y el ajuste se propaga. Pero corregir el stock a mano también es un movimiento:
el plugin lo anota, y el esperado se mueve junto con el real.

```
Esperado 50, real 47.
El dueño pone 50 a mano → el plugin anota +3 → esperado 53, real 50.
La diferencia sigue en 3                                                ❌
```

Así que hacen falta **dos acciones que no son un cambio de stock común**. Las dos cierran la
discrepancia:

| El dueño dice | Qué hace el plugin |
| --- | --- |
| **"El stock de acá está bien"** (hubo una venta o un ajuste que no se anotó) | Anota la diferencia como un ajuste —en el ejemplo, −3— **sin tocar el stock de acá**. El ajuste viaja a la otra tienda como cualquier otro, y las dos quedan con el número real |
| **"El libro está bien"** (alguien escribió mal el stock) | Le suma al stock de acá la diferencia —en el ejemplo, +3— **sin anotar un movimiento**, con el guard (ADR-006 §3). La otra tienda no se entera, porque ya tenía el número bueno |

**"El libro está bien" suma la diferencia; no escribe el número esperado.** Escribir 50 pisaría
una venta que entrara justo en el medio: quedaría anotada en el libro, pero el stock no la
tendría, y la tienda mostraría una unidad que no hay. Sumando +3 con
`wc_update_product_stock( …, 'increase' )`, como hace `apply()`, la venta y la corrección se
suman las dos.

**Las dos acciones rehacen la cuenta en el momento.** No usan los números guardados, que pueden
tener días. Vuelven a mirar las exclusiones de §2, vuelven a calcular, y **se niegan si la
diferencia no es la que vio el dueño**: "cambió desde que la viste, mirala de nuevo". Así tampoco
anotan un ajuste equivocado si justo entra una venta mientras calculan.

Hasta que exista la pantalla (tarea 1.11), las dos van por WP-CLI:

```
wp wss discrepancies                      la lista, de esta tienda
wp wss discrepancy accept <producto>      "el stock de acá está bien"
wp wss discrepancy restore <producto>     "el libro está bien"
```

El SPEC se corrige en el mismo commit que apruebe este ADR: el criterio de UC-06 sobre el cierre
pasa a decir que se cierra con cualquiera de las dos acciones, o sola si los números vuelven a
coincidir.

### 7. Cuándo corre, y en qué tienda

**Corre en las dos tiendas, al final de cada reconciliación.** Es una tercera pregunta del
mismo endpoint de ADR-009:

```
Primera:   L → O  "¿qué tenés de lo mío?"
Segunda:   L → O  "esto tengo de lo tuyo"
Tercera:   L → O  "hacé tu fase 2"
           O      → hace su cuenta y contesta con sus discrepancias abiertas
           L      → hace la suya
```

Así los tres disparadores de ADR-009 —la noche, la manual y el cierre de una importación— hacen
las dos fases en las dos tiendas, como pide UC-06. Y la maestra recibe las discrepancias de la
secundaria, que la pantalla necesita para mostrar las dos en un solo lugar.

**Si la otra tienda no responde desde el principio**, ADR-009 ya corta antes: no corre ninguna
fase en ninguna.

**Si falla sólo la tercera pregunta** —la otra se cayó justo después de la fase 1—, L hace igual
su propia fase 2, porque la cuenta es local. El resultado dice que la de O no se pudo mirar.

Lo que contesta O se guarda en el resultado de la última reconciliación
(`wss_last_reconciliation`, ADR-009 §7).

**La maestra ve las de la secundaria con una reconciliación de atraso.** O contesta con las que
ya tiene abiertas; las nuevas se confirman 2 minutos después (§3), así que la maestra las ve en la
pasada siguiente. Se acepta así:

- Hasta la tarea 1.11, **cada tienda resuelve las suyas con su propio WP-CLI**.
- La vista unificada, y resolver las de la secundaria desde la maestra, van al ADR de la
  interfaz: hace falta un endpoint que hoy no existe, y ese ADR tiene que decidir igual cómo se
  ven.

---

### Qué cambia en otros ADRs y en el SPEC

| Dónde | Qué cambia |
| --- | --- |
| ADR-004 §2 | `wss_links` suma las cuatro columnas. Sube la versión del esquema |
| ADR-009 | El endpoint suma la tercera pregunta |
| SPEC, UC-06 | El criterio de "se cierra sola" se corrige como dice §6. Y el de "sin falso positivo por movimiento en vuelo" ya no se apoya en el orden de las fases —desde ADR-009, la fase 1 sólo vuelve a poner en la cola—, sino en que la cuenta es local |

### Lo que se vuelve más difícil

La cuenta tiene que coincidir con la purga, con los cambios de generación y con lo retenido: un
error en cualquiera de las tres abre discrepancias falsas en todos los productos. Los tests
tienen que cubrir cada una.

### Los riesgos que quedan

- **Una discrepancia falsa por una escritura lenta.** Si entre el cambio de stock y su anotación
  pasaran más de 2 minutos, se abriría igual. Se cerraría sola en la reconciliación siguiente.
- **El doble conteo del detector** (ADR-006 §1): un cambio ajeno al stock entre las dos lecturas
  se cuenta dos veces. Eso abre una discrepancia de verdad, y está bien que se vea.

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| El aviso de una discrepancia | Va al log (ADR-011), no al email: en v1 las discrepancias viven en el log y en la pantalla (UC-08, *fuera de este caso*) |
| Cómo se ve en la pantalla | ADR de la interfaz (tarea 1.11) |

### En qué se parece a otras cosas

Recalcular un saldo desde el historial y compararlo con el guardado es lo que hace un banco al
**conciliar** una cuenta, y lo que en event sourcing se llama reconstruir el estado desde los
eventos. Y confirmar antes de abrir es lo que hace un monitoreo con un umbral de "dos lecturas
seguidas": evita alarmas por un instante.

---

## ADR-011 — El log: qué queda registrado y dónde

Estado: Aprobada · 2026-10-06 · aprobada por Julián
Revisado: 2026-10-06 — aprobado por Julián, con ADR-012. Un `409 wss_reconciling` no es
un rechazo: la otra está ocupada. Y `link_failed` dice en `context` si salió de algo que corría
solo. Ver §3 y §4.
Temas: db-schema, uninstall, modulos
Cubre: includes/Log/_.php, includes/Schema/Installer.php, includes/Cli/Command.php, uninstall.php

### En pocas palabras

Una tabla nueva, `wss_events`, guarda **lo que pasa y no es un movimiento de stock**: lo que el
dueño hace con un par, los intentos que fallan, y los eventos del sistema —una caída de la otra
tienda, una reconciliación, una discrepancia—. Los movimientos de stock **no** van ahí: ya están
en el libro.

El historial de un producto junta las dos cosas en una línea de tiempo: los movimientos del
libro y los eventos de esta tabla (UC-03).

Los avisos por email (UC-08) van en el ADR-012, y leen de acá.

---

### 1. Dónde se guarda

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Una tabla propia, `wss_events`, para todo** ✅ | Un solo lugar, que se consulta por producto, por tipo o por fecha. Es lo que ya hace woonderful-sync con `ws_stock_logs`, y su pantalla de log (`Logs.js`: filtros, paginado, filas que se expanden) es el punto de partida de la de este plugin (tarea 1.11). Contra: una tabla más, con su purga y su lugar en el desinstalador |
| B — Una tabla para lo de cada producto, y el logger de WooCommerce para lo del sistema | El log del sistema sale casi gratis en *WooCommerce → Estado → Registros*. Pero son dos lugares, y mostrar lo del sistema en la pantalla propia obliga a leer los archivos de WooCommerce |
| C — Todo en el logger de WooCommerce | Ninguna tabla nueva. Pero por defecto escribe archivos: armar el historial de un producto es recorrer texto, y el ADR-012 no podría preguntar bien "¿esto ya lo avisé?" |

**La diferencia con woonderful-sync:** allá se escribe una fila por cada movimiento de stock,
porque no hay otro registro. Acá el libro ya es ese registro, así que repetirlo duplicaría cada
venta. Por eso esta tabla guarda **sólo lo que no es un movimiento**.

### 2. La tabla

```sql
CREATE TABLE {prefix}wss_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at  DATETIME        NOT NULL,   -- UTC
  level       VARCHAR(10)     NOT NULL,   -- info · warning · error
  kind        VARCHAR(40)     NOT NULL,   -- qué pasó: ver §3
  product     BIGINT UNSIGNED NULL,       -- el producto de esta tienda, si es de uno
  user_id     BIGINT UNSIGNED NULL,       -- quién, si fue una persona; NULL si no (WP-CLI sin --user da 0)
  cause       VARCHAR(40)     NULL,       -- a qué se atribuye: ver §5
  context     TEXT            NULL,       -- JSON con los datos del evento
  PRIMARY KEY (id),
  KEY by_product (product, created_at),
  KEY by_date (created_at)
) ENGINE=InnoDB;
```

**Dos índices, y por qué.** `by_product` es el historial de un producto, que es lo que más se
mira. `by_date` es la purga y el listado general, que se lee de lo más nuevo a lo más viejo. No
se indexan `kind` ni `cause`: filtrar por ellos recorre a lo sumo 90 días de eventos, que son
pocos (ver §6).

**El texto no se guarda: se arma al mostrarlo.** Cada evento guarda su tipo y sus datos en
`context`, y la pantalla o el comando arman la frase. Así se puede traducir, y cambiar cómo se
dice algo no obliga a migrar filas viejas.

### 3. Qué se registra

**Lo que el dueño hace con un par**, con su usuario, aunque no mueva stock (UC-03):

| `kind` | Cuándo |
| --- | --- |
| `linked` | Se vinculó un producto |
| `link_failed` | **Un intento de vincular que falló**, con su motivo. Es lo que distingue un olvido de una falla. `context` dice si salió de algo que corría solo —la pregunta por un bootstrap incierto, `background: true`— o de lo que el dueño estaba haciendo: el usuario no alcanza, porque WP-CLI sin `--user` y lo automático dan `NULL` los dos |
| `unlinked` | Se desvinculó |
| `discarded` · `undiscarded` | Se descartó la sugerencia de un producto, o se deshizo el descarte (UC-03; lo usa la pantalla, tarea 1.11) |
| `discrepancy_accepted` · `discrepancy_restored` | Se resolvió una discrepancia con una de las dos acciones (ADR-010 §6) |

**Lo que pasa solo**, sin nadie mirando:

| `kind` | Cuándo |
| --- | --- |
| `peer_unreachable` · `peer_back` | La otra tienda dejó de responder, o volvió. **Sólo el cambio**: no una línea por cada pasada que falla mientras sigue caída (ver §4) |
| `peer_rejected` · `peer_accepted` | La otra tienda **responde pero rechaza** —un 4xx: una firma mala después de re-emparejar mal, por ejemplo—, o dejó de rechazar. También sólo el cambio. No es una caída: es un error propio, y se registra como `error` |
| `abandoned` | Una pasada abandonó movimientos por pasar las 6 horas: una línea con cuántos (ADR-001 §2). **Con la otra caída no se registra**: es un síntoma de la caída, cada pasada abandonaría unos pocos —una línea por minuto—, y lo repara la reconciliación cuando vuelve. Con la otra rechazando sí: eso no lo repara nada solo |
| `reconciled` | Una reconciliación terminó: quién la lanzó, cómo terminó y qué hizo (ADR-009 §7). **La registran las dos tiendas**, cada una con lo suyo, como las dos guardan su resultado |
| `discrepancy_opened` · `discrepancy_closed` | La fase 2 abrió una discrepancia, o se cerró sola (ADR-010 §5) |
| `link_removed` | La reconciliación borró un vínculo sin respaldo en la secundaria (ADR-009 §8) |
| `pair_broken` · `pair_restored` | Un producto **de esta tienda** que está vinculado se fue a la papelera, se borró o dejó de llevar su propio stock; o volvió. Ver §6 |
| `link_unresolved` | **Un bootstrap sigue incierto y la pregunta por él falla** (ADR-008 §5): sus ventas quedan retenidas hasta que se resuelva. Una vez por bootstrap, la primera vez que la pregunta falla |
| `ledger_error` | Una escritura del libro que la base rechazó —por ejemplo, el `UPDATE` del claim—: la entrega se corta hasta que se arregle |

**Lo que no se registra:**

- **Cada movimiento de stock.** Ya está en el libro.
- **Cada reintento de un envío.** El estado vive en la fila del libro —intentos, último error—;
  el log recibe una línea por evento, no por reintento (ADR-001 §2).
- **Cada venta de un producto sin vincular.** Llenaría el log de ruido, y el producto ya figura
  como _sin vincular_ (UC-01). Lo que no sincroniza lo avisa el ADR-012 por tiempo, no por
  ventas.
- **Una reconciliación salteada** porque había otra en curso: no pasó nada.

### 4. Cuándo está caída la otra tienda

**Una caída es un error de red, un timeout o un 5xx.** Un 4xx no: la otra respondió y dijo que no
—una firma mala, un reloj desfasado, un endpoint que no existe—. Eso es un **rechazo**, y no lo
arregla esperar.

**Salvo el `409 wss_reconciling`**: la otra está en medio de su propia reconciliación (ADR-009
§4). Es normal —pasa si un cierre de importación coincide con la noche de la otra— y no cambia
el estado.

| Respuesta | Qué es | Se registra |
| --- | --- | --- |
| Error de red, timeout, 5xx | Caída | `peer_unreachable` una vez, `peer_back` cuando vuelve |
| 4xx | Rechazo | `peer_rejected` una vez, como `error`; `peer_accepted` cuando deja de rechazar |
| 200 | Anda | Nada, salvo que antes estuviera caída o rechazando |

**El estado vive en una opción, `wss_peer_state`**: `ok`, `unreachable` o `rejected`, y desde
cuándo. Se compara con la respuesta nueva, y sólo un cambio escribe una línea. El transient
`wss_send_failed` no sirve para esto: dura una pasada, y el log escribiría la caída y la vuelta
una vez por minuto.

**Una caída se nota con el primer request que falla**, no a la hora en que la otra cayó: el
plugin no la vigila, se entera cuando le habla. Le hablan la pasada cuando tiene algo que
mandar, el envío al final del request, la reconciliación, la vinculación y el catálogo; todos
actualizan el estado. Sin ventas, la reconciliación de la noche de la maestra es un request por
día, así que una caída se registra a lo sumo a la mañana siguiente.

### 5. La causa

`cause` dice **a qué se atribuye** un evento, para que una tienda caída no se lea como un error
de cada producto (UC-08):

```
14:00  peer_unreachable                      ← la otra tienda dejó de responder
14:05  link_failed   producto 123   cause: peer_unreachable
14:06  link_failed   producto 124   cause: peer_unreachable
```

El historial del 123 dice "falló porque la otra tienda no respondía", no "falló el 123". Y el
ADR-012 agrupa por causa: 300 productos que fallan por lo mismo son un aviso, no 300.

### 6. Cuánto se guarda

**90 días, como el libro** (SPEC §5), en la purga de la noche (ADR-009 §5), con el mismo
candado.

**Con una excepción: lo que es una decisión o una memoria no se borra.** La purga borra
eventos; no puede borrar algo que el plugin necesita recordar.

- **El descarte.** Descartar es una decisión del dueño, y es lo que hace que ese producto no
  vuelva a aparecer en la pantalla ni en el email (UC-03). Si se purgara a los 90 días, el
  producto volvería a avisarse como si nadie hubiera decidido nada. Así que `discarded` y
  `undiscarded` no se purgan nunca.
- **Lo que ya se avisó** (ADR-012). UC-08 pide avisar de cada producto una sola vez, aunque
  siga igual durante meses: si "ya lo avisé" viviera en una fila que se purga a los 90 días, el
  aviso se repetiría. El ADR-012 lo guarda en filas que la purga no borra, o en otro lado; esta
  tabla sólo garantiza que la purga respeta la lista de tipos que no se borran.

Son pocos: uno por producto que el dueño decidió dejar afuera, o del que ya se avisó.

**Cuánto ocupa.** Sin una línea por movimiento ni por reintento, son unos pocos eventos por día:
las acciones del dueño, una reconciliación por noche, y los cambios de estado. Una caída de un
día entero son dos líneas.

### 7. Quién escribe

Una sola clase, `Log\Events`, con `record( $kind, $level, $product, $context, $cause )`. La
llaman las piezas que ya existen, en el lugar donde pasa cada cosa:

| Quién | Qué registra |
| --- | --- |
| `Linking\Linker` | `linked`, `link_failed`, `unlinked`; `link_unresolved` la primera vez que la pregunta por un bootstrap incierto falla |
| Todo lo que le habla a la otra tienda | El estado de §4, por una sola función: `peer_unreachable`, `peer_back`, `peer_rejected`, `peer_accepted` |
| `Transport\Sender` | `abandoned`, después del claim: `claim()` cuenta cuántos abandonó, y el emisor —que sabe el estado de la otra— decide si registrarlo. `ledger_error` si el claim no pudo escribir |
| `Reconciliation\Reconciler` y `Rest\Reconcile` | `reconciled`, cada tienda la suya |
| `Reconciliation\Audit` | `discrepancy_opened`, `discrepancy_closed`, `discrepancy_accepted`, `discrepancy_restored` |
| `Reconciliation\Books` | `link_removed` |
| La noche (`Reconciliation\Schedule`) | `pair_broken` y `pair_restored` |

**Si escribir el log falla, no se corta nada.** El log cuenta lo que pasó; no puede impedir que
pase. Una venta no deja de propagarse porque no se pudo escribir una línea.

**`pair_broken`, un caso aparte.** El par se rompe cuando un producto se va a la papelera, y
nada del plugin lo observa: el estado se calcula al mirarlo (ADR-005 §1).

- **Cada tienda mira sus propios productos.** Lo de este lado se sabe sin preguntarle a nadie;
  lo del otro lado lo registra la otra tienda. Así no depende de que la otra conteste.
- **Lo mira la noche, en las dos tiendas**, recorriendo los vínculos: no la fase 2, que no corre
  las noches en que la otra no responde.
- **Recuerda que ya lo vio en una columna de `wss_links`, `broken_since`**, como las de la
  discrepancia: se llena al verlo roto —y ahí se registra `pair_broken`— y vuelve a `NULL` al
  verlo sano —y ahí `pair_restored`—. Así no depende de buscar un evento que la purga pudo
  borrar, y si se rompe otra vez después de arreglarse, se registra de nuevo.

Llega a la mañana siguiente y no en el momento: si el aviso tiene que salir antes, lo decide el
ADR-012.

### 8. Cómo se lee

- **El historial de un producto:** los movimientos del libro y los eventos de ese producto, en
  una sola línea de tiempo, de lo más nuevo a lo más viejo (UC-03).
- **El log general:** los eventos, filtrables por nivel, tipo, causa y fecha, paginados.
- Hasta que exista la pantalla (tarea 1.11), por WP-CLI: `wp wss log` y `wp wss log --product=<id>`.

---

### Qué cambia en otros ADRs

| ADR | Qué cambia |
| --- | --- |
| ADR-004 | Una cuarta tabla, `wss_events`, y la columna `broken_since` en `wss_links`. Sube la versión del esquema. El control de las tablas sanas suma la tabla nueva |
| ADR-003 | `wss_events` es historial del dueño: se borra **sólo con la casilla marcada**, como el libro. La opción `wss_peer_state` se borra siempre: es estado de la conexión |
| ADR-010 | "El aviso por email de una discrepancia nueva" no va: en v1 las discrepancias viven en el log y en la pantalla, no en el email (UC-08, *fuera de este caso*) |

### Lo que se vuelve más difícil

Cada pieza que hace algo importante tiene que acordarse de registrarlo, y un evento que falta no
se nota hasta que alguien lo busca. Los tests de cada pieza tienen que mirar que el evento quede.

### Los riesgos que quedan

- **Un par roto se registra a la mañana siguiente**, no en el momento (§7).
- **Una caída se registra cuando el plugin le habla a la otra**, no cuando la otra cae (§4).
- **Si escribir el log falla, el evento se pierde** sin aviso: se prefiere eso a que una venta
  no se propague.

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| Los emails, la ventana de silencio, "la otra tienda no responde" y "volvió" por email, y qué ya se avisó | ADR-012 |
| La pantalla del log y del historial | ADR de la interfaz (tarea 1.11), partiendo de `Logs.js` de woonderful-sync |
| Las sugerencias por SKU y qué productos "no sincronizan" | ADR de la interfaz (tarea 1.11) |
| Editar las líneas de un pedido deja el movimiento como `adjustment`, sin número de pedido | No es un evento que falte: es un límite del estampado (ADR-006 §2), y queda como deuda conocida en STATUS |

---

## ADR-012 — Los avisos por email

Estado: Aprobada · 2026-10-06 · aprobada por Julián
Temas: opciones, uninstall, modulos
Cubre: includes/Log/Notifier.php, includes/Reconciliation/Schedule.php, includes/Cli/Command.php, uninstall.php

### En pocas palabras

**Cada tienda avisa de lo suyo**, por email, de lo que pasa cuando no hay nadie mirando (UC-08):

- **Lo que no sincroniza**, en un resumen de la noche, una sola vez por producto.
- **Los errores, en el momento**: una vinculación que falló sola, un par roto, la otra tienda
  que no responde o que rechaza todo.
- **Que la otra tienda volvió**, si se había avisado que no respondía.

Lee del log (ADR-011): cada aviso sale de un evento que ya quedó registrado. Si no hay nada
nuevo, no sale nada. El silencio es lo normal.

---

### 1. Quién avisa

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Cada tienda, de lo suyo** ✅ | Todo lo que necesita para avisar está en ella: sus productos, su log, el estado de la otra. No depende de que la otra conteste, y si se cae una, la otra avisa. Contra: dos tiendas, dos configuraciones, y puede llegar un email de cada una el mismo día |
| B — Sólo la maestra, preguntándole a la secundaria | Un solo puesto, como el resto de la administración (SPEC §3). Pero necesita un endpoint nuevo para preguntar, y si la maestra se cae no avisa nadie, justo cuando más hace falta |

### 2. Qué se avisa, y cuándo

| Qué | Cuándo sale | De qué evento (ADR-011) |
| --- | --- | --- |
| **Lo que no sincroniza** | En el resumen de la noche | Se calcula: ver §3 |
| **Una vinculación que falló sola** | En el momento | `link_failed` con `background: true` —un bootstrap que la pregunta de los 10 minutos dio por fallido— y `link_unresolved` |
| **Un par roto** | Cuando se detecta: a la noche (ADR-011 §7), así que llega a la mañana | `pair_broken` |
| **La otra tienda no responde** | **A los 30 minutos seguidos** sin responder | `peer_unreachable`, si a los 30 minutos sigue igual |
| **La otra tienda rechaza todo** | **A los 30 minutos seguidos** rechazando, como la caída: un solo 4xx no es "rechaza todo" | `peer_rejected`, si a los 30 minutos sigue igual |
| **La otra tienda volvió** | En el momento | `peer_back` o `peer_accepted`, **sólo si antes se avisó** que no respondía o rechazaba |

**Lo que no se avisa por email:**

- **Lo que pasa en una operación que el dueño está mirando.** Un intento de vincular que falla
  desde el comando o la pantalla se lo dice el resultado, no el email (UC-08). Sólo se avisa
  lo que descubre algo que corre solo: la pregunta por un bootstrap incierto, la noche, la
  pasada.
- **Las discrepancias**, los abandonos y los errores sueltos: viven en el log y en la pantalla
  (UC-08, *fuera de este caso*).
- **Nada que se atribuya a la caída o al rechazo de la otra.** Un evento con `cause:
  peer_unreachable` o `cause: peer_rejected` —un `link_failed`, un `link_unresolved`, lo que sea—
  no sale por email: ya se avisó la caída, una vez. Es lo que impide que una tienda caída
  dispare un email por cada cosa que falla.

#### Por qué 30 minutos y no 6 horas

El SPEC decía que la otra tienda se da por caída cuando la cola agota los reintentos de un
envío: a las 6 horas. Es tarde: en esas 6 horas la otra puede vender lo que ya no hay. Y avisar
en el primer error es ruido: un corte de un minuto no merece un email.

**30 minutos seguidos** separan las dos cosas. Un corte corto no avisa; una caída de verdad
avisa a la media hora. Es una constante, no una opción, como los otros tiempos del plugin.

El SPEC se corrige en el commit que apruebe este ADR.

#### Quién mira el reloj: un tick cada minuto

Un evento se escribe sólo cuando algo cambia (ADR-011 §4). Si después de la caída no pasa nada
más, nadie vuelve a mirar a los 30 minutos, ni cierra la ventana de §4, ni reintenta un email
que no salió. Así que **la pasada de cada minuto llama a `Notifier::tick()`**, que corre aunque
no haya nada que mandar:

- **Si la otra no está bien**, le pregunta a `GET /health` antes de decidir. La tienda le habla a
  la otra sólo cuando tiene algo que mandar: sin ventas, el estado podría decir "caída" de una
  tienda que volvió al minuto 2, y saldría un email falso a los 30. Y "volvió" esperaría hasta la
  noche. Con la pregunta, el estado está al día cada minuto mientras no está bien. Cuando está
  bien, el tick no pregunta nada: no agrega requests.
- **Si lleva 30 minutos caída o rechazando** y no se avisó, avisa.
- **Cierra las ventanas vencidas** de §4 y manda su resumen.
- **Reintenta lo que no salió**, al cerrar su ventana (§7).

El tick es barato cuando no hay nada: lee una opción y sale.

### 3. Lo que no sincroniza

Un producto de esta tienda entra en el resumen si:

- **lleva su propio stock** y está publicado: uno que no lleva su stock no se puede sincronizar
  por diseño (ADR-005);
- **no está vinculado**;
- **lleva más del plazo así** —24 horas por defecto—: desde que se creó, o desde que se
  desvinculó si fue después;
- **no está descartado** (UC-03);
- y **todavía no se avisó** de él desde la última vez que quedó sin vincular.

**Con sugerencia y sin sugerencia.** UC-08 pide separarlos, porque piden trabajos distintos.
Las sugerencias por SKU son de la pantalla (tarea 1.11), y todavía no existen. **Hasta
entonces, el resumen va en un solo grupo**; cuando estén, se separa con la misma función que use
la pantalla, sin cambiar nada más.

**El email:** cuántos nuevos y cuántos pendientes en total, y la lista de los nuevos —hasta 50;
si hay más, "y otros N"—. Una instalación con 5.000 productos sin vincular manda un email con
el total y 50 de ejemplo, y después silencio (UC-08).

### 4. Una vez por producto, una vez por incidente

**Lo que no sincroniza: "ya avisé" se guarda en el log**, como un evento `notified` de ese
producto, que la purga no borra (ADR-011 §6). Vale hasta que el producto se vincula: si después
se desvincula, es un problema nuevo y se puede volver a avisar (UC-08). Se compara el último
`notified` con el último `unlinked`.

**Los errores no necesitan nada guardado.** El log ya los escribe una vez por incidente —sólo
el cambio, ADR-011 §4—, así que avisar de cada evento es avisar una vez por incidente. Una marca
de "ya avisé" por causa que nunca se borrara apagaría la caída del mes siguiente: figuraría como
avisada. Para los errores alcanza con la ventana de 10 minutos.

**Por causa, con 10 minutos de silencio** (UC-08):

```
14:00:00  pair_broken, producto 12     → sale el email: "se rompió el par del 12"
14:00:05  pair_broken, productos 13…300 → se juntan: la ventana de esa causa está abierta
14:10:00  se cierra la ventana         → sale UN email: "se rompieron 289 pares más"
```

- **La primera de una causa sale en el momento.**
- **Después, 10 minutos de silencio para esa causa**: lo que siga, se junta.
- **Al cerrarse, sale un resumen** si se juntó algo, y la ventana se renueva.
- **Una causa no tapa a otra**: cada una tiene su ventana.

La ventana de cada causa y lo que se juntó viven en una opción, `wss_notify_state`, que se
borra siempre al desinstalar.

### 5. Un tope: 10 emails por día

Todo lo de arriba está pensado para que no salgan emails de más. **El tope es la red por si algo
de eso falla**: como mucho **10 emails por día** por tienda.

```
Una caída de 5 horas con 200 ventas:
   200 ventas que no se pueden mandar       → 0 emails: no hay un evento por venta (ADR-011)
   a los 30 minutos                         → 1: "la otra tienda no responde"
   lo que se abandona mientras está caída   → 0: no se registra
   la reconciliación de la noche no mira    → 0: va al log
   vuelve                                   → 1: "volvió"
                                              2 en total
```

Con el diseño, un día malo son unos pocos. El tope cubre lo que el diseño no previó: una tienda
que se cae y vuelve muchas veces en el día —cada caída de más de 30 minutos avisaría—, o un error
al programar que dispare avisos de más.

**Al llegar al tope sale un último email** —"llegaron muchos avisos hoy, mirá el log"— y después
silencio hasta el día siguiente, en la hora del sitio. Lo que no salió queda en el log. El costo
es que un día muy malo puede tapar el aviso número 11; el email del tope dice dónde mirarlo.

Es una constante, no una opción. La cuenta del día vive en `wss_notify_state`.

### 6. La configuración

Tres opciones por tienda:

| Opción | Por defecto | Qué es |
| --- | --- | --- |
| `wss_notify_enabled` | Sí | Si se mandan emails. Apagado, **todo se sigue registrando** en el log (UC-08), pero **los errores no se guardan para después**: si se apagan tres meses, al encenderlos no tiene que llegar una caída que ya pasó. El resumen de lo que no sincroniza se pone al día solo, porque se calcula cada noche |
| `wss_notify_email` | El email del administrador del sitio | A quién |
| `wss_notify_after_hours` | 24 | El plazo de §3 |

Hasta que exista la pantalla (tarea 1.11), se cambian por WP-CLI:
`wp wss notify --enabled=no`, `wp wss notify --email=<dirección>`, `wp wss notify --after=<horas>`;
y `wp wss notify` sin nada muestra cómo está.

### 7. Si el email no sale

`wp_mail()` puede fallar: un hosting sin correo configurado, por ejemplo. Entonces **no se
da por avisado**: el tick lo reintenta cuando se cierra la ventana de esa causa, a los 10
minutos —no cada minuto: con un hosting sin correo serían 1.440 intentos por día—. Lo que no
sincroniza vuelve a entrar en el resumen de la noche siguiente. Y queda
en el log como `notify_failed`, una vez por causa, para que la pantalla diga que los emails no
están saliendo.

### Qué cambia en otros ADRs y en el SPEC

| Dónde | Qué cambia |
| --- | --- |
| SPEC, UC-08 | La otra tienda se da por no disponible a los 30 minutos seguidos sin responder, no al agotar los reintentos. "La otra tienda rechaza todo" se avisa igual que una caída. Un tope de 10 emails por día. La separación con sugerencia / sin sugerencia llega con la pantalla |
| ADR-011 | Suma `notified` —sólo de productos, y la purga no lo borra— y `notify_failed`. Revisado: el `409 wss_reconciling` no es un rechazo, y `link_failed` dice en `context` si salió de algo que corría solo |
| ADR-001 §2 | La pasada de cada minuto llama a `Notifier::tick()` |
| ADR-003 | Las tres opciones son ajustes del dueño: se borran con la casilla marcada. `wss_notify_state` se borra siempre |

### Lo que se vuelve más difícil

Cada aviso depende de que el evento correcto esté en el log, con su causa: un evento que falta
es un aviso que no sale, y nadie lo nota. Los tests tienen que probar cada aviso desde el evento
que lo dispara.

### Los riesgos que quedan

- **Un día con más de 10 avisos tapa los que siguen**, hasta el día siguiente: quedan en el log,
  y el último email lo dice.
- **Una caída de menos de 30 minutos no se avisa.** Queda en el log, y la cola y la
  reconciliación la reparan solas.
- **Mientras la otra no está bien, el tick le pregunta cada minuto.** Es un request liviano, y
  sólo mientras dura el problema.
- **Un par roto se avisa a la mañana**, no en el momento: nada observa la papelera (ADR-011 §7).
- **Si el email no sale y nadie mira el log**, nadie se entera. Es el límite de avisar por
  email; la pantalla lo va a mostrar (tarea 1.11).

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| Las sugerencias por SKU y el descarte | ADR de la interfaz (tarea 1.11) |
| La pantalla de ajustes de estas tres opciones | ADR de la interfaz (tarea 1.11) |

---

## ADR-013 — La interfaz: la app, sus pantallas y cómo habla con el servidor

Estado: Aprobada · 2026-10-07 · aprobada por Julián
Revisado: 2026-10-07 — aprobado por Julián. Suma la configuración inicial: un asistente en pasos
que revisa lo que el plugin necesita para funcionar, nombra la tienda, la empareja y configura
los avisos. Ver §3.
Revisado: 2026-10-09 — aprobado por Julián. Las fuentes de la marca viajan dentro del plugin en
vez de caer al stack del sistema. Ver §2.1.
Temas: rest-api, capabilities, dependencias, modulos
Cubre: admin/_.php, src/_, build/_, assets/brand/_, package.json, includes/Rest/Health.php

### En pocas palabras

Una app en React, **igual a la de woonderful-sync**: la misma sidebar con el logo, las mismas
pantallas por `#/ruta`, los mismos colores y estilos. Habla con el servidor por un namespace
REST propio del admin, separado del que usan las tiendas entre sí.

Cuatro pantallas: **Resumen**, **Productos**, **Registros** y **Configuración**. La lógica de la
vinculación —sugerencias, masiva, reasignar, descartar— va en el ADR-014.

---

### 1. Cómo se construye

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Una app en React, como woonderful-sync** ✅ | El mismo diseño y la misma estructura: se copian los tokens, los estilos y componentes como el paginado. Y hay cosas que lo necesitan: buscar el producto de la otra tienda desde una fila, desplegar variaciones, vincular sin recargar (UC-05). El SPEC pide React (§2). Contra: un paso de build, y lo compilado viaja en el plugin |
| B — Pantallas clásicas de WordPress en PHP, con el CSS de woonderful | Sin build. Pero se aparta de woonderful-sync, y buscar en la otra tienda sin salir de la fila o vincular sin recargar se vuelven JavaScript suelto |
| C — Mixta: ajustes en PHP, productos y log en React | Dos formas de hacer lo mismo, y dos estilos que mantener |

**Cómo, igual que woonderful-sync:**

- `@wordpress/scripts` compila `src/` a `build/`. **`build/` se sube al repo**: el ZIP de
  distribución lo necesita. `src/` viaja también: WordPress.org pide que el código compilado
  tenga su fuente disponible, y llevarla en el ZIP es la forma más simple.
- Un menú propio en el admin, con el ícono de Woonderful, y una sola página que monta la app.
- Las pantallas se eligen por `#/ruta`, sin recargar.
- Las traducciones de la app, con `wp_set_script_translations()`.
- Tests de JavaScript con `wp-scripts test-unit-js`, como los de woonderful-sync.

### 2. El diseño

**Se copia el de woonderful-sync**, no se diseña de nuevo: los tokens de color, tipografía y
espaciado de `style.scss`, las clases `woon-*`, la sidebar, el logo y los íconos de
`assets/brand/`. **Sin recursos externos** —ni fuentes de Google ni CDN—, como allá: la
tipografía cae al stack del sistema. Es lo que pide WordPress.org, y lo que ya hace el otro
plugin.

#### 2.1 Las fuentes, dentro del plugin (revisado el 2026-10-09)

**Qué pasó.** Con el stack del sistema, el logo y los títulos no se ven como la marca: el logo de
Woonderful es tipográfico —Bricolage Grotesque, con las "oo" en lila y la estrella—, y con otra
fuente es otra cosa. El rediseño de Productos lo dejó en evidencia.

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Los archivos de las fuentes dentro del plugin** ✅ | Se ve como la marca, y la fuente se sirve desde el mismo sitio: ningún pedido a otro servidor. Es lo que acepta WordPress.org, y no expone la IP de quien abre el admin. Contra: unos 100 KB más en el plugin, y la licencia de cada fuente tiene que viajar con ella |
| B — Pedirlas a Google Fonts | Lo más simple. Pero cada vez que alguien abre el admin, su IP va a Google sin consentimiento: WordPress.org lo marca en la revisión, y en Europa hubo fallos por eso. Sin internet, no carga |
| C — Seguir con el stack del sistema | Nada que cambiar, pero el logo y los títulos no son los de la marca |

**Qué viaja.** Sólo el subconjunto latino —incluye los acentos y la ñ— y sólo los pesos que usa
`style.scss`, en `assets/fonts/`, en woff2:

| Fuente | Para qué | Archivo | Peso |
| --- | --- | --- | --- |
| Bricolage Grotesque 600 | Títulos y logo (`--woon-font-display`) | `bricolage-grotesque-latin-600-normal.woff2` | 22 KB |
| DM Sans, variable 400–700 | Texto (`--woon-font-body`) | `dm-sans-latin-wght-normal.woff2` | 37 KB |
| JetBrains Mono 400 y 500 | Cabeceras de tabla, SKUs (`--woon-font-mono`) | `jetbrains-mono-latin-400-normal.woff2`, `…-500-…` | 21 KB c/u |

Unos 100 KB en total. Medido el 2026-10-09 con los paquetes de Fontsource 5.3.0, que publican en npm
las mismas fuentes de Google Fonts.

**De dónde salen y cómo se cargan.** Los woff2 se copian una vez de Fontsource a `assets/fonts/` y
se versionan: **no es una dependencia de npm** —el build no los necesita, y el plugin no baja
nada—. Se declaran con `@font-face` en un CSS que `Admin\Page` agrega al encolar la app —no en
`style.scss`, porque el build las copiaría a `build/` y viajarían dos veces—, con
`font-display: swap`: mientras cargan, se
ve el texto con la fuente del sistema, que ya es el respaldo de los tokens. Los tokens no cambian:
ya nombran estas tres fuentes.

**La licencia.** Las tres son SIL Open Font License 1.1, que permite distribuirlas dentro de otro
software. El texto de cada licencia viaja junto a los archivos, en `assets/fonts/`, y el
`readme.txt` las nombra en los créditos.

**woonderful-sync.** Comparte este diseño y hoy cae al stack del sistema. Para que los dos plugins
se vean iguales, el mismo cambio se lleva allá; queda anotado en su STATUS.

**Qué cambia en este ADR.** El párrafo de arriba —"sin recursos externos, la tipografía cae al stack
del sistema"— pasa a: sin recursos externos; las fuentes de la marca viajan dentro del plugin.

**Se copia, no se comparte.** Extraer el diseño a una librería común —como `woonderful-license`—
tiene sentido cuando haya un tercer plugin; con dos, una copia es más simple. Lo que cambie en
uno se pasa a mano al otro.

### 3. Las pantallas

| Pantalla | Qué muestra | En la secundaria |
| --- | --- | --- |
| **Resumen** | Cómo está la otra tienda —y desde cuándo, si está caída—, la última reconciliación con lo que hizo, las discrepancias de **las dos** tiendas en un solo lugar, y lo que no sincroniza | Igual, con lo suyo |
| **Productos** | La vinculación: el catálogo de la maestra, cada fila con los dos lados (ADR-014) | En modo lectura |
| **Registros** | El log, con filtros por nivel, tipo y fecha, paginado de a 50, y filas que se expanden: la `Logs.js` de woonderful-sync adaptada. El historial de un producto se abre desde su fila en Productos | Igual |
| **Configuración** | El emparejamiento —generar el código, o canjearlo—, los avisos por email (ADR-012 §6), y la casilla de borrar todo al desinstalar (ADR-003) | Igual |

#### La configuración inicial

Un asistente en pasos, como el `Setup.js` de woonderful-sync: con la barra que marca en qué paso
se está, y "configurar más tarde" en cada uno. **Se abre solo mientras la tienda no está
emparejada**; los requisitos se pueden volver a revisar desde Configuración.

1. **Requisitos.** Cada uno con su resultado, si es crítico o recomendado, y cómo arreglarlo.
   Los críticos no dejan avanzar; los recomendados se avisan y dejan seguir.

   | Requisito | Tipo | Por qué |
   | --- | --- | --- |
   | PHP 8.1 o más, y WooCommerce 9.1 o más activo | Crítico | El mínimo del SPEC §5 |
   | Las tablas del plugin creadas y en InnoDB | Crítico | Sin transacciones, el stock queda mal contado (ADR-004) |
   | No es una copia de otro sitio | Crítico | Una copia no sincroniza (ADR-001 §1) |
   | HTTPS, salvo en un entorno local | Crítico | El emparejamiento lo exige |
   | La API REST responde desde afuera | Crítico | Si un plugin de seguridad la bloquea, la otra tienda no puede mandar nada. Se prueba con un request a `GET /health` de la propia tienda |
   | La pasada corrió en los últimos minutos | Recomendado | Sin visitas, WP-Cron no la dispara: hace falta un cron real |
   | PHP-FPM o LiteSpeed | Recomendado | Sin eso, la venta espera a la pasada en vez de salir al terminar el request (ADR-006 §5) |
   | El correo sale | Recomendado | Con un botón "mandar email de prueba": un hosting sin correo no avisa nada (ADR-012) |

2. **Esta tienda:** el nombre con que la ve la otra, y el rol: maestra o secundaria.
3. **Emparejar.** La maestra genera el código y espera; la secundaria lo pega. Al terminar se
   prueba la conexión con `GET /health`.
4. **Avisos:** el email y el plazo (ADR-012 §6).
5. **Listo.** La maestra va a Productos, donde la vinculación masiva propone los pares; la
   secundaria va a Resumen.

Cada paso usa las clases que ya existen; lo nuevo es el chequeo de requisitos y el email de
prueba.

**Si la otra tienda no responde, un aviso arriba de todo, en todas las pantallas**, con desde
cuándo (UC-05). Sin él, los stocks descuadrados de Productos parecerían un problema de cada
producto.

**La reconciliación manual** se lanza desde Resumen, en la maestra (UC-06). Hace lo mismo que
`wp wss reconcile`, y dice "no se pudo mirar" cuando la otra no responde.

**Resolver una discrepancia** —"el stock de acá está bien" o "el libro está bien" (ADR-010 §6)—
se hace desde Resumen, en la tienda donde está. Resolver las de la otra tienda desde la maestra
necesita un endpoint nuevo entre tiendas: no entra en v1. Resumen las muestra, y dice que se
resuelven desde la otra.

### 4. Cómo habla con el servidor

**Un namespace REST propio del admin, `wss-admin/v1`**, separado de `wss/v1`:

| | `wss/v1` | `wss-admin/v1` |
| --- | --- | --- |
| Quién lo llama | La otra tienda | La app, en el navegador del dueño |
| Cómo se autentica | Firma HMAC con el secreto (ADR-001) | La cookie de WordPress y su nonce (`wp_rest`) |
| Permiso | — | `manage_woocommerce` en **cada** endpoint |

Mezclarlos obligaría a cada endpoint a saber quién lo llama. Separados, cada uno tiene una sola
forma de autenticarse.

**Los endpoints de `wss-admin/v1`:**

| Endpoint | Para qué |
| --- | --- |
| `GET /status` | Todo lo de Resumen: rol, estado de la otra, última reconciliación, discrepancias, cuántos no sincronizan |
| `GET /log` | El log, filtrado y paginado; con `product`, el historial (ADR-011 §8) |
| `POST /reconcile` | La reconciliación manual. Sólo en la maestra |
| `POST /discrepancies/<producto>/accept` · `/restore` | Resolver una discrepancia de esta tienda |
| `GET` · `POST /settings` | Los avisos y la casilla de borrado |
| `POST /pairing/code` · `/pairing/redeem` | Generar el código, o canjearlo |
| `GET /requirements` | Los requisitos, cada uno con su resultado |
| `POST /store` | El nombre de esta tienda |
| `POST /test-email` | Mandar un email de prueba al destinatario de los avisos |
| Los de Productos | En el ADR-014 |

**Cada endpoint hace lo mismo que su comando de WP-CLI**, llamando a la misma clase: la pantalla
no tiene lógica propia. Si algo anda por WP-CLI, anda por la pantalla.

### 5. Los permisos

- **`manage_woocommerce`**, como woonderful-sync y como pide UC-05. Se verifica en cada
  endpoint, no sólo en el menú: un botón oculto no es un permiso (UC-05).
- **Un usuario sin el permiso no ve el menú.**
- **Lo que sólo hace la maestra se rechaza en el servidor**, aunque la pantalla de la secundaria
  no muestre el botón: vincular, desvincular, reasignar, descartar y la reconciliación manual
  (UC-05, UC-06). Es la misma guarda que ya usan `Linker` y `wp wss reconcile`.

### 6. La secundaria: modo lectura, con el enlace a la maestra

UC-05 pide que la secundaria diga que la vinculación se hace desde la otra tienda,
**nombrándola y con el enlace directo**. La secundaria sabe el nombre de la maestra —lo guardó al
emparejarse—, pero no la dirección de su admin.

**`GET /health` suma dos campos: `admin_url` y la etiqueta.** La secundaria se los pregunta a la
maestra al abrir la pantalla. Sumar campos dentro de `wss/v1` está permitido (ADR-001 §4): una
versión vieja del otro lado los ignora.

---

### Qué cambia en otros ADRs

| ADR | Qué cambia |
| --- | --- |
| ADR-001 §4 | `GET /health` suma `admin_url` |
| ADR-000 | Suma `@wordpress/scripts` como dependencia de desarrollo, y la carpeta `build/` versionada |

### Lo que se vuelve más difícil

- **Un paso de build.** Un cambio en `src/` sin compilar no se ve. Va a la lista de lo que se
  revisa antes de cada release.
- **El diseño vive en dos plugins.** Un cambio de marca hay que pasarlo a mano.

### Los riesgos que quedan

- **Resolver las discrepancias de la secundaria desde la maestra no entra en v1**: se resuelven
  desde la tienda donde están.

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| Las sugerencias por SKU y sus motivos, la vinculación masiva, reasignar, descartar, las variaciones | ADR-014 |

---

## ADR-014 — La vinculación en la pantalla

Estado: Aprobada · 2026-10-07 · aprobada por Julián
Temas: db-schema, rest-api, uninstall, modulos
Cubre: includes/Linking/_.php, includes/Catalog/_.php, includes/Rest/Products.php, admin/_.php, src/screens/Products.js, includes/Schema/Installer.php, uninstall.php

### En pocas palabras

Cada tienda guarda **una copia del catálogo de la otra**, que se refresca. Con esa copia, la
pantalla de Productos arma cada fila con los dos lados, sugiere el par por SKU, filtra por estado
y por motivo sobre todo el catálogo, y vincula de a uno o en masa.

La copia puede estar unos minutos atrasada, y no es un riesgo: **todo lo que escribe vuelve a
mirar los dos lados en el momento** (ADR-008 §3, UC-05).

---

### 1. La copia del catálogo de la otra tienda

| Opción | Por qué sí o por qué no |
| --- | --- |
| A — Preguntar sólo por las filas que se ven | Liviano y al día. Pero los filtros y la masiva necesitan el catálogo entero: "¿cuántos tienen sugerencia?" no se contesta mirando una página |
| **B — Una copia, que se refresca** ✅ | Filtros, conteos y masiva sobre todo el catálogo, rápidos. Atrasada unos minutos, pero lo que escribe revalida en el momento. Contra: una tabla más |
| C — Traer el catálogo entero cada vez | Sin copia. Pero con 5.000 productos son 50 pedidos cada vez que el dueño entra |

**La tabla, `wss_remote_products`:** el producto de la otra, su padre si es una variación, el
tipo, el SKU, el nombre, el stock —o `NULL` si no lleva el suyo—, el estado (publicado, papelera…),
si está vinculado allá, y cuándo se vio.

**Cuándo se refresca:**

- **Al abrir la pantalla, si tiene más de 10 minutos.** La app la pide de a una página por
  request —100 productos— y muestra el avance: con 5.000 son 50 requests cortos, no uno largo que
  se corte por tiempo.
- **Con un botón "actualizar"**, cuando el dueño cambió algo en la otra tienda y no quiere
  esperar.
- **De noche**, en las dos tiendas, para que la mañana arranque con la copia al día.

**Lo que ya no está allá se borra.** Cada refresco completo marca lo que vio; al terminar, lo que
no vio se borra. Un refresco cortado a mitad no borra nada.

**Es de las dos tiendas, no sólo de la maestra.** La secundaria también la necesita, para mostrar
lo mismo en modo lectura —por ejemplo, que un producto suyo no tiene con quién vincularse en la
maestra (UC-05)—.

**Si se re-empareja con otra tienda, la copia se vacía**: es de la tienda anterior.

### 2. `GET /products` suma el tipo y el padre

Para agrupar las variaciones bajo su padre (UC-05). Sumar campos dentro de `wss/v1` está
permitido: una versión vieja del otro lado los ignora (ADR-001 §4).

### 3. Las filas

**La unidad vinculable es un producto simple o una variación que lleva su propio stock** (UC-03,
ADR-005). El producto variable es una fila que se despliega, sin acciones propias.

**Una fila por cada vinculable de esta tienda**, con los dos lados:

```
Alpargata verde 39   SKU ALP-V39   stock 6   │   Alpargata verde 39   SKU alp-v-39   stock 6   vinculado
Alpargata negra 41   SKU ALP-N41   stock 4   │   sugerencia: Alpargata negra 41 (#88)            sin vincular
Ojota azul           —             stock 3   │                                                   sin vincular · sin SKU
```

**El estado** es el de ADR-005 §1 —vinculado, sin vincular, suspendido con su motivo, incierto—,
calculado con la copia en vez de preguntarle a la otra en el momento.

**El motivo de un sin vincular**, en este orden:

| Motivo | Cuándo |
| --- | --- |
| Sugerencia descartada | El dueño la descartó (sólo se ve si se activa "mostrar descartados") |
| Sin SKU | El de acá no tiene SKU |
| SKU duplicado | El SKU se repite entre los vinculables de acá, o entre los de allá |
| Sin coincidencia | Ningún vinculable de allá tiene ese SKU |
| Con sugerencia | Uno solo de allá tiene ese SKU, sin vincular |

**El SKU se compara sin espacios a los costados y sin distinguir mayúsculas**: `alp-v39` y
`ALP-V39 ` son el mismo. Así no se pierde una sugerencia por cómo se tipeó el SKU en cada tienda;
y si eso junta dos que el dueño consideraba distintos, aparece como SKU duplicado, no como un
par equivocado.

**El stock en el padre.** Si un producto variable lleva el stock en el padre, sus variaciones no
llevan el suyo, y no hay nada que vincular. La fila lo dice —"el stock está en el padre:
llevalo a cada variación"— en vez de mostrarlo como un sin vincular más.

### 4. Filtros y búsqueda

- **Por estado:** todos, con sugerencia, **sin vincular** —el que más se usa—, vinculados,
  suspendidos.
- **Por motivo:** sin SKU, SKU duplicado, sin coincidencia, en papelera.
- **"Mostrar descartados"**, un control en la barra de filtros, **apagado al abrir la pantalla**.
  Encendido, aparecen las filas descartadas y el motivo "sugerencia descartada". Es lo que el SPEC
  dejó pendiente: va en la barra de filtros porque es un filtro, y no se guarda porque lo normal
  es no verlas (UC-05).
- **La búsqueda** mira el nombre y el SKU **de los dos lados**, y encuentra también lo
  descartado (UC-05).

**Se calcula del lado del servidor, sobre todo el catálogo**, y la app recibe una página de 50.

### 5. Vincular a mano

1. Desde la fila, el dueño **busca el producto de la otra** por nombre o SKU, en la copia: sólo
   vinculables sin vincular. El SKU no hace falta (UC-05).
2. Antes de confirmar, la pantalla **pide ese producto a la otra en el momento**
   (`GET /products?include=`): uno solo, para mostrar su stock real. Y muestra los dos stocks y
   **cuál va a quedar**: el de la maestra (UC-05, UC-07).
3. Al confirmar, `Linker::link()` con los dos stocks que vio. Si alguno cambió, lo rechaza
   (ADR-008 §3) y la pantalla vuelve al paso 2.

La fila se actualiza con lo que devuelve el servidor, no con lo que se esperaba (UC-05).

### 6. La vinculación masiva

1. La pantalla dice **cuántos pares sin ambigüedad** va a vincular —los "con sugerencia", sin
   descartar— y avisa que **todos arrancan con el stock de la maestra**. Los ambiguos —SKU
   duplicado— quedan aparte, para resolver de a uno (UC-05).
2. Al confirmar, la app los manda **en tandas de 20**. Por cada tanda, el servidor **vuelve a
   validar cada par en el momento**: que los dos sigan publicados y sin vincular, que el SKU siga
   sin ambigüedad, que no se haya descartado. Para eso pide los 20 de allá con
   `GET /products?include=`, frescos (UC-05).
3. Cada par se vincula por separado, con `Linker::link()` y los stocks recién leídos. Uno que
   falla no corta la tanda.
4. Al terminar, la pantalla muestra **los vinculados y los omitidos con su motivo**.

Reintentar no duplica: un par ya vinculado vuelve como omitido, `already_linked` (ADR-001).

**Lo que dice UC-08 sobre los emails:** una masiva que falla con la otra tienda caída no manda
ningún email, porque el dueño la está mirando. Ya es así: `link_failed` de algo que el dueño hizo
no se avisa (ADR-012 §2).

### 7. Reasignar, desvincular, descartar

- **Desvincular** pide confirmación y avisa que el producto deja de sincronizar; el stock no se
  toca (UC-05). Es `Linker::unlink()`.
- **Reasignar** es desvincular y vincular, **en ese orden** (UC-05). Si el segundo paso falla,
  el producto queda sin vincular: un estado válido y reintentable.
- **Descartar** la sugerencia y **deshacer el descarte** escriben `discarded` y `undiscarded` en
  el log, con el usuario (ADR-011 §3). Descartar no impide vincular a mano (UC-05).

Todo esto es **sólo de la maestra**, verificado en el servidor (ADR-013 §5).

### 8. Los endpoints

En `wss-admin/v1` (ADR-013 §4):

| Endpoint | Para qué |
| --- | --- |
| `GET /products` | Las filas: filtros, búsqueda, página de 50 |
| `POST /catalog/refresh` | Una página del refresco de la copia, con su avance |
| `GET /catalog/search` | Buscar el producto de la otra para vincular a mano |
| `GET /catalog/fresh/<id>` | El de la otra en el momento, antes de confirmar |
| `POST /link` · `/unlink` · `/reassign` | Vincular, desvincular y reasignar uno |
| `POST /link/bulk` | Una tanda de la masiva |
| `POST /discard` · `/undiscard` | Descartar y deshacer |

---

### Qué cambia en otros ADRs

| ADR | Qué cambia |
| --- | --- |
| ADR-004 | Una quinta tabla, `wss_remote_products`. Sube la versión del esquema |
| ADR-001 §4 | `GET /products` suma `type` y `parent` |
| ADR-003 | `wss_remote_products` es una copia de otra tienda, no un dato del dueño: se borra siempre |
| ADR-005 §1 | En la pantalla, el estado se calcula con la copia; `wp wss status` sigue preguntando en el momento |

### Lo que se vuelve más difícil

- **El cálculo de las filas es lo más pesado de la pantalla**: cruza los vinculables de acá, los
  vínculos, la copia y los descartes, para todo el catálogo, en cada página. Con miles de
  productos hay que medirlo; si pesa, se guarda el resultado unos minutos.
- **Una copia que se refresca** suma un estado más que puede estar atrasado. Lo cubre que todo lo
  que escribe revalide.

### Los riesgos que quedan

- **La copia puede tener hasta 10 minutos**: una sugerencia que ya no vale se ve hasta el próximo
  refresco. Al vincularla, la revalidación la rechaza con su motivo.

### Lo que no cubre

| Qué | Dónde va |
| --- | --- |
| Cómo se ven las pantallas | El código: se copia de woonderful-sync (ADR-013 §2) |

---

## ADR-015 — Pausar la sincronización y desemparejar

Estado: Aprobada · 2026-10-10 · aprobada por Julián
Temas: opciones, rest-api, uninstall
Cubre: includes/Transport/Pause.php, includes/Transport/Peers.php, includes/Transport/Sender.php, includes/Rest/Movements.php, includes/Rest/Pair.php, includes/Log/Peer.php, includes/Reconciliation/Schedule.php, includes/Linking/Linker.php, includes/Admin/Api.php, includes/Cli/Command.php, src/screens/Settings.js, uninstall.php

### En pocas palabras

Dos formas de cortar, pedidas por Julián (2026-10-10):

- **Pausar** es para el día a día —un inventario, una migración, algo raro—: las dos tiendas dejan
  de mandarse stock, pero **cada venta se sigue anotando en el libro** y, al reanudar, la cola manda
  lo pendiente. No se pierde nada.
- **Desemparejar** corta del todo: las dos olvidan la URL y el secreto de la otra. Para volver hace
  falta un código nuevo.

Las dos se hacen desde cualquiera de las dos tiendas, y **se le avisan a la otra** con un pedido
firmado: la pausa y el corte son del par, no de un lado.

---

### 1. Qué para la pausa

| Opción | Por qué sí o por qué no |
| --- | --- |
| A — Reusar la suspensión (`Plugin::suspension_reason()`) | Ya existe y apaga todo. Pero apaga también el detector: **las ventas de la pausa no se anotan** y al reanudar aparecen como discrepancias, una por producto vendido |
| **B — Una pausa propia: el libro anota, nadie manda** ✅ | Las ventas quedan en la cola como cuando la otra está caída, y al reanudar salen solas. Contra: hay que cortar en cada lugar que manda o recibe |
| C — Pausar sólo el envío de esta tienda | Simple. Pero la otra sigue mandando y ésta aplicando: media sincronización, difícil de explicar |

**Durante la pausa, en las dos tiendas:**

- **El detector sigue anotando** en el libro, como siempre.
- **La pasada no reclama nada** (`Sender::run()` vuelve antes, como sin socia). Así no corre el
  techo de 6 horas: se cuenta desde el primer intento (`first_attempt_at`), y no hay intento hasta
  reanudar.
- **`POST /movements` contesta `409 wss_paused`** y no aplica nada. Es la red de seguridad si el
  aviso a la otra no llegó.
- **No se reconcilia**, ni de noche ni a mano, y no se contesta la de la otra (`409 wss_paused`).
- **No se vincula, desvincula ni reasigna**: vincular le pregunta el stock a la otra. Descartar sí,
  porque es local.
- **No se avisa que la otra no responde**: en pausa no se le habla.

**Al reanudar:** la próxima pasada manda lo anotado durante la pausa, en orden. Si fue larga, el
dueño puede reconciliar a mano para confirmar.

### 2. Cómo se entera la otra

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Un pedido firmado `POST /pause` a la otra** ✅ | Las dos quedan en el mismo estado al instante, y cada una lo muestra. Si la otra no responde, se pausa igual acá y el `409 wss_paused` le avisa en su próximo envío |
| B — Que cada tienda se pause por su lado | Sin endpoint nuevo. Pero el dueño tendría que acordarse de pausar las dos, y si se olvida, la que no pausó acumula rechazos |

**El endpoint:** `POST wss/v1/pause` con `{"paused": true|false}`, firmado como los demás
(ADR-001). Guarda el estado igual que si se hubiera pausado acá, con `by: "peer"`.

**El emisor entiende `409 wss_paused`:** `Peer::classify()` lo toma como un estado nuevo,
`paused`, no como un rechazo. No es un error ni manda email, como el `409 wss_reconciling`
(ADR-011 §4). Y la tienda que lo recibe se marca en pausa también: el par queda parejo aunque el
aviso no haya llegado.

**La opción, `wss_paused`:** `{at, by, user}` —cuándo, si fue `here` o `peer`, y quién—, o no
existe. Se borra al desinstalar.

### 3. Desemparejar

| Opción | Por qué sí o por qué no |
| --- | --- |
| A — Borrar todo lo del par: vínculos, libro, copia del catálogo | Arranque limpio. Pero si se vuelve a emparejar con la misma tienda, hay que vincular todo de nuevo |
| **B — Olvidar la URL y el secreto; los vínculos y el libro quedan** ✅ | Re-emparejar con la misma tienda deja todo como estaba. Contra: lo anotado mientras estuvieron separadas sale al re-emparejar |
| C — Sólo acá, sin avisar a la otra | Sin endpoint nuevo. Pero la otra sigue mandando, recibe `401 wss_unknown_store` y lo avisa como rechazo |

**Qué se borra:** la `rest_url` y el secreto de la entrada (`Peers::forget()`), como cuando se
empareja con otra tienda (ADR-001 §1); también la pausa. Quedan la etiqueta, `purged_through`, los
vínculos, el libro y la copia del catálogo.

**Cómo se entera la otra:** `POST wss/v1/unpair`, firmado. La otra olvida lo mismo. Si no
responde, se desempareja igual acá; la otra va a ver `401 wss_unknown_store` y mostrar "rechaza
los pedidos", que es la verdad: hay que re-emparejar o desemparejar también allá.

**Lo anotado mientras están separadas:** queda en la cola, sin destino. Si se re-empareja con la
**misma** tienda, sale en la próxima pasada, como al volver de una caída. **Es lo que pide un
depósito compartido**: una venta de una tienda tiene que descontarse en la otra. Con **otra**
tienda, los vínculos viejos apuntan a la anterior y no se usan.

**La confirmación lo dice:** "Las dos tiendas dejan de sincronizar. Los vínculos se guardan por si
vuelven a emparejar. Lo que se venda mientras tanto se manda al re-emparejar con esta misma tienda."

### 4. Desde dónde

- **La pantalla:** en Configuración → Emparejamiento, un interruptor "Sincronización activa" y un
  botón "Desemparejar" con confirmación. `POST wss-admin/v1/pause` y `POST wss-admin/v1/unpair`.
- **WP-CLI:** `wp wss pause`, `wp wss resume`, `wp wss unpair`.
- **Las dos tiendas pueden:** pausar y cortar son operativos, no deciden stock. La secundaria
  sigue sin poder vincular.
- **Resumen** muestra la pausa en su cabecera ("Sincronización en pausa desde…, por…") y la
  barra lateral, un punto amarillo.

---

**Decisión:** pausa propia del par, avisada a la otra con `POST /pause`, que deja anotar y corta
envío, aplicación, reconciliación y vinculación (§1, §2). Desemparejar olvida la URL y el secreto
en las dos tiendas y guarda vínculos y libro (§3).

**Por qué:** la pausa no puede perder ventas —es un depósito compartido—, y la cola del libro ya
resuelve "mandar después" para las caídas. Desemparejar sin borrar vínculos hace que volver a la
misma tienda no cueste nada.

**Por qué no reusar la suspensión:** apaga el detector antes de registrarlo (`Plugin::register()`
vuelve antes de `Detector::register()`), así que las ventas de la pausa no se anotarían.

**Por qué no pausar sólo un lado:** la otra seguiría mandando y ésta, aplicando. Habría que explicar
qué mitad está andando.

**Por qué no borrar todo al desemparejar:** el caso más común es volver con la misma tienda —un
cambio de dominio, una migración—, y obligaría a vincular cientos de productos de nuevo.

**Consecuencias:**

- Cada lugar que manda o recibe mira la pausa: `Sender::run()`, `POST /movements`, la
  reconciliación —de noche, a mano y la de la otra— y `Linker`. Uno que se olvide manda en pausa.
  Va con un test por cada uno.
- `Peer` gana un estado, `paused`, que la pantalla y los avisos tienen que entender.
- Una pausa de semanas deja una cola larga: sale en lotes de `Ledger::MAX_BATCH`, como después de
  una caída larga.
- **Si el supuesto del depósito compartido no vale** —si mientras están separadas cada tienda lleva
  su propio stock—, mandar lo anotado al re-emparejar descuadraría. Habría que ofrecer descartar la
  cola al re-emparejar, o vincular de nuevo, que arranca con el stock de la maestra (ADR-008 §1).

---

## ADR-016 — El nombre de la tienda llega a la otra al cambiarlo

Estado: Aprobada · 2026-10-10 · aprobada por Julián
Temas: opciones, rest-api, uninstall
Cubre: includes/Transport/Peers.php, includes/Rest/Pair.php, includes/Transport/Pass.php, includes/Admin/Api.php, uninstall.php

### En pocas palabras

Hoy el nombre de cada tienda viaja una sola vez, al emparejarse (ADR-001 §1): si después se cambia,
la otra sigue mostrando el viejo en Resumen, la barra lateral y los avisos hasta re-emparejar.
Con esta decisión, **guardar el nombre se lo avisa a la otra** con un pedido firmado, como la
pausa (ADR-015 §2). Si la otra no responde, queda pendiente y sale en la próxima pasada.

---

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Avisarle al guardar: `POST /label`** ✅ | El nombre nuevo aparece al instante en la otra. Contra: un endpoint más entre las tiendas |
| B — Guardarlo cada vez que se pregunta `GET /health` | Sin endpoint nuevo: `health` ya trae `label`. Pero sólo se pregunta al probar la conexión o con la otra caída: el nombre nuevo puede tardar días en aparecer |
| C — Mandarlo en cada lote de movimientos | Llega con la próxima venta. Pero toca el cuerpo de `POST /movements`, el contrato más sensible, para algo que no es stock |

**El endpoint:** `POST wss/v1/label` con `{"label": "…"}`, firmado con el secreto (ADR-001).
Guarda la etiqueta en la entrada de `wss_peers` de quien lo manda, sin tocar la URL ni el secreto.
Una etiqueta vacía o de más de 100 caracteres es un `400`.

**Si la otra no responde:** queda anotado en `wss_label_pending` y **la pasada lo reintenta**
mientras la otra conteste y no haya pausa. Se borra al confirmarse, al desemparejar y siempre al
desinstalar.

**Quién lo dispara:** guardar el nombre desde Configuración (`POST wss-admin/v1/store`). Al
emparejarse no hace falta: ya viaja en el canje.

---

**Decisión:** opción A, con reintento en la pasada.

**Por qué:** el nombre es lo que el dueño ve para reconocer la otra tienda en todas las pantallas
y en los emails; que tarde días en cambiar parece un error.

**Por qué no B:** `health` se consulta con la otra caída o a mano, y con la otra andando bien no se
consulta nunca: el nombre nuevo no llegaría.

**Por qué no C:** el cuerpo de `POST /movements` es el contrato que más cuesta cambiar (romperlo es
`wss/v2`), y el nombre no tiene nada que ver con el stock.

**Consecuencias:**

- Un endpoint firmado más en `wss/v1`, y una opción más, `wss_label_pending`.
- Una tienda con la versión anterior del plugin contesta `404` a `POST /label`: el nombre queda
  pendiente y se reintenta en cada pasada hasta que se actualice. **Si eso molesta**, habría que
  dejar de reintentar ante un `404`.

---

## ADR-017 — Lo que salía tarde por un fallo sale con lo nuevo, en orden

Estado: Aprobada · 2026-10-11 · aprobada por Julián
Temas: modulos
Cubre: includes/Ledger/Ledger.php

### En pocas palabras

Hoy, si una venta falla al salir —la otra no respondía—, queda esperando su reintento (1 minuto,
2, 4…, ADR-001 §2). Las ventas que vienen después no tienen esa espera: cuando la otra vuelve, la
pasada manda primero las nuevas y la vieja recién cuando se cumple su minuto. El stock termina bien
—cada venta resta, y restar da lo mismo en cualquier orden—, pero **la otra queda un rato con
una venta de menos y su historial, desordenado**. Lo encontró la prueba manual 4.5 (2026-10-10).

Con esta decisión, **cuando la pasada manda algo, manda también lo pendiente más viejo aunque su
espera no haya vencido, y todo en el orden en que se anotó**.

---

| Opción | Por qué sí o por qué no |
| --- | --- |
| **A — Adelantar lo viejo: si sale algo, sale también lo anterior** ✅ | En orden y antes que hoy: la otra se pone al día de una vez. Un cambio en la consulta que reclama. Contra: una fila que la otra rechaza siempre se reintenta cada vez que sale algo nuevo, no con su espera |
| B — Que lo nuevo espere a lo viejo | En orden. Pero la otra recibe todo más tarde: con tres ventas, le faltan tres durante ese minuto en vez de una. Empeora lo que más importa en un depósito compartido |
| C — Sin espera por fila cuando falló el pedido entero | Todo queda listo a la vez y sale en orden. Pero con la otra caída se reintenta en cada pasada, un pedido por minuto que puede esperar 30 segundos (`Sender::TIMEOUT`): carga y pasadas lentas mientras dura la caída. Y distinguir "falló el pedido" de "rechazó esta fila" pide una columna nueva |

**Qué cambia en `Ledger::claim()`:**

- **Cuáles:** las listas para salir (`next_attempt_at` vencido, o las del envío inmediato) y,
  **si hay alguna, también las pendientes propias anteriores a la más nueva de ellas**, aunque
  estén esperando: con "anteriores a la más vieja", una que espera entre dos listas quedaría
  atrás. Si no hay nada listo, nada se adelanta: con la otra caída y sin ventas nuevas,
  la espera sigue como hoy.
- **En qué orden:** por `id`, que es el orden en que se anotaron. Hoy es por `next_attempt_at`,
  que pone al final justo lo que esperaba.
- **Lo que no cambia:** lo que cuelga de un bootstrap sin resolver sigue sin salir (ADR-008 §4);
  el techo de 6 horas se sigue contando desde el primer intento (`first_attempt_at`); el lote
  sigue siendo de hasta `MAX_BATCH`; y cada intento adelantado cuenta como intento.

---

**Decisión:** opción A.

**Por qué:** si algo nuevo puede salir es porque la otra está respondiendo, o al menos se va a
intentar ahora. Que lo viejo siga esperando no protege nada y desordena. Mandarlo junto arregla el
orden y la demora a la vez.

**Por qué no B:** cambia un historial desprolijo por más tiempo con el stock desactualizado en la
otra tienda, que es peor.

**Por qué no C:** la espera por fila existe para no golpear a una tienda caída en cada pasada.
Sacarla para los fallos del pedido entero devuelve ese costo, y además pide distinguir los dos
fallos con una columna nueva (esquema).

**Consecuencias:**

- **Una fila que la otra rechaza siempre** —un motivo propio de esa fila, no una caída— se reintenta
  cada vez que sale algo nuevo, en vez de cada 1, 2, 4… minutos. Son pedidos que ya iban a salir:
  no suma pedidos, sólo filas al lote. Y sigue abandonándose a las 6 horas, como hoy.
- El receptor ya ignora lo repetido (ADR-001 §3): adelantar una fila que en realidad había llegado
  no descuenta dos veces.
- **Si el supuesto falla** —si resultara que una fila rechazada traba el lote entero del
  receptor—, habría que excluir del adelanto las filas con un rechazo propio, y para eso sí haría
  falta guardar qué clase de fallo fue.
