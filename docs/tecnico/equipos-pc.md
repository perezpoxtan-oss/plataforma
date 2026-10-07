# Equipos de Protección Civil (catálogo)

Catálogo de extintores, hidrantes, detectores, botiquines y demás infraestructura fija de Protección Civil de cada sede (SEGCAT: `cat_equipos_pc`, `pc_equipos_lista.php`, `pc_equipos_modal_editar.php`, `pc_equipos_proceso.php`, `pc_equipos_ticket.php`). Es lo que se inspecciona en los [Recorridos de Protección Civil](recorridos-pc.md).

Desde QA 4 es un **módulo propio**, `equipos_pc` («Equipos de Protección Civil», icono `bi-fire`), en **Padrones → Inventarios de Seguridad**, justo después de Equipos de seguridad. Antes era una pantalla dentro de Recorridos PC (`/recorridos-pc/equipos`) que se consultaba con `recorridos_pc.ver` y se administraba con `equipos.*`.

- Controlador: `app/Http/Controllers/Seguridad/EquipoPcController.php`
- Reglas: `app/Services/RecorridosPc/CatalogoEquiposPc.php` (alcance, alta, edición, baja, reactivación, validación); ubicaciones con `Ubicaciones.php`
- Modelo: `App\Models\EquipoPc` (`PerteneceAEmpresa`, `RegistraAutor`, `TieneIdentificador`, `Identificable`)
- Vistas: `resources/views/seguridad/equipos-pc/{index,etiqueta}.blade.php` (antes `seguridad/recorridos-pc/{equipos,etiqueta}`)
- Migración del módulo para instalaciones existentes: `2026_10_10_000360_catalogo_equipos_pc_en_padrones` (la tabla `equipos_pc` sigue siendo la de `2026_10_10_000350`)
- Pruebas: `tests/Feature/Seguridad/EquiposPcTest.php` (módulo, permisos, migración, redirecciones) y las del catálogo en `RecorridosPcTest.php`

## Tabla

`equipos_pc` (sin cambios): `empresa_id`, `sede_id`, `categoria` (`EquipoPc::CATEGORIAS`, 13), `numero_serie` (mayúsculas, único por sede), `espacio_id` (zona, piso o área específica de Zonas y áreas), `referencia`, `activo`, `codigo_qr` (aleatorio, único), `etiqueta_nfc` (única en la empresa), autoría y timestamps. Ver [recorridos-pc.md](recorridos-pc.md#tablas).

## Rutas (endpoints) y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/equipos-pc` | `equipos_pc.index` | `equipos_pc.ver` |
| POST | `/equipos-pc` (`_siguiente=1`: «Guardar y capturar siguiente») | `equipos_pc.store` | `equipos_pc.crear` (sede de su alcance) |
| PUT | `/equipos-pc/{id}` | `equipos_pc.update` | `equipos_pc.editar` |
| PATCH | `/equipos-pc/{id}/desactivar` · `/reactivar` | `equipos_pc.desactivar` · `.reactivar` | `equipos_pc.eliminar` |
| GET | `/equipos-pc/{id}/etiqueta` | `equipos_pc.etiqueta` | `equipos_pc.imprimir` |
| GET | `/equipos-pc/{id}/qr` (SVG) | `equipos_pc.qr` | `equipos_pc.ver` |
| GET | `/equipos-pc/{id}/ir` | `equipos_pc.ir` | `equipos_pc.ver` (a dónde lleva el QR / la etiqueta NFC) |
| GET | `/recorridos-pc/equipos` | `equipos_pc.anterior` | `equipos_pc.ver` → **301** a `/equipos-pc` (conserva la consulta) |
| GET | `/recorridos-pc/equipos/{id}/{etiqueta\|qr\|ir}` | `equipos_pc.anterior.equipo` | `equipos_pc.ver` y el equipo dentro del alcance (si no, 404) → **301** a la nueva |

- Las direcciones anteriores solo existen para GET (marcadores y enlaces guardados). Los QR impresos no cambian: llevan `/e/{codigo}` (lector universal), que resuelve con `EquipoPc::urlLector()` → `equipos_pc.ir`.
- **Alcance:** con alcance de sede solo se ve y se toca lo de sus sedes (otra sede u otra empresa → 404); con «propios», lo que él registró. El Super Administrador elige la empresa de trabajo.
- **Auditoría:** `equipos_pc.creado | actualizado | desactivado | reactivado` (antes se registraban como `recorridos_pc.*`; los eventos viejos se quedan como están en la bitácora).

## Permisos y plantillas

| Plantilla | `equipos_pc.*` |
|---|---|
| Administrador | todo, empresa |
| Director | ver e imprimir, empresa |
| Jefe de seguridad | todo, sede |
| Asistente | ver, crear, editar, imprimir, sede |
| Supervisor | todo menos eliminar, sede |
| Agente | solo **ver**, sede (Padrones) |

Las reglas de `RolesPlantillaSeeder` no cambiaron: el módulo es de Seguridad y está en el menú Padrones, así que cada plantilla recibe lo mismo que en los demás padrones.

### Migración en instalaciones existentes

`2026_10_10_000360_catalogo_equipos_pc_en_padrones` corre **antes** que los seeders del despliegue y solo agrega:

1. Crea el módulo `equipos_pc` (área Seguridad, ruta `equipos_pc.index`) con las acciones `ver, crear, editar, eliminar, imprimir`.
2. Lo pone en Padrones → Inventarios de Seguridad, después de Equipos de seguridad (recorre un lugar a los que seguían).
3. Lo activa (`empresa_modulos`) en cada empresa que tenía Recorridos PC o Equipos de seguridad.
4. Copia permisos con el **mismo alcance**, en plantillas y roles de cada empresa (también los roles personalizados):
   - `equipos_pc.ver` ← `recorridos_pc.ver`
   - `equipos_pc.crear | editar | eliminar | imprimir` ← `equipos.crear | editar | eliminar | imprimir`
5. En una instalación nueva no hace nada (lo hacen `CatalogoSeeder`, `MenuSeeder` y `RolesPlantillaSeeder`). Es idempotente; `down()` quita el módulo.

## Lector universal

- Tipo `equipo_pc` en `config/lector.php` (sin cambios). `EquipoPc::permisoLector()` = **`equipos_pc.ver`** y `urlLector()` = `equipos_pc.ir`.
- `ir`: si el usuario puede `recorridos_pc.crear` y hay un recorrido **En Proceso** de la sede del equipo, abre ese recorrido con el equipo listo para inspeccionar; si no, la ficha del catálogo.
- El recorrido (Operación) sigue escaneando los equipos de su sede con el componente universal. Como el lector solo devuelve tipos que el usuario puede ver, quien hace recorridos necesita también `equipos_pc.ver` (la migración y las plantillas ya lo dan a quien tenía `recorridos_pc.ver`).

## Pantalla

- Padrones → **Equipos de Protección Civil** (`/equipos-pc`): mismo diseño, textos y colores de SEGCAT («Catálogo de Equipos de Protección Civil», tarjetas, filtros Activos / De baja / Todos, sede, tipo y búsqueda, Ver QR, etiqueta, «Guardar y capturar siguiente»).
- El botón **Recorridos PC** de la cabecera solo aparece a quien puede ver Recorridos.
- En **Recorridos de Protección Civil** se quitó el botón «Catálogo de Equipos»; queda un enlace pequeño «Los equipos se dan de alta en Padrones → Equipos de Protección Civil» solo para quien ve el catálogo.

## Qué se corrigió respecto a SEGCAT

- El catálogo vivía escondido dentro de la bitácora de recorridos y se protegía con los permisos de la bitácora (`bitacora.*`); ahora es un padrón con sus propios permisos `equipos_pc.*` en la Matriz de permisos, como Llaves, Gafetes o Equipos de seguridad.
- El QR de la etiqueta se pedía a `api.qrserver.com`; ahora se dibuja en el servidor y lleva un código no adivinable (ver [recorridos-pc.md](recorridos-pc.md#qué-se-corrigió-respecto-a-segcat)).
- Las direcciones anteriores no se rompen: redirigen de forma permanente (301) sin revelar equipos de otra empresa o sede.

## Ronda 6: aviso de duplicado en vivo

El **Núm. de Serie / ID** avisa mientras se escribe si ya existe **en la sede elegida** (`GET /equipos-pc/duplicado`, con `data-duplicado-con="sede_id"`; sin sede no revisa) o si se parece (sin guiones ni espacios), con **Reactivar** si está dado de baja. La etiqueta NFC avisa «ya la tiene X» al leerla (`identificacion.etiqueta-duplicado`). Ver `avisos-duplicado.md`.
