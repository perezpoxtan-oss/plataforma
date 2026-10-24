<?php

namespace App\Services\Novedades;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Ajustes de la Bitácora de novedades por empresa (Configuración → Bitácora
 * de novedades, permiso «configuracion.editar»; ADR-0013). Sin tocarlos, todo
 * se ve y funciona como siempre:
 *
 *  - Nombre del módulo (decisión del dueño, 2026-10-12): cada empresa elige
 *    «Novedades» (el original y valor por omisión: todo lo que acontece en el
 *    día, sin importar tipo) o «Incidentes»; otro nombre queda en Avanzado.
 *    Se guarda en empresa_modulos.nombre_visible del módulo «novedades» (el
 *    mismo dato que ya usa el menú; vacío = «Novedades»), así las empresas
 *    que ya lo cambiaron conservan su nombre. Se aplica en el menú, el botón
 *    de abajo del celular, títulos, avisos (campana y correo), informes y el
 *    manual; las direcciones y los permisos no cambian.
 *  - Categoría del ticket que abre un hallazgo de un Recorrido de Protección
 *    Civil: «Siniestro Protección Civil» (omisión) o «Mantenimiento de equipo
 *    PC». Se guarda en empresas.preferencias['novedades'].
 */
class AjustesNovedades
{
    public const NOMBRE_OMISION = 'Novedades';

    /** Nombres que se sugieren (se puede escribir otro). */
    public const NOMBRES_SUGERIDOS = ['Novedades', 'Incidentes'];

    /** Valor del selector cuando la empresa escribe su propio nombre (Avanzado). */
    public const OPCION_OTRO = 'otro';

    /** Memoria por petición (el nombre se pide en varias partes de la misma pantalla). */
    private const MEMORIA = 'ajustes_novedades.nombre.';

    public const CATEGORIAS_HALLAZGO = [
        'proteccion_civil' => 'Siniestro Protección Civil (como siempre)',
        'mantenimiento_pc' => 'Mantenimiento de equipo PC',
    ];

    public const CATEGORIA_HALLAZGO_OMISION = 'proteccion_civil';

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /** Nombre del módulo en el menú de la empresa (vacío = el de siempre). */
    public function nombreMenu(?int $empresaId): string
    {
        if ($empresaId === null) {
            return self::NOMBRE_OMISION;
        }
        $memoria = request()->attributes;
        if ($memoria->has(self::MEMORIA.$empresaId)) {
            return $memoria->get(self::MEMORIA.$empresaId);
        }
        $nombre = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('m.clave', 'novedades')->value('em.nombre_visible');
        $nombre = is_string($nombre) && trim($nombre) !== '' ? $nombre : self::NOMBRE_OMISION;
        $memoria->set(self::MEMORIA.$empresaId, $nombre);

        return $nombre;
    }

    /** ¿La empresa cambió el nombre del menú? */
    public function nombrePropio(?int $empresaId): bool
    {
        return $this->nombreMenu($empresaId) !== self::NOMBRE_OMISION;
    }

    /**
     * Nombre largo del módulo para títulos, avisos e informes:
     * «Bitácora de novedades» (omisión), «Bitácora de incidentes» o, si la
     * empresa escribió otro, ese nombre tal cual.
     */
    public function bitacora(?int $empresaId): string
    {
        $nombre = $this->nombreMenu($empresaId);

        return in_array($nombre, self::NOMBRES_SUGERIDOS, true) ? 'Bitácora de '.mb_strtolower($nombre) : $nombre;
    }

    /** Igual que bitacora(), listo para «Ir a …» / «Ábrelo en …»: «la Bitácora de novedades» o «Despacho» entre comillas. */
    public function bitacoraConArticulo(?int $empresaId): string
    {
        $nombre = $this->nombreMenu($empresaId);

        return in_array($nombre, self::NOMBRES_SUGERIDOS, true) ? 'la '.$this->bitacora($empresaId) : '«'.$nombre.'»';
    }

    /** Empresa de trabajo de quien tiene la sesión (null sin sesión o Super Administrador sin empresa elegida). */
    public function empresaActual(): ?int
    {
        $usuario = auth()->user();

        return $usuario instanceof User ? app(EmpresaDeTrabajo::class)->id($usuario) : null;
    }

    /** Atajos para pantallas: con la empresa de trabajo de la sesión. */
    public static function nombreActual(): string
    {
        $ajustes = app(self::class);

        return $ajustes->nombreMenu($ajustes->empresaActual());
    }

    public static function bitacoraActual(): string
    {
        $ajustes = app(self::class);

        return $ajustes->bitacora($ajustes->empresaActual());
    }

    public static function bitacoraActualConArticulo(): string
    {
        $ajustes = app(self::class);

        return $ajustes->bitacoraConArticulo($ajustes->empresaActual());
    }

    /** Opción del selector para el nombre actual: «Novedades», «Incidentes» u «otro». */
    public function opcion(?int $empresaId): string
    {
        $nombre = $this->nombreMenu($empresaId);

        return in_array($nombre, self::NOMBRES_SUGERIDOS, true) ? $nombre : self::OPCION_OTRO;
    }

    public function categoriaHallazgoPc(?Empresa $empresa): string
    {
        $valor = $empresa?->preferencias['novedades']['categoria_hallazgo_pc'] ?? null;

        return isset(self::CATEGORIAS_HALLAZGO[$valor]) ? $valor : self::CATEGORIA_HALLAZGO_OMISION;
    }

    /**
     * @param  array<string, mixed>  $entrada  nombre_opcion (Novedades | Incidentes | otro), nombre_menu (solo con «otro»; sin opción, como antes), categoria_hallazgo_pc
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $opcion = $entrada['nombre_opcion'] ?? null;
        if (in_array($opcion, self::NOMBRES_SUGERIDOS, true)) {
            $entrada['nombre_menu'] = $opcion; // el selector manda; lo escrito en «Otro nombre» se ignora
        } elseif ($opcion === self::OPCION_OTRO && trim((string) ($entrada['nombre_menu'] ?? '')) === '') {
            $entrada['nombre_menu'] = null;
        }
        $datos = Validator::make($entrada, [
            'nombre_opcion' => ['nullable', 'in:'.implode(',', [...self::NOMBRES_SUGERIDOS, self::OPCION_OTRO])],
            'nombre_menu' => [$opcion === self::OPCION_OTRO ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[\pL\pN .&\-]+$/u'],
            'categoria_hallazgo_pc' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIAS_HALLAZGO))],
        ], [
            'nombre_opcion.in' => 'Elige «Novedades», «Incidentes» u «Otro nombre».',
            'nombre_menu.required' => 'Escribe el nombre que quieres para el módulo o elige «Novedades» o «Incidentes».',
            'nombre_menu.max' => 'El nombre del menú admite máximo 40 letras.',
            'nombre_menu.regex' => 'El nombre del menú solo puede llevar letras, números, espacios, punto, guion y «&».',
            'categoria_hallazgo_pc.required' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
            'categoria_hallazgo_pc.in' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
        ])->validate();

        $antes = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa)];
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['nombre_menu'] ?? '')));
        $nombre = $nombre === '' || $nombre === self::NOMBRE_OMISION ? null : $nombre;
        $categoria = $datos['categoria_hallazgo_pc'];

        DB::transaction(function () use ($empresa, $nombre, $categoria) {
            $modulo = Modulo::where('clave', 'novedades')->value('id');
            if ($modulo !== null) {
                // Solo en la empresa que tiene el módulo contratado (sin contrato no hay menú que nombrar)
                DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->where('modulo_id', $modulo)
                    ->update(['nombre_visible' => $nombre, 'updated_at' => now(), 'actualizado_por' => Auth::id()]);
            }
            $fresca = Empresa::query()->lockForUpdate()->find($empresa->id);
            $preferencias = is_array($fresca?->preferencias) ? $fresca->preferencias : [];
            if ($categoria === self::CATEGORIA_HALLAZGO_OMISION) {
                unset($preferencias['novedades']['categoria_hallazgo_pc']);
                if (($preferencias['novedades'] ?? null) === []) {
                    unset($preferencias['novedades']);
                }
            } else {
                $preferencias['novedades']['categoria_hallazgo_pc'] = $categoria;
            }
            $fresca?->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        });
        $empresa->refresh();
        request()->attributes->remove(self::MEMORIA.$empresa->id);

        $despues = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa)];
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'novedades.configurado', $empresa, $antes, $despues);
        }
    }
}
