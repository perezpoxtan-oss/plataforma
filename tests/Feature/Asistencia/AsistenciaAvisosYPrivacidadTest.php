<?php

namespace Tests\Feature\Asistencia;

use App\Models\Acceso;
use App\Models\AsistenciaJustificacion;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Services\Asistencia\AjustesAsistencia;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\DatosDePersona;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Relojes\FaltasDelDia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Asistencia (ADR-0024): el aviso de faltas al jefe (apagado de fábrica;
 * encendido usa «Reloj y suplente»: Mis pendientes, campana, suplente) y la
 * privacidad (búsqueda, exportación y «Eliminar» ARCO, plazo de conservación)
 * y su resumen en Configuración.
 */
class AsistenciaAvisosYPrivacidadTest extends TestCase
{
    use EscenarioAsistencia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistencia();
    }

    private function a(string $hora): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 '.$hora, self::ZONA)->utc());
        Cache::flush();
        app(MotorRelojes::class)->reloj('asistencia_faltas')->olvidar();
    }

    private function avisos(string $tipo, int $usuario, ?int $colaborador = null): int
    {
        return Notificacion::query()->withoutGlobalScopes()->where('tipo', $tipo)->where('user_id', $usuario)
            ->where('referencia_tipo', 'asistencia_falta')->when($colaborador, fn ($q) => $q->where('referencia_id', $colaborador))->count();
    }

    public function test_el_aviso_de_faltas_viene_apagado(): void
    {
        $this->colaborador('Carla', $this->matutino);
        $this->a('08:00');
        app(MotorRelojes::class)->correr();
        $this->a('11:00');
        app(MotorRelojes::class)->correr();
        $reloj = app(MotorRelojes::class)->reloj('asistencia_faltas');
        $this->assertSame(0, $this->enEmpresa(fn () => $reloj->esperas($this->empresa->id)->count()));
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'asistencia_falta')->count());
        $this->assertNull($this->enEmpresa(fn () => $reloj->renglon($this->jefe, $this->empresa->id)));
    }

    public function test_encendido_avisa_al_jefe_pasa_a_recursos_humanos_y_se_apaga_al_justificar(): void
    {
        $carla = $this->colaborador('Carla', $this->matutino);
        $rita = $this->colaborador('Rita', $this->matutino, $this->recepcion);
        app(AjustesAsistencia::class)->guardar($this->rh, Empresa::find($this->empresa->id), ['tolerancia_minutos' => 10, 'aviso_faltas' => 1]);
        $reloj = app(MotorRelojes::class)->reloj('asistencia_faltas');
        $this->assertInstanceOf(FaltasDelDia::class, $reloj);

        $this->a('08:05'); // aún en la tolerancia
        app(MotorRelojes::class)->correr();
        $this->assertSame(0, $this->enEmpresa(fn () => $reloj->esperas($this->empresa->id)->count()));

        $this->a('08:20');
        app(MotorRelojes::class)->correr();
        // Mis pendientes del jefe: solo Carla (Cocina); Rita es de Recepción
        $renglon = $this->enEmpresa(fn () => $reloj->renglon($this->jefe, $this->empresa->id));
        $this->assertSame(['asistencia_faltas', 'Faltas de hoy', 1], [$renglon['clave'], $renglon['titulo'], $renglon['total']]);
        $this->assertSame(0, $this->avisos(MotorRelojes::TIPO_RECORDATORIO, $this->jefe->id), 'Antes de los 30 minutos no suena la campana');

        $this->a('08:45');
        app(MotorRelojes::class)->correr();
        $this->assertSame(1, $this->avisos(MotorRelojes::TIPO_RECORDATORIO, $this->jefe->id));
        $this->assertSame(0, $this->avisos(MotorRelojes::TIPO_ESCALADO, $this->rh->id));

        $this->a('10:15');
        app(MotorRelojes::class)->correr();
        $this->assertSame(1, $this->avisos(MotorRelojes::TIPO_ESCALADO, $this->rh->id, $carla->id), 'A las 2 horas pasa a Recursos Humanos');
        // Rita (Recepción no tiene jefe asignado): también llega a Recursos Humanos a las 2 horas
        $this->assertSame(1, $this->avisos(MotorRelojes::TIPO_ESCALADO, $this->rh->id, $rita->id));
        $this->assertSame(1, $this->avisos(MotorRelojes::TIPO_RECORDATORIO, $this->jefe->id), 'No se repite');
        $this->a('10:16');
        app(MotorRelojes::class)->correr();
        $this->assertSame(1, $this->avisos(MotorRelojes::TIPO_ESCALADO, $this->rh->id, $carla->id));

        // Recursos Humanos justifica la falta de hoy: los avisos pierden su botón y deja de esperar
        $this->flushSession();
        $this->actingAs($this->rh)->post(route('asistencia.justificar', $carla->id), ['fecha' => '2026-11-04', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertSessionHasNoErrors();
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'asistencia_falta')->where('referencia_id', $carla->id)->whereNull('leida_en')->count());
        $this->a('10:20');
        $this->assertSame([$rita->id], $this->enEmpresa(fn () => $reloj->esperas($this->empresa->id)->map(fn ($e) => $e->referenciaId)->all()));

        // Si llega, deja de esperar
        $this->marcaje($rita, '2026-11-04 10:25');
        $this->a('10:30');
        $this->assertSame(0, $this->enEmpresa(fn () => $reloj->esperas($this->empresa->id)->count()));
    }

    public function test_arco_busca_exporta_y_elimina_las_notas_de_quien_ya_causo_baja(): void
    {
        $ana = $this->colaborador('Anabel', $this->matutino);
        $this->marcaje($ana, '2026-11-03 08:00', '2026-11-03 16:00');
        $this->enEmpresa(fn () => AsistenciaJustificacion::create(['colaborador_id' => $ana->id, 'fecha' => '2026-11-02', 'tipo' => 'falta', 'motivo' => 'otro', 'nota' => 'Asunto familiar']));
        $datos = app(DatosDePersona::class);

        $encontrado = $this->enEmpresa(fn () => $datos->buscar(['nombre' => 'Anabel Prueba']));
        $this->assertCount(1, $encontrado['asistencia']);
        $this->assertSame($ana->id, $encontrado['asistencia'][0]['id']);
        $this->assertStringContainsString('1 entrada(s) y salida(s) · 1 justificación(es)', $encontrado['asistencia'][0]['detalle']);

        $registros = $this->enEmpresa(fn () => (fn () => $this->registros(['asistencia' => [$ana->id]]))->call($datos));
        $this->assertCount(1, $registros['asistencia_entradas_salidas']);
        $this->assertSame('Asunto familiar', $registros['asistencia_justificaciones'][0]['nota']);

        // Relación laboral vigente: no se quita nada
        $hecho = $this->enEmpresa(fn () => $datos->eliminar(['asistencia' => [$ana->id]], 'Datos eliminados por solicitud ARCO'));
        $this->assertSame([0, 1], [$hecho['asistencia'], $hecho['omitidos']]);
        $this->assertSame('Asunto familiar', $this->enEmpresa(fn () => AsistenciaJustificacion::first()->nota));

        // Ya causó baja: se quita la nota; el día y el motivo quedan; las entradas son de la Bitácora de accesos
        $this->enEmpresa(fn () => $ana->forceFill(['activo' => false])->save());
        $hecho = $this->enEmpresa(fn () => $datos->eliminar(['asistencia' => [$ana->id]], 'Datos eliminados por solicitud ARCO'));
        $this->assertSame(1, $hecho['asistencia']);
        $j = $this->enEmpresa(fn () => AsistenciaJustificacion::first());
        $this->assertNull($j->nota);
        $this->assertNotNull($j->datos_eliminados_en);
        $this->assertSame('otro', $j->motivo);
        $this->assertSame(1, $this->enEmpresa(fn () => Acceso::where('colaborador_id', $ana->id)->count()));
    }

    public function test_conservacion_quita_las_notas_vencidas(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino, alta: '2019-01-01');
        $this->enEmpresa(function () use ($ana) {
            AsistenciaJustificacion::create(['colaborador_id' => $ana->id, 'fecha' => '2020-03-02', 'tipo' => 'falta', 'motivo' => 'otro', 'nota' => 'Vieja']);
            AsistenciaJustificacion::create(['colaborador_id' => $ana->id, 'fecha' => '2026-11-02', 'tipo' => 'falta', 'motivo' => 'otro', 'nota' => 'Reciente']);
        });
        $this->assertSame(1825, app(ConservacionDatos::class)->ajustes(Empresa::find($this->empresa->id))['asistencia_dias']);
        $total = app(ConservacionDatos::class)->ejecutar();
        $this->assertSame(1, $total['asistencia']);
        $this->assertEqualsCanonicalizing([null, 'Reciente'], $this->enEmpresa(fn () => AsistenciaJustificacion::pluck('nota')->all()));
    }

    public function test_configuracion_resume_los_ajustes_solo_con_el_modulo_encendido(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->get('/configuracion/personal')->assertOk()->assertSee('Asistencia: tolerancia y aviso de faltas')
            ->assertSee('Configurar en Asistencia → Avanzado');
        $this->actingAs($admin)->get('/configuracion/seguridad')->assertOk()->assertSee('Asistencia: notas de las justificaciones');

        $otra = $this->crearEmpresa('Hotel Sin Asistencia');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->configuracionCompleta()->assertDontSee('Asistencia: tolerancia y aviso de faltas')
            ->assertDontSee('Asistencia: notas de las justificaciones');
    }
}
