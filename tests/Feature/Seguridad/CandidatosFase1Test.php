<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Vacantes\AdministradorVacantes;
use App\Services\Vacantes\BolsaTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Candidatos, fase 1: postulaciones, una ficha por persona, «¿A qué viene?»
 * en la caseta y el «Que pase» de Recursos Humanos.
 */
class CandidatosFase1Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

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
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param array<string, mixed> $extra */
    private function caseta(array $extra = [], ?User $quien = null): TestResponse
    {
        return $this->actingAs($quien ?? $this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Karla Pérez Uc', 'motivo_visita' => 'rh',
            'viene_a' => 'busca_empleo', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);
    }

    private function rhAutorizaPaso(bool $si): void
    {
        $e = $this->empresa->refresh();
        $recepcion = array_merge($e->preferencias['recepcion'] ?? [], ['rh_autoriza_paso' => $si]);
        $e->forceFill(['preferencias' => array_merge($e->preferencias ?? [], ['recepcion' => $recepcion])])->save();
    }

    private function vacante(string $titulo = 'Camarista'): Vacante
    {
        return $this->enEmpresa(function () use ($titulo) {
            $v = app(AdministradorVacantes::class)->crear($this->rh, ['titulo' => $titulo, 'plazas' => 1, 'todas_las_sedes' => '1', 'tipo_contrato' => 'indeterminado',
                'jornada' => 'completa', 'sueldo_a_tratar' => '1']);
            app(AdministradorVacantes::class)->cambiarEstado($this->rh, $v, 'publicada', null, AdministradorVacantes::hoy($this->empresa->refresh()));

            return $v->fresh();
        });
    }

    private function salida(Acceso $a): void
    {
        $this->enEmpresa(fn () => Acceso::whereKey($a->id)->update(['estado' => 'finalizado', 'salida_at' => now()]));
    }

    private function firmaImagen(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 40, 150, 560, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    // ------------------------------------------------------------------ Una ficha por persona

    public function test_internet_y_rh_son_la_misma_ficha_y_rh_ve_el_aviso_de_duplicado(): void
    {
        $this->rhAutorizaPaso(false);
        $vacante = $this->vacante();
        $this->enEmpresa(fn () => app(BolsaTrabajo::class)->postular($this->empresa->refresh(), $vacante->fresh('sedes'), [
            'nombre' => 'Karla', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Uc', 'telefono' => '998 123 4567', 'sede_id' => $this->centro->id,
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firmaImagen(), 'curp' => 'PEUK970815MQRRCR09',
        ], null, '187.190.10.20'));
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame('web', $c->origen);

        // Se vuelve a postular por internet (mismo teléfono): misma ficha y misma postulación abierta
        $this->enEmpresa(fn () => app(BolsaTrabajo::class)->postular($this->empresa->refresh(), $vacante->fresh('sedes'), [
            'nombre' => 'Karla', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Uc', 'telefono' => '9981234567', 'sede_id' => $this->centro->id,
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firmaImagen(),
        ], null, '187.190.10.20'));
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
        $this->assertSame(1, $this->enEmpresa(fn () => Postulacion::count()));
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('comentario', 'like', 'Se volvió a postular por internet%')->exists()));

        // «Nuevo candidato» de RR. HH.: aviso en vivo con «Abrir su ficha»
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=telefono&valor=998-123-4567')->assertOk()
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Karla Pérez Uc')
            ->assertJsonPath('coincidencias.0.abrir', route('candidatos.show', $c->id));
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=curp&valor=PEUK970815MQRRCR09')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=telefono&valor=9990000000')->assertJsonPath('estado', 'nada');
        $this->actingAs($this->agente)->getJson('/candidatos/duplicado?campo=telefono&valor=9981234567')->assertForbidden();
        $this->actingAs($this->rh)->get('/candidatos')->assertSee('data-duplicado="'.route('candidatos.duplicado').'"', false);

        // Y si guarda de todos modos: no se crea otra ficha
        $this->actingAs($this->rh)->post('/candidatos', ['sede_id' => $this->centro->id, 'nombre_completo' => 'Karla Pérez Uc', 'telefono' => '9981234567',
            'acepta_privacidad' => '1'])->assertRedirect("/candidatos/{$c->id}")->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya tenía ficha'));
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
    }

    // ------------------------------------------------------------------ Migración, auditoría y demo

    public function test_la_migracion_crea_una_postulacion_por_ficha_y_es_idempotente(): void
    {
        $vacante = $this->vacante();
        [$ficha, $acceso] = $this->enEmpresa(function () use ($vacante) {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'PEDRO UICAB', 'motivo_visita' => 'rh', 'entrada_at' => now()]);
            $a->forceFill(['estado' => 'finalizado'])->save();
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Pedro Uicab', 'acceso_id' => $a->id, 'vacante' => 'Cocinero', 'origen' => 'caseta']);
            $c->forceFill(['etapa' => 'descartado', 'motivo_descarte' => 'Sin experiencia', 'vacante_id' => $vacante->id, 'revision_en' => now()->subDay(),
                'decision_en' => now(), 'decision_por' => $this->rh->id])->save();
            $tramite = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'MARIO EK', 'motivo_visita' => 'rh', 'entrada_at' => now()]);
            $tramite->save();

            return [$c, $a];
        });
        // Una empresa que ya existía (sin el ajuste guardado)
        $this->empresa->refresh()->forceFill(['preferencias' => ['recepcion' => ['visitas_requieren_autorizacion' => true]]])->save();

        $migracion = require database_path('migrations/2026_10_19_000100_crear_postulaciones.php');
        $migracion->up();
        $migracion->up();

        $p = $this->enEmpresa(fn () => Postulacion::where('candidato_id', $ficha->id)->sole());
        $this->assertSame(['descartado', 'Sin experiencia', $vacante->id, 'Cocinero', 'caseta', $this->rh->id, $this->centro->id],
            [$p->etapa, $p->motivo_descarte, $p->vacante_id, $p->vacante, $p->origen, $p->decision_por, $p->sede_id]);
        $this->assertNotNull($p->revision_en);
        $acceso->refresh();
        $this->assertSame([$p->id, 'busca_empleo'], [$acceso->postulacion_id, $acceso->viene_a]);
        $this->assertSame('tramite', $this->enEmpresa(fn () => Acceso::where('nombre', 'MARIO EK')->value('viene_a')));
        // Empresas que ya existían: la caseta sigue igual (ajuste apagado) y no se pierde lo demás
        $this->empresa->refresh();
        $this->assertFalse($this->empresa->preferencias['recepcion']['rh_autoriza_paso']);
        $this->assertTrue($this->empresa->preferencias['recepcion']['visitas_requieren_autorizacion']);
    }
}
