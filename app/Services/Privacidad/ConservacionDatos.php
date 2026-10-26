<?php

namespace App\Services\Privacidad;

use App\Models\Acceso;
use App\Models\AccidenteColaborador;
use App\Models\AccidenteHuesped;
use App\Models\Auditoria;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundReportePerdida;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\NovedadTestigo;
use App\Models\Reserva;
use App\Models\RoboDetalle;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * Conservación de datos personales (LFPDPPP; docs/tecnico/privacidad.md).
 *
 * Por empresa (preferencias.privacidad), días que se conservan:
 *  - fotos de la persona tomadas en la caseta (90);
 *  - fotos de la identificación (90);
 *  - datos de visitantes externos (visita, huésped, proveedor, contratista)
 *    en registros de la bitácora ya cerrados (365): el registro se conserva
 *    con «Datos eliminados por conservación» y sin foto, identificación,
 *    placas ni notas.
 *  - C-37 (X-01), con su marca datos_eliminados_en (el registro se conserva):
 *    Lost & Found (quien reportó una pérdida o recibió un objeto, con su
 *    firma; 365), novedades y accidentes cerrados (involucrados, testigos,
 *    sospechoso, huésped accidentado y sus datos médicos; 1825; nunca los
 *    accidentes de colaborador), reservas terminadas (datos del cliente; 1825)
 *    y transporte (firma del taxista; 1825). En las empresas que ya existían
 *    se aplican cuando el administrador guarda la tarjeta (plazos_ampliados).
 *  - candidatos no contratados: NO se duplica; es el plazo «Borrar» que ya
 *    tienen Candidatos → Documentos y plazos (12 meses, con aviso 15 días
 *    antes y «Conservar 6 meses más»). Aquí solo se muestra con su enlace.
 *
 * La tarea diaria (plataforma:privacidad --si-toca, en proceso) quita lo
 * vencido de las empresas que la tienen ENCENDIDA. Las empresas que ya
 * existían la tienen APAGADA hasta que el administrador la confirme (nada se
 * borra sin aprobación); las nuevas la traen encendida. También revisa los
 * plazos de las solicitudes ARCO. Resumen a la campana del Super
 * Administrador y a la Bitácora de auditoría.
 */
class ConservacionDatos
{
    public const CLAVE = 'privacidad';

    public const TEXTO_ELIMINADO = 'Datos eliminados por conservación';

    /** clave => [etiqueta, por omisión, mínimo, máximo] */
    public const DIAS = [
        'fotos_persona_dias' => ['Fotos de la persona (caseta)', 90, 7, 3650],
        'fotos_identificacion_dias' => ['Fotos de la identificación (caseta)', 90, 7, 3650],
        'visitantes_dias' => ['Datos de visitantes externos en registros cerrados', 365, 30, 3650],
        // C-37 (X-01): los demás módulos con datos personales (InventarioDatosPersonales)
        'lost_found_dias' => ['Lost & Found: quien reportó una pérdida o recibió un objeto', 365, 30, 3650],
        'incidentes_dias' => ['Novedades y accidentes cerrados: huéspedes, testigos, sospechosos y datos médicos', 1825, 365, 3650],
        'reservas_dias' => ['Reservas terminadas: datos del cliente', 1825, 365, 3650],
        'transporte_dias' => ['Transporte: firma del taxista en los vales', 1825, 365, 3650],
    ];

    /** Plazos que llegaron con C-37: en las empresas que ya existían se aplican cuando el administrador los confirma. */
    public const AMPLIADOS = ['lost_found_dias', 'incidentes_dias', 'reservas_dias', 'transporte_dias'];

    /** Lo que se cuenta y se quita en cada corrida: clave del resumen => clave de días. */
    public const QUITA = [
        'fotos_persona' => 'fotos_persona_dias', 'fotos_identificacion' => 'fotos_identificacion_dias', 'visitantes' => 'visitantes_dias',
        'lost_found' => 'lost_found_dias', 'incidentes' => 'incidentes_dias', 'reservas' => 'reservas_dias', 'transporte' => 'transporte_dias',
    ];

    public const RECORDAR_ARCO = 5;

    /** Registros por tipo y por empresa en cada corrida (lo que falte sigue en la siguiente). */
    public const LOTE = 500;

    private const REVISION = 'privacidad_revision';

    public function __construct(
        private readonly Tenant $tenant,
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $campana,
        private readonly DatosDePersona $datos,
    ) {}

    /**
     * @return array{activa: bool, fotos_persona_dias: int, fotos_identificacion_dias: int, visitantes_dias: int, arco_recordar_dias: int}
     */
    public function ajustes(Empresa $empresa): array
    {
        $p = $empresa->preferencias[self::CLAVE] ?? [];
        $p = is_array($p) ? $p : [];
        $r = ['activa' => (bool) ($p['conservacion_activa'] ?? true)];
        foreach (self::DIAS as $clave => [, $omision]) {
            $r[$clave] = (int) ($p[$clave] ?? $omision);
        }
        $r['arco_recordar_dias'] = (int) ($p['arco_recordar_dias'] ?? self::RECORDAR_ARCO);
        // Empresas que ya existían: los plazos nuevos no se aplican hasta que se confirmen al guardar
        $r['ampliados'] = (bool) ($p['plazos_ampliados'] ?? true);

        return $r;
    }

    /** @param  array<string, mixed>  $entrada */
    public function guardar(User $actor, Empresa $empresa, array $entrada): array
    {
        $reglas = ['conservacion_activa' => ['nullable', 'boolean'], 'arco_recordar_dias' => ['required', 'integer', 'min:1', 'max:15']];
        $mensajes = ['arco_recordar_dias.*' => '«Recordar cuando falten…» va de 1 a 15 días hábiles.'];
        foreach (self::DIAS as $clave => [$etiqueta, , $min, $max]) {
            // Los plazos de C-37 son opcionales en la petición (un formulario anterior no los trae: se conservan)
            $reglas[$clave] = [in_array($clave, self::AMPLIADOS, true) ? 'nullable' : 'required', 'integer', "min:{$min}", "max:{$max}"];
            $mensajes["{$clave}.*"] = "«{$etiqueta}» va de {$min} a {$max} días.";
        }
        $d = Validator::make($entrada, $reglas, $mensajes)->validate();
        $antes = $this->ajustes($empresa);
        $nuevos = ['conservacion_activa' => (bool) ($d['conservacion_activa'] ?? false), 'arco_recordar_dias' => (int) $d['arco_recordar_dias']];
        foreach (array_keys(self::DIAS) as $clave) {
            $nuevos[$clave] = (int) ($d[$clave] ?? $antes[$clave]);
        }
        // Guardar con los plazos nuevos a la vista los confirma
        if (array_intersect(self::AMPLIADOS, array_keys(array_filter($d, fn ($v) => $v !== null))) !== []) {
            $nuevos['plazos_ampliados'] = true;
        }
        $preferencias = $empresa->preferencias ?? [];
        $preferencias[self::CLAVE] = array_merge(is_array($preferencias[self::CLAVE] ?? null) ? $preferencias[self::CLAVE] : [], $nuevos);
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $despues = $this->ajustes($empresa);
        $this->auditoria->auditar($actor, 'privacidad.conservacion', $empresa, $antes, $despues);

        return $despues;
    }

    /**
     * Lo que pinta Configuración → Privacidad (null si el rol no lo ve).
     *
     * @return array<string, mixed>|null
     */
    public function paraConfiguracion(User $actor, ?Empresa $empresa): ?array
    {
        if ($empresa === null || ! $actor->can(SolicitudesArco::PERMISO) || ! app(Autorizador::class)->alcanceDeEmpresa($actor, SolicitudesArco::PERMISO)) {
            return null;
        }
        $avisos = app(AvisosPrivacidad::class);

        return [
            'ajustes' => $this->ajustes($empresa),
            'pendientes' => $this->tenant->conEmpresa($empresa->id, fn () => $this->pendientes($empresa)),
            'candidatos_meses' => app(PlazosCandidatos::class)->plazos($empresa)['borrar_meses'] ?? PlazosCandidatos::DEFECTO['borrar_meses'],
            'candidatos_url' => $actor->can('candidatos.configurar') ? route('candidatos.configuracion') : null,
            'vigente' => $avisos->vigente($empresa),
            'historial' => $avisos->historial($empresa->id),
            'texto' => $avisos->textoActual($empresa),
            'url_publica' => route('privacidad.aviso', $avisos->codigoPublico($empresa)),
            'arco' => app(SolicitudesArco::class)->porAtender($empresa->id),
            'ultima' => ConfiguracionPlataforma::where('clave', self::REVISION)->value('valor'),
        ];
    }

    /**
     * Cuánto se quitaría hoy con los días guardados (para que el
     * administrador sepa qué confirma al encender la tarea).
     *
     * @return array<string, int> clave de QUITA => registros
     */
    public function pendientes(Empresa $empresa): array
    {
        $a = $this->ajustes($empresa);
        $r = [];
        foreach (self::QUITA as $tipo => $clave) {
            $r[$tipo] = array_sum(array_map(fn ($q) => $q->count(), $this->vencidos($tipo, (int) $a[$clave])));
        }

        return $r;
    }

    /**
     * Consultas de lo vencido de cada tipo (en la empresa activa).
     *
     * @return list<Builder>
     */
    private function vencidos(string $tipo, int $dias): array
    {
        $limite = now()->subDays($dias);

        return match ($tipo) {
            'fotos_persona' => [$this->conFoto('foto_persona', $dias)],
            'fotos_identificacion' => [$this->conFoto('foto_identificacion', $dias)],
            'visitantes' => [$this->visitantesVencidos($dias)],
            'lost_found' => [
                LostFoundReportePerdida::query()->whereNull('datos_eliminados_en')->where('created_at', '<=', $limite),
                LostFoundEntrega::query()->whereNull('datos_eliminados_en')->where('created_at', '<=', $limite),
            ],
            // Tickets cerrados (no los de un accidente de colaborador: relación laboral)
            'incidentes' => [Novedad::query()->whereNull('datos_eliminados_en')->where('estatus', Novedad::RESUELTO)
                ->where(fn ($q) => $q->where('cerrado_en', '<=', $limite)->orWhere(fn ($w) => $w->whereNull('cerrado_en')->where('updated_at', '<=', $limite)))
                ->whereNotIn('id', AccidenteColaborador::query()->select('novedad_id'))
                ->where(fn ($q) => $q->whereNotNull('involucrados')
                    ->orWhereIn('id', AccidenteHuesped::query()->select('novedad_id'))
                    ->orWhereIn('id', NovedadTestigo::query()->select('novedad_id'))
                    ->orWhereIn('id', RoboDetalle::query()->whereNotNull('descripcion_sospechoso')->select('novedad_id')))],
            'reservas' => [Reserva::query()->whereNull('datos_eliminados_en')->where('fin', '<=', $limite)
                ->where(fn ($q) => $q->whereNotNull('cliente_nombre')->orWhereNotNull('cliente_correo')->orWhereNotNull('cliente_telefono'))],
            'transporte' => [MovimientoTransporte::query()->whereNull('datos_eliminados_en')->whereNotNull('firma_taxista')->where('fecha', '<=', $limite->toDateString())],
            default => [],
        };
    }

    // ------------------------------------------------------- Tarea diaria

    public function toca(): bool
    {
        $ahora = now()->setTimezone('America/Mexico_City');
        if ((int) $ahora->format('G') < 2) {
            return false;
        }
        $ultima = ConfiguracionPlataforma::where('clave', self::REVISION)->value('valor');

        return ($ultima['fecha'] ?? null) !== $ahora->toDateString() || (bool) ($ultima['pendiente'] ?? false);
    }

    /**
     * @return array<string, int|bool> empresas, una cuenta por cada clave de QUITA, arco_recordatorios, arco_vencidas y pendiente
     */
    public function ejecutar(bool $siToca = false): array
    {
        $total = ['empresas' => 0] + array_fill_keys(array_keys(self::QUITA), 0) + ['arco_recordatorios' => 0, 'arco_vencidas' => 0, 'pendiente' => false];
        if ($siToca && ! $this->toca()) {
            return $total;
        }
        foreach (Empresa::query()->where('activo', true)->orderBy('id')->get() as $empresa) {
            $ajustes = $this->ajustes($empresa);
            $arco = app(SolicitudesArco::class)->revisarPlazos($empresa, $ajustes['arco_recordar_dias']);
            $total['arco_recordatorios'] += $arco['recordatorios'];
            $total['arco_vencidas'] += $arco['vencidas'];
            if (! $ajustes['activa']) {
                continue; // apagada: nada se borra sin la aprobación del administrador
            }
            $hecho = $this->tenant->conEmpresa($empresa->id, fn () => $this->aplicar($ajustes));
            $cuentas = array_intersect_key($hecho, self::QUITA);
            if (array_sum($cuentas) === 0) {
                continue;
            }
            $total['empresas']++;
            foreach ($cuentas as $k => $n) {
                $total[$k] += $n;
            }
            $total['pendiente'] = $total['pendiente'] || $hecho['pendiente'];
            Auditoria::create([
                'empresa_id' => $empresa->id, 'user_id' => null, 'evento' => 'privacidad.conservacion_aplicada',
                'auditable_type' => Empresa::class, 'auditable_id' => $empresa->id, 'antes' => array_intersect_key($ajustes, self::DIAS),
                'despues' => array_filter($cuentas), 'ip' => null,
            ]);
        }
        // Solicitudes que llegaron a la plataforma y nadie ha canalizado
        $plataforma = app(SolicitudesArco::class)->revisarPlazos(null, self::RECORDAR_ARCO);
        $total['arco_recordatorios'] += $plataforma['recordatorios'];
        $total['arco_vencidas'] += $plataforma['vencidas'];

        if (array_sum(array_intersect_key($total, self::QUITA)) > 0) {
            $otros = $total['lost_found'] + $total['incidentes'] + $total['reservas'] + $total['transporte'];
            $this->campana->avisarPlataforma(User::where('es_superadmin', true)->where('activo', true)->pluck('id'), 'privacidad_conservacion', [
                'titulo' => 'Conservación de datos: se quitaron datos vencidos',
                'texto' => sprintf('En %d empresa(s): %d foto(s) de personas, %d de identificaciones, los datos de %d registro(s) de visitantes%s. El detalle de cada empresa está en la Bitácora de auditoría.',
                    $total['empresas'], $total['fotos_persona'], $total['fotos_identificacion'], $total['visitantes'],
                    $otros > 0 ? sprintf(' y %d de Lost & Found, novedades, reservas o transporte', $otros) : ''),
                'url' => route('auditoria.index'),
            ]);
        }
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => self::REVISION]);
        $registro->valor = ['fecha' => now()->setTimezone('America/Mexico_City')->toDateString(), 'en' => now()->toIso8601String()] + $total;
        $registro->save();

        return $total;
    }

    /**
     * Quita lo vencido de la empresa activa (el tenant ya está puesto). Los
     * plazos de C-37 solo si la empresa ya los confirmó.
     *
     * @param  array<string, int|bool>  $a
     * @return array<string, int|bool> una cuenta por clave de QUITA y «pendiente»
     */
    private function aplicar(array $a): array
    {
        $r = array_fill_keys(array_keys(self::QUITA), 0) + ['pendiente' => false];
        foreach (self::QUITA as $tipo => $clave) {
            if (in_array($clave, self::AMPLIADOS, true) && ! $a['ampliados']) {
                continue;
            }
            foreach ($this->vencidos($tipo, (int) $a[$clave]) as $consulta) {
                $lote = $consulta->orderBy('id')->limit(self::LOTE)->get();
                foreach ($lote as $registro) {
                    $this->quitar($tipo, $registro);
                    $r[$tipo]++;
                }
                $r['pendiente'] = $r['pendiente'] || $lote->count() === self::LOTE;
            }
        }

        return $r;
    }

    private function quitar(string $tipo, Model $registro): void
    {
        $texto = self::TEXTO_ELIMINADO;
        match (true) {
            $tipo === 'visitantes' => $this->datos->anonimizarAcceso($registro, $texto),
            $tipo === 'fotos_persona' => $this->datos->quitarFoto($registro, 'foto_persona'),
            $tipo === 'fotos_identificacion' => $this->datos->quitarFoto($registro, 'foto_identificacion'),
            $registro instanceof LostFoundReportePerdida => $this->datos->anonimizarReportePerdida($registro, $texto),
            $registro instanceof LostFoundEntrega => $this->datos->anonimizarEntrega($registro, $texto),
            $registro instanceof Novedad => $this->datos->anonimizarIncidente($registro, $texto),
            $registro instanceof Reserva => $this->datos->anonimizarReserva($registro, $texto),
            $registro instanceof MovimientoTransporte => $this->datos->anonimizarTransporte($registro, $texto, '', true),
            default => null,
        };
    }

    private function conFoto(string $campo, int $dias)
    {
        return Acceso::query()->whereNotNull($campo)->whereNotIn('estado', Acceso::ESTADOS_ABIERTOS)
            ->where('entrada_at', '<=', now()->subDays($dias));
    }

    private function visitantesVencidos(int $dias)
    {
        $limite = now()->subDays($dias);

        return Acceso::query()->whereIn('tipo', DatosDePersona::ACCESOS_EXTERNOS)->where('estado', 'finalizado')->whereNull('datos_eliminados_en')
            ->where(fn ($q) => $q->where('salida_at', '<=', $limite)->orWhere(fn ($w) => $w->whereNull('salida_at')->where('entrada_at', '<=', $limite)));
    }

    /** ¿Hay solicitudes ARCO abiertas de la plataforma (sin empresa)? Para el Super Administrador. */
    public static function arcoDePlataforma(): int
    {
        return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereNull('empresa_id')
            ->whereIn('estado', [SolicitudArco::POR_VERIFICAR, SolicitudArco::PENDIENTE])->count();
    }
}
