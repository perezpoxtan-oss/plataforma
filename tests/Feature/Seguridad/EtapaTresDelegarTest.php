<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Candidatos\Entrevistas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 3 · C-12 (DD-22, A-10): «Delegar mis aprobaciones» («No molestar»)
 * vive en Mi cuenta. Se elige a quién y hasta cuándo; las autorizaciones de
 * visita, Mis pendientes y la campana lo respetan; Pases y Reservas tienen
 * su prueba en sus archivos (PasesSalidaJefeDepartamentoTest y ReservasDeAreasTest).
 */
class EtapaTresDelegarTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $jefa;

    private User $suplente;

    private User $agente;

    private Departamento $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Delegar');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->jefa = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->suplente = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->recepcion = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->recepcion->id, 'user_id' => $this->jefa->id, 'sede_id' => $this->centro->id]));
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['visitas_requieren_autorizacion' => true]]])->save();
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function visita(string $nombre): Acceso
    {
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => $nombre,
            'motivo_visita' => 'departamento', 'departamento_id' => $this->recepcion->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Acceso::where('nombre', mb_strtoupper($nombre))->firstOrFail());
    }

    private function pendientes(User $u): int
    {
        $this->app->forgetScopedInstances();

        return collect(app()->build(MisPendientes::class)->para($u->fresh())['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? 0;
    }

    public function test_mi_cuenta_ofrece_delegar_solo_a_quien_aprueba_algo(): void
    {
        $this->actingAs($this->jefa)->get('/mi-cuenta')->assertOk()
            ->assertSee('Delegar mis aprobaciones')->assertSee('visitas')->assertSee('¿A quién?')->assertSee('¿Hasta cuándo?')
            ->assertSee($this->suplente->name);
        // El agente no aprueba nada: no ve la sección ni puede delegar
        $this->actingAs($this->agente)->get('/mi-cuenta')->assertOk()->assertDontSee('Delegar mis aprobaciones');
        $this->actingAs($this->agente)->post('/mi-cuenta/delegar', ['delegado_id' => $this->suplente->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])->assertForbidden();
        // No se delega en quien no aprueba nada, ni en uno mismo
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->agente->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('delegado_id');
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->jefa->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('delegado_id');
        // Accesos directos desde Autorizaciones
        $this->actingAs($this->jefa)->get('/autorizaciones')->assertOk()->assertSee(route('mi-cuenta.index').'#delegar', false)->assertDontSee('No molestar / delegar');
    }

    public function test_mientras_delega_la_campana_y_mis_pendientes_van_al_delegado_y_al_cancelar_regresan(): void
    {
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->suplente->id, 'hasta' => now()->addDays(3)->format('Y-m-d\TH:i'), 'motivo' => 'Vacaciones'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, $this->suplente->name));
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('user_id', $this->suplente->id)->where('tipo', 'delegacion')->count());
        $this->actingAs($this->jefa)->get('/mi-cuenta')->assertSee('Hoy tus aprobaciones van a '.$this->suplente->name);
        $this->actingAs($this->jefa)->get('/autorizaciones')->assertSee('Delegaste tus aprobaciones a');

        $acceso = $this->visita('Ana Visita');
        $a = $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $acceso->id)->firstOrFail());
        $this->assertSame([$this->suplente->id], array_map('intval', $a->avisados), 'Campana: al delegado');
        $this->assertSame(0, $this->pendientes($this->jefa), 'Mis pendientes de quien delegó: nada');
        $this->assertSame(1, $this->pendientes($this->suplente));
        // Inicio del delegado lo muestra; la jefa todavía puede responder si entra
        $this->actingAs($this->suplente)->get('/')->assertOk()->assertSee('espera tu autorización');
        $this->actingAs($this->jefa)->get("/autorizaciones/{$a->id}")->assertOk();

        // Cancelar en Mi cuenta: todo vuelve a la jefa
        $d = $this->enEmpresa(fn () => Delegacion::where('user_id', $this->jefa->id)->firstOrFail());
        $this->actingAs($this->jefa)->patch("/mi-cuenta/delegar/{$d->id}/cancelar")->assertSessionHas('ok');
        $this->assertSame(1, $this->pendientes($this->jefa));
        $otra = $this->visita('Beto Visita');
        $this->assertSame([$this->jefa->id], array_map('intval', $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $otra->id)->firstOrFail())->avisados));
        // Nadie cancela la delegación de otro desde su Mi cuenta
        $this->actingAs($this->suplente)->patch("/mi-cuenta/delegar/{$d->id}/cancelar")->assertNotFound();
    }

    public function test_si_el_delegado_no_puede_responder_visitas_el_aviso_se_queda_con_la_jefa(): void
    {
        // Un aprobador de pases (Solicitante no; Jefe de departamento de otra sede sí aprueba pases pero no entra a Centro)
        $playa = $this->crearSede($this->empresa, 'PLA');
        $deOtraSede = $this->crearUsuario($this->empresa, 'Jefe de departamento', $playa);
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $deOtraSede->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $acceso = $this->visita('Carla Visita');
        $this->assertSame([$this->jefa->id], array_map('intval', $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $acceso->id)->firstOrFail())->avisados));
        $this->assertSame(1, $this->pendientes($this->jefa));
    }

    public function test_entrevistas_el_aviso_va_al_delegado_solo_si_puede_entrevistar(): void
    {
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->jefa->id, 'delegado_id' => $this->suplente->id, 'desde' => now()->subHour(), 'hasta' => now()->addDay()]));
        $this->assertSame([$this->suplente->id], $this->enEmpresa(fn () => app(Entrevistas::class)->aQuienAvisar($this->jefa->id)->pluck('id')->all()));
        $solicitante = $this->crearUsuario($this->empresa, 'Solicitante', $this->centro);
        $this->enEmpresa(fn () => Delegacion::query()->update(['delegado_id' => $solicitante->id]));
        $this->assertSame([$this->jefa->id], $this->enEmpresa(fn () => app(Entrevistas::class)->aQuienAvisar($this->jefa->id)->pluck('id')->all()));
    }
}
