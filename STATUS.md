# Woonderful Stock Sync for WooCommerce — Estado del proyecto

> Creado: 2026-09-20
> Plugin: `woonderful-stock-sync-for-woocommerce` | WP 6.5+ | PHP 8.1+ | WooCommerce 8.2+
> Repo propio: [woonderful-stock-sync-for-woocommerce](https://github.com/julianjuan77/woonderful-stock-sync-for-woocommerce)
> Última sesión: 2026-09-20 — revisión y aprobación de ADR-001 (transporte entre tiendas)

Sincroniza el stock entre dos tiendas WooCommerce propias que despachan del mismo
depósito. Pool único, sin reparto: lo que se vende en una descuenta en las dos.

---

## Estado general

| Área                        | Estado |
|-----------------------------|--------|
| SPEC funcional              | ✅ 100% (8 casos de uso, §1-§6) |
| ADRs                        | 🟡 71% (5 de ~7 escritos; faltan detector y vinculación) |
| ARCHITECTURE.md             | 🟡 70% (contrato, módulos, seguridad y convenciones de código) |
| Scaffold del plugin         | ✅ 100% — activa y crea las tablas, verificado en sandbox |
| Tests                       | 🟡 30 en verde (esquema, identidad, guarda, desinstalación); falta transporte y libro |
| Estándares (PHPCS / ESLint) | ✅ PHPCS y PHPStan en verde |
| i18n / traducciones         | ⬜ 0% |
| readme.txt                  | ⬜ 0% |

---

## ✅ Implementado

_(nada de código: el plugin está en fase de diseño)_

- **`docs/SPEC.md`** — especificación funcional completa. Problema, alcance, fuente de
  verdad (§3), ocho casos de uso, supuestos y preguntas abiertas.
- **`docs/DECISIONS.md` — ADR-001 Transporte entre tiendas · Aprobada 2026-09-20.**
  API REST propia `wss/v1` firmada con HMAC-SHA256 y secreto por par; el libro de
  movimientos **es** la cola y Action Scheduler sólo dispara; dos ejes de estado
  independientes (entrega y aplicación); qué campos viajan en cada movimiento.
- **ADR-002 Identidad de tienda y numeración · Aprobada 2026-09-20.** Identificador opaco de
  128 bits generado al instalar + etiqueta editable; la secuencia es el `AUTO_INCREMENT` del
  libro, con huecos, y nada puede deducir nada de un hueco; tienda y secuencia son dos campos
  separados, nunca el string `A-77`.
- **ADR-003 Desinstalación y borrado de datos · Aprobada 2026-09-20.** Casilla "borrar todos
  los datos al desinstalar" desmarcada por defecto; el secreto compartido se borra siempre.
- **ADR-004 Esquema de la base de datos · Aprobada 2026-09-20.** Tres tablas InnoDB, tres
  índices en el libro, y la retención con sus tres reglas duras.
- **Scaffold + esquema, en código.** Archivo principal con HPOS declarado, `composer.json`
  con PSR-4, `phpcs.xml`, `phpstan.neon.dist`, `uninstall.php` (ADR-003), `Plugin`,
  `Identity\Store` y `Schema\Installer`. **Verificado en sandbox el 2026-09-20:** activa,
  crea `wp_wss_movements` (20 columnas, 7 entradas de índice), `wp_wss_links` y
  `wp_wss_nonces`, las tres InnoDB, y genera el identificador de tienda.
- **La guarda que suspende la sincronización.** `Plugin::suspension_reason()` responde por
  clon (ADR-001 §1) o por tablas sin transacciones (ADR-004 §5), y `boot()` corta antes de
  registrar nada. Estaban escritas las dos funciones y no las llamaba nadie.
- **Tests: 30 en verde.** Suite de WordPress (`wp-phpunit` por Composer) contra la base del
  sitio con prefijo `wptests_`. Cubren el esquema, la identidad, la guarda y la
  desinstalación. `tests/TEST-CASES.md` tiene la matriz decisión ↔ test y los hallazgos de
  auditoría ya corregidos.
- **Auditoría de tests (2026-09-21).** Un sub-agente con el encargo de nombrar, por cada
  hallazgo, la mutación que dejaría el test en verde. Nueve hallazgos, ocho ciertos. De ahí
  salieron la guarda, los tests de `uninstall.php` y el refuerzo de `SCH-02`, `SCH-03` y
  `SCH-08`.

---

## 🔄 En progreso — sesión actual

_(nada)_

---

## 📋 Backlog — en orden

- [ ] **ADR-005 — Vinculación.** ✍️ Escrito, `Estado: Propuesta`. El estado del vínculo es
      derivado; el alta viaja como bootstrap y la baja como movimiento `unlink`; la
      generación es la secuencia del bootstrap; quinto endpoint `GET /products`. **Revisar
      la §6**, que la elegí yo y no salió de una conversación.
- [ ] **ADR — Detector.** Cómo se detecta el cambio de stock y el guard anti-eco.
- [ ] **ADR-000 — Scaffold.** ✍️ Escrito, `Estado: Propuesta`, esperando lectura.
- [ ] **Código del libro** (`Ledger/`): el `INSERT` + `UPDATE` de la secuencia en
      transacción, el claim y la purga. Habilitado por ADR-004.
- [ ] **Código del transporte** (`Transport/`, `Rest/`): firma HMAC, emparejamiento,
      `POST /movements`. Habilitado por ADR-001.
- [ ] `readme.txt` y `.pot`, antes de pensar en publicar.
- [ ] Dejar el auditor de tests como agente fijo en `.claude/agents/` del entorno, si se
      decide que rinde en más de un plugin.
- [ ] Infection (mutation testing) **cuando exista `Transport/`**: ahí viven los límites
      (±5 min, 6 h, backoff, claim) que las mutaciones de frontera atacan. Requiere PCOV en
      la imagen de wp-restore, que hoy no tiene driver de cobertura.

---

## Decisiones de arquitectura

Las decisiones vivas están en `docs/DECISIONS.md`, que es la fuente. Acá sólo el resumen
de una línea por ADR aprobado.

- **ADR-002 — Identidad de tienda** (aprobada 2026-09-20). El identificador es opaco porque
  cambiar de identidad invalida el libro, y todo lo legible por una persona es algo que una
  persona va a querer corregir.
- **ADR-003 — Desinstalación** (aprobada 2026-09-20). Desmarcada por defecto porque el libro
  es el historial de stock del negocio; el secreto es la excepción porque re-emparejar es
  barato y una credencial viva en un plugin desinstalado no la vigila nadie.
- **ADR-004 — Esquema** (aprobada 2026-09-20). Los índices salen de las consultas que el
  diseño hace, no de las columnas que existen: el cuarto candidato, el de la purga, se
  rechaza porque costaría en cada venta para ahorrar un escaneo por noche.
- **ADR-001 — Transporte entre tiendas** (aprobada 2026-09-20). Las dos tiendas se hablan
  por `wss/v1` firmado con HMAC y secreto compartido, porque la identidad que hay que
  autenticar es la tienda y no una persona. El libro de movimientos es la cola: no hay
  segunda tabla, y Action Scheduler sólo agenda la pasada que lo vacía. Cada movimiento
  lleva dos estados independientes, de entrega y de aplicación, porque un movimiento propio
  y uno recibido no responden la misma pregunta.

---

## Deuda y cabos sueltos conocidos

- **El SPEC referencia ADR-004 y ADR-005** (vinculación manual, cambio de SKU) que no
  existen: quedaron de un arranque anterior. Hay que escribirlos o sacar las referencias.
- **UC-01 dice "reintenta un número finito de veces"**, pero ADR-001 fijó el techo en
  tiempo (6 h desde el primer intento). Sigue siendo cierto, pero el criterio de aceptación
  conviene reescribirlo contra horas para que el test se escriba así.
- **Preguntas abiertas del SPEC (§6)** sin cerrar: multisite, volumen real del catálogo y
  cómo se agrupa una importación masiva.
- **No cubierto a propósito en ADR-001:** el clon de una tienda servido en la **misma URL**
  pasa la detección. Declarado, no resuelto.
