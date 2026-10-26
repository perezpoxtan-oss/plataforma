<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Arranque\AsistenteArranque;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\Novedades\AjustesNovedades;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Plataforma\SuperAdministradores;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PanelController extends Controller
{
    /**
     * Qué se espera de quien entra en cada renglón de «Mis pendientes»
     * (mismos nombres, mismo orden): clave => [frase en singular, en plural, explicación, botón].
     */
    public const TEXTOS = [
        'colaboradores_por_verificar' => ['1 colaborador por verificar', '%d colaboradores por verificar', 'La caseta registró colaboradores que aún no estaban en el directorio. Verifícalos o únelos con su registro correcto.', 'Revisar ahora'],
        'autorizaciones' => ['1 solicitud espera tu autorización', '%d solicitudes esperan tu autorización', 'Una visita de tu departamento espera tu respuesta. Mientras no contestes, la persona sigue esperando.', 'Responder'],
        'pases_salida' => ['1 pase de salida espera tu aprobación', '%d pases de salida esperan tu aprobación', 'Revisa los artículos y firma o rechaza con tus comentarios. El siguiente paso no avanza hasta que firmes.', 'Ir a mi bandeja'],
        'procedimientos_aprobar' => ['1 procedimiento espera tu aprobación', '%d procedimientos esperan tu aprobación', 'Revisa los pasos y apruébalo con tu firma, o recházalo con tus comentarios para que lo corrijan.', 'Revisar'],
        'procedimientos' => ['Tienes 1 procedimiento por leer y firmar', 'Tienes %d procedimientos por leer y firmar', 'Léelos con calma y firma «Leí y entendí». Así sabrás qué hacer en cada situación.', 'Leer ahora'],
        'altas' => ['1 alta por verificar', '%d altas por verificar', 'La caseta registró desde Operación algo que no estaba en el padrón. Acéptalo, recházalo o únelo con el registro correcto.', 'Revisar ahora'],
        'candidatos' => ['1 asunto de candidatos te espera', '%d asuntos de candidatos te esperan', 'Personas que esperan a Recursos Humanos y candidatos en cada etapa. Toca la etapa para ir directo.', 'Ir a candidatos'],
        'entrevistas' => ['1 entrevista espera tu evaluación', '%d entrevistas esperan tu evaluación', 'Recursos Humanos te canalizó candidatos: entrevístalos, califícalos y elige.', 'Entrevistar'],
        'cuentas' => ['1 cuenta por revisar', '%d cuentas por revisar', 'Cuentas bloqueadas, desactivadas por una baja, que piden pases sin colaborador vinculado o que te piden ayuda para entrar.', 'Revisar cuentas'],
        // Mejoras Incidentes (ADR-0013)
        'novedades_mias' => ['1 ticket canalizado a ti', '%d tickets canalizados a ti', 'Te toca darles seguimiento en la Bitácora de novedades. Quien los levantó se entera cuando los cierras.', 'Atender'],
        'novedades_turno' => ['1 pendiente de turno sin dueño', '%d pendientes de turno sin dueño', 'Tickets que el turno anterior dejó pendientes y nadie ha tomado. Ábrelos y atiéndelos o canalízalos.', 'Atender'],
        // Mejoras activos (ADR-0014)
        'vales_taxi' => ['1 vale de taxi espera tu Vo.Bo.', '%d vales de taxi esperan tu Vo.Bo.', 'La unidad no llegó y se usaron taxis: revisa el vale y dale tu Vo.Bo.', 'Revisar'],
        'llaves_fuera' => ['1 llave fuera de tiempo', '%d llaves fuera de tiempo', 'Llaves prestadas que ya pasaron las horas que tu empresa permite. Pide que las regresen o recíbelas.', 'Ver llaves'],
        'equipos_turno' => ['1 responsiva con equipos de turno sin regresar', '%d responsivas con equipos de turno sin regresar', 'Los equipos de «Turno» debían regresar a su hora. Recíbelos o pregunta por ellos.', 'Ver responsivas'],
    ];

    /**
     * Inicio (ADR-0016): lo que espera la respuesta de quien entra, con los
     * mismos renglones, nombres y orden que «Mis pendientes» (sin renglones
     * en cero), cada uno con su explicación y su botón.
     */
    public function __invoke(Request $request, EmpresaDeTrabajo $empresa, Tenant $tenant): View
    {
        $actor = $request->user();
        $pendientes = [];
        $empresaId = $empresa->id($actor);

        if ($empresaId !== null) {
            // Siempre el cálculo fresco (instancia propia, sin lo memorizado en esta petición)
            foreach (app()->build(MisPendientes::class)->para($actor)['vista'] ?? [] as $item) {
                [$uno, $varios, $texto, $boton] = self::TEXTOS[$item['clave']] ?? ['', '', '', 'Revisar'];
                $frase = $item['total'] === 1 ? $uno : sprintf($varios, $item['total']);
                // Pases: las aprobaciones y las firmas de caseta se cuentan juntas; la frase las separa
                if ($item['clave'] === 'pases_salida' && $actor->can('pases_salida.aprobar')) {
                    $aprobar = count($tenant->conEmpresa($empresaId, fn () => app(AdministradorPasesSalida::class)->idsPorAprobar($actor)));
                    $firmar = $item['total'] - $aprobar;
                    $frase = trim(($aprobar > 0 ? ($aprobar === 1 ? $uno : sprintf($varios, $aprobar)) : '')
                        .($firmar > 0 ? ($aprobar > 0 ? ' y ' : '').($firmar === 1 ? '1 espera tu firma en caseta' : "{$firmar} esperan tu firma en caseta") : ''));
                }
                // El texto nombra el módulo como lo llama la empresa («Bitácora de incidentes»)
                $texto = str_replace('la Bitácora de novedades', app(AjustesNovedades::class)->bitacoraConArticulo($empresaId), $texto);
                $pendientes[] = $item + ['texto' => trim(($frase !== '' ? $frase.'. ' : '').$texto), 'boton' => $boton];
            }
        }

        // C-36 (DD-21, E-14): el Super Administrador ve si falta el segundo y las peticiones de los otros
        if ($actor->es_superadmin) {
            $pendientes = array_merge($this->pendientesDePlataforma($actor), $pendientes);
        }

        // «Configura tu empresa» (docs/tecnico/configura-tu-empresa.md): solo empresa nueva o casi vacía
        $asistente = app(AsistenteArranque::class)->tarjeta($actor, $empresaId);

        return view('panel.index', ['pendientes' => $pendientes, 'asistente' => $asistente]);
    }

    /** @return list<array<string, mixed>> */
    private function pendientesDePlataforma(User $actor): array
    {
        $lista = [];
        $url = route('configuracion.index').'#cfg-superadmins';
        $super = app(SuperAdministradores::class);
        if ($super->faltan()) {
            $lista[] = ['clave' => 'superadmins', 'titulo' => 'Falta el segundo Super Administrador', 'icono' => 'bi-shield-exclamation', 'total' => $super->activos(),
                'url' => $url, 'nivel' => 'roja', 'boton' => 'Agregar',
                'texto' => 'Solo hay un Super Administrador activo. Si pierde su teléfono y sus códigos de respaldo, nadie podría devolverle el acceso. Da de alta a otra persona de confianza.'];
        }
        $peticiones = app(SolicitudesCuenta::class)->porAtender($actor, null)->count();
        if ($peticiones > 0) {
            $lista[] = ['clave' => 'cuentas_superadmin', 'titulo' => 'Super Administradores que piden ayuda', 'icono' => 'bi-person-exclamation', 'total' => $peticiones,
                'url' => $url, 'nivel' => null, 'boton' => 'Atender',
                'texto' => 'Otro Super Administrador perdió su teléfono o su contraseña. Confirma con él que fue quien lo pidió y restablécelo.'];
        }

        return $lista;
    }
}
