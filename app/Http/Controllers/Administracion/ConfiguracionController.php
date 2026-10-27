<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Mail\CorreoDePrueba;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\LostFoundUmbral;
use App\Models\User;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Candidatos\DocumentosParaContratar;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Lector\ImpresionDesdePadron;
use App\Services\Lector\PlantillasEtiquetas;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Novedades\ArchivoLostFound;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\SuperAdministradores;
use App\Services\Plataforma\VersionPhp;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Respaldos\Respaldos;
use App\Support\AjustesCaseta;
use App\Support\CorreoPlataforma;
use App\Support\Entrada;
use App\Support\GruposAvisos;
use App\Support\NombresMenu;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\ZonasHorarias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Configuración, ordenada por secciones con índice (docs/tecnico/configuracion.md
 * y el inventario completo en docs/tecnico/configuracion-inventario.md):
 * General, Caseta y accesos, Recepción y RR. HH., Novedades, Activos,
 * Transporte, Pases de salida, Avisos por correo, Cuentas y seguridad y
 * Plataforma (solo Super Administrador: correo, respaldos y enlaces).
 *  - Empresa (configuracion.editar con alcance de empresa): avisos, ajustes
 *    de caseta y activos, nombres del menú, bitácora, auditoría.
 *  - Lost & Found: días de resguardo (lost_found.configurar con alcance de empresa).
 *  - Lo que vive en otro módulo se muestra con su enlace «Configurar en …».
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly CorreoPlataforma $correo,
        private readonly Respaldos $respaldos,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly AdministradorRoles $auditoria,
        private readonly Autorizador $autorizador,
        private readonly ArchivoLostFound $lostFound,
        private readonly Tenant $tenant,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        // Entra quien ve Configuración o quien configura los días de resguardo de Lost & Found
        $configuraLostFound = $this->lostFound->puedeConfigurar($actor);
        abort_unless($actor->can('configuracion.ver') || $configuraLostFound, 403);
        $empresaId = $this->empresa->id($actor);
        $correo = $this->correo->datos();

        return view('administracion.configuracion.index', [
            'esSuperadmin' => $actor->es_superadmin,
            'correo' => array_merge($correo, ['contrasena' => null]),
            'tieneContrasena' => ! empty($correo['contrasena']),
            'correoListo' => $this->correo->configurado(),
            'cifrados' => CorreoPlataforma::CIFRADOS,
            'respaldos' => $actor->es_superadmin ? $this->respaldos->listar() : collect(),
            // Versión de PHP del servidor: solo el Super Administrador (nunca se calcula para nadie más)
            'php' => $actor->es_superadmin ? app(VersionPhp::class)->datos() : null,
            // C-36: Super Administradores (dos como mínimo) y sus peticiones de ayuda
            'superadmins' => $actor->es_superadmin ? [
                'lista' => app(SuperAdministradores::class)->todos(),
                'activos' => app(SuperAdministradores::class)->activos(),
                'faltan' => app(SuperAdministradores::class)->faltan(),
                'solicitudes' => app(SolicitudesCuenta::class)->porAtender($actor, null),
                'correoListo' => $this->correo->configurado(),
            ] : null,
            'empresa' => $empresaId === null ? null : Empresa::find($empresaId),
            'avisos' => Empresa::AVISOS,
            'puedeEditar' => $this->editaEmpresa($actor),
            'verConfiguracion' => $actor->can('configuracion.ver'),
            'lostFound' => $this->datosLostFound($actor, $empresaId, $configuraLostFound),
            // Mejoras estructura (ADR-0016): avisos por módulo, versión instalada y bitácora de auditoría
            'grupos' => GruposAvisos::agrupados($empresaId),
            'version' => self::versionInstalada(),
            'auditoriaSede' => $empresaId === null ? null : (Empresa::find($empresaId)?->preferencias['auditoria_sede'] ?? AuditoriaController::SEDE_POR_OMISION),
            // Configuración por secciones: resúmenes de lo que se configura en otra pantalla (enlace «Configurar en …»)
            'enOtras' => $empresaId === null ? [] : $this->ajustesEnOtrasPantallas($actor, Empresa::find($empresaId)),
            // Verificación en dos pasos (docs/tecnico/dos-pasos.md) y Privacidad (docs/tecnico/privacidad.md)
            'dosPasos' => $empresaId === null ? null : $this->datosDosPasos(Empresa::find($empresaId)),
            'privacidad' => $empresaId === null ? null : app(ConservacionDatos::class)->paraConfiguracion($actor, Empresa::find($empresaId)),
        ]);
    }

    /**
     * Ajustes por empresa cuya pantalla natural es otro módulo: aquí solo se
     * muestran (valor vigente y original) con su enlace «Configurar en …».
     * Una sola fuente de verdad: el formulario vive en su módulo. Solo se
     * listan los que el rol puede abrir.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ajustesEnOtrasPantallas(User $actor, ?Empresa $empresa): array
    {
        if ($empresa === null) {
            return [];
        }
        $r = [];
        $recepcion = app(AjustesRecepcion::class);
        if ($actor->can('empresas.ver')) {
            $r['empresa'] = ['zona' => ZonasHorarias::etiqueta($empresa->zona_horaria), 'url' => route('empresas.index').'#empresa-'.$empresa->id,
                'puedeEditar' => $actor->can('empresas.editar')];
        }
        if ($actor->can('autorizaciones.configurar')) {
            $r['esperas'] = [
                'tipos' => collect(AjustesRecepcion::ESPERAN_DEPARTAMENTO)->map(fn ($d, $tipo) => [$d[1], $recepcion->esperaAlDepartamento($empresa, $tipo)])->values()->all(),
                'rh' => $recepcion->rhAutorizaPaso($empresa),
                'recordar' => $recepcion->recordarMinutos($empresa), 'escalar' => $recepcion->escalarMinutos($empresa),
                'url' => route('autorizaciones.responsables'),
                // C-08 (A-03) y DD-11: la verdad de hoy y qué pasa sin quien autorice
                'sinCobertura' => AjustesRecepcion::SIN_COBERTURA[$recepcion->sinCobertura($empresa)],
                'faltan' => $this->tenant->conEmpresa($empresa->id, fn () => collect(app(Cobertura::class)->sinCobertura())->pluck('departamento_id')->unique()->count()),
            ];
        }
        if ($actor->can('candidatos.configurar')) {
            $plazos = app(PlazosCandidatos::class)->plazos($empresa);
            $r['plazos'] = [
                'lista' => collect($plazos)->map(fn ($v, $k) => [PlazosCandidatos::etiqueta($k), $v, PlazosCandidatos::DEFECTO[$k]])->values()->all(),
                'documentos' => $this->tenant->conEmpresa($empresa->id, fn () => app(DocumentosParaContratar::class)->lista($empresa->id)->count()),
                'url' => route('candidatos.configuracion'),
            ];
            $criterios = $recepcion->criterios($empresa);
            $r['criterios'] = ['lista' => $criterios, 'propios' => $criterios !== AjustesRecepcion::CRITERIOS_DEFECTO, 'url' => route('candidatos.criterios')];
        }
        if ($actor->can(PlantillasEtiquetas::CONFIGURAR)) {
            $modo = app(ImpresionDesdePadron::class)->modo($empresa->id);
            $r['impresion'] = ['texto' => ImpresionDesdePadron::OPCIONES[$modo], 'original' => $modo === ImpresionDesdePadron::MODULO, 'url' => route('etiquetas.plantillas')];
        }

        return $r;
    }

    /** @return array{politica: string, activas: int, total: int}|null */
    private function datosDosPasos(?Empresa $empresa): ?array
    {
        if ($empresa === null) {
            return null;
        }
        $servicio = app(VerificacionDosPasos::class);

        return ['politica' => $servicio->politica($empresa)] + $servicio->conteo($empresa);
    }

    // ------------------------------------------- Nombres en el menú (empresa)

    /**
     * Configuración → General → Avanzado: cómo se llama cada módulo en el
     * menú de la empresa (empresa_modulos.nombre_visible). Vacío = el de siempre.
     */
    public function menu(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        abort_unless($this->editaEmpresa($request->user()), 403, 'Estos ajustes son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $empresa = Empresa::findOrFail($empresaId);
        $entrada = $request->input('nombres');
        [$antes, $despues] = NombresMenu::guardar($empresa, is_array($entrada) ? $entrada : []);
        $this->autorizador->olvidar();
        if ($antes !== $despues) {
            $this->auditoria->auditar($request->user(), 'configuracion.menu', $empresa, ['nombres' => $antes], ['nombres' => $despues]);
        }

        return redirect()->to(route('configuracion.index').'#cfg-menu')->with('ok', 'Nombres del menú guardados. Revisa el menú: ya aparecen con el nombre nuevo.');
    }

    // --------------------------------------------- Restablecer valor original

    /** Tarjeta => [texto para el mensaje «Listo: … a su valor original.», ancla]. */
    public const RESTABLECIBLES = [
        'transporte' => ['las firmas del vale de taxi volvieron', 'cfg-transporte'],
        'llaves' => ['el semáforo de llaves volvió', 'cfg-llaves'],
        'responsivas' => ['las horas de los equipos de «Turno» volvieron', 'cfg-responsivas'],
        'procedimientos' => ['los ajustes de Procedimientos volvieron', 'cfg-procedimientos'],
        'vobo' => ['el reloj del visto bueno volvió', 'cfg-vobo'],
        'menu' => ['los nombres del menú volvieron', 'cfg-menu'],
        'novedades' => ['el nombre de la bitácora y la categoría del hallazgo volvieron', 'novedades'],
        'lost_found' => ['los días de resguardo de Lost & Found volvieron', 'lost-found'],
        'auditoria' => ['lo que ve «Su sede» en la Bitácora de auditoría volvió', 'config-auditoria'],
        'dos_pasos' => ['a quién se exige la verificación en dos pasos volvió', 'cfg-dos-pasos'],
        'avisos' => ['los avisos por correo volvieron', 'sec-avisos'],
    ];

    /**
     * «Restablecer valor original» de una tarjeta: regresa sus ajustes al
     * valor con el que nace la plataforma (lo que se usa sin configurar).
     * Cada tarjeta pide el mismo permiso que su botón «Guardar».
     */
    public function restablecer(Request $request): RedirectResponse
    {
        $actor = $request->user();
        // Primero el permiso (sin rol: 403, nunca una validación que delate la ruta)
        if ($request->input('ajuste') === 'lost_found') {
            Gate::authorize('lost_found.configurar');
            abort_unless($this->lostFound->puedeConfigurar($actor), 403, 'Los días de resguardo son de toda la empresa: hace falta el permiso «configurar» de Lost & Found con alcance de empresa.');
        } else {
            Gate::authorize('configuracion.editar');
            abort_unless($this->editaEmpresa($actor), 403, 'Estos ajustes son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        }
        $ajuste = $request->validate(['ajuste' => ['required', Rule::in(array_keys(self::RESTABLECIBLES))]])['ajuste'];
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $empresa = Empresa::findOrFail($empresaId);

        match ($ajuste) {
            'transporte', 'llaves', 'responsivas', 'procedimientos', 'vobo' => $this->restablecerCaseta($actor, $empresa, AjustesCaseta::GRUPOS[$ajuste]),
            'menu' => $this->restablecerMenu($actor, $empresa),
            'novedades' => app(AjustesNovedades::class)->guardar($actor, $empresa, ['nombre_opcion' => AjustesNovedades::NOMBRE_OMISION, 'categoria_hallazgo_pc' => AjustesNovedades::CATEGORIA_HALLAZGO_OMISION]),
            'lost_found' => $this->tenant->conEmpresa($empresaId, fn () => $this->lostFound->guardarUmbrales($actor, ['dias' => LostFoundUmbral::POR_OMISION])),
            'auditoria', 'avisos' => $this->restablecerPreferencias($actor, $empresa, $ajuste === 'auditoria' ? ['auditoria_sede'] : ['avisos', 'avisos_modulos']),
            'dos_pasos' => $this->restablecerPreferencias($actor, $empresa, ['dos_pasos']),
        };

        [$texto, $ancla] = self::RESTABLECIBLES[$ajuste];

        return redirect()->to(route('configuracion.index').'#'.$ancla)->with('ok', "Listo: {$texto} a su valor original.".($ajuste === 'avisos' ? ' Los correos que capturaste se conservan.' : ''));
    }

    /** @param  list<string>  $claves */
    private function restablecerCaseta(User $actor, Empresa $empresa, array $claves): void
    {
        $antes = AjustesCaseta::de($empresa);
        $preferencias = $empresa->preferencias ?? [];
        $guardados = array_diff_key(is_array($preferencias[AjustesCaseta::CLAVE] ?? null) ? $preferencias[AjustesCaseta::CLAVE] : [], array_flip($claves));
        if ($guardados === []) {
            unset($preferencias[AjustesCaseta::CLAVE]);
        } else {
            $preferencias[AjustesCaseta::CLAVE] = $guardados;
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $this->auditoria->auditar($actor, 'configuracion.restablecido', $empresa, array_intersect_key($antes, array_flip($claves)),
            array_intersect_key(AjustesCaseta::de($empresa->refresh()), array_flip($claves)));
    }

    private function restablecerMenu(User $actor, Empresa $empresa): void
    {
        $antes = collect(NombresMenu::de($empresa))->flatten(1)->filter(fn ($m) => $m['propio'] !== null)->mapWithKeys(fn ($m) => [$m['clave'] => $m['propio']])->all();
        NombresMenu::restablecer($empresa);
        $this->autorizador->olvidar();
        $this->auditoria->auditar($actor, 'configuracion.restablecido', $empresa, ['nombres' => $antes], ['nombres' => []]);
    }

    /** @param  list<string>  $claves  claves de empresas.preferencias que vuelven a no existir (= valor original) */
    private function restablecerPreferencias(User $actor, Empresa $empresa, array $claves): void
    {
        $preferencias = $empresa->preferencias ?? [];
        $antes = array_intersect_key($preferencias, array_flip($claves));
        foreach ($claves as $clave) {
            unset($preferencias[$clave]);
        }
        $empresa->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        $this->auditoria->auditar($actor, 'configuracion.restablecido', $empresa, $antes, array_fill_keys($claves, 'original'));
    }

    /**
     * Versión instalada (primer renglón del archivo VERSION que deja la
     * instalación) y cuándo se instaló. Sin archivo: «sin versión» (desarrollo).
     *
     * @return array{numero: ?string, instalada: ?Carbon}
     */
    public static function versionInstalada(): array
    {
        $archivo = base_path('VERSION');
        if (! is_file($archivo)) {
            return ['numero' => null, 'instalada' => null];
        }
        $numero = trim((string) strtok((string) file_get_contents($archivo), "\n"));

        return [
            'numero' => preg_match('/^v?\d+\.\d+\.\d+[0-9A-Za-z.+-]*$/', $numero) ? $numero : null,
            'instalada' => ($fecha = @filemtime($archivo)) ? Carbon::createFromTimestamp($fecha) : null,
        ];
    }

    // ------------------------------------------------- Bitácora de auditoría (empresa)

    /**
     * Qué ve en la Bitácora de auditoría quien tiene el permiso con alcance
     * «Su sede» (ADR-0016): solo sus movimientos (original) o los de su sede.
     */
    public function auditoria(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        abort_unless($this->editaEmpresa($request->user()), 403, 'Este ajuste es de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $valor = $request->validate(['auditoria_sede' => ['required', Rule::in(['propios', 'sede'])]])['auditoria_sede'];
        $empresa = Empresa::findOrFail($empresaId);
        $antes = $empresa->preferencias['auditoria_sede'] ?? AuditoriaController::SEDE_POR_OMISION;
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['auditoria_sede' => $valor])])->save();
        $this->auditoria->auditar($request->user(), 'configuracion.auditoria', $empresa, ['auditoria_sede' => $antes], ['auditoria_sede' => $valor]);

        return redirect()->to(route('configuracion.index').'#config-cuentas')->with('ok', $valor === 'sede'
            ? 'Listo: quien tiene la bitácora con alcance «Su sede» ahora ve los movimientos de su sede. Puedes revisarlo en la Bitácora de auditoría.'
            : 'Listo: quien tiene la bitácora con alcance «Su sede» ve solo sus propios movimientos.');
    }

    // ------------------------------------- Verificación en dos pasos (empresa)

    /**
     * Cuentas y seguridad: a quién se exige la verificación en dos pasos
     * (nadie —original—, administradores o todos). El Super Administrador
     * siempre la necesita, sin importar este ajuste.
     */
    public function dosPasos(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        abort_unless($this->editaEmpresa($request->user()), 403, 'Este ajuste es de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $valor = $request->validate(['dos_pasos' => ['required', Rule::in(array_keys(VerificacionDosPasos::POLITICAS))]], [], ['dos_pasos' => 'a quién se exige'])['dos_pasos'];

        app(VerificacionDosPasos::class)->guardarPolitica($request->user(), Empresa::findOrFail($empresaId), $valor);

        return redirect()->to(route('configuracion.index').'#cfg-dos-pasos')->with('ok', match ($valor) {
            'nadie' => 'Listo: cada persona decide si activa la verificación en dos pasos en Mi cuenta.',
            'administradores' => 'Listo: quien administra usuarios, roles, permisos o la configuración la activará la próxima vez que entre. Avísales que tengan a la mano su teléfono.',
            default => 'Listo: todas las cuentas la activarán la próxima vez que entren. Avisa a tu personal que tenga a la mano su teléfono.',
        });
    }

    // ------------------------------------------- Lost & Found: días de resguardo

    /**
     * Días de resguardo por clasificación (SEGCAT: lf_config_umbrales.php).
     * Son de toda la empresa: hace falta «lost_found.configurar» con alcance de empresa.
     */
    public function lostFound(Request $request): RedirectResponse
    {
        Gate::authorize('lost_found.configurar');
        $actor = $request->user();
        abort_unless($this->lostFound->puedeConfigurar($actor), 403, 'Los días de resguardo son de toda la empresa: hace falta el permiso «configurar» de Lost & Found con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $this->tenant->conEmpresa($empresaId, fn () => $this->lostFound->guardarUmbrales($actor, $request->only('dias')));

        return redirect()->to(route('configuracion.index').'#lost-found')->with('ok', 'Los días de resguardo de Lost & Found se guardaron correctamente.');
    }

    /**
     * Sección «Lost & Found: días de resguardo»: editable para quien la
     * configura; de consulta para quien ve Configuración y Lost & Found.
     *
     * @return array{dias: array<string, int>, guardados: Collection<string, LostFoundUmbral>, puedeEditar: bool}|null
     */
    private function datosLostFound(User $actor, ?int $empresaId, bool $configura): ?array
    {
        if ($empresaId === null || (! $configura && ! ($actor->can('configuracion.ver') && $actor->can('lost_found.ver')))) {
            return null;
        }

        return $this->tenant->conEmpresa($empresaId, fn () => [
            'dias' => LostFoundUmbral::vigentes(),
            'guardados' => LostFoundUmbral::with('editor:id,name')->get()->keyBy('tipo_valor'),
            'puedeEditar' => $configura,
        ]);
    }

    // ----------------------------- Mejoras Incidentes: Bitácora de novedades (empresa)

    /**
     * Nombre del módulo en el menú y categoría del ticket que abre un hallazgo
     * de recorrido (ADR-0013). Es de toda la empresa: «configuracion.editar»
     * con alcance de empresa, como los avisos.
     */
    public function novedades(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        abort_unless($this->editaEmpresa($request->user()), 403, 'Estos ajustes son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        app(AjustesNovedades::class)->guardar($request->user(), Empresa::findOrFail($empresaId), $request->only(['nombre_opcion', 'nombre_menu', 'categoria_hallazgo_pc']));

        return redirect()->to(route('configuracion.index').'#novedades')->with('ok', 'Ajustes de '.app(AjustesNovedades::class)->bitacoraConArticulo($empresaId).' guardados.');
    }
    // Fin Mejoras Incidentes

    // --------------------------------------------------------- Correo (plataforma)

    public function correo(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $datos = $request->validate([
            // Seguridad (SSRF): solo servidores que no sean direcciones reservadas y solo puertos de correo
            'host' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9.-]+$/', function (string $campo, mixed $valor, \Closure $falla) {
                $problema = CorreoPlataforma::problemaDestino(Entrada::texto($valor), CorreoPlataforma::PUERTOS[0]);
                if ($problema !== null) {
                    $falla($problema);
                }
            }],
            'puerto' => ['required', 'integer', Rule::in(CorreoPlataforma::PUERTOS)],
            'cifrado' => ['required', Rule::in(array_keys(CorreoPlataforma::CIFRADOS))],
            'usuario' => ['nullable', 'string', 'max:150'],
            'contrasena' => ['nullable', 'string', 'max:200'],
            'remitente_correo' => ['required', 'email:rfc', 'max:150'],
            'remitente_nombre' => ['nullable', 'string', 'max:80'],
        ], [
            'host.regex' => 'Escribe solo el nombre del servidor (ej. mail.tudominio.com), sin https:// ni espacios.',
            'puerto.in' => 'Usa un puerto de correo: '.implode(', ', CorreoPlataforma::PUERTOS).'.',
        ], [
            'host' => 'servidor', 'puerto' => 'puerto', 'remitente_correo' => 'correo del remitente', 'remitente_nombre' => 'nombre del remitente',
        ]);

        $contrasena = $datos['contrasena'] ?? null;
        unset($datos['contrasena']);
        $this->correo->guardar($datos, $contrasena, $request->boolean('quitar_contrasena'));

        // En la bitácora nunca va la contraseña: solo si se cambió
        $this->auditar($request, 'configuracion.correo', ['host' => $datos['host'], 'puerto' => $datos['puerto'], 'usuario' => $datos['usuario'] ?? null,
            'remitente' => $datos['remitente_correo'], 'contrasena' => $contrasena ? 'cambiada' : ($request->boolean('quitar_contrasena') ? 'quitada' : 'sin cambio')]);

        return redirect()->route('configuracion.index')->with('ok', 'Configuración de correo guardada. Envía un correo de prueba para confirmar que funciona.');
    }

    public function probarCorreo(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $para = $request->validate(['para' => ['required', 'email:rfc', 'max:150']], [], ['para' => 'correo de destino'])['para'];

        if (! $this->correo->configurado()) {
            return back()->with('error', 'Primero guarda la configuración del correo.');
        }

        $enviado = $this->correo->enviar($para, new CorreoDePrueba($request->user()->name));
        $this->auditar($request, 'configuracion.correo_prueba', ['para' => $para, 'resultado' => $enviado ? 'enviado' : 'error']);

        return back()->with($enviado ? 'ok' : 'error', $enviado
            ? "Correo de prueba enviado a {$para}. Revisa la bandeja de entrada (y la de spam)."
            : 'No se pudo enviar: '.($this->correo->datos()['ultimo_error'] ?? 'revisa servidor, puerto, usuario y contraseña.'));
    }

    // -------------------------------------------------------- Avisos (empresa)

    public function avisos(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        // Seguridad (AZ-02): los avisos son de toda la empresa; con alcance de sede solo se consultan
        abort_unless($this->editaEmpresa($request->user()), 403, 'Los avisos son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $empresa = Empresa::findOrFail($empresaId);
        $antes = $empresa->preferencias['avisos'] ?? [];
        $avisos = collect(Empresa::AVISOS)->mapWithKeys(fn ($v, $clave) => [$clave => $request->boolean("avisos.{$clave}")])->all();
        // Avisos con lista de correos (p. ej. vales de taxi): uno por línea o separados por coma
        $destinatarios = $this->destinatariosAvisos($request);
        // Interruptor general por módulo (ADR-0016): solo los que vienen en el formulario; los demás se conservan
        $modulosAntes = $empresa->preferencias['avisos_modulos'] ?? [];
        $modulos = $modulosAntes;
        foreach (array_keys(GruposAvisos::agrupados()) as $grupo) {
            if ($request->has("avisos_modulos.{$grupo}")) {
                $modulos[$grupo] = $request->boolean("avisos_modulos.{$grupo}");
            }
        }
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['avisos' => $avisos, 'avisos_destinatarios' => $destinatarios, 'avisos_modulos' => $modulos])])->save();

        $this->auditoria->auditar($request->user(), 'configuracion.avisos', $empresa, ['avisos' => $antes, 'avisos_modulos' => $modulosAntes], ['avisos' => $avisos, 'avisos_destinatarios' => $destinatarios, 'avisos_modulos' => $modulos]);

        $apagados = collect($modulos)->filter(fn ($v) => $v === false)->keys()->map(fn ($g) => GruposAvisos::GRUPOS[$g][0] ?? $g);

        return redirect()->to(route('configuracion.index').($request->filled('_grupo') && preg_match('/^[a-z_]+$/', (string) $request->input('_grupo')) ? '#config-'.$request->input('_grupo') : ''))
            ->with('ok', 'Avisos actualizados.'.($apagados->isNotEmpty() ? ' Apagados por módulo: '.$apagados->join(', ').'.' : ''));
    }

    // ------------------------------------------------------ Respaldos (plataforma)

    public function respaldar(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);

        try {
            $r = $this->respaldos->crear('manual');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo crear el respaldo: '.$e->getMessage());
        }
        $this->auditar($request, 'configuracion.respaldo_creado', ['archivo' => $r['archivo'], 'bytes' => $r['bytes']]);

        return back()->with('ok', "Respaldo {$r['archivo']} creado.");
    }

    public function descargar(Request $request, string $archivo): BinaryFileResponse
    {
        $this->soloSuperadmin($request);
        $ruta = $this->respaldos->ruta($archivo);
        abort_if($ruta === null, 404);

        // Descargar la base completa queda en la bitácora
        $this->auditar($request, 'configuracion.respaldo_descargado', ['archivo' => $archivo]);

        // Seguridad: BinaryFileResponse se marca "public" por defecto y pisaba el "private"
        return response()->download($ruta, $archivo, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store, private'])
            ->setPrivate();
    }

    /**
     * Listas de correos de Empresa::AVISOS_CON_DESTINATARIOS (máximo 20 por aviso).
     *
     * @return array<string, list<string>>
     */
    private function destinatariosAvisos(Request $request): array
    {
        $resultado = [];
        $errores = [];
        // Ronda 5: más las tres copias del voucher con cobro (Seguridad, Recepción, Administración)
        foreach ([...Empresa::AVISOS_CON_DESTINATARIOS, ...array_keys(Empresa::DESTINATARIOS_VOUCHER)] as $clave) {
            $texto = $request->input("destinatarios.{$clave}");
            $correos = collect(preg_split('/[\s,;]+/', is_string($texto) ? mb_strtolower($texto) : '') ?: [])->filter()->unique()->values();
            $invalidos = $correos->reject(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($c) <= 150);
            if ($invalidos->isNotEmpty()) {
                $errores["destinatarios.{$clave}"] = 'Revisa estos correos: '.$invalidos->take(3)->implode(', ').'.';
            } elseif ($correos->count() > 20) {
                $errores["destinatarios.{$clave}"] = 'Captura máximo 20 correos por aviso.';
            }
            $resultado[$clave] = $correos->all();
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $resultado;
    }

    // -------------------------------------------------------------------------

    /**
     * Seguridad (AZ-02): la configuración de la empresa solo la cambia quien
     * tiene "configuracion.editar" con alcance de toda la empresa.
     */
    private function editaEmpresa(User $actor): bool
    {
        return $actor->can('configuracion.editar') && $this->autorizador->alcanceDeEmpresa($actor, 'configuracion.editar');
    }

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()->es_superadmin, 403);
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(Request $request, string $evento, array $despues): void
    {
        $registro = ConfiguracionPlataforma::firstOrCreate(['clave' => CorreoPlataforma::CLAVE], ['valor' => []]);
        $this->auditoria->auditar($request->user(), $evento, $registro, null, $despues);
    }
}
