<?php

namespace App\Services\Arranque;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Support\Facades\DB;

/**
 * «Configura tu empresa» (ver docs/tecnico/configura-tu-empresa.md): la
 * tarjeta de Inicio que guía al Administrador de una empresa nueva. Cada
 * paso se marca solo con los datos reales (no hay casillas a mano).
 *
 * Se ve en Inicio a quien puede editar la Configuración de la empresa:
 *  - empresa nueva (creada desde Empresas o al activar una suscripción): sí,
 *    hasta que alguien toque «Ya terminé»;
 *  - empresa que ya existía (sin preferencia guardada): solo si está casi
 *    vacía (MAX_HECHOS_CASI_VACIA pasos hechos o menos), así la empresa de
 *    ejemplo y las que ya operan no la ven;
 *  - siempre se puede volver a mostrar u ocultar desde Configuración.
 *
 * La preferencia vive en empresas.preferencias['configura_tu_empresa']
 * («visible» u «oculto»), sin columna nueva.
 */
class AsistenteArranque
{
    public const PREFERENCIA = 'configura_tu_empresa';

    public const VISIBLE = 'visible';

    public const OCULTO = 'oculto';

    /** Una empresa que ya existía muestra la tarjeta sola si tiene a lo más estos pasos hechos. */
    public const MAX_HECHOS_CASI_VACIA = 2;

    public function __construct(private readonly Tenant $tenant) {}

    /** Quien la ve y la oculta: el que administra la configuración de la empresa. */
    public function puedeAdministrar(User $actor): bool
    {
        return $actor->can('configuracion.editar');
    }

    /** Al crear una empresa nueva: la tarjeta aparece en su Inicio. */
    public static function marcarNueva(Empresa $empresa): void
    {
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], [self::PREFERENCIA => self::VISIBLE])])->save();
    }

    public function cambiar(Empresa $empresa, bool $visible): void
    {
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], [self::PREFERENCIA => $visible ? self::VISIBLE : self::OCULTO])])->save();
    }

    public function preferencia(Empresa $empresa): ?string
    {
        $valor = $empresa->preferencias[self::PREFERENCIA] ?? null;

        return in_array($valor, [self::VISIBLE, self::OCULTO], true) ? $valor : null;
    }

    /**
     * La tarjeta para Inicio, o null si no toca mostrarla.
     *
     * @return array{pasos: list<array<string, mixed>>, hechos: int, total: int}|null
     */
    public function tarjeta(User $actor, ?int $empresaId): ?array
    {
        if ($empresaId === null || ! $this->puedeAdministrar($actor)) {
            return null;
        }
        $empresa = Empresa::find($empresaId);
        if ($empresa === null || $this->preferencia($empresa) === self::OCULTO) {
            return null;
        }
        $avance = $this->avance($actor, $empresa);
        if ($this->preferencia($empresa) === null && $avance['hechos'] > self::MAX_HECHOS_CASI_VACIA) {
            return null;
        }

        return $avance;
    }

    /**
     * Los pasos con su marca de hecho (calculada) y su enlace directo.
     *
     * @return array{pasos: list<array<string, mixed>>, hechos: int, total: int}
     */
    public function avance(User $actor, Empresa $empresa): array
    {
        $pasos = $this->tenant->conEmpresa($empresa->id, function () use ($actor, $empresa) {
            $enlace = fn (string $permiso, string $ruta, array $parametros = []) => $actor->can($permiso) ? route($ruta, $parametros) : null;
            $importar = fn (string $modulo, string $tipo, string $lista) => $enlace($modulo.'.importar', 'importaciones.create', ['tipo' => $tipo]) ?? $enlace($modulo.'.ver', $lista);

            return [
                [
                    'clave' => 'identidad', 'icono' => 'bi-image', 'titulo' => 'Identidad y logotipo',
                    'texto' => 'Revisa el nombre, la razón social y sube el logotipo de tu empresa.',
                    'hecho' => $empresa->logo_ruta !== null && $empresa->logo_ruta !== '',
                    'enlaces' => array_filter(['Ir a Empresas' => $enlace('empresas.ver', 'empresas.index')]),
                ],
                [
                    'clave' => 'sedes', 'icono' => 'bi-geo-alt', 'titulo' => 'Sedes',
                    'texto' => 'Da de alta cada lugar que vas a operar (hotel, oficina, planta…).',
                    'hecho' => Sede::where('activo', true)->exists(),
                    'enlaces' => array_filter(['Ir a Sedes' => $enlace('sedes.ver', 'sedes.index')]),
                ],
                [
                    'clave' => 'departamentos', 'icono' => 'bi-diagram-2', 'titulo' => 'Departamentos y responsables',
                    'texto' => 'Crea tus departamentos y nombra a su responsable: él autoriza las visitas y los pases de su gente.',
                    'hecho' => Departamento::where('activo', true)->exists() && DepartamentoResponsable::query()->exists(),
                    'enlaces' => array_filter(['Ir a Departamentos' => $enlace('departamentos.ver', 'departamentos.index')]),
                ],
                [
                    'clave' => 'usuarios', 'icono' => 'bi-person-gear', 'titulo' => 'Usuarios y roles',
                    'texto' => 'Da acceso a tu equipo (jefe de seguridad, agentes, Recursos Humanos…) con el rol de cada quien.',
                    'hecho' => DB::table('users')->where('empresa_id', $empresa->id)->where('activo', true)->count() > 1,
                    'enlaces' => array_filter(['Ir a Usuarios' => $enlace('usuarios.ver', 'usuarios.index')]),
                ],
                [
                    'clave' => 'colaboradores', 'icono' => 'bi-people', 'titulo' => 'Importar colaboradores',
                    'texto' => 'Sube tu plantilla de personal desde Excel en un solo paso.',
                    'hecho' => Colaborador::where('activo', true)->where('provisional', false)->exists(),
                    'enlaces' => array_filter(['Importar colaboradores' => $importar('colaboradores', 'colaboradores', 'colaboradores.index')]),
                ],
                [
                    'clave' => 'zonas', 'icono' => 'bi-grid-3x3-gap', 'titulo' => 'Zonas y edificios',
                    'texto' => ($t = Terminos::para($empresa))->esHotel() ? 'Captura tus zonas, pisos y habitaciones (u oficinas), o impórtalos desde Excel.'
                        : 'Captura tus zonas, pisos y '.mb_strtolower($t->espacios()).', o impórtalos desde Excel.', // Giros
                    'hecho' => Espacio::where('nivel', Espacio::EDIFICIO)->exists(),
                    'enlaces' => array_filter([
                        'Importar zonas' => $enlace('espacios.importar', 'importaciones.create', ['tipo' => 'zonas']),
                        'Ir a Zonas y edificios' => $enlace('espacios.ver', 'espacios.index'),
                    ]),
                ],
                [
                    'clave' => 'padrones', 'icono' => 'bi-journal-text', 'titulo' => 'Padrones',
                    'texto' => 'Empresas externas, personas y vehículos que llegan seguido: regístralos o impórtalos para que la caseta los encuentre.',
                    'hecho' => Proveedor::query()->exists() || Persona::query()->exists() || Vehiculo::query()->exists(),
                    'enlaces' => array_filter([
                        'Empresas externas' => $importar('proveedores', 'proveedores', 'proveedores.index'),
                        'Personas' => $importar('visitantes', 'personas', 'personas.index'),
                        'Vehículos' => $importar('vehiculos', 'vehiculos', 'vehiculos.index'),
                    ]),
                ],
            ];
        });

        $hechos = count(array_filter($pasos, fn ($p) => $p['hecho']));

        return ['pasos' => $pasos, 'hechos' => $hechos, 'total' => count($pasos)];
    }
}
