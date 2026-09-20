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

**La decisión, en cuatro líneas:**

1. Las dos tiendas se hablan por una **API REST propia**, namespace `wss/v1`.
2. Cada request va firmado con **HMAC-SHA256 y un secreto compartido**. Lo que se autentica es
   **la tienda**, no una persona.
3. **Lo que falta entregar vive en el libro de movimientos.** No hay tabla de cola aparte.
   Action Scheduler sólo agenda la tarea que lo vacía.
4. Cada movimiento lleva **dos estados independientes**: uno de entrega y uno de aplicación.

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

{"movimientos":[ ... ]}
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

Cada tienda guarda **su propia URL** al emparejar, y la compara en cada carga:

```
Al emparejar:   home_url() = https://tienda-a.com   → guardado

En cada carga:  home_url() = https://tienda-a.com   → todo normal
                home_url() = http://localhost:8130  → SUSPENDE: no emite ni acepta nada,
                                                      y la pantalla pide re-emparejar
```

**Por qué.** Restaurar una copia de la base en otro sitio se lleva adentro el identificador de
tienda y el secreto. Ese clon firma requests perfectamente válidos contra la tienda viva del
cliente, y numera movimientos con la misma secuencia que el original:

```
Original: movimiento A-78 = venta de 1 unidad
Clon:     movimiento A-78 = lo que sea que hiciste probando
          → el receptor ve dos veces "A-78", deduplica, y se queda con el equivocado
```

La firma prueba que quien la hizo **tiene el secreto**, no **dónde corre**. La URL es lo único
que el clon no puede llevarse.

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
UPDATE libro SET entrega = 'pending'
 WHERE entrega = 'in_flight' AND tomado_en < NOW() - INTERVAL 5 MINUTE;

-- 2. Reclama un lote para este worker  ← esto es el "claim"
UPDATE libro SET entrega = 'in_flight', tomado_por = <worker>, tomado_en = NOW()
 WHERE entrega = 'pending' AND proximo_intento <= NOW()
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
y nadie se entera — es la misma lógica del candado de UC-06. Va como primer paso de la pasada
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
       (no se pierde: sigue en el libro y lo entrega la reconciliación nocturna, UC-06)
```

Los intentos siguen siendo finitos, como exige UC-01, porque el backoff crece.

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

#### Por qué no las alternativas

| Alternativa                            | Qué falla                                                                                                                                  |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| **Una acción de AS por movimiento**    | El estado de entrega quedaría en la tabla de AS y el del movimiento en el libro: dos verdades, y la reconciliación lee el libro. Además una ráfaga de importación (UC-04) crearía miles de acciones para algo que el lote resuelve en una |
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

Las dos filas dicen la verdad sobre lo que le consta a su tienda, que es todo lo que cada una
puede saber.

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
| Qué suma el total esperado en la fase 2 de UC-06 | los **propios** de la generación vigente + los **recibidos** con `applied` |
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
| `POST /reconcile/digest` | Quien lanza la pasada  | Fase 1 de UC-06: qué identificadores conoce cada una dentro de los límites acordados |
| `GET /health`            | Cualquiera             | Canal, firma y versión del plugin del otro lado. Es lo que consulta la pantalla de ajustes |

#### Qué campos lleva cada movimiento

Este ADR fija **qué información tiene que viajar**. Cómo se escribe cada campo lo fija el ADR
que manda sobre esa pieza:

```json
{ "movimientos": [
    {
      "id":          "A-77",
      "generacion":  4,
      "delta":       -1,
      "producto":    123,
      "motivo":      "venta",
      "referencia":  "pedido #4512"
    }
] }
```

| Campo        | Qué es                                                        | Quién fija su formato |
| ------------ | ------------------------------------------------------------- | --------------------- |
| `id`         | Identificador global: tienda de origen + secuencia            | ADR de identidad      |
| `generacion` | La generación del vínculo vigente cuando se emitió            | ADR de vinculación    |
| `delta`      | Entero con signo. §5 no admite decimales                      | Este ADR              |
| `producto`   | ID local del producto **en la tienda de origen**              | ADR de identidad      |
| `motivo`     | `venta`, `devolución`, `ajuste`, `bootstrap`                  | ADR del detector      |
| `referencia` | Con qué se audita después: el número de pedido, etc.          | ADR del detector      |

**El que no puede faltar es `generacion`:**

```
Sin generacion: {"id":"A-77","delta":-1,"producto":123}
   → el receptor no tiene con qué saber si es de la época que corre
   → lo aplica, y el stock queda mal contra un libro que no lo cuenta
   → el 'discarded' de §3 queda sin uso, porque nunca hay con qué decidir descartar
```

El **bootstrap** viaja como un movimiento más, con campos adicionales que §3 exige para poder
auditarlo: los dos IDs apareados y el stock previo de cada tienda. Los define el ADR de
vinculación.

#### El ACK es por movimiento, no por lote

```json
{ "resultados": [
    {"id": "A-77", "estado": "recibido"},
    {"id": "A-78", "estado": "recibido"},
    {"id": "A-79", "estado": "error", "motivo": "..."}
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

Sin tope, la ráfaga de importación de UC-04 puede hacerle timeout al receptor; el emisor lee
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
La fase 1 de UC-06 encuentra que a B le falta A-77 (está 'abandoned' en A)
   → NO lo entrega por su cuenta
   → devuelve la fila a 'pending' con la hora del primer intento en cero
   → la pasada normal lo manda
```

Un solo camino de salida en vez de dos. Y sin reiniciar el reloj, un movimiento reparado
nacería abandonado de nuevo: su primer intento fue hace días.

#### La fase 2 de UC-06 no necesita endpoint

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
| **Esquema**        | Materializar los dos ejes de estado; guardar intentos, **hora del primer intento** (contra la que se mide el techo de 6 h) y próximo reintento; crear el **almacén de nonces** con su purga |
| **Identidad**      | El identificador que firma tiene que ser el mismo que numera los movimientos, y queda fijado en el emparejamiento |
| **Retención**      | Una fila propia no se purga hasta que se confirme. Queda abierto el libro que crece sin techo porque la otra tienda no vuelve nunca: es una condición que hay que avisar (UC-08), no purgar en silencio |
| **Opciones**       | El tamaño de lote (tope 200), la curva de backoff, las 6 horas del techo y los 5 minutos del claim. Por cada tienda remota: URL, identificador, secreto y fecha del emparejamiento. Del lado propio, **la URL del sitio al emparejar**, que es lo que detecta el clon. El secreto es material sensible: `uninstall.php` tiene que borrarlo |
