<?php

namespace Tests\Feature\Asistencia;

use App\Models\AsistenciaJustificacion;
use App\Models\Auditoria;
use App\Models\Departamento;
use App\Models\Turno;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asistencia (ADR-0024), pantallas: «Asistencia del día» con filtros y
 * alcance (el jefe ve su departamento), justificar (Guardar, auditoría,
 * errores), «Reporte por periodo» y «Exportar a Excel», ajustes en
 * «Avanzado», y los vínculos con Turnos y Colaboradores. Otra empresa
 * nunca ve ni toca nada.
 */
class AsistenciaPantallasTest extends TestCase
{
    use EscenarioAsistencia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistencia();
    }

    public function test_asistencia_del_dia_con_filtros_y_el_jefe_ve_su_departamento(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $beto = $this->colaborador('Beto', $this->matutino);
        $rita = $this->colaborador('Rita', $this->matutino, $this->recepcion);
        $this->marcaje($ana, '2026-11-04 07:55');
        $this->marcaje($beto, '2026-11-04 08:40');

        $this->actingAs($this->rh)->get('/asistencia')->assertOk()
            ->assertSee('Ana Prueba')->assertSee('Beto Prueba')->assertSee('Rita Prueba')
            ->assertSee('Faltan (1)')->assertSee('Retardos (1)')->assertSee('Llegaron (2)')
            ->assertSee('retardo de 40 min')->assertSee('No ha llegado')->assertSee('07:55');
        $this->actingAs($this->rh)->get('/asistencia?ver=faltan')->assertOk()->assertSee('Rita Prueba')->assertDontSee('Ana Prueba');
        $this->actingAs($this->rh)->get('/asistencia?departamento='.$this->recepcion->id)->assertOk()->assertSee('Rita Prueba')->assertDontSee('Beto Prueba');
        // Un día pasado y un parámetro raro no rompen
        $this->actingAs($this->rh)->get('/asistencia?fecha=2026-11-03')->assertOk()->assertSee('martes 3 de noviembre de 2026');
        $this->actingAs($this->rh)->get('/asistencia?fecha=2026-02-31&sede[]=x&ver=<b>')->assertOk();

        // Jefe de departamento: solo Cocina (donde es responsable) y sin botón Justificar
        $this->actingAs($this->jefe)->get('/asistencia')->assertOk()
            ->assertSee('Ana Prueba')->assertDontSee('Rita Prueba')->assertDontSee('dialogoJustificar');
        // Recursos Humanos sí justifica
        $this->actingAs($this->rh)->get('/asistencia')->assertSee('dialogoJustificar');
    }

    public function test_justificar_guarda_audita_y_cambia_el_motivo(): void
    {
        $rita = $this->colaborador('Rita', $this->matutino, $this->recepcion);
        $url = route('asistencia.justificar', $rita->id);

        $this->actingAs($this->rh)->from('/asistencia?fecha=2026-11-03')->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'vacaciones', 'nota' => 'Aprobadas', '_volver' => '/asistencia?fecha=2026-11-03'])
            ->assertRedirect('/asistencia?fecha=2026-11-03')->assertSessionHas('ok', fn ($m) => str_contains($m, 'Justificación guardada para Rita Prueba'));
        $j = $this->enEmpresa(fn () => AsistenciaJustificacion::firstOrFail());
        $this->assertSame(['falta', 'vacaciones', 'Aprobadas', $this->rh->id], [$j->tipo, $j->motivo, $j->nota, (int) $j->creado_por]);
        $this->assertTrue(Auditoria::where('evento', 'asistencia.justificada')->exists());

        // Guardar otra vez cambia el motivo (una por día y tipo)
        $this->actingAs($this->rh)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => AsistenciaJustificacion::count()));
        $this->assertSame('permiso', $this->enEmpresa(fn () => AsistenciaJustificacion::firstOrFail()->motivo));
        $this->assertTrue(Auditoria::where('evento', 'asistencia.justificacion_cambiada')->exists());
        $this->actingAs($this->rh)->get('/asistencia?fecha=2026-11-03')->assertSee('Falta justificada: Permiso');

        // Errores con mensaje claro
        $this->actingAs($this->rh)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'otro'])->assertSessionHasErrors(['nota' => 'Con el motivo «Otro», escribe en la nota qué pasó.']);
        $this->actingAs($this->rh)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'x', 'motivo' => 'y'])->assertSessionHasErrors(['tipo', 'motivo']);
        $this->actingAs($this->rh)->post(route('asistencia.justificar', $rita->id), ['fecha' => '2026-11-09', 'tipo' => 'falta', 'motivo' => 'permiso'])
            ->assertSessionHasErrors(['fecha']);
        // El botón «Justificar» de la pantalla lleva el día en la dirección del formulario (el 2 de noviembre sigue sin justificar)
        $this->actingAs($this->rh)->get('/asistencia?fecha=2026-11-02')
            ->assertSee(e(route('asistencia.justificar', ['colaborador' => $rita->id, 'fecha' => '2026-11-02'])), false);
        $this->actingAs($this->rh)->post(route('asistencia.justificar', ['colaborador' => $rita->id, 'fecha' => '2026-11-02']), ['tipo' => 'falta', 'motivo' => 'vacaciones'])
            ->assertSessionHasNoErrors();
        $this->assertSame('vacaciones', $this->enEmpresa(fn () => AsistenciaJustificacion::where('fecha', '2026-11-02')->firstOrFail()->motivo));
        // Un regreso fuera de Asistencia no se sigue
        $this->actingAs($this->rh)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso', '_volver' => 'https://otro.sitio/x'])->assertRedirect(route('asistencia.index'));

        // Sin permiso o fuera del alcance: nada
        $this->actingAs($this->jefe)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertForbidden();
        $this->actingAs($this->agente)->post($url, ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertForbidden();
    }

    public function test_reporte_por_periodo_y_exportar_a_excel(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $this->marcaje($ana, '2026-11-02 08:30', '2026-11-02 16:00');
        $this->marcaje($ana, '2026-11-03 07:58', '2026-11-03 15:20');

        // De fábrica, la quincena en curso (1 al 4 de noviembre)
        $this->actingAs($this->rh)->get('/asistencia/periodo')->assertOk()->assertSee('domingo 1 de noviembre de 2026')->assertSee('Ana Prueba');
        $this->actingAs($this->rh)->get('/asistencia/periodo?desde=2026-11-02&hasta=2026-11-03&colaborador='.$ana->id)->assertOk()
            ->assertSee('Asistencias: <strong>2</strong> de 2', false)->assertSee('retardo de 30 min')->assertSee('salió 40 min antes')->assertSee('7:30');

        $csv = $this->actingAs($this->rh)->get('/asistencia/periodo/exportar?desde=2026-11-02&hasta=2026-11-03')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Núm. empleado",Colaborador,Departamento,Sede,Fecha,Día,Turno', $csv);
        $this->assertStringContainsString('Ana Prueba', $csv);
        $this->assertStringContainsString('02/11/2026,Lunes,Matutino,08:00,16:00,"02/11/2026 08:30","02/11/2026 16:00",30,,7:30', $csv);
        $this->assertStringContainsString('salió 40 min antes', $csv);

        // Un periodo de más de 62 días se recorta; fechas al revés se acomodan
        $this->actingAs($this->rh)->get('/asistencia/periodo?desde=2026-01-01&hasta=2026-11-04')->assertOk()->assertSee('viernes 4 de septiembre de 2026');
        $this->actingAs($this->rh)->get('/asistencia/periodo?desde=2026-11-04&hasta=2026-11-02')->assertOk()->assertSee('lunes 2 de noviembre de 2026');

        // El jefe ve el reporte de su gente pero no exporta (su plantilla no lo trae)
        $this->actingAs($this->jefe)->get('/asistencia/periodo')->assertOk()->assertSee('Ana Prueba')->assertDontSee('Exportar a Excel');
        $this->actingAs($this->jefe)->get('/asistencia/periodo/exportar')->assertForbidden();
    }

    public function test_ajustes_en_avanzado_solo_con_configurar(): void
    {
        $this->actingAs($this->rh)->get('/asistencia')->assertSee('Avanzado · Ajustes de asistencia');
        $this->actingAs($this->rh)->put('/asistencia/ajustes', ['tolerancia_minutos' => 15, 'aviso_faltas' => 1])
            ->assertRedirect(route('asistencia.index'))->assertSessionHas('ok');
        $this->assertSame(['tolerancia_minutos' => 15, 'aviso_faltas' => true], $this->empresa->fresh()->preferencias['asistencia']);
        $this->assertTrue(Auditoria::where('evento', 'asistencia.ajustes')->exists());
        $this->actingAs($this->rh)->put('/asistencia/ajustes', ['tolerancia_minutos' => 500])->assertSessionHasErrors('tolerancia_minutos');
        // Volver a los valores originales no deja nada guardado
        $this->actingAs($this->rh)->put('/asistencia/ajustes', ['tolerancia_minutos' => 10, 'aviso_faltas' => 0]);
        $this->assertArrayNotHasKey('asistencia', $this->empresa->fresh()->preferencias ?? []);

        $this->actingAs($this->jefe)->get('/asistencia')->assertDontSee('Avanzado · Ajustes de asistencia');
        $this->actingAs($this->jefe)->put('/asistencia/ajustes', ['tolerancia_minutos' => 0])->assertForbidden();
    }

    public function test_turnos_con_asistencia_guardan_los_dias_que_se_trabaja(): void
    {
        $this->actingAs($this->rh)->get('/turnos')->assertOk()->assertSee('Días que se trabaja')->assertSee('Lun a Vie')->assertSee('Todos los días');

        $this->actingAs($this->rh)->put(route('turnos.update', $this->nocturno->id), ['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:00',
            '_con_dias' => 1, 'dias' => [1, 3, 5]])->assertSessionHasNoErrors();
        $this->assertSame('1,3,5', $this->enEmpresa(fn () => Turno::find($this->nocturno->id)->dias_laborables));
        $this->actingAs($this->rh)->put(route('turnos.update', $this->nocturno->id), ['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:00',
            '_con_dias' => 1, 'dias' => []])->assertSessionHasErrors(['dias' => 'Marca al menos un día que se trabaja en este turno.']);
        // Sin el campo (formulario de antes) se conservan; los siete = todos (vacío)
        $this->actingAs($this->rh)->put(route('turnos.update', $this->nocturno->id), ['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:30'])->assertSessionHasNoErrors();
        $this->assertSame('1,3,5', $this->enEmpresa(fn () => Turno::find($this->nocturno->id)->dias_laborables));
        $this->actingAs($this->rh)->put(route('turnos.update', $this->nocturno->id), ['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:00',
            '_con_dias' => 1, 'dias' => [1, 2, 3, 4, 5, 6, 7]])->assertSessionHasNoErrors();
        $this->assertNull($this->enEmpresa(fn () => Turno::find($this->nocturno->id)->dias_laborables));
    }

    public function test_colaboradores_enlaza_a_su_asistencia_y_avisa_quien_no_tiene_turno(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $this->colaborador('Libre', null);
        $this->actingAs($this->rh)->get('/colaboradores')->assertOk()->assertSee('Asistencia de Ana Prueba')
            ->assertSee(route('asistencia.periodo', ['colaborador' => $ana->id]), false);
        $this->actingAs($this->rh)->get('/asistencia')->assertSee('Hay 1 colaborador sin turno')->assertSee('Asígnales su turno en Colaboradores');
    }

    public function test_al_unir_un_colaborador_duplicado_sus_justificaciones_pasan_al_correcto(): void
    {
        $correcto = $this->colaborador('Ana', $this->matutino);
        $provisional = $this->colaborador('Ana Duplicada', null);
        $this->enEmpresa(function () use ($correcto, $provisional) {
            $provisional->forceFill(['provisional' => true])->save();
            AsistenciaJustificacion::create(['colaborador_id' => $provisional->id, 'fecha' => '2026-11-02', 'tipo' => 'falta', 'motivo' => 'permiso']);
            AsistenciaJustificacion::create(['colaborador_id' => $provisional->id, 'fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'otro', 'nota' => 'Repetida']);
            AsistenciaJustificacion::create(['colaborador_id' => $correcto->id, 'fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'vacaciones']);
        });
        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->rh, $provisional->fresh(), $correcto->fresh()));

        $this->assertSame(0, $this->enEmpresa(fn () => AsistenciaJustificacion::where('colaborador_id', $provisional->id)->count()));
        $this->assertSame(['2026-11-02' => 'permiso', '2026-11-03' => 'vacaciones'], $this->enEmpresa(fn () => AsistenciaJustificacion::where('colaborador_id', $correcto->id)
            ->orderBy('fecha')->get()->mapWithKeys(fn ($j) => [$j->fecha->format('Y-m-d') => $j->motivo])->all()));
    }

    public function test_otra_empresa_no_ve_ni_justifica(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $otra = $this->crearEmpresa('Hotel Playa');
        $this->encenderAsistencia($otra);
        $rhOtra = $this->crearUsuario($otra, 'Recursos Humanos');
        $this->actingAs($rhOtra)->get('/asistencia')->assertOk()->assertDontSee('Ana Prueba');
        $this->actingAs($rhOtra)->get('/asistencia/periodo?colaborador='.$ana->id)->assertOk()->assertDontSee('Ana Prueba');
        $this->actingAs($rhOtra)->post(route('asistencia.justificar', $ana->id), ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertNotFound();
        $this->assertSame(0, app(Tenant::class)->conEmpresa($this->empresa->id, fn () => AsistenciaJustificacion::count()));
        $this->assertSame(0, app(Tenant::class)->conEmpresa($otra->id, fn () => Departamento::where('nombre', 'Cocina')->count()));
    }
}
