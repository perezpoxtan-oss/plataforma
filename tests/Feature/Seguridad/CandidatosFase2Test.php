<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Mail\AvisoEntrevistaCandidato;
use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\EvaluacionCandidato;
use App\Models\Notificacion;
use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\CorreoPlataforma;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Candidatos, fase 2 (ADR-0009): etapas nuevas, evaluación de RR. HH.,
 * canalizar al departamento con cita, la pantalla Entrevistar del jefe,
 * elegir (y plazas), segunda entrevista, no se presentó, avisos (al
 * entrevistador, a RR. HH. y al candidato), Mis pendientes, la tabla de la
 * vacante, criterios configurables, alcance por sede y la migración.
 */
class CandidatosFase2Test extends TestCase
{
    use AyudasEntrevistas, CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    private User $jefa;

    private Departamento $recepcion;

    private Departamento $cocina;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefa = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        [$this->recepcion, $this->cocina] = $this->enEmpresa(fn () => [Departamento::create(['nombre' => 'Recepción']), Departamento::create(['nombre' => 'Cocina'])]);
        $this->responsable($this->recepcion, $this->jefa);
        // La caseta deja pasar directo (el «Que pase» se prueba en la fase 1)
        $this->rhAutorizaPaso(false);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function rhAutorizaPaso(bool $si): void
    {
        $e = $this->empresa->refresh();
        $recepcion = array_merge($e->preferencias['recepcion'] ?? [], ['rh_autoriza_paso' => $si]);
        $e->forceFill(['preferencias' => array_merge($e->preferencias ?? [], ['recepcion' => $recepcion])])->save();
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    private function responsable(Departamento $d, User $u, ?Sede $sede = null): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $u->id, 'es_suplente' => false, 'sede_id' => $sede?->id]));
    }

    private function vacante(string $titulo = 'Recepcionista', int $plazas = 1, bool $jefeVeCv = false): Vacante
    {
        return $this->enEmpresa(function () use ($titulo, $plazas, $jefeVeCv) {
            $servicio = app(AdministradorVacantes::class);
            $v = $servicio->crear($this->rh, ['titulo' => $titulo, 'plazas' => $plazas, 'departamento_id' => $this->recepcion->id, 'todas_las_sedes' => '1',
                'jefe_ve_cv' => $jefeVeCv ? '1' : '0', 'sueldo_a_tratar' => '1']);
            $servicio->cambiarEstado($this->rh, $v, 'publicada', null, AdministradorVacantes::hoy($this->empresa->refresh()));

            return $v->fresh();
        });
    }

    /**
     * Un candidato capturado por RR. HH. (ya atendido: «En revisión»).
     *
     * @param  array<string, mixed>  $datos
     */
    private function candidato(string $nombre = 'Juan Pérez Chan', ?Vacante $vacante = null, array $datos = [], ?Sede $sede = null): Candidato
    {
        $this->actingAs($this->rh)->post('/candidatos', $datos + ['nombre_completo' => $nombre, 'telefono' => '998'.random_int(1000000, 9999999),
            'sede_id' => ($sede ?? $this->centro)->id, 'acepta_privacidad' => '1', 'vacante_id' => $vacante?->id, 'departamento_id' => $this->recepcion->id,
            'escolaridad' => [['nivel' => 'bachillerato', 'institucion' => 'CBTIS 111']], 'experiencia' => [['empresa' => 'Hotel Sol', 'puesto' => 'Botones', 'anos' => '3']],
            'disponibilidad' => 'inmediata', 'pretension' => '10,500'])->assertSessionHas('ok');
        $c = $this->enEmpresa(fn () => Candidato::where('nombre_completo', $nombre)->sole());
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'revision'])->assertSessionHas('ok');

        return $c->fresh();
    }

    private function postulacion(Candidato $c): Postulacion
    {
        return $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->orderByDesc('id')->firstOrFail());
    }

    /** Fecha y hora locales (zona de la empresa) para el formulario de la cita. */
    private function citaLocal(Carbon $utc): string
    {
        return $utc->copy()->setTimezone($this->empresa->refresh()->zona_horaria ?: 'America/Mexico_City')->format('Y-m-d H:i');
    }

    /** Evaluación de RR. HH. «Canalizar» y canalización a la jefa de Recepción. */
    private function hastaCanalizado(Candidato $c, ?User $entrevistador = null, ?Carbon $cita = null, array $extra = []): TestResponse
    {
        $this->evaluarRh($this->rh, $c, 'canalizar', 'Buena presentación')->assertSessionHas('ok');

        return $this->canalizar($this->rh, $c, $entrevistador ?? $this->jefa, $this->recepcion->id, $cita ? $this->citaLocal($cita) : null, $extra);
    }

    /** Opciones del selector «¿Quién lo entrevista?» de la ficha. */
    private function opcionesEntrevistador(Candidato $c): string
    {
        $html = (string) $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->getContent();

        return preg_match('/<select id="can_entrevistador".*?<\/select>/s', $html, $m) ? $m[0] : '';
    }

    // ------------------------------------------------------------------ Migración

    public function test_la_migracion_mapea_las_etapas_viejas_y_es_idempotente(): void
    {
        $chef = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->responsable($this->cocina, $chef);
        $mapa = ['aprobado_rh' => 'canalizado', 'entrevista' => 'canalizado', 'seleccionado' => 'elegido', 'cartera' => 'considerar', 'descartado' => 'rechazado',
            'revision' => 'revision'];
        $ids = [];
        foreach (array_keys($mapa) as $i => $vieja) {
            $ids[$vieja] = $this->enEmpresa(function () use ($vieja, $i) {
                $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Persona '.$i, 'departamento_id' => $this->cocina->id, 'origen' => 'rh']);
                $c->forceFill(['etapa' => $vieja])->save();
                $p = new Postulacion(['candidato_id' => $c->id, 'sede_id' => $this->centro->id, 'departamento_id' => $this->cocina->id, 'origen' => 'rh']);
                $p->forceFill(['etapa' => $vieja, 'aprobado_rh_en' => now()->subHour(), 'decision_en' => $vieja === 'seleccionado' ? now()->subMinutes(5) : null])->save();
                DB::table('candidato_eventos')->insert(['empresa_id' => $this->empresa->id, 'candidato_id' => $c->id, 'evento' => 'etapa', 'etapa_anterior' => 'revision',
                    'etapa_nueva' => $vieja, 'created_at' => now(), 'updated_at' => now()]);

                return [$c->id, $p->id];
            });
        }
        // El departamento ya había respondido «Bajar a entrevistar» por «entrevista»; la de «aprobado_rh» seguía pendiente
        [$autRespondida, $autPendiente] = $this->enEmpresa(function () use ($ids, $chef) {
            $r = Autorizacion::create(['sede_id' => $this->centro->id, 'departamento_id' => $this->cocina->id, 'tipo' => 'candidato', 'candidato_id' => $ids['entrevista'][0],
                'solicitada_en' => now()->subHour()]);
            $r->forceFill(['estado' => 'entrevista', 'respondida_por' => $chef->id, 'respondida_en' => now()])->save();
            $p = Autorizacion::create(['sede_id' => $this->centro->id, 'departamento_id' => $this->cocina->id, 'tipo' => 'candidato', 'candidato_id' => $ids['aprobado_rh'][0],
                'solicitada_en' => now(), 'avisados' => [$chef->id]]);
            Notificacion::create(['user_id' => $chef->id, 'tipo' => 'autorizacion_candidato', 'titulo' => 'RR. HH. aprobó', 'referencia_tipo' => 'autorizacion',
                'referencia_id' => $p->id, 'acciones' => [['etiqueta' => 'Bajar a entrevistar', 'url' => '/x']]]);

            return [$r, $p];
        });

        $migracion = require database_path('migrations/2026_10_20_000100_etapas_de_candidatos_fase_2.php');
        $migracion->up();
        $migracion->up();

        foreach ($mapa as $vieja => $nueva) {
            [$c, $p] = $ids[$vieja];
            $this->assertSame($nueva, DB::table('postulaciones')->where('id', $p)->value('etapa'), "Postulación {$vieja}");
            $this->assertSame($nueva, DB::table('candidatos')->where('id', $c)->value('etapa'), "Espejo {$vieja}");
            $this->assertSame($nueva, DB::table('candidato_eventos')->where('candidato_id', $c)->value('etapa_nueva'), "Historial {$vieja}");
        }
        // Canalizados: con su fecha y su entrevistador (quien respondió, o a quien se avisó)
        foreach (['aprobado_rh', 'entrevista'] as $vieja) {
            $p = DB::table('postulaciones')->where('id', $ids[$vieja][1])->first();
            $this->assertNotNull($p->canalizado_en);
            $this->assertSame($chef->id, (int) $p->entrevistador_id);
        }
        $this->assertNotNull(DB::table('postulaciones')->where('id', $ids['seleccionado'][1])->value('elegido_en'));
        // La autorización pendiente se canceló (medio «sistema») y su aviso perdió los botones; la respondida queda como historial
        $autPendiente->refresh();
        $this->assertSame(['cancelada', 'sistema'], [$autPendiente->estado, $autPendiente->respuesta_medio]);
        $this->assertSame('entrevista', $autRespondida->fresh()->estado);
        $this->assertNull($this->enEmpresa(fn () => Notificacion::where('referencia_id', $autPendiente->id)->value('acciones')));
        // El canalizado migrado aparece en los pendientes de su entrevistador
        Cache::flush();
        $this->assertSame(2, collect(app(MisPendientes::class)->para($chef)['items'])->firstWhere('clave', 'entrevistas')['total']);
    }

    // ------------------------------------------------------------------ Flujo completo

    public function test_flujo_completo_hasta_contratar(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $vacante = $this->vacante();
        $juan = $this->candidato('Juan Pérez Chan', $vacante, ['correo' => 'juan@correo.mx', 'curp' => 'PECJ960410HQRRHN05']);
        $otra = $this->candidato('Laura Gómez Poot', $vacante);

        // Atender → Entrevistar (abre la evaluación) → evaluación de RR. HH.
        $this->actingAs($this->rh)->get("/candidatos/{$juan->id}")->assertOk()->assertSee('Entrevistar')->assertDontSee('Canalizar al departamento</button>', false);
        $this->actingAs($this->rh)->patch("/candidatos/{$juan->id}/etapa", ['etapa' => 'entrevista_rh'])->assertSessionHas('abrir_dialogo', 'evaluacion');
        $this->actingAs($this->rh)->get("/candidatos/{$juan->id}")->assertSee('id="dialogoEvaluacion"', false)->assertSee('Experiencia para el puesto');
        $this->evaluarRh($this->rh, $juan, 'canalizar', 'Buena presentación', [5, 4, 4, 5, 3])->assertSessionHas('ok')->assertSessionHas('abrir_dialogo', 'canalizar');
        $rh = $this->enEmpresa(fn () => EvaluacionCandidato::sole());
        $this->assertSame(['rh', 'canalizar', 4.2, 1], [$rh->tipo, $rh->resultado, (float) $rh->promedio, $rh->numero]);
        $this->assertSame(5, $rh->criterios['Presentación']);
        $this->assertSame('entrevista_rh', $juan->fresh()->etapa);
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->exists()), 'Evaluar no avisa al jefe');

        // Canalizar: la jefa responsable sale por omisión; aviso por campana, Mis pendientes y correo firmado (sin datos oficiales)
        $ficha = $this->actingAs($this->rh)->get("/candidatos/{$juan->id}")->assertOk()->assertSee('Canalizar al departamento');
        $ficha->assertSee('<option value="'.$this->jefa->id.'" selected>', false);
        $cita = now()->addDay()->setTime(17, 0)->startOfMinute();
        $this->canalizar($this->rh, $juan, $this->jefa, $this->recepcion->id, $this->citaLocal($cita), ['avisar_candidato' => '1'])->assertSessionHas('ok');
        $p = $this->postulacion($juan);
        $this->assertSame(['canalizado', $this->jefa->id, 1], [$p->etapa, $p->entrevistador_id, $p->numero_entrevista]);
        $this->assertTrue($p->cita_en->eq($cita));
        $aviso = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('tipo', 'entrevista_asignada')->sole());
        $this->assertStringStartsWith('Entrevista: Juan Pérez Chan', $aviso->titulo);
        $this->assertStringContainsString('Buena presentación', (string) $aviso->texto);
        $this->assertStringNotContainsString('PECJ960410', (string) $aviso->texto);
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->jefa->email) && str_contains($m->botones[0][1], 'signature=')
            && ! str_contains(implode(' ', $m->lineas), 'PECJ960410') && str_contains(implode(' ', $m->lineas), 'Evaluación de RR. HH.'));
        Mail::assertSent(AvisoEntrevistaCandidato::class, fn ($m) => $m->hasTo('juan@correo.mx'));

        // El botón del correo pide sesión y abre la entrevista
        $firmada = URL::temporarySignedRoute('entrevistas.correo', now()->addHour(), ['postulacion' => $p->id]);
        auth()->logout();
        $this->get($firmada)->assertRedirect('/login');
        $this->actingAs($this->jefa)->get($firmada)->assertRedirect(route('entrevistas.show', $p->id));
        $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}/correo")->assertForbidden(); // sin firma

        // Entrevistar: resumen sin datos oficiales y la evaluación de RR. HH.
        $this->actingAs($this->jefa)->get('/entrevistas')->assertOk()->assertSee('Juan Pérez Chan')->assertDontSee('Laura Gómez Poot');
        $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}")->assertOk()->assertSee('Bachillerato')->assertSee('Buena presentación')
            ->assertSee('Elegir')->assertDontSee('PECJ960410HQRRHN05')->assertDontSee('juan@correo.mx');

        // Laura también va con la jefa (misma vacante)
        $this->hastaCanalizado($otra)->assertSessionHas('ok');

        // La jefa elige a Juan: queda «Elegido»; Laura pasa a «Considerar» (1 plaza) y RR. HH. recibe los avisos
        $this->evaluarDepartamento($this->jefa, $p->id, 'elegir', null, 5)->assertRedirect('/entrevistas')->assertSessionHas('ok');
        $this->assertSame('elegido', $p->fresh()->etapa);
        $this->assertSame('elegido', $juan->fresh()->etapa);
        $laura = $this->postulacion($otra);
        $this->assertSame(['considerar', 'Se eligió a otra persona para esta vacante'], [$laura->etapa, $laura->motivo_descarte]);
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('titulo', 'Elegido por el departamento: Juan Pérez Chan')->exists()));
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('titulo', 'Vacante cubierta: «Recepcionista»')->exists()));
        // Sus avisos de entrevista ya no tienen pendientes
        $this->assertSame(0, $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('referencia_tipo', 'entrevista')->whereNull('leida_en')->count()));

        // RR. HH. contrata
        $this->actingAs($this->rh)->get("/candidatos/{$juan->id}")->assertSee('Contratar')->assertSee('Elegir')->assertSee('5.0');
        $this->actingAs($this->rh)->post("/candidatos/{$juan->id}/contratar", ['num_empleado' => '4001', 'nombre' => 'Juan', 'apellido_paterno' => 'Pérez',
            'apellido_materno' => 'Chan'])->assertSessionHas('ok');
        $this->assertSame('contratado', $juan->fresh()->etapa);

        // Bitácora legible
        $lector = app(LectorAuditoria::class);
        foreach (['candidatos.evaluado_rh', 'candidatos.canalizado', 'candidatos.evaluado_departamento', 'candidatos.correo_candidato'] as $evento) {
            $this->assertTrue(Auditoria::where('evento', $evento)->exists(), $evento);
        }
        $this->assertSame('Canalización al departamento', $lector->accion('candidatos.canalizado'));
        $this->actingAs($this->admin)->get('/auditoria')->assertOk()->assertSee('Evaluación de entrevista')->assertSee('Evaluación de la entrevista del departamento');
    }

    public function test_rh_considerar_o_rechazar_en_su_evaluacion_no_avisa_al_jefe(): void
    {
        $c = $this->candidato();
        $this->evaluarRh($this->rh, $c, 'rechazar')->assertSessionHasErrors('comentario');
        $this->evaluarRh($this->rh, $c, 'considerar', null, 9)->assertSessionHasErrors(['comentario', 'criterios.presentacion']);
        $this->assertSame('revision', $c->fresh()->etapa);
        $this->evaluarRh($this->rh, $c, 'rechazar', 'No tiene disponibilidad de horario')->assertSessionHas('ok');
        $p = $this->postulacion($c);
        $this->assertSame(['rechazado', 'No tiene disponibilidad de horario'], [$p->etapa, $p->motivo_descarte]);
        $otro = $this->candidato('Otra Persona');
        $this->evaluarRh($this->rh, $otro, 'considerar', 'Para la temporada alta')->assertSessionHas('ok');
        $this->assertSame('considerar', $otro->fresh()->etapa);
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->exists()));
        // No se canaliza sin la evaluación «Canalizar»
        $tercero = $this->candidato('Tercera Persona');
        $this->actingAs($this->rh)->patch("/candidatos/{$tercero->id}/etapa", ['etapa' => 'entrevista_rh']);
        $this->canalizar($this->rh, $tercero, $this->jefa, $this->recepcion->id)->assertSessionHas('error');
        $this->assertSame('entrevista_rh', $tercero->fresh()->etapa);
    }

    // ------------------------------------------------------------------ Segunda entrevista

    public function test_segunda_entrevista(): void
    {
        $c = $this->candidato();
        $this->hastaCanalizado($c)->assertSessionHas('ok');
        $p = $this->postulacion($c);
        $this->evaluarDepartamento($this->jefa, $p->id, 'rechazar')->assertSessionHasErrors('comentario'); // obligatorio para Considerar y Rechazar
        $this->assertSame('canalizado', $p->fresh()->etapa);
        $this->evaluarDepartamento($this->jefa, $p->id, 'segunda_entrevista', 'Que la conozca el gerente de noche')->assertSessionHas('ok');
        $this->assertSame('evaluado', $p->fresh()->etapa);
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('titulo', 'El departamento evaluó a Juan Pérez Chan')->exists()));

        // RR. HH.: «Segunda entrevista» la regresa a «Entrevista con el departamento» (2.ª)
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Segunda entrevista')->assertSee('Que la conozca el gerente de noche');
        $gerente = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->canalizar($this->rh, $c, $gerente, $this->recepcion->id)->assertSessionHas('ok');
        $p->refresh();
        $this->assertSame(['canalizado', 2, $gerente->id], [$p->etapa, $p->numero_entrevista, $p->entrevistador_id]);
        $this->evaluarDepartamento($this->jefa, $p->id, 'elegir')->assertNotFound(); // ya no es suya
        $this->evaluarDepartamento($gerente, $p->id, 'rechazar', 'No tiene inglés')->assertSessionHas('ok');
        $p->refresh();
        $this->assertSame('evaluado', $p->etapa, 'Rechazar del jefe: Recursos Humanos cierra');
        $ultima = $this->enEmpresa(fn () => EvaluacionCandidato::where('tipo', 'departamento')->orderByDesc('id')->first());
        $this->assertSame([2, 'rechazar'], [$ultima->numero, $ultima->resultado]);
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'rechazado', 'comentario' => 'El departamento no lo eligió'])->assertSessionHas('ok');
        $this->assertSame('rechazado', $c->fresh()->etapa);
    }

    // ------------------------------------------------------------------ No se presentó y reprogramar

    public function test_no_se_presento_y_reprogramar_avisa_al_anterior_y_al_nuevo(): void
    {
        $c = $this->candidato();
        $cita = now()->addHours(3)->startOfMinute();
        $this->hastaCanalizado($c, null, $cita)->assertSessionHas('ok');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Reprogramar')->assertDontSee('No se presentó</button>', false);
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/no-se-presento")->assertSessionHas('error'); // aún no es la hora

        $this->travelTo($cita->copy()->addMinutes(20));
        $this->flushSession(); // la sesión venció por inactividad: se entra de nuevo
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('No se presentó');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/no-se-presento")->assertSessionHas('ok');
        $p = $this->postulacion($c);
        $this->assertSame('no_se_presento', $p->etapa);
        $this->assertNotNull($p->no_se_presento_en);
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('titulo', 'Se canceló la entrevista de Juan Pérez Chan')->exists()));
        $this->evaluarDepartamento($this->jefa, $p->id, 'elegir')->assertSessionHas('error');

        // Reprogramar con otra persona: se avisa a la anterior y a la nueva
        $otro = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $nueva = now()->addDay()->setTime(10, 30);
        $this->canalizar($this->rh, $c, $otro, $this->recepcion->id, $this->citaLocal($nueva))->assertSessionHas('ok');
        $p->refresh();
        $this->assertSame(['canalizado', $otro->id], [$p->etapa, $p->entrevistador_id]);
        $this->assertTrue($p->cita_en->eq($nueva->copy()->startOfMinute()));
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $otro->id)->where('tipo', 'entrevista_asignada')->exists()));
        // Reprogramar sin cambiar de persona: «Se reprogramó la entrevista»
        $this->canalizar($this->rh, $c, $otro, $this->recepcion->id, $this->citaLocal($nueva->copy()->addHour()))->assertSessionHas('ok');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $otro->id)->where('titulo', 'like', 'Se reprogramó la entrevista: Juan Pérez Chan%')->exists()));
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, $this->citaLocal($nueva->copy()->addHours(2)))->assertSessionHas('ok');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $otro->id)->where('titulo', 'Ya no entrevistas a Juan Pérez Chan')->exists()));
        // Una cita que ya pasó no se acepta
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, $this->citaLocal(now()->subDay()))->assertSessionHasErrors('fecha');
        $this->assertTrue(Auditoria::where('evento', 'candidatos.reprogramado')->exists());
        $this->assertTrue(Auditoria::where('evento', 'candidatos.no_se_presento')->exists());
    }

    // ------------------------------------------------------------------ Quién evalúa

    public function test_solo_el_entrevistador_asignado_o_su_delegado_evalua_y_rh_no_elige(): void
    {
        $c = $this->candidato();
        $this->hastaCanalizado($c)->assertSessionHas('ok');
        $p = $this->postulacion($c);
        $otroJefe = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);

        foreach ([$otroJefe, $this->rh, $this->admin, $supervisor] as $quien) {
            $this->actingAs($quien)->get("/entrevistas/{$p->id}")->assertNotFound();
            $this->evaluarDepartamento($quien, $p->id, 'elegir')->assertNotFound();
        }
        $this->actingAs($this->agente)->get("/entrevistas/{$p->id}")->assertForbidden();
        $this->actingAs($this->agente)->get('/entrevistas')->assertForbidden();
        $this->assertSame('canalizado', $p->fresh()->etapa);
        // RR. HH. no tiene «Elegir» en la ficha
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertDontSee('value="elegir"', false);
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'elegido'])->assertSessionHasErrors('etapa');
        // RR. HH. no se puede asignar a sí mismo como entrevistador (no es responsable del departamento)
        $this->assertStringNotContainsString('value="'.$this->rh->id.'"', $this->opcionesEntrevistador($c));
        $this->assertStringContainsString('value="'.$this->jefa->id.'"', $this->opcionesEntrevistador($c));
        $this->canalizar($this->rh, $c, $this->rh, $this->recepcion->id)->assertSessionHasErrors('entrevistador_id');

        // La jefa delega («No molestar»): el delegado la ve y evalúa; el aviso nuevo le llega a él
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->jefa->id, 'delegado_id' => $otroJefe->id, 'desde' => now()->subHour(), 'hasta' => now()->addDay()]));
        $this->actingAs($otroJefe)->get('/entrevistas')->assertOk()->assertSee('Juan Pérez Chan')->assertSee('Por delegación de');
        $segundo = $this->candidato('Ana Pool');
        $this->hastaCanalizado($segundo)->assertSessionHas('ok');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $otroJefe->id)->where('tipo', 'entrevista_asignada')->exists()), 'El aviso va al delegado');
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('titulo', 'like', '%Ana Pool%')->exists()));
        $this->evaluarDepartamento($otroJefe, $p->id, 'considerar', 'Bien, pero sin experiencia en noche')->assertSessionHas('ok');
        $e = $this->enEmpresa(fn () => EvaluacionCandidato::where('tipo', 'departamento')->sole());
        $this->assertSame($otroJefe->id, $e->evaluador_id);
        $this->assertSame('evaluado', $p->fresh()->etapa);
        // Quien evaluó puede volver a verla (solo lectura)
        $this->actingAs($otroJefe)->get("/entrevistas/{$p->id}")->assertOk()->assertSee('Esta entrevista ya se evaluó');
    }

    public function test_el_jefe_no_ve_datos_oficiales_ni_el_cv_sin_la_casilla(): void
    {
        $vacante = $this->vacante();
        $c = $this->candidato('Juan Pérez Chan', $vacante, ['curp' => 'PECJ960410HQRRHN05', 'rfc' => 'PECJ960410AB1', 'nss' => '12345678901',
            'calle_numero' => 'Calle 10 Mz 2', 'colonia' => 'Región 100', 'correo' => 'juan@correo.mx']);
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'cv',
            'documento' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF\n")])->assertSessionHas('ok');
        $this->hastaCanalizado($c)->assertSessionHas('ok');
        $p = $this->postulacion($c);

        $pagina = $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}")->assertOk()->assertSee('Resumen del candidato');
        foreach (['PECJ960410HQRRHN05', 'PECJ960410AB1', '12345678901', 'Calle 10 Mz 2', 'Región 100', 'juan@correo.mx', $c->telefono] as $dato) {
            $pagina->assertDontSee($dato);
        }
        $pagina->assertDontSee('Ver su CV');
        $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}/cv")->assertNotFound();
        $this->actingAs($this->jefa)->get("/candidatos/{$c->id}")->assertForbidden();
        $doc = $this->enEmpresa(fn () => CandidatoDocumento::sole());
        $this->actingAs($this->jefa)->get("/candidatos/{$c->id}/documentos/{$doc->id}")->assertForbidden();

        // Con «El jefe puede ver el CV»: el PDF por ruta autorizada
        $this->enEmpresa(fn () => Vacante::whereKey($vacante->id)->update(['jefe_ve_cv' => true]));
        $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}")->assertSee('Ver su CV');
        $r = $this->actingAs($this->jefa)->get("/entrevistas/{$p->id}/cv")->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        // Otro jefe no
        $this->actingAs($this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro))->get("/entrevistas/{$p->id}/cv")->assertNotFound();
        // La casilla se guarda desde la vacante (apagada por omisión)
        $this->assertFalse((bool) $this->vacante('Mesero')->jefe_ve_cv);
    }

    // ------------------------------------------------------------------ Plazas

    public function test_con_dos_plazas_los_demas_pasan_a_considerar_hasta_cubrirlas(): void
    {
        $vacante = $this->vacante('Recepcionista', 2);
        $candidatos = collect(['Ana Uno', 'Beto Dos', 'Carla Tres'])->map(fn ($n) => $this->candidato($n, $vacante));
        foreach ($candidatos as $c) {
            $this->hastaCanalizado($c)->assertSessionHas('ok');
        }
        $ps = $candidatos->map(fn ($c) => $this->postulacion($c));
        // Una evaluada (no elegida) también cuenta como «con el departamento»
        $this->evaluarDepartamento($this->jefa, $ps[2]->id, 'considerar', 'Quizá más adelante')->assertSessionHas('ok');

        $this->evaluarDepartamento($this->jefa, $ps[0]->id, 'elegir')->assertSessionHas('ok');
        $this->assertSame(['elegido', 'canalizado', 'evaluado'], $ps->map(fn ($p) => $p->fresh()->etapa)->all(), 'Queda una plaza');
        $this->evaluarDepartamento($this->jefa, $ps[1]->id, 'elegir')->assertSessionHas('ok');
        $this->assertSame(['elegido', 'elegido', 'considerar'], $ps->map(fn ($p) => $p->fresh()->etapa)->all(), 'Plazas cubiertas');
        $this->assertSame('Se eligió a otra persona para esta vacante', $ps[2]->fresh()->motivo_descarte);
        // Sin el dato, una vacante tiene 1 plaza
        $this->assertSame(1, (new Vacante)->plazas);
    }

    // ------------------------------------------------------------------ Correo al candidato

    public function test_correo_al_candidato_con_su_cita_y_nunca_resultados(): void
    {
        Mail::fake();
        $c = $this->candidato('Juan Pérez Chan', null, ['correo' => 'juan@correo.mx']);
        // Sin correo configurado: la casilla aparece apagada y no se envía
        $this->evaluarRh($this->rh, $c)->assertSessionHas('ok');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('La plataforma no tiene correo configurado.');
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, $this->citaLocal(now()->addDay()), ['avisar_candidato' => '1'])->assertSessionHas('ok');
        Mail::assertNotSent(AvisoEntrevistaCandidato::class);
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('evento', 'correo_candidato')->where('comentario', 'like', 'No se pudo enviar%')->exists()));

        // Con correo configurado: al reprogramar se le envía fecha, hora, lugar y a quién buscar (texto neutral)
        $this->configurarCorreo();
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Se le envía a juan@correo.mx');
        $cita = now($this->empresa->refresh()->zona_horaria ?: 'America/Mexico_City')->addDays(2)->setTime(11, 0)->utc();
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, $this->citaLocal($cita), ['avisar_candidato' => '1', 'lugar' => 'Oficina de Recepción'])
            ->assertSessionHas('ok');
        Mail::assertSent(AvisoEntrevistaCandidato::class, function (AvisoEntrevistaCandidato $m) {
            $html = $m->render();

            return $m->hasTo('juan@correo.mx') && $m->hora === '11:00' && $m->lugar === 'Oficina de Recepción' && $m->buscar === $this->jefa->name
                && str_contains($html, 'Hotel Ébano') && ! str_contains($html, 'Evaluación') && ! str_contains($html, 'promedio');
        });
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('evento', 'correo_candidato')->where('comentario', 'like', 'Se le envió por correo%')->exists()));
        // Sin la casilla, o «Ahora, está en sala», no se envía
        Mail::fake();
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, $this->citaLocal($cita->copy()->addHour()), ['avisar_candidato' => '0'])->assertSessionHas('ok');
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id, null, ['avisar_candidato' => '1'])->assertSessionHas('ok');
        Mail::assertNotSent(AvisoEntrevistaCandidato::class);
        // El rechazo nunca se le envía
        $this->evaluarDepartamento($this->jefa, $this->postulacion($c)->id, 'rechazar', 'No le interesa el turno')->assertSessionHas('ok');
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'rechazado', 'comentario' => 'No le interesa el turno'])->assertSessionHas('ok');
        Mail::assertNotSent(AvisoEntrevistaCandidato::class);
        Mail::assertNotSent(AvisoRecepcion::class, fn ($m) => $m->hasTo('juan@correo.mx'));
    }

    // ------------------------------------------------------------------ Mis pendientes y llegada

    public function test_mis_pendientes_de_rh_y_del_entrevistador(): void
    {
        $a = $this->candidato('Ana Uno');                     // revisión → Por entrevistar
        $b = $this->candidato('Beto Dos');
        $this->hastaCanalizado($b, null, now()->addHours(2)); // canalizado → Entrevistas por evaluar (jefa)
        $c = $this->candidato('Carla Tres');
        $this->hastaCanalizado($c);
        $this->evaluarDepartamento($this->jefa, $this->postulacion($c)->id, 'considerar', 'Le falta experiencia'); // evaluado
        $d = $this->candidato('Daniel Cuatro');
        $this->hastaCanalizado($d);
        $this->evaluarDepartamento($this->jefa, $this->postulacion($d)->id, 'elegir'); // elegido

        Cache::flush();
        $rh = collect(app(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame(1, $rh['por_entrevistar']['total']);
        $this->assertSame(route('candidatos.index', ['etapa' => 'por_entrevistar']), $rh['por_entrevistar']['url']);
        $this->assertSame(1, $rh['evaluaciones_departamento']['total']);
        $this->assertSame(1, $rh['elegidos']['total']);
        $this->assertSame('Elegidos por contratar', $rh['elegidos']['titulo']);
        $this->actingAs($this->rh)->get('/candidatos?etapa=por_entrevistar')->assertOk()->assertSee('Ana Uno')->assertDontSee('Beto Dos');

        $jefa = collect(app(MisPendientes::class)->para($this->jefa)['items'])->keyBy('clave');
        $this->assertSame(1, $jefa['entrevistas']['total']);
        $this->assertStringStartsWith('Entrevistas por evaluar · próxima: ', $jefa['entrevistas']['titulo']);
        $this->assertSame(route('entrevistas.index'), $jefa['entrevistas']['url']);
        $this->assertArrayNotHasKey('por_entrevistar', $jefa->all());
        // La campana suma el total
        foreach ([$this->rh, $this->jefa] as $u) {
            Cache::flush();
            $total = app(MisPendientes::class)->para($u)['total'];
            $this->actingAs($u)->getJson('/notificaciones/resumen')->assertJsonPath('pendientes.total', $total);
        }
        $this->actingAs($this->jefa)->get('/')->assertOk()->assertSee('1 entrevista espera tu evaluación');
        $this->actingAs($this->jefa)->get('/autorizaciones')->assertOk()->assertSee('Entrevistas (1)');
        // El agente no tiene esos renglones
        $this->assertSame([], array_intersect(['por_entrevistar', 'entrevistas'], array_column(app(MisPendientes::class)->para($this->agente)['items'], 'clave')));
        $this->assertNotNull($a->id);
    }

    public function test_aviso_de_llegada_a_la_entrevista(): void
    {
        // Llegó por caseta (ficha con su persona del padrón)
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Juan Pérez Chan', 'motivo_visita' => 'rh',
            'viene_a' => 'busca_empleo', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->enEmpresa(fn () => Acceso::query()->update(['estado' => 'finalizado', 'salida_at' => now()]));
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'revision'])->assertSessionHas('ok');
        $cita = now()->addMinutes(40)->startOfMinute();
        $this->hastaCanalizado($c, null, $cita)->assertSessionHas('ok');

        // Vuelve para su entrevista
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Juan Pérez Chan', 'motivo_visita' => 'rh',
            'viene_a' => 'entrevista', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine', 'persona_decision' => 'misma'])->assertSessionHas('ok');
        $hora = $cita->copy()->setTimezone($this->empresa->refresh()->zona_horaria ?: 'America/Mexico_City')->format('H:i');
        $aviso = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('tipo', 'entrevista_llegada')->sole());
        $this->assertStringStartsWith('Juan Pérez Chan ya está en recepción para su entrevista d', $aviso->titulo);
        $this->assertStringContainsString($hora, $aviso->titulo);
        $this->assertSame(route('entrevistas.show', $this->postulacion($c)->id), $aviso->url);
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('evento', 'llegada_entrevista')->exists()));
        // Si viene a otra cosa (documentos) no se avisa al entrevistador
        $this->enEmpresa(fn () => Acceso::query()->update(['estado' => 'finalizado', 'salida_at' => now()]));
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Juan Pérez Chan', 'motivo_visita' => 'rh',
            'viene_a' => 'documentos', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine', 'persona_decision' => 'misma'])->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('tipo', 'entrevista_llegada')->count()));
    }

    // ------------------------------------------------------------------ Alcance por sede

    public function test_alcance_por_sede(): void
    {
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->playa);
        $this->responsable($this->recepcion, $jefePlaya, $this->playa);
        $c = $this->candidato();
        $this->evaluarRh($this->rh, $c)->assertSessionHas('ok');
        // En Centro no se ofrece ni se acepta al jefe de Playa
        $this->assertStringNotContainsString('value="'.$jefePlaya->id.'"', $this->opcionesEntrevistador($c));
        $this->canalizar($this->rh, $c, $jefePlaya, $this->recepcion->id)->assertSessionHasErrors('entrevistador_id');
        $this->canalizar($this->rh, $c, $this->jefa, $this->recepcion->id)->assertSessionHas('ok');
        $p = $this->postulacion($c);
        // Aunque se le asignara a la fuerza, su alcance (Playa) no le deja ver ni evaluar una de Centro
        $this->enEmpresa(fn () => Postulacion::whereKey($p->id)->update(['entrevistador_id' => $jefePlaya->id]));
        $this->actingAs($jefePlaya)->get("/entrevistas/{$p->id}")->assertNotFound();
        $this->evaluarDepartamento($jefePlaya, $p->id, 'elegir')->assertNotFound();
        $this->assertSame('canalizado', $p->fresh()->etapa);
        // Un jefe de Playa sí entrevista a un candidato de Playa
        $deLaPlaya = $this->candidato('Rosa Pool', null, [], $this->playa);
        $this->hastaCanalizado($deLaPlaya, $jefePlaya)->assertSessionHas('ok');
        $this->evaluarDepartamento($jefePlaya, $this->postulacion($deLaPlaya)->id, 'elegir')->assertSessionHas('ok');
        // Otra empresa: 404
        $ajeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($ajeno)->get("/entrevistas/{$p->id}")->assertNotFound();
        $this->actingAs($ajeno)->post("/candidatos/{$c->id}/canalizar", [])->assertNotFound();
    }

    // ------------------------------------------------------------------ Criterios configurables

    public function test_criterios_configurables_en_los_ajustes_de_recepcion(): void
    {
        $this->actingAs($this->rh)->get('/rh/recepcion/ajustes')->assertOk()->assertSee('Criterios para calificar las entrevistas')
            ->assertSee('value="Expectativa económica"', false);
        $guardar = fn (array $criterios) => $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['aviso_privacidad' => 'Aviso de prueba.', 'rh_autoriza_paso' => '0',
            'criterios' => $criterios]);
        $guardar(['', ''])->assertSessionHasErrors('criterios');
        $guardar(['Inglés', 'inglés'])->assertSessionHasErrors('criterios');
        $guardar(array_map(fn ($i) => "Criterio {$i}", range(1, 9)))->assertSessionHasErrors('criterios');
        $guardar(['Inglés', '', 'Servicio al cliente', 'Puntualidad'])->assertSessionHas('ok');
        $this->assertSame(['Inglés', 'Servicio al cliente', 'Puntualidad'], app(AjustesRecepcion::class)->criterios($this->empresa->refresh()));
        $this->assertTrue(Auditoria::where('evento', 'candidatos.configurado')->exists());

        $c = $this->candidato();
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Servicio al cliente')->assertDontSee('Expectativa económica');
        $nuevos = ['Inglés', 'Servicio al cliente', 'Puntualidad'];
        // Faltó uno: error en ese criterio
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/evaluacion-rh", ['criterios' => ['ingles' => '5', 'servicio_al_cliente' => '4'], 'resultado' => 'canalizar'])
            ->assertSessionHasErrors('criterios.puntualidad');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/evaluacion-rh", ['criterios' => $this->calificaciones([5, 4, 3], $nuevos), 'resultado' => 'canalizar'])
            ->assertSessionHas('ok');
        $e = $this->enEmpresa(fn () => EvaluacionCandidato::sole());
        $this->assertSame(['Inglés' => 5, 'Servicio al cliente' => 4, 'Puntualidad' => 3], $e->criterios);
        $this->assertSame('4.00', (string) $e->promedio);

        // Si cambian los criterios, la evaluación conserva los suyos
        $guardar(AjustesRecepcion::CRITERIOS_DEFECTO)->assertSessionHas('ok');
        $this->assertSame(AjustesRecepcion::CRITERIOS_DEFECTO, app(AjustesRecepcion::class)->criterios($this->empresa->refresh()));
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Servicio al cliente')->assertSee('Presentación');
        // Quien solo consulta no guarda
        $this->actingAs($this->jefa)->put('/rh/recepcion/ajustes', ['aviso_privacidad' => 'x', 'criterios' => ['Uno']])->assertForbidden();
    }

    // ------------------------------------------------------------------ Vacante, Recepción y permiso

    public function test_tabla_de_candidatos_de_la_vacante_para_comparar(): void
    {
        $vacante = $this->vacante();
        $a = $this->candidato('Ana Uno', $vacante);
        $b = $this->candidato('Beto Dos', $vacante);
        $this->hastaCanalizado($a)->assertSessionHas('ok');
        $this->evaluarDepartamento($this->jefa, $this->postulacion($a)->id, 'considerar', 'Buena, pero sin inglés', 3)->assertSessionHas('ok');
        $sinVacante = $this->candidato('Carla Sin Vacante');

        $this->actingAs($this->rh)->get('/vacantes')->assertOk()->assertSee(route('vacantes.candidatos', $vacante->id), false);
        $this->actingAs($this->rh)->get("/vacantes/{$vacante->id}/candidatos")->assertOk()->assertSee('Candidatos de esta vacante')
            ->assertSee('Ana Uno')->assertSee('Beto Dos')->assertDontSee('Carla Sin Vacante')->assertSee('Considerar')->assertSee('3.0')->assertSee('4.0');
        // La jefa ve solo los que le canalizaron
        $this->actingAs($this->jefa)->get("/vacantes/{$vacante->id}/candidatos")->assertOk()->assertSee('Ana Uno')->assertDontSee('Beto Dos');
        // La caseta ve la vacante, pero no sus candidatos
        $this->actingAs($this->agente)->get("/vacantes/{$vacante->id}/candidatos")->assertForbidden();
        $this->actingAs($this->agente)->get('/vacantes')->assertDontSee(route('vacantes.candidatos', $vacante->id), false);
        $this->assertNotNull($b->id.$sinVacante->id);
    }

    public function test_recepcion_cuenta_las_etapas_nuevas(): void
    {
        $this->candidato('Ana Uno');
        $b = $this->candidato('Beto Dos');
        $this->hastaCanalizado($b)->assertSessionHas('ok');
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertOk()->assertJsonPath('contadores.revision', 1)
            ->assertJsonPath('contadores.departamento', 1)->assertJsonPath('contadores.evaluado', 0);
        $this->actingAs($this->rh)->get('/rh/recepcion')->assertOk()->assertSee('Entrevista con el departamento');
        $this->actingAs($this->rh)->get('/rh/recepcion/metricas')->assertOk()->assertSee('Del aviso al departamento a su evaluación');
        $this->actingAs($this->rh)->get('/candidatos/exportar')->assertOk();
    }

    public function test_permiso_evaluar_en_las_plantillas(): void
    {
        $permiso = fn (string $rol) => RolPermiso::whereHas('moduloAccion', fn ($q) => $q->whereHas('modulo', fn ($m) => $m->where('clave', 'candidatos'))
            ->whereHas('accion', fn ($a) => $a->where('clave', 'evaluar')))->where('rol_id', Rol::where('empresa_id', $this->empresa->id)->where('nombre', $rol)->value('id'))
            ->first()?->alcance;
        $this->assertSame(Alcance::Sede, $permiso('Jefe de departamento'));
        $this->assertSame(Alcance::Empresa, $permiso('Director'));
        $this->assertSame(Alcance::Sede, $permiso('Jefe de seguridad'));
        $this->assertSame(Alcance::Sede, $permiso('Supervisor'));
        $this->assertNull($permiso('Agente'));
        $this->assertNull($permiso('Solicitante'));
        // Sin candidatos.ver: no ven la lista ni las fichas (solo Entrevistar)
        $this->actingAs($this->jefa)->get('/candidatos')->assertForbidden();
        $this->actingAs($this->jefa)->get('/entrevistas')->assertOk();
        // La jefa del departamento ve la página del manual para entrevistar
        $this->actingAs($this->jefa)->get('/manual/entrevistar-y-elegir')->assertOk()->assertSee('Entrevistar y elegir');
        $this->assertTrue(app(Autorizador::class)->puede($this->jefa, 'autorizaciones.responder'), 'Sigue respondiendo visitas');

        // En la matriz, «Evaluar» no marca «Ver»: guardar el rol no le abre los CV al jefe
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Jefe de departamento')->firstOrFail();
        $this->actingAs($this->admin)->get('/permisos?rol='.$rol->id)->assertOk()->assertSee('data-sin-ver', false);
        $this->actingAs($this->admin)->put("/permisos/{$rol->id}", ['permisos' => [
            'candidatos' => ['acciones' => ['evaluar'], 'alcance' => 'sede'],
            'autorizaciones' => ['acciones' => ['responder'], 'alcance' => 'sede'],
        ]])->assertSessionHas('ok');
        $claves = RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')->where('rol_id', $rol->id)->get()->map(fn ($p) => $p->moduloAccion->clave())->all();
        $this->assertContains('candidatos.evaluar', $claves);
        $this->assertNotContains('candidatos.ver', $claves);
        $this->assertContains('autorizaciones.ver', $claves, 'Las demás acciones sí implican Ver');
        app(Autorizador::class)->olvidar();
        $this->actingAs($this->jefa)->get('/candidatos')->assertForbidden();
    }

    public function test_el_demo_trae_la_vacante_con_tres_candidatos_en_distintas_etapas(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful()->doesntExpectOutputToContain('No se pudo completar');
        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $jefa = User::where('username', 'jefedepto.demo')->firstOrFail();
        app(Tenant::class)->conEmpresa($demo->id, function () use ($jefa) {
            $v = Vacante::where('titulo', 'Recepcionista')->firstOrFail();
            $this->assertTrue($v->jefe_ve_cv);
            $etapas = Postulacion::where('vacante_id', $v->id)->with('candidato')->get()->mapWithKeys(fn ($p) => [$p->candidato->nombre_completo => $p->etapa])->all();
            $this->assertSame(['Juan Pérez Chan' => 'canalizado', 'Laura Gómez Poot' => 'evaluado', 'Miguel Ángel Cauich Pech' => 'revision'], $etapas);
            $juan = Postulacion::where('vacante_id', $v->id)->where('etapa', 'canalizado')->sole();
            $this->assertSame($jefa->id, $juan->entrevistador_id);
            $this->assertTrue($juan->cita_en->copy()->setTimezone('America/Cancun')->isSameDay(now()->setTimezone('America/Cancun')) || $juan->cita_en->isFuture());
            $this->assertSame(3, EvaluacionCandidato::whereIn('postulacion_id', Postulacion::where('vacante_id', $v->id)->select('id'))->count());
        });
        $this->actingAs($jefa)->get('/entrevistas')->assertOk()->assertSee('Juan Pérez Chan');
    }
}
