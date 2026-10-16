<?php

namespace App\Services\Privacidad;

use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
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

        return $r;
    }

    /** @param  array<string, mixed>  $entrada */
    public function guardar(User $actor, Empresa $empresa, array $entrada): array
    {
        $reglas = ['conservacion_activa' => ['nullable', 'boolean'], 'arco_recordar_dias' => ['required', 'integer', 'min:1', 'max:15']];
        $mensajes = ['arco_recordar_dias.*' => '«Recordar cuando falten…» va de 1 a 15 días hábiles.'];
        foreach (self::DIAS as $clave => [$etiqueta, , $min, $max]) {
            $reglas[$clave] = ['required', 'integer', "min:{$min}", "max:{$max}"];
            $mensajes["{$clave}.*"] = "«{$etiqueta}» va de {$min} a {$max} días.";
        }
        $d = Validator::make($entrada, $reglas, $mensajes)->validate();
        $antes = $this->ajustes($empresa);
        $nuevos = ['conservacion_activa' => (bool) ($d['conservacion_activa'] ?? false), 'arco_recordar_dias' => (int) $d['arco_recordar_dias']];
        foreach (array_keys(self::DIAS) as $clave) {
            $nuevos[$clave] = (int) $d[$clave];
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
     * @return array{fotos_persona: int, fotos_identificacion: int, visitantes: int}
     */
    public function pendientes(Empresa $empresa): array
    {
        $a = $this->ajustes($empresa);

        return [
            'fotos_persona' => $this->conFoto('foto_persona', $a['fotos_persona_dias'])->count(),
            'fotos_identificacion' => $this->conFoto('foto_identificacion', $a['fotos_identificacion_dias'])->count(),
            'visitantes' => $this->visitantesVencidos($a['visitantes_dias'])->count(),
        ];
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
     * @return array{empresas: int, fotos_persona: int, fotos_identificacion: int, visitantes: int, arco_recordatorios: int, arco_vencidas: int, pendiente: bool}
     */
    public function ejecutar(bool $siToca = false): array
    {
        $total = ['empresas' => 0, 'fotos_persona' => 0, 'fotos_identificacion' => 0, 'visitantes' => 0, 'arco_recordatorios' => 0, 'arco_vencidas' => 0, 'pendiente' => false];
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
            if ($hecho['fotos_persona'] + $hecho['fotos_identificacion'] + $hecho['visitantes'] === 0) {
                continue;
            }
            $total['empresas']++;
            foreach (['fotos_persona', 'fotos_identificacion', 'visitantes'] as $k) {
                $total[$k] += $hecho[$k];
            }
            $total['pendiente'] = $total['pendiente'] || $hecho['pendiente'];
            Auditoria::create([
                'empresa_id' => $empresa->id, 'user_id' => null, 'evento' => 'privacidad.conservacion_aplicada',
                'auditable_type' => Empresa::class, 'auditable_id' => $empresa->id, 'antes' => array_intersect_key($ajustes, self::DIAS),
                'despues' => ['fotos_persona' => $hecho['fotos_persona'], 'fotos_identificacion' => $hecho['fotos_identificacion'], 'visitantes' => $hecho['visitantes']], 'ip' => null,
            ]);
        }
        // Solicitudes que llegaron a la plataforma y nadie ha canalizado
        $plataforma = app(SolicitudesArco::class)->revisarPlazos(null, self::RECORDAR_ARCO);
        $total['arco_recordatorios'] += $plataforma['recordatorios'];
        $total['arco_vencidas'] += $plataforma['vencidas'];

        if ($total['fotos_persona'] + $total['fotos_identificacion'] + $total['visitantes'] > 0) {
            $this->campana->avisarPlataforma(User::where('es_superadmin', true)->where('activo', true)->pluck('id'), 'privacidad_conservacion', [
                'titulo' => 'Conservación de datos: se quitaron datos vencidos',
                'texto' => sprintf('En %d empresa(s): %d foto(s) de personas, %d de identificaciones y los datos de %d registro(s) de visitantes. El detalle de cada empresa está en la Bitácora de auditoría.',
                    $total['empresas'], $total['fotos_persona'], $total['fotos_identificacion'], $total['visitantes']),
                'url' => route('auditoria.index'),
            ]);
        }
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => self::REVISION]);
        $registro->valor = ['fecha' => now()->setTimezone('America/Mexico_City')->toDateString(), 'en' => now()->toIso8601String()] + $total;
        $registro->save();

        return $total;
    }

    /**
     * Quita lo vencido de la empresa activa (el tenant ya está puesto).
     *
     * @param  array<string, int|bool>  $a
     * @return array{fotos_persona: int, fotos_identificacion: int, visitantes: int, pendiente: bool}
     */
    private function aplicar(array $a): array
    {
        $r = ['fotos_persona' => 0, 'fotos_identificacion' => 0, 'visitantes' => 0, 'pendiente' => false];
        foreach (['visitantes' => null, 'fotos_persona' => 'foto_persona', 'fotos_identificacion' => 'foto_identificacion'] as $tipo => $campo) {
            $consulta = $campo === null ? $this->visitantesVencidos((int) $a['visitantes_dias']) : $this->conFoto($campo, (int) $a[$tipo.'_dias']);
            $lote = $consulta->orderBy('id')->limit(self::LOTE)->get();
            foreach ($lote as $acceso) {
                $campo === null ? $this->datos->anonimizarAcceso($acceso, self::TEXTO_ELIMINADO) : $this->datos->quitarFoto($acceso, $campo);
                $r[$tipo]++;
            }
            $r['pendiente'] = $r['pendiente'] || $lote->count() === self::LOTE;
        }

        return $r;
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
