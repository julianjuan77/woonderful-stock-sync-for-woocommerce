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
| ADRs                        | 🟡 50% (3 de ~6 aprobados; ADR-004 en propuesta) |
| ARCHITECTURE.md             | 🟡 70% (contrato, módulos, seguridad y convenciones de código) |
| Scaffold del plugin         | ⬜ 0% — no hay una sola línea de código todavía |
| Tests                       | ⬜ 0% |
| Estándares (PHPCS / ESLint) | ⬜ 0% |
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

---

## 🔄 En progreso — sesión actual

_(nada)_

---

## 📋 Backlog — en orden

El esquema es el que habilita escribir código; el detector y la vinculación son
independientes entre sí.

- [ ] **ADR-004 — Esquema de la base de datos.** ✍️ Escrito, `Estado: Propuesta`. Tres
      tablas InnoDB, tres índices en el libro, y la retención con sus tres reglas duras
      (sólo se purga lo terminado, el stock base absorbe lo purgado, y cada tienda declara
      hasta dónde purgó para que la reconciliación no re-entregue lo borrado).

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
