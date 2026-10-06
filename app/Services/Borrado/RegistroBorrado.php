<?php

namespace App\Services\Borrado;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\Paradero;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\ProcedimientoVersion;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoGafete;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué se puede "Eliminar definitivamente" (borrado físico controlado).
 *
 * Solo catálogos y padrones. Las bitácoras y evidencias (accesos, novedades,
 * Lost & Found, robo, accidentes, préstamos de llaves, responsivas, pases de
 * salida, transporte, vouchers, firmas, auditoría) NUNCA se registran aquí:
 * esas solo se anulan o se dan de baja. Empresas y usuarios tampoco.
 *
 * Cada entrada (clave de la URL /borrar/{clave}/{id}):
 *  - modulo:    módulo del permiso "<modulo>.borrar" y del evento de auditoría
 *  - modelo:    clase del registro
 *  - tipo:      cómo se llama ("sede", "tipo de gafete"…) y su género (f/m)
 *  - nombre:    nombre que se muestra
 *  - confirmar: lo que hay que teclear para confirmar (nombre o identificador corto)
 *  - alcance:   'sede' (el registro es de una sede: se revisa con el motor) o
 *               'empresa' (catálogo de toda la empresa: exige alcance de empresa)
 *  - propios:   tablas hijas que forman parte del registro y se borran con él
 *               (horarios de una llave, horarios y paradas de una ruta, permisos
 *               de un rol). Se revisa que nadie más las use.
 *  - cuentan:   tablas puente que, para este registro, SÍ son dependencias (los
 *               colaboradores o proveedores asignados a una sede, las llaves que
 *               abren una zona). Las demás tablas puente se borran con él.
 *  - extras:    referencias sin llave foránea que también se revisan [tabla, columna]
 *  - baja:      cómo ofrecer "Dar de baja" en lugar de eliminar: [ruta, método,
 *               campos, permiso] o un texto con la indicación
 *  - volver:    pantalla a la que se regresa después de eliminar
 *  - consulta:  (opcional) consulta base si el modelo no usa el filtro de empresa
 *  - verificar: (opcional) reglas propias; devuelve un motivo que impide eliminar
 *               o lanza AuthorizationException (403)
 */
class RegistroBorrado
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definiciones(): array
    {
        $estado = fn (string $ruta, string $permiso) => ['ruta' => $ruta, 'metodo' => 'PATCH', 'campos' => ['activo' => 0], 'permiso' => $permiso];

        return [
            'sedes' => [
                'modulo' => 'sedes', 'modelo' => Sede::class, 'tipo' => ['sede', 'f'],
                'nombre' => fn (Sede $m) => $m->nombre,
                'confirmar' => fn (Sede $m) => $m->codigo,
                'alcance' => 'empresa',
                'cuentan' => ['colaborador_sede', 'proveedor_sede'],
                'baja' => $estado('sedes.estado', 'sedes.eliminar'),
                'volver' => 'sedes.index',
                'verificar' => fn (Sede $m) => Sede::whereKeyNot($m->id)->exists() ? null : 'Es la única sede de la empresa.',
            ],
            'espacios' => [
                'modulo' => 'espacios', 'modelo' => Espacio::class, 'tipo' => ['zona o área', 'f'],
                'nombre' => fn (Espacio $m) => $m->nombre,
                'alcance' => 'sede',
                'cuentan' => ['espacio_llave'],
                'baja' => $estado('espacios.estado', 'espacios.eliminar'),
                'volver' => 'espacios.index',
            ],
            'departamentos' => [
                'modulo' => 'departamentos', 'modelo' => Departamento::class, 'tipo' => ['departamento', 'm'],
                'nombre' => fn (Departamento $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => $estado('departamentos.estado', 'departamentos.eliminar'),
                'volver' => 'departamentos.index',
            ],
            'puestos' => [
                'modulo' => 'puestos', 'modelo' => Puesto::class, 'tipo' => ['puesto', 'm'],
                'nombre' => fn (Puesto $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => $estado('puestos.estado', 'puestos.eliminar'),
                'volver' => 'puestos.index',
            ],
            'turnos' => [
                'modulo' => 'turnos', 'modelo' => Turno::class, 'tipo' => ['turno', 'm'],
                'nombre' => fn (Turno $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => $estado('turnos.estado', 'turnos.eliminar'),
                'volver' => 'turnos.index',
            ],
            'colaboradores' => [
                'modulo' => 'colaboradores', 'modelo' => Colaborador::class, 'tipo' => ['colaborador', 'm'],
                'nombre' => fn (Colaborador $m) => $m->nombreCompleto(),
                'confirmar' => fn (Colaborador $m) => $m->num_empleado ?: $m->nombreCompleto(),
                'alcance' => 'sede',
                'sedes_adicionales' => 'colaborador_sede',
                'extras' => [
                    ...array_map(null, array_keys(AdministradorColaboradores::REFERENCIAS), array_values(AdministradorColaboradores::REFERENCIAS)),
                    ...AdministradorColaboradores::REFERENCIAS_ADICIONALES,
                ],
                'baja' => $estado('colaboradores.estado', 'colaboradores.eliminar'),
                'volver' => 'colaboradores.index',
            ],
            'roles' => [
                'modulo' => 'roles', 'modelo' => Rol::class, 'tipo' => ['rol', 'm'],
                'nombre' => fn (Rol $m) => $m->nombre,
                'alcance' => 'empresa',
                'propios' => ['rol_permisos'],
                // Nunca las plantillas de la plataforma: solo los roles de la empresa de trabajo
                'consulta' => fn (int $empresaId) => Rol::query()->where('empresa_id', $empresaId),
                'verificar' => function (Rol $m, User $actor) {
                    app(AdministradorRoles::class)->exigirPuedeAdministrarRol($actor, $m, 'roles.borrar');

                    return null;
                },
                'baja' => 'Si ya no se usa, desactívalo desde «Editar» (casilla «Activo»).',
                'volver' => 'roles.index',
            ],
            'proveedores' => [
                'modulo' => 'proveedores', 'modelo' => Proveedor::class, 'tipo' => ['empresa externa', 'f'],
                'nombre' => fn (Proveedor $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => $estado('proveedores.estado', 'proveedores.eliminar'),
                'volver' => 'proveedores.index',
            ],
            'personas' => [
                'modulo' => 'visitantes', 'modelo' => Persona::class, 'tipo' => ['persona', 'f'],
                'nombre' => fn (Persona $m) => $m->nombre_completo,
                'alcance' => 'empresa',
                'baja' => $estado('personas.estado', 'visitantes.eliminar'),
                'volver' => 'personas.index',
            ],
            'vehiculos' => [
                'modulo' => 'vehiculos', 'modelo' => Vehiculo::class, 'tipo' => ['vehículo', 'm'],
                'nombre' => fn (Vehiculo $m) => $m->placas,
                'alcance' => 'empresa',
                'baja' => $estado('vehiculos.estado', 'vehiculos.eliminar'),
                'volver' => 'vehiculos.index',
            ],
            'llaves' => [
                'modulo' => 'llaves', 'modelo' => Llave::class, 'tipo' => ['llave', 'f'],
                'nombre' => fn (Llave $m) => $m->nomenclatura,
                'alcance' => 'sede',
                'propios' => ['horarios_llave'],
                'baja' => 'Usa el botón «Dar de baja» de su ficha (pide el motivo y, si aplica, genera el voucher).',
                'volver' => 'llaves.index',
            ],
            'gafetes' => [
                'modulo' => 'gafetes', 'modelo' => Gafete::class, 'tipo' => ['gafete', 'm'],
                'nombre' => fn (Gafete $m) => $m->nomenclatura,
                'alcance' => 'sede',
                'baja' => 'Usa el botón «Dar de baja» de su ficha (pide el motivo y, si aplica, genera el voucher).',
                'volver' => 'gafetes.index',
            ],
            'tipos_gafete' => [
                'modulo' => 'gafetes', 'modelo' => TipoGafete::class, 'tipo' => ['tipo de gafete', 'm'],
                'nombre' => fn (TipoGafete $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => 'Mientras tenga gafetes, déjalo como está: cambia primero el tipo de esos gafetes.',
                'volver' => 'gafetes.index',
            ],
            'equipos' => [
                'modulo' => 'equipos', 'modelo' => Equipo::class, 'tipo' => ['equipo', 'm'],
                'nombre' => fn (Equipo $m) => $m->numero_serie,
                'alcance' => 'sede',
                'baja' => 'Usa el botón «Dar de baja» de su ficha (pide el motivo y, si aplica, genera el voucher).',
                'volver' => 'equipos.index',
            ],
            'tipos_equipo' => [
                'modulo' => 'equipos', 'modelo' => TipoEquipo::class, 'tipo' => ['tipo de equipo', 'm'],
                'nombre' => fn (TipoEquipo $m) => $m->nombre,
                'alcance' => 'empresa',
                'baja' => 'Mientras tenga equipos, déjalo como está: cambia primero el tipo de esos equipos.',
                'volver' => 'equipos.index',
            ],
            'equipos_pc' => [
                'modulo' => 'equipos_pc', 'modelo' => EquipoPc::class, 'tipo' => ['equipo de Protección Civil', 'm'],
                'nombre' => fn (EquipoPc $m) => $m->numero_serie,
                'alcance' => 'sede',
                'baja' => ['ruta' => 'equipos_pc.desactivar', 'metodo' => 'PATCH', 'campos' => [], 'permiso' => 'equipos_pc.eliminar'],
                'volver' => 'equipos_pc.index',
            ],
            'estacionamientos' => [
                'modulo' => 'estacionamientos', 'modelo' => ZonaEstacionamiento::class, 'tipo' => ['zona de estacionamiento', 'f'],
                'nombre' => fn (ZonaEstacionamiento $m) => $m->nombre,
                'alcance' => 'sede',
                'baja' => $estado('estacionamientos.estado', 'estacionamientos.eliminar'),
                'volver' => 'estacionamientos.index',
            ],
            'rutas' => [
                'modulo' => 'rutas', 'modelo' => Ruta::class, 'tipo' => ['ruta', 'f'],
                'nombre' => fn (Ruta $m) => $m->nombre,
                'alcance' => 'sede',
                'propios' => ['ruta_horarios', 'ruta_paradas'],
                'baja' => $estado('rutas.estado', 'rutas.eliminar'),
                'volver' => fn (Ruta $m) => route('rutas.sede', $m->sede_id),
            ],
            'paraderos' => [
                'modulo' => 'rutas', 'modelo' => Paradero::class, 'tipo' => ['paradero', 'm'],
                'nombre' => fn (Paradero $m) => $m->nombre,
                'alcance' => 'sede',
                'baja' => $estado('rutas.paraderos.estado', 'rutas.eliminar'),
                'volver' => fn (Paradero $m) => route('rutas.sede', $m->sede_id),
            ],
            // Procedimientos: solo un borrador que NUNCA se publicó (lo publicado se retira, no se borra)
            'procedimientos' => [
                'modulo' => 'procedimientos', 'modelo' => Procedimiento::class, 'tipo' => ['procedimiento', 'm'],
                'nombre' => fn (Procedimiento $m) => $m->clave.' · '.$m->titulo,
                'confirmar' => fn (Procedimiento $m) => $m->clave,
                'alcance' => 'empresa',
                'propios' => ['procedimiento_versiones', 'procedimiento_pasos', 'procedimiento_aplicaciones', 'procedimiento_adjuntos', 'procedimiento_eventos'],
                'verificar' => fn (Procedimiento $m) => ProcedimientoVersion::where('procedimiento_id', $m->id)->whereNotNull('aprobado_en')->exists()
                    ? 'Ya se publicó una versión (su historial y sus acuses se conservan).' : null,
                'baja' => 'Si ya se publicó, usa «Retirar» en su ficha: queda como obsoleto con todo su historial.',
                'volver' => 'procedimientos.index',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function de(string $clave): ?array
    {
        return self::definiciones()[$clave] ?? null;
    }

    /**
     * Módulos del catálogo que tienen la acción "borrar".
     *
     * @return list<string>
     */
    public static function modulos(): array
    {
        return array_values(array_unique(array_column(self::definiciones(), 'modulo')));
    }

    /**
     * Consulta base del registro (con el filtro de empresa ya activo).
     */
    public static function consulta(array $definicion, int $empresaId): Builder
    {
        return isset($definicion['consulta'])
            ? ($definicion['consulta'])($empresaId)
            : $definicion['modelo']::query();
    }

    public static function nombre(array $definicion, Model $registro): string
    {
        return trim((string) ($definicion['nombre'])($registro));
    }

    public static function confirmacion(array $definicion, Model $registro): string
    {
        return trim((string) (($definicion['confirmar'] ?? $definicion['nombre'])($registro)));
    }

    /**
     * "la sede" / "el departamento".
     */
    public static function conArticulo(array $definicion): string
    {
        [$tipo, $genero] = $definicion['tipo'];

        return ($genero === 'f' ? 'la ' : 'el ').$tipo;
    }

    /**
     * @throws AuthorizationException
     */
    public static function verificar(array $definicion, Model $registro, User $actor): ?string
    {
        return isset($definicion['verificar']) ? ($definicion['verificar'])($registro, $actor) : null;
    }
}
