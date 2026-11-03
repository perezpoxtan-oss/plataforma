<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\AsistenciaJustificacion;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Turno;
use App\Services\Asistencia\AjustesAsistencia;
use App\Services\Asistencia\AlcanceAsistencia;
use App\Services\Asistencia\CalculoAsistencia;
use App\Services\Asistencia\JustificacionesAsistencia;
use App\Services\Permisos\Autorizador;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\ZonasHorarias;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recursos Humanos → Asistencia (módulo opcional, ADR-0024):
 *  - «Asistencia del día»: quién llegó, quién falta y los retardos, con
 *    filtros por sede y departamento según el alcance.
 *  - «Reporte por periodo»: totales por colaborador y su detalle día por
 *    día; «Exportar» a Excel (CSV) para la nómina externa (la plataforma
 *    no calcula nómina).
 *  - Justificar una falta, retardo o salida anticipada (Guardar).
 *  - Avanzado: tolerancia de retardo y aviso de faltas al jefe.
 * Con el módulo apagado nada de esto existe para la empresa (403/404).
 */
class AsistenciaController extends Controller
{
    /** Días máximos de un reporte (dos quincenas largas). */
    public const MAX_DIAS = 62;

    private const ORDEN = ['sin_llegar' => 0, 'falta' => 1, 'asistio' => 3, 'por_llegar' => 4, 'descanso' => 5, 'antes_de_alta' => 6, 'sin_turno' => 7];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AlcanceAsistencia $alcance,
        private readonly CalculoAsistencia $calculo,
        private readonly AjustesAsistencia $ajustes,
        private readonly HoraLocal $hora,
    ) {}

    // ------------------------------------------------------- Asistencia del día

    public function index(Request $request): View
    {
        Gate::authorize(AlcanceAsistencia::VER);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.asistencia.dia', ['sinEmpresa' => true]);
        }
        $this->exigirEncendido($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $empresa = Empresa::find($empresaId);
            $f = $this->filtros($request);
            [$zona, $hoy] = $this->zonaYHoy($f['sedes'], $f['sede']);
            $fecha = $this->fecha($request->query('fecha'), $zona, $hoy);
            $colaboradores = $this->colaboradores($actor, $f['sede'], $f['departamento'], soloActivos: true);
            $dias = $this->calculo->dias($colaboradores, $fecha, $fecha, $empresa);
            $filas = $colaboradores->map(fn (Colaborador $c) => ['colaborador' => $c, 'dia' => $dias[$c->id][0]])
                ->sortBy(fn ($f) => sprintf('%d|%s', $f['dia']['retardo'] > 0 && $f['dia']['estado'] === 'asistio' ? 2 : self::ORDEN[$f['dia']['estado']] ?? 9,
                    mb_strtolower($f['colaborador']->nombreCompleto())))->values();
            $ver = in_array($v = Entrada::texto($request->query('ver')), ['faltan', 'retardos', 'llegaron'], true) ? $v : null;
            $cuentas = [
                'llegaron' => $filas->where('dia.estado', 'asistio')->count(),
                'retardos' => $filas->filter(fn ($f) => $f['dia']['retardo'] > 0)->count(),
                'faltan' => $filas->whereIn('dia.estado', ['sin_llegar', 'falta'])->count(),
                'por_llegar' => $filas->where('dia.estado', 'por_llegar')->count(),
                'descanso' => $filas->where('dia.estado', 'descanso')->count(),
                'sin_turno' => $filas->where('dia.estado', 'sin_turno')->count(),
            ];
            $visibles = $filas->filter(fn ($f) => match ($ver) {
                'faltan' => in_array($f['dia']['estado'], ['sin_llegar', 'falta'], true),
                'retardos' => $f['dia']['retardo'] > 0,
                'llegaron' => $f['dia']['estado'] === 'asistio',
                default => true,
            })->values();

            return view('rh.asistencia.dia', $this->comunes($actor, $empresa, $f, $zona, $hoy) + [
                'fecha' => $fecha, 'fechaLegible' => self::legible($fecha), 'filas' => $visibles, 'cuentas' => $cuentas, 'ver' => $ver,
                'total' => $filas->count(),
            ]);
        });
    }

    // ------------------------------------------------------- Reporte por periodo

    public function periodo(Request $request): View
    {
        Gate::authorize(AlcanceAsistencia::VER);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $this->exigirEncendido($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $empresa = Empresa::find($empresaId);
            $d = $this->datosPeriodo($request, $actor, $empresa);
            $detalle = null;
            if ($d['colaborador'] !== null) {
                $detalle = ['colaborador' => $d['colaborador'], 'dias' => array_reverse($d['dias'][$d['colaborador']->id] ?? []),
                    'resumen' => CalculoAsistencia::resumen($d['dias'][$d['colaborador']->id] ?? [])];
            }

            return view('rh.asistencia.periodo', $this->comunes($actor, $empresa, $d['f'], $d['zona'], $d['hoy']) + [
                'desde' => $d['desde'], 'hasta' => $d['hasta'], 'periodoLegible' => self::legible($d['desde']).' al '.self::legible($d['hasta']),
                'resumenes' => $d['colaboradores']->map(fn (Colaborador $c) => ['colaborador' => $c, 'resumen' => CalculoAsistencia::resumen($d['dias'][$c->id] ?? [])])->values(),
                'detalle' => $detalle, 'colaboradorId' => $d['colaborador']?->id,
            ]);
        });
    }

    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize(AlcanceAsistencia::EXPORTAR);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $this->exigirEncendido($empresaId);
        $d = $this->tenant->conEmpresa($empresaId, fn () => $this->datosPeriodo($request, $actor, Empresa::find($empresaId), AlcanceAsistencia::EXPORTAR));
        $lista = $d['colaborador'] !== null ? collect([$d['colaborador']]) : $d['colaboradores'];

        return response()->streamDownload(function () use ($d, $lista) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Núm. empleado', 'Colaborador', 'Departamento', 'Sede', 'Fecha', 'Día', 'Turno', 'Entrada programada', 'Salida programada',
                'Entrada', 'Salida', 'Retardo (min)', 'Salida anticipada (min)', 'Horas trabajadas', 'Estado', 'Justificación']);
            foreach ($lista as $c) {
                foreach ($d['dias'][$c->id] ?? [] as $dia) {
                    if ($dia['estado'] === 'antes_de_alta') {
                        continue;
                    }
                    Csv::fila($salida, [
                        $c->num_empleado, $c->nombreCompleto(), $c->departamento?->nombre, $c->sede?->nombre ?? 'Corporativo',
                        CarbonImmutable::parse($dia['fecha'])->format('d/m/Y'), Turno::DIAS[$dia['dia']], $dia['turno'],
                        $dia['inicio']?->format('H:i'), $dia['fin']?->format('d/m/Y') !== CarbonImmutable::parse($dia['fecha'])->format('d/m/Y') ? $dia['fin']?->format('d/m/Y H:i') : $dia['fin']?->format('H:i'),
                        $dia['entrada']?->format('d/m/Y H:i'), $dia['sin_salida'] ? 'Sin salida registrada' : $dia['salida']?->format('d/m/Y H:i'),
                        $dia['retardo'] ?: '', $dia['anticipada'] ?: '', $dia['trabajados'] > 0 ? CalculoAsistencia::horas($dia['trabajados']) : '',
                        self::textoEstado($dia), self::textoJustificaciones($dia),
                    ]);
                }
            }
            fclose($salida);
        }, 'asistencia-'.$d['desde'].'-al-'.$d['hasta'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------- Justificar y ajustes

    public function justificar(Request $request, int $colaborador): RedirectResponse
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        // Un colaborador de otra empresa responde igual que uno que no existe (antes del permiso: no se revela nada)
        abort_unless($this->tenant->conEmpresa($empresaId, fn () => Colaborador::whereKey($colaborador)->exists()), 404);
        Gate::authorize(AlcanceAsistencia::JUSTIFICAR);
        $this->exigirEncendido($empresaId);

        $fecha = Entrada::texto($request->input('fecha'));
        $nombre = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $colaborador, $fecha) {
            abort_unless($this->alcance->puedeVerColaborador($actor, $colaborador, AlcanceAsistencia::JUSTIFICAR), 404);
            $modelo = Colaborador::with('sede:id,zona_horaria')->findOrFail($colaborador);
            $zona = $modelo->sede?->zona_horaria ?: $this->hora->zona();
            app(JustificacionesAsistencia::class)->guardar($actor, $modelo, $fecha, CarbonImmutable::now($zona)->format('Y-m-d'), $request->all());

            return $modelo->nombreCompleto();
        });

        $regreso = $this->regreso($request, 'asistencia.index');

        // Regla 5: el aviso propone el siguiente paso natural
        return redirect()->to($regreso)->with('ok', "Justificación guardada para {$nombre}. Sigue con el reporte del periodo o justifica a otra persona.");
    }

    public function ajustes(Request $request): RedirectResponse
    {
        Gate::authorize(AlcanceAsistencia::CONFIGURAR);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $this->exigirEncendido($empresaId);
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($actor, AlcanceAsistencia::CONFIGURAR), 403);
        $this->ajustes->guardar($actor, Empresa::findOrFail($empresaId), $request->all());

        return redirect()->route('asistencia.index')->with('ok', 'Ajustes de asistencia guardados. Revisa la asistencia de hoy con la nueva tolerancia.');
    }

    // ------------------------------------------------------- Apoyo

    private function exigirEncendido(int $empresaId): void
    {
        abort_unless($this->alcance->encendido($empresaId), 403, 'Asistencia no viene en el plan de tu empresa.');
    }

    /** @return array{sedes: Collection, departamentos: Collection, sede: ?int, departamento: ?int} */
    private function filtros(Request $request): array
    {
        $listas = $this->alcance->filtros($request->user());
        $sede = (int) Entrada::texto($request->query('sede'));
        $departamento = (int) Entrada::texto($request->query('departamento'));

        return $listas + [
            'sede' => $listas['sedes']->contains('id', $sede) ? $sede : null,
            'departamento' => $listas['departamentos']->contains('id', $departamento) ? $departamento : null,
        ];
    }

    /** @return array{0: string, 1: string} zona y fecha de hoy en esa zona */
    private function zonaYHoy(Collection $sedes, ?int $sede): array
    {
        $elegida = $sede !== null ? $sedes->firstWhere('id', $sede) : ($sedes->count() === 1 ? $sedes->first() : null);
        $zona = $elegida?->zona_horaria ?: $this->hora->zona();

        return [$zona, CarbonImmutable::now($zona)->format('Y-m-d')];
    }

    private function fecha(mixed $valor, string $zona, string $hoy, ?string $omision = null): string
    {
        $texto = Entrada::texto($valor);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
            $f = CarbonImmutable::createFromFormat('!Y-m-d', $texto, $zona);
            if ($f !== false && $f->format('Y-m-d') === $texto && $texto <= $hoy) {
                return $texto;
            }
        }

        return $omision ?? $hoy;
    }

    /** @return Collection<int, Colaborador> */
    private function colaboradores($actor, ?int $sede, ?int $departamento, bool $soloActivos, string $permiso = AlcanceAsistencia::VER): Collection
    {
        return $this->alcance->consulta($actor, $permiso)
            ->when($soloActivos, fn ($q) => $q->where('colaboradores.activo', true))
            ->when($sede !== null, fn ($q) => $q->where('colaboradores.sede_id', $sede))
            ->when($departamento !== null, fn ($q) => $q->where('colaboradores.departamento_id', $departamento))
            ->with(['turno', 'sede:id,nombre,zona_horaria', 'departamento:id,nombre'])
            ->orderBy('nombre')->orderBy('apellido_paterno')->limit(2000)->get();
    }

    /** @return array<string, mixed> */
    private function datosPeriodo(Request $request, $actor, ?Empresa $empresa, string $permiso = AlcanceAsistencia::VER): array
    {
        $f = $this->filtros($request);
        [$zona, $hoy] = $this->zonaYHoy($f['sedes'], $f['sede']);
        $hoyC = CarbonImmutable::createFromFormat('!Y-m-d', $hoy, $zona);
        // De fábrica, la quincena en curso
        $inicioQuincena = $hoyC->day <= 15 ? $hoyC->startOfMonth() : $hoyC->setDay(16);
        $desde = $this->fecha($request->query('desde'), $zona, $hoy, $inicioQuincena->format('Y-m-d'));
        $hasta = $this->fecha($request->query('hasta'), $zona, $hoy);
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $minimo = CarbonImmutable::createFromFormat('!Y-m-d', $hasta, $zona)->subDays(self::MAX_DIAS - 1)->format('Y-m-d');
        $desde = max($desde, $minimo);

        $colaboradores = $this->colaboradores($actor, $f['sede'], $f['departamento'], soloActivos: false, permiso: $permiso)
            ->filter(fn (Colaborador $c) => $c->activo || ($c->updated_at && $c->updated_at->format('Y-m-d') >= $desde))->values();
        $elegido = (int) Entrada::texto($request->query('colaborador'));
        $colaborador = $elegido > 0 ? $colaboradores->firstWhere('id', $elegido) : null;
        // Exportar a una sola persona calcula solo la suya; la pantalla necesita los totales de todos
        $calcular = $colaborador !== null && $permiso === AlcanceAsistencia::EXPORTAR ? collect([$colaborador]) : $colaboradores;
        $dias = $this->calculo->dias($calcular, $desde, $hasta, $empresa);

        return ['f' => $f, 'zona' => $zona, 'hoy' => $hoy, 'desde' => $desde, 'hasta' => $hasta, 'colaboradores' => $colaboradores, 'colaborador' => $colaborador, 'dias' => $dias];
    }

    /** @return array<string, mixed> */
    private function comunes($actor, ?Empresa $empresa, array $f, string $zona, string $hoy): array
    {
        $configura = $actor->can(AlcanceAsistencia::CONFIGURAR)
            && app(Autorizador::class)->alcanceDeEmpresa($actor, AlcanceAsistencia::CONFIGURAR);

        return [
            'sinEmpresa' => false, 'empresaNombre' => $empresa?->nombre_comercial,
            'sedes' => $f['sedes'], 'departamentos' => $f['departamentos'], 'sede' => $f['sede'], 'departamento' => $f['departamento'],
            'hoy' => $hoy, 'zonaTexto' => ZonasHorarias::etiqueta($zona),
            'ajustes' => $this->ajustes->ajustes($empresa), 'configura' => $configura,
            'puedeJustificar' => $actor->can(AlcanceAsistencia::JUSTIFICAR), 'puedeExportar' => $actor->can(AlcanceAsistencia::EXPORTAR),
            'motivos' => AsistenciaJustificacion::MOTIVOS, 'tipos' => AsistenciaJustificacion::TIPOS,
            'sinTurno' => Colaborador::query()->whereIn('id', $this->alcance->consulta($actor)->select('colaboradores.id'))
                ->where('activo', true)->whereNull('turno_id')->count(),
            'verColaboradores' => $actor->can('colaboradores.editar'),
        ];
    }

    private function regreso(Request $request, string $omision): string
    {
        $volver = Entrada::texto($request->input('_volver'));

        return str_starts_with($volver, '/asistencia') && ! str_contains($volver, '//') ? $volver : route($omision);
    }

    // ------------------------------------------------------- Textos (vista y Excel)

    public static function legible(string $fecha): string
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $f = CarbonImmutable::parse($fecha);

        return mb_strtolower(Turno::DIAS[$f->dayOfWeekIso]).' '.$f->day.' de '.$meses[$f->month].' de '.$f->year;
    }

    /** @param array<string, mixed> $dia */
    public static function textoEstado(array $dia): string
    {
        $texto = CalculoAsistencia::ESTADOS[$dia['estado']] ?? $dia['estado'];
        if ($dia['estado'] === 'asistio') {
            $partes = array_filter([$dia['retardo'] > 0 ? 'retardo de '.$dia['retardo'].' min' : null,
                $dia['anticipada'] > 0 ? 'salió '.$dia['anticipada'].' min antes' : null, $dia['sin_salida'] ? 'sin salida registrada' : null]);
            $texto .= $partes !== [] ? ' · '.implode(' · ', $partes) : '';
        }
        if ($dia['estado'] === 'descanso' && $dia['entrada'] !== null) {
            $texto .= ' · vino a trabajar';
        }

        return $texto;
    }

    /** @param array<string, mixed> $dia */
    public static function textoJustificaciones(array $dia): string
    {
        return collect($dia['justificaciones'])->map(fn ($j, $tipo) => AsistenciaJustificacion::TIPOS[$tipo].': '.$j['texto'].($j['nota'] ? ' ('.$j['nota'].')' : ''))
            ->implode(' · ');
    }
}
