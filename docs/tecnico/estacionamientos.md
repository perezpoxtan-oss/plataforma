# Estacionamientos y zonas

Réplica de `modules/estacionamientos` de SEGCAT (§4.21). Pantalla "Cupos por sede": estacionamientos con cupo y zonas de descarga (lobby, almacén, andén) sin cupo.

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_09_000400_crear_equipos_y_estacionamientos.php` |
| Modelo | `app/Models/ZonaEstacionamiento.php` (no es Identificable) |
| Reglas | `app/Services/Estacionamientos/AdministradorEstacionamientos.php` |
| Ocupación | `app/Services/Estacionamientos/OcupacionEstacionamientos.php` (contrato) y `SinOcupacion.php` (implementación por defecto) |
| Controlador | `app/Http/Controllers/Seguridad/EstacionamientoController.php` |
| Vista | `resources/views/seguridad/estacionamientos/index.blade.php` |
| Pruebas | `tests/Feature/Seguridad/EstacionamientosTest.php` |

## Tabla `zonas_estacionamiento`

(SEGCAT: `estacionamientos_zonas`) `empresa_id`, `sede_id`, `nombre` (único por sede), `tipo` (`estacionamiento` | `zona_descarga`), `cupo_total` (1–9999 en estacionamiento; `null` en descarga), `activo`, auditoría y fechas. `unique(empresa_id, sede_id, nombre)`; la aplicación además compara sin importar mayúsculas.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /estacionamientos` | `estacionamientos.ver` | Cupos por sede |
| `POST /estacionamientos` | `estacionamientos.crear` | Nueva zona |
| `PUT /estacionamientos/{id}` | `estacionamientos.editar` | Editar |
| `PATCH /estacionamientos/{id}/estado` | `estacionamientos.eliminar` | Desactivar (`activo=0`) o reactivar (`activo=1`) |

Otra empresa o fuera de alcance → 404. Con **alcance de sede** solo se ven y administran las zonas de sus sedes y solo se crean en ellas. El **Agente** solo consulta.

## Ocupación (gancho para la Bitácora de accesos)

SEGCAT contaba en vivo los accesos `EN SITIO` de cada zona (nunca guardaba la ocupación). La Bitácora de accesos aún no existe, así que la pantalla pide la ocupación al contrato:

```php
interface OcupacionEstacionamientos
{
    /** @param list<int> $zonaIds  @return array<int,int> zona_id => vehículos en sitio */
    public function ocupados(array $zonaIds): array;
}
```

- Por defecto responde `SinOcupacion` (todo 0, por el atributo `#[Bind]`): se ve "0 / 40 espacios".
- Se llama **una vez** por pantalla con todas las zonas (sin N+1), dentro de la empresa de trabajo.
- Cuando se migre Accesos, en su ServiceProvider: `$this->app->bind(OcupacionEstacionamientos::class, OcupacionPorAccesos::class);` (contar `accesos` con `estatus = en_sitio` agrupados por `zona_estacionamiento_id`). La pantalla no cambia.
- Lleno = ocupados ≥ cupo (barra `.cupo-barra-fill.lleno`, roja, y la palabra LLENO). Las zonas de descarga muestran "N vehículos usando el andén ahora".
- Accesos debe ofrecer solo zonas **activas** de la sede del acceso.

## Auditoría

`estacionamientos.creado`, `estacionamientos.actualizado`, `estacionamientos.desactivado`, `estacionamientos.reactivado`.

## Qué se corrigió respecto a SEGCAT

- Desactivar y reactivar usaban `editar`; ahora es `estacionamientos.eliminar`, como el resto de la plataforma.
- El nombre de la zona se podía repetir en la misma sede; ahora es único por sede (avisa si la repetida está desactivada).
- Un cupo inválido se convertía en 1 en silencio (`max(1, …)`); ahora se pide corregirlo con un mensaje claro.
- Al editar no se podía cambiar la sede; ahora sí, solo a sedes activas dentro de su alcance.
- La pantalla quedaba vacía sin explicación si fallaba la consulta y mostraba el error SQL al Súper Admin; ahora hay estado vacío y los errores van a las pantallas de error de la plataforma.
- Se agregaron búsqueda, filtro por sede y por tipo, agrupación por sede y trazas "Creado por / Editado por".
- Sin JavaScript en línea (`onclick`, JSON incrustado en atributos de `onclick`).
