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

Estado: Aprobada · 2026-09-20 · aprobada por Julián
Revisado: 2026-09-20 — los nombres del contrato (campos del JSON y valores de `reason`)
pasaron a inglés, para no mezclar idiomas con los estados de §3. Sin cambios de diseño.
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
             método + "\n" + ruta + "\n" + timestamp + "\n" + nonce + "\n" + sha256(cuerpo) )
```

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

El almacén de nonces es una tabla propia. **La purga la hace la misma pasada recurrente que
vacía pendientes** (§2), que corre en las dos tiendas: borra todo nonce con más de 5 minutos,
porque fuera de la ventana ya no sirve para nada.

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

#### Detección de clon: la tienda que cambió de sitio se apaga sola

**Un clon es una tienda levantada a partir de una copia de la base de otra**: un entorno de
prueba armado con el backup de producción, un staging, una migración a medio hacer. Nadie lo
crea con mala intención —es la forma normal de probar algo sin tocar la tienda viva— y por eso
mismo es el caso que hay que atajar: la copia se lleva adentro todo lo que hace falta para
hablar como si fuera la original.

Cada tienda guarda **el hash de su propia URL** al emparejar, y lo compara en cada carga:

```
Al emparejar:   sha256( home_url() ) = 3f7a9c2e...   → guardado

En cada carga:  sha256( home_url() ) = 3f7a9c2e...   → todo normal
                sha256( home_url() ) = b41d08e5...   → SUSPENDE: no emite ni acepta nada,
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
| Clon servido **en la misma URL** (por `hosts`)    | **Pasa el control.** No se cubre en v1     |

El segundo es un escenario deliberado, no un accidente de operación, que es lo que este
control ataja.

#### Por qué no las alternativas

| Alternativa                      | Qué falla                                                                                                                         |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Application Passwords** de WP  | Autentican un **usuario**, no una tienda. Que le borren el usuario, le cambien el rol o le revoquen la contraseña deja la malla caída sin señal de por qué. Además hay hostings que filtran la cabecera `Authorization` y sitios que desactivan la función entera |
| **Claves REST de WooCommerce**   | Pertenecen al controlador `wc/v3`. En endpoints propios hay que validarlas a mano contra la tabla interna de WooCommerce, que no es contrato público: es tanto código como el HMAC, apoyado en algo más frágil, y sigue atado a un usuario |

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
dependencias: §5 exige WooCommerce 8.2+, que ya lo trae.

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
configuración, como el tamaño del lote.

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
backoff, y un cambio de configuración lo rompe sin que nadie lo note. La curva y las 6 horas
son configuración; **que el techo se exprese en tiempo, no**.

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

**La única causa de `discarded` hoy es la generación no vigente.** Sumar otra es una decisión
de diseño y va a un ADR: la tentación de usarlo como cajón de lo que no se supo aplicar es
justo lo que rompe la consulta de `held`.

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

#### Qué campos lleva cada movimiento

Este ADR fija **qué información tiene que viajar**. Cómo se escribe cada campo lo fija el ADR
que manda sobre esa pieza:

```json
{ "movements": [
    {
      "id":         "A-77",
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
| `id`         | Identificador global: tienda de origen + secuencia            | ADR de identidad      |
| `generation` | La generación del vínculo vigente cuando se emitió            | ADR de vinculación    |
| `delta`      | Entero con signo. §5 no admite decimales                      | Este ADR              |
| `product`    | ID local del producto **en la tienda de origen**              | ADR de identidad      |
| `reason`     | `sale`, `refund`, `adjustment`, `bootstrap`                   | ADR del detector      |
| `reference`  | Con qué se audita después: el número de pedido, etc.          | ADR del detector      |

**Los nombres del contrato van en inglés**, igual que los estados de §3 y por el mismo motivo:
es lo que va a leer cualquiera que audite el tráfico entre las dos tiendas, y un JSON con
tildes adentro (`"devolución"`) es una fuente de problemas de codificación que no hace falta
tener.

**El que no puede faltar es `generation`:**

```
Sin generation: {"id":"A-77","delta":-1,"product":123}
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
    {"id": "A-77", "status": "received"},
    {"id": "A-78", "status": "received"},
    {"id": "A-79", "status": "error", "reason": "..."}
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

#### El receptor declara un máximo de 200 movimientos por lote

```
Lote de 200   → se procesa
Lote de 5000  → se rechaza con un error explícito
```

Sin tope, la ráfaga de importación de UC-04 —una carga de CSV que cambia el stock de miles de
productos de golpe— puede hacerle timeout al receptor; el emisor lee
ese timeout como caída y reintenta el mismo lote imposible hasta agotar las 6 horas. El tamaño
que usa el emisor es configuración; **el techo del receptor es contrato**.

Y por eso el tamaño configurable del emisor **no puede pasar de 200**: sin ese límite, poner
500 en los ajustes haría que el receptor rechazara todos los lotes y no saliera nunca ningún
movimiento.

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
| **Opciones**       | El tamaño de lote (tope 200), la curva de backoff, las 6 horas del techo y los 5 minutos del claim. Por cada tienda remota: URL, identificador, secreto y fecha del emparejamiento. Del lado propio, **el hash de la URL del sitio al emparejar**, que es lo que detecta el clon: en claro lo reescribiría el `search-replace` de cualquier restore. El secreto es material sensible: `uninstall.php` tiene que borrarlo |


---

## ADR-002 — Identidad de tienda y numeración de movimientos

Estado: Aprobada · 2026-09-20 · aprobada por Julián
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
   wss_store_id  = bin2hex( random_bytes(16) )   → "8f4e2a91c7b3d05e6a1f9c48b2703d17"

Y una etiqueta, que el dueño puede cambiar cuando quiera:
   wss_store_label = "Minorista"
```

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
POST /pair llega declarando  origen = 8f4e2a91...
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
{ "origin": "8f4e2a91c7b3d05e6a1f9c48b2703d17", "seq": 77 }
```

```sql
origin_store CHAR(32),  origin_seq BIGINT UNSIGNED,
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
| **Esquema**     | `origin_store CHAR(32)` + `origin_seq BIGINT UNSIGNED` con su `UNIQUE`; la secuencia propia **es** el `AUTO_INCREMENT` de la tabla, así que el libro no lleva contador aparte |
| **Opciones**    | `wss_store_id` (inmutable) y `wss_store_label` (editable). Qué pasa con las dos al desinstalar lo decide ADR-003, junto con el resto de los datos |
| **Scaffold**    | El identificador se genera en la activación, antes de que exista nada más. Es lo primero que el plugin escribe |


---

## ADR-003 — Desinstalación y borrado de datos

Estado: Aprobada · 2026-09-20 · aprobada por Julián
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

Estado: Propuesta
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
  origin_store     CHAR(32)        NOT NULL,                 -- quién lo emitió (ADR-002)
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
  claimed_by       CHAR(32)        NULL,
  claimed_at       DATETIME        NULL,
  confirmed_at     DATETIME        NULL,

  -- eje de aplicación: sólo en las filas recibidas
  application      VARCHAR(12)     NULL,                     -- applied, held, discarded
  applied_at       DATETIME        NULL,

  PRIMARY KEY (id),
  UNIQUE KEY dedup (origin_store, origin_seq),
  KEY delivery_queue (delivery, next_attempt_at),
  KEY by_link (link_id, generation)
) ENGINE=InnoDB {charset_collate};
```

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
  remote_store   CHAR(32)        NOT NULL,
  remote_product BIGINT UNSIGNED NOT NULL,
  generation     INT UNSIGNED    NOT NULL,   -- cada bootstrap abre una nueva
  base_stock     INT             NOT NULL,   -- el valor del bootstrap vigente
  base_set_at    DATETIME        NOT NULL,
  status         VARCHAR(12)     NOT NULL,   -- linked, suspended
  PRIMARY KEY (id),
  UNIQUE KEY local (local_product, remote_store),
  KEY remote (remote_store, remote_product)
) ENGINE=InnoDB {charset_collate};
```

Son las columnas que el libro necesita para funcionar. **Qué significa cada estado, cómo se
vincula y qué abre una generación lo decide el ADR de vinculación**, que puede sumar columnas
pero no quitar estas.

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

---

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
