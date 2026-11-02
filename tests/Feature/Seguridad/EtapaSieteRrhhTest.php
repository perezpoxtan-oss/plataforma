<?php

namespace Tests\Feature\Seguridad;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Candidatos\Postulaciones;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 7 · C-35: Recursos Humanos pulido. El turno de la vacante pasa al
 * colaborador al contratar; un solo campo «¿A qué vacante o puesto?» que
 * deduce el departamento (el texto libre solo si no está en el catálogo);
 * la lista de Candidatos corta (lo demás en «Avanzado») y las etapas con
 * un solo nombre en todas las pantallas.
 */
class EtapaSieteRrhhTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $rh;

    private Departamento $cocina;

    private Departamento $ayb;

    private Puesto $cocinero;

    private Puesto $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        [$this->cocina, $this->ayb, $this->cocinero, $this->mesero] = $this->enEmpresa(function () {
            $cocina = Departamento::create(['nombre' => 'Cocina', 'todas_las_sedes' => true]);
            $ayb = Departamento::create(['nombre' => 'Alimentos y Bebidas', 'todas_las_sedes' => true]);
            $cocinero = Puesto::create(['nombre' => 'Cocinero']);
            $cocinero->departamentos()->attach($cocina->id);
            $mesero = Puesto::create(['nombre' => 'Mesero']);
            $mesero->departamentos()->attach([$cocina->id, $ayb->id]); // dos departamentos: no se deduce

            return [$cocina, $ayb, $cocinero, $mesero];
        });
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function turno(string $nombre, bool $todas = true, array $sedes = []): Turno
    {
        return $this->enEmpresa(function () use ($nombre, $todas, $sedes) {
            $t = Turno::create(['nombre' => $nombre, 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => $todas, 'activo' => true]);
            if ($sedes !== []) {
                $t->sedes()->attach($sedes);
            }

            return $t;
        });
    }

    private function vacante(array $datos = []): Vacante
    {
        return $this->enEmpresa(function () use ($datos) {
            $v = new Vacante($datos + ['titulo' => 'Cocinero de línea', 'puesto_id' => $this->cocinero->id, 'departamento_id' => $this->cocina->id, 'todas_las_sedes' => true]);
            $v->forceFill(['estado' => 'publicada', 'publicada_en' => now(), 'codigo' => 'VAC-'.str_pad((string) (Vacante::count() + 1), 4, '0', STR_PAD_LEFT)])->save();

            return $v;
        });
    }

    private function nuevo(array $extra): Candidato
    {
        $this->actingAs($this->rh)->post('/candidatos', $extra + [
            'sede_id' => $this->centro->id, 'nombre' => 'Luis', 'apellido_paterno' => 'Chi', 'telefono' => '9981234567', 'acepta_privacidad' => '1',
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Candidato::orderByDesc('id')->firstOrFail());
    }

    private function elegido(Vacante $v): Candidato
    {
        return $this->enEmpresa(function () use ($v) {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Ramón Ek Balam', 'telefono' => '9987654321',
                'puesto_id' => $this->cocinero->id, 'departamento_id' => $this->cocina->id, 'origen' => 'rh', 'llegada_en' => now()]);
            $c->forceFill(['etapa' => 'registrado', 'vacante_id' => $v->id])->save();
            $p = app(Postulaciones::class)->asegurar($c);
            app(Postulaciones::class)->cambiar($p, null, ['etapa' => 'elegido'], null);

            return $c;
        });
    }

    private function contratar(Candidato $c, array $extra = []): Colaborador
    {
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", $extra + ['forzar' => '1', 'motivo_forzar' => 'Entrega documentos el lunes',
            'num_empleado' => '3001', 'nombre' => 'Ramón', 'apellido_paterno' => 'Ek'])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Colaborador::where('num_empleado', '3001')->sole());
    }

    // ------------------------------------------------------------------ Turno

    public function test_el_turno_de_la_vacante_pasa_al_colaborador_al_contratar(): void
    {
        $matutino = $this->turno('Matutino');
        $c = $this->elegido($this->vacante(['turno_id' => $matutino->id]));

        // El diálogo de contratar ya trae el turno de la vacante
        $ficha = $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$matutino->id.'" selected>Matutino/u', $ficha);
        $this->assertStringContainsString('Es el turno de la vacante', $ficha);

        // Sin tocar el turno (como llegaba antes, sin el campo) también lo toma
        $col = $this->contratar($c);
        $this->assertSame($matutino->id, (int) $col->turno_id);
        $this->assertSame($this->cocina->id, (int) $col->departamento_id);
        $this->assertSame($this->cocinero->id, (int) $col->puesto_id);
    }

    public function test_rrhh_puede_cambiar_el_turno_o_dejarlo_sin_turno(): void
    {
        $matutino = $this->turno('Matutino');
        $nocturno = $this->turno('Nocturno');
        $c = $this->elegido($this->vacante(['turno_id' => $matutino->id]));
        $col = $this->contratar($c, ['turno_id' => (string) $nocturno->id]);
        $this->assertSame($nocturno->id, (int) $col->turno_id);

        $otro = $this->enEmpresa(function () {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Ana Uc', 'telefono' => '9980000001', 'origen' => 'rh', 'llegada_en' => now()]);
            $c->forceFill(['etapa' => 'registrado'])->save();
            app(Postulaciones::class)->cambiar(app(Postulaciones::class)->asegurar($c), null, ['etapa' => 'elegido'], null);

            return $c;
        });
        $this->actingAs($this->rh)->post("/candidatos/{$otro->id}/contratar", ['forzar' => '1', 'motivo_forzar' => 'x', 'num_empleado' => '3002', 'nombre' => 'Ana', 'apellido_paterno' => 'Uc', 'turno_id' => ''])
            ->assertSessionHas('ok');
        $this->assertNull($this->enEmpresa(fn () => Colaborador::where('num_empleado', '3002')->sole())->turno_id);
    }

    public function test_un_turno_que_no_se_usa_en_la_sede_no_se_copia(): void
    {
        $soloPlaya = $this->turno('Playa matutino', false, [$this->playa->id]);
        $c = $this->elegido($this->vacante(['turno_id' => $soloPlaya->id]));
        $col = $this->contratar($c);
        $this->assertNull($col->turno_id, 'El turno de otra sede no se impone: Colaboradores lo rechazaría');
    }

    // ---------------------------------------------- «¿A qué vacante o puesto?»

    public function test_un_solo_campo_vacante_o_puesto_deduce_el_departamento(): void
    {
        $v = $this->vacante(['departamento_id' => $this->ayb->id]);
        $form = $this->actingAs($this->rh)->get('/candidatos')->assertOk()->getContent();
        $this->assertStringContainsString('¿A qué vacante o puesto?', $form);
        $this->assertStringContainsString('<option value="v:'.$v->id.'"', $form);
        $this->assertStringContainsString('<option value="p:'.$this->cocinero->id.'"', $form);
        $this->assertStringNotContainsString('Puesto (catálogo)', $form);
        $this->assertStringNotContainsString('Vacante (si no está en el catálogo)', $form);

        // Vacante publicada: su puesto y su departamento
        $c = $this->nuevo(['a_que' => 'v:'.$v->id]);
        $this->assertSame([$v->id, $this->cocinero->id, $this->ayb->id], [(int) $c->vacante_id, (int) $c->puesto_id, (int) $c->departamento_id]);
        $p = $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->sole());
        $this->assertSame($this->ayb->id, (int) $p->departamento_id);
        $this->assertSame($v->id, (int) $p->vacante_id);

        // Puesto de un solo departamento: se deduce
        $c2 = $this->nuevo(['a_que' => 'p:'.$this->cocinero->id, 'telefono' => '9981110000', 'nombre' => 'Eva']);
        $this->assertSame([$this->cocinero->id, $this->cocina->id], [(int) $c2->puesto_id, (int) $c2->departamento_id]);
        $this->assertNull($c2->vacante_id);

        // Puesto de dos departamentos: no se adivina; se puede elegir en «Avanzado»
        $c3 = $this->nuevo(['a_que' => 'p:'.$this->mesero->id, 'telefono' => '9981110001', 'nombre' => 'Iván']);
        $this->assertNull($c3->departamento_id);
        $c4 = $this->nuevo(['a_que' => 'p:'.$this->mesero->id, 'departamento_id' => (string) $this->ayb->id, 'telefono' => '9981110002', 'nombre' => 'Olga']);
        $this->assertSame($this->ayb->id, (int) $c4->departamento_id);
    }

    public function test_el_texto_libre_solo_se_usa_si_no_existe_en_el_catalogo(): void
    {
        $c = $this->nuevo(['a_que' => 'otro', 'vacante' => '  cocinero ']);
        $this->assertSame($this->cocinero->id, (int) $c->puesto_id, 'Coincide con un puesto: se liga al catálogo');
        $this->assertNull($c->vacante);
        $this->assertSame($this->cocina->id, (int) $c->departamento_id);

        $c2 = $this->nuevo(['a_que' => 'otro', 'vacante' => 'Ayudante de temporada', 'telefono' => '9981110003', 'nombre' => 'Pía']);
        $this->assertNull($c2->puesto_id);
        $this->assertSame('Ayudante de temporada', $c2->vacante);

        // Una vacante cerrada no se puede elegir
        $cerrada = $this->vacante();
        $this->enEmpresa(fn () => Vacante::whereKey($cerrada->id)->update(['estado' => 'cerrada']));
        $this->actingAs($this->rh)->post('/candidatos', ['sede_id' => $this->centro->id, 'nombre' => 'X', 'apellido_paterno' => 'Y', 'telefono' => '9981110004',
            'acepta_privacidad' => '1', 'a_que' => 'v:'.$cerrada->id])->assertSessionHasErrors('a_que');
    }

    public function test_al_editar_la_ficha_cambia_la_vacante_y_el_departamento_sigue(): void
    {
        $c = $this->nuevo(['a_que' => 'p:'.$this->cocinero->id]);
        $v = $this->vacante(['departamento_id' => $this->ayb->id, 'titulo' => 'Capitán de meseros', 'puesto_id' => null]);
        $ficha = $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="p:'.$this->cocinero->id.'" selected>/u', $ficha);

        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", ['nombre' => 'Luis', 'apellido_paterno' => 'Chi', 'telefono' => '9981234567', 'a_que' => 'v:'.$v->id, 'departamento_id' => ''])
            ->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame([$v->id, $this->ayb->id], [(int) $c->vacante_id, (int) $c->departamento_id]);
        $this->assertNull($c->puesto_id);
        $this->assertSame('Capitán de meseros', $c->puestoVisible());
        $p = $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->sole());
        $this->assertSame($this->ayb->id, (int) $p->departamento_id);
    }

    public function test_sin_el_campo_nuevo_los_campos_de_antes_siguen_funcionando(): void
    {
        // La caseta, el kiosco y la bolsa siguen mandando departamento, puesto y texto
        $c = $this->nuevo(['departamento_id' => (string) $this->ayb->id, 'puesto_id' => (string) $this->mesero->id]);
        $this->assertSame([$this->ayb->id, $this->mesero->id], [(int) $c->departamento_id, (int) $c->puesto_id]);
    }

    // ------------------------------------------------------- Lista corta

    public function test_lista_de_candidatos_corta_con_lo_demas_en_avanzado(): void
    {
        $html = $this->actingAs($this->rh)->get('/candidatos')->assertOk()->getContent();
        $arriba = substr($html, 0, (int) strpos($html, 'data-avanzado-candidatos'));
        $this->assertStringContainsString('Nuevo candidato', $arriba);
        $this->assertStringContainsString('Buscar candidato', $arriba);
        foreach (['Criterios de entrevista', 'Documentos y plazos', 'Todos los departamentos'] as $texto) {
            $this->assertStringNotContainsString($texto, $arriba, "«{$texto}» va en Avanzado");
        }
        $avanzado = substr($html, (int) strpos($html, 'data-avanzado-candidatos'));
        $this->assertStringContainsString('Criterios de entrevista', $avanzado);
        $this->assertStringContainsString('Documentos y plazos', $avanzado);
        $this->assertStringContainsString('form="filtrosCandidatos"', $avanzado);

        // Con un filtro de Avanzado puesto, Avanzado se abre solo
        $html = $this->actingAs($this->rh)->get('/candidatos?departamento='.$this->cocina->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-avanzado-candidatos\s+open/u', $html);
    }

    // ------------------------------------------------------- Etapas

    public function test_las_etapas_se_llaman_igual_en_todas_partes(): void
    {
        $this->assertSame('Considerar', Candidato::ETAPAS['considerar']);
        $this->assertSame(Candidato::BOTONES_ETAPA['considerar'], Candidato::ETAPAS['considerar'], 'El botón y la etapa dicen lo mismo');
        foreach (Candidato::ETAPAS as $texto) {
            $this->assertStringNotContainsString('/', $texto);
        }
        $manual = (string) file_get_contents(base_path('docs/usuario/candidatos.md'));
        foreach (Candidato::ETAPAS as $texto) {
            $this->assertStringContainsString('**'.$texto.'**', $manual, "El manual nombra la etapa «{$texto}» igual que la pantalla");
        }
    }
}
