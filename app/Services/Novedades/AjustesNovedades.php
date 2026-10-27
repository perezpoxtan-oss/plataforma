<?php

namespace App\Services\Novedades;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\RecorridoPc;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        // C-29 (DD-17): el hallazgo de un recorrido es mantenimiento, no un siniestro (no infla Tablero ni Tendencias)
        'mantenimiento_pc' => 'Mantenimiento de equipo PC (recomendado)',
        'proteccion_civil' => 'Siniestro Protección Civil',
    ];

    public const CATEGORIA_HALLAZGO_OMISION = 'mantenimiento_pc';

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

    /**
     * C-24 (D-04): reloj de los tickets canalizados por categoría, en horas
     * hasta el recordatorio (ámbar). Al suplente (el jefe de seguridad de la
     * sede) pasan al triple. Accidente 2 h, Robo 4 h y lo demás 24 h.
     */
    public const RELOJ_HORAS = ['accidente' => 2, 'robo' => 4, 'otras' => 24];

    public const RELOJ_MAX_HORAS = 240;

    /** @return array{accidente: int, robo: int, otras: int} */
    public function relojHoras(Empresa|int|null $empresa): array
    {
        $empresa = is_int($empresa) ? Empresa::find($empresa) : $empresa;
        $guardado = (array) ($empresa?->preferencias['novedades']['reloj_horas'] ?? []);
        $horas = self::RELOJ_HORAS;
        foreach ($horas as $clave => $omision) {
            $valor = $guardado[$clave] ?? null;
            $horas[$clave] = is_numeric($valor) ? max(1, min(self::RELOJ_MAX_HORAS, (int) $valor)) : $omision;
        }

        return $horas;
    }

    /** Horas de recordatorio de una categoría. */
    public function relojHorasDe(Empresa|int|null $empresa, ?string $categoria): int
    {
        $horas = $this->relojHoras($empresa);

        return $horas[$categoria] ?? $horas['otras'];
    }

    /**
     * Revisión Etapa 5 (C-28): «Departamento que recibe accidentes de
     * personal». El Accidente de un colaborador se canaliza a este
     * departamento (su formato no cambia). Se guarda en
     * preferencias['novedades']['departamento_accidentes']: el id, o 0 =
     * ninguno. Sin guardar (valor de fábrica): el departamento activo cuyo
     * nombre es el de Recursos Humanos. Devuelve null si no hay.
     */
    public function departamentoAccidentes(Empresa|int|null $empresa): ?int
    {
        $empresa = is_int($empresa) ? Empresa::find($empresa) : $empresa;
        if ($empresa === null) {
            return null;
        }
        $guardado = $empresa->preferencias['novedades']['departamento_accidentes'] ?? null;
        $activos = Departamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('activo', true);
        if (is_numeric($guardado)) {
            return (int) $guardado > 0 && (clone $activos)->whereKey((int) $guardado)->exists() ? (int) $guardado : null;
        }
        $rrhh = $activos->orderBy('id')->get(['id', 'nombre'])->first(fn (Departamento $d) => preg_match(CanalizarDepartamento::PATRON_RRHH, (string) $d->nombre) === 1);

        return $rrhh?->id === null ? null : (int) $rrhh->id;
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
            'reloj_horas' => ['nullable', 'array'],
            'departamento_accidentes' => ['nullable', 'integer', 'min:0'],
            'reloj_horas.*' => ['nullable', 'integer', 'min:1', 'max:'.self::RELOJ_MAX_HORAS],
        ], [
            'reloj_horas.*.integer' => 'Escribe las horas del reloj con un número entero, de 1 a '.self::RELOJ_MAX_HORAS.'.',
            'reloj_horas.*.min' => 'Escribe las horas del reloj con un número entero, de 1 a '.self::RELOJ_MAX_HORAS.'.',
            'reloj_horas.*.max' => 'Escribe las horas del reloj con un número entero, de 1 a '.self::RELOJ_MAX_HORAS.'.',
            'nombre_opcion.in' => 'Elige «Novedades», «Incidentes» u «Otro nombre».',
            'nombre_menu.required' => 'Escribe el nombre que quieres para el módulo o elige «Novedades» o «Incidentes».',
            'nombre_menu.max' => 'El nombre del menú admite máximo 40 letras.',
            'nombre_menu.regex' => 'El nombre del menú solo puede llevar letras, números, espacios, punto, guion y «&».',
            'categoria_hallazgo_pc.required' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
            'categoria_hallazgo_pc.in' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
            'departamento_accidentes.*' => 'Elige de la lista el departamento que recibe los accidentes del personal.',
        ])->validate();
        // Revisión Etapa 5: el departamento debe ser activo y de esta empresa (0 = ninguno; sin el campo, se conserva)
        $deptoAccidentes = array_key_exists('departamento_accidentes', $datos) && $datos['departamento_accidentes'] !== null ? (int) $datos['departamento_accidentes'] : null;
        if ($deptoAccidentes !== null && $deptoAccidentes > 0
            && ! Departamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('activo', true)->whereKey($deptoAccidentes)->exists()) {
            throw ValidationException::withMessages(['departamento_accidentes' => 'Elige de la lista el departamento que recibe los accidentes del personal.']);
        }

        $antes = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa), 'reloj_horas' => $this->relojHoras($empresa),
            'departamento_accidentes' => $this->departamentoAccidentes($empresa)];
        // C-24: solo se guardan las horas distintas de fábrica (sin el campo, se conservan)
        $reloj = array_key_exists('reloj_horas', $datos)
            ? array_filter(array_map(fn ($v) => $v === null || $v === '' ? null : (int) $v, array_intersect_key((array) $datos['reloj_horas'], self::RELOJ_HORAS)),
                fn ($v, $k) => $v !== null && $v !== self::RELOJ_HORAS[$k], ARRAY_FILTER_USE_BOTH)
            : ($empresa->preferencias['novedades']['reloj_horas'] ?? []);
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['nombre_menu'] ?? '')));
        $nombre = $nombre === '' || $nombre === self::NOMBRE_OMISION ? null : $nombre;
        $categoria = $datos['categoria_hallazgo_pc'];

        DB::transaction(function () use ($empresa, $nombre, $categoria, $reloj, $deptoAccidentes) {
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
            if ($reloj === []) {
                unset($preferencias['novedades']['reloj_horas']);
                if (($preferencias['novedades'] ?? null) === []) {
                    unset($preferencias['novedades']);
                }
            } else {
                $preferencias['novedades']['reloj_horas'] = $reloj;
            }
            if ($deptoAccidentes !== null) {
                $preferencias['novedades']['departamento_accidentes'] = $deptoAccidentes;
            }
            $fresca?->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        });
        $empresa->refresh();
        request()->attributes->remove(self::MEMORIA.$empresa->id);

        $despues = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa), 'reloj_horas' => $this->relojHoras($empresa),
            'departamento_accidentes' => $this->departamentoAccidentes($empresa)];
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'novedades.configurado', $empresa, $antes, $despues);
        }
    }

    // ------------------------------------------- C-29: reclasificar lo de antes

    /**
     * Tickets que abrió un recorrido como «Siniestro Protección Civil» y que
     * nadie documentó como siniestro real (sin detalle, servicios, equipos ni
     * daños): son los que se pueden pasar a «Mantenimiento de equipo PC».
     *
     * @return Builder<Novedad>
     */
    public function hallazgosComoSiniestro(): Builder
    {
        return Novedad::query()->where('novedades.categoria', 'proteccion_civil')
            ->whereIn('novedades.id', RecorridoPc::query()->whereNotNull('novedad_id')->select('novedad_id'))
            ->whereDoesntHave('siniestro')->whereDoesntHave('siniestroServicios')->whereDoesntHave('siniestroEquipos')->whereDoesntHave('siniestroDanos');
    }

    /**
     * «Pasar los hallazgos anteriores a Mantenimiento de equipo PC» (opcional,
     * desde Configuración). Deja una nota en cada ticket y una sola entrada
     * en la auditoría con los folios, para poder revisarlo o deshacerlo.
     *
     * @return int tickets reclasificados
     */
    public function reclasificarHallazgos(User $actor, Empresa $empresa): int
    {
        $tickets = $this->hallazgosComoSiniestro()->where('novedades.empresa_id', $empresa->id)->get(['novedades.id', 'numero']);
        if ($tickets->isEmpty()) {
            return 0;
        }
        DB::transaction(function () use ($tickets, $actor, $empresa) {
            Novedad::whereIn('id', $tickets->pluck('id'))->where('categoria', 'proteccion_civil')
                ->update(['categoria' => 'mantenimiento_pc', 'actualizado_por' => $actor->id, 'updated_at' => now()]);
            foreach ($tickets as $t) {
                NovedadNota::create([
                    'empresa_id' => $empresa->id, 'novedad_id' => $t->id, 'tipo' => 'sistema', 'autor_nombre' => mb_substr($actor->name, 0, 150),
                    'texto' => 'Se reclasificó de «Siniestro Protección Civil» a «Mantenimiento de equipo PC»: lo abrió un recorrido y no se documentó como siniestro.',
                ]);
            }
        });
        $this->auditoria->auditar($actor, 'novedades.hallazgos_reclasificados', $empresa, ['categoria' => 'proteccion_civil'],
            ['categoria' => 'mantenimiento_pc', 'tickets' => $tickets->map(fn (Novedad $t) => $t->folio())->all()]);

        return $tickets->count();
    }
}
