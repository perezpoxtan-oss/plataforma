<?php

namespace Tests\Feature\Asistencia;

use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Services\Permisos\Autorizador;
use App\Support\DependenciasModulos;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Asistencia (ADR-0024), catálogo: módulo opcional y apagado de fábrica,
 * permisos por plantilla, dependencias, complemento del catálogo comercial
 * y la migración (revertir y volver a migrar con datos).
 */
class AsistenciaCatalogoTest extends TestCase
{
    use EscenarioAsistencia, RefreshDatabase;

    public function test_apagado_de_fabrica_nada_cambia(): void
    {
        $this->prepararAsistencia(encender: false);
        $this->assertFalse(app(Autorizador::class)->moduloActivo($this->empresa->id, 'asistencia'));

        // Nadie la abre, ni el Super Administrador con la empresa de trabajo elegida
        $this->actingAs($this->rh)->get('/asistencia')->assertForbidden();
        $this->actingAs($this->rh)->get('/asistencia/periodo')->assertForbidden();
        $this->actingAs($this->rh)->get('/asistencia/periodo/exportar')->assertForbidden();
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/asistencia')->assertStatus(403);

        // Turnos y Colaboradores se ven igual que antes
        $this->actingAs($this->rh)->get('/turnos')->assertOk()->assertDontSee('Días que se trabaja')->assertDontSee('Todos los días');
        $c = $this->colaborador('Ana', $this->matutino);
        $this->actingAs($this->rh)->get('/colaboradores')->assertOk()->assertDontSee('Asistencia de '.$c->nombreCompleto());
        // El menú no la muestra
        $this->actingAs($this->rh)->get('/')->assertOk()->assertDontSee(route('asistencia.index'));
    }

    public function test_encendida_aparece_en_recursos_humanos_para_quien_tiene_permiso(): void
    {
        $this->prepararAsistencia();
        $this->actingAs($this->rh)->get('/')->assertOk()->assertSee(route('asistencia.index'));
        $this->actingAs($this->rh)->get('/asistencia')->assertOk()->assertSee('Asistencia del día');
        $this->actingAs($this->jefe)->get('/asistencia')->assertOk();
        // El agente de caseta no tiene ningún paso nuevo ni ve el módulo
        $this->actingAs($this->agente)->get('/asistencia')->assertForbidden();
        $this->actingAs($this->agente)->get('/')->assertOk()->assertDontSee(route('asistencia.index'));

        $modulo = Modulo::where('clave', 'asistencia')->with('area', 'menu')->firstOrFail();
        $this->assertSame('recursos_humanos', $modulo->area->clave);
        $this->assertSame('recursos_humanos', $modulo->menu->clave);
        $this->assertSame('Asistencia', $modulo->seccion_menu);
        $this->assertEqualsCanonicalizing(['ver', 'justificar', 'exportar', 'configurar'], $modulo->acciones()->pluck('clave')->all());
    }

    public function test_permisos_de_las_plantillas(): void
    {
        $this->prepararAsistencia();
        $permisos = fn (string $rol) => DB::table('rol_permisos as rp')->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')->whereNull('r.empresa_id')->where('r.nombre', $rol)->where('m.clave', 'asistencia')
            ->pluck('rp.alcance', 'a.clave')->all();

        $this->assertEquals(['ver' => 'empresa', 'justificar' => 'empresa', 'exportar' => 'empresa', 'configurar' => 'empresa'], $permisos('Recursos Humanos'));
        $this->assertEquals(['ver' => 'empresa', 'justificar' => 'empresa', 'exportar' => 'empresa', 'configurar' => 'empresa'], $permisos('Administrador'));
        $this->assertEquals(['ver' => 'empresa', 'exportar' => 'empresa'], $permisos('Director'));
        $this->assertEquals(['ver' => 'propios'], $permisos('Jefe de departamento'));
        foreach (['Agente', 'Supervisor', 'Jefe de seguridad', 'Asistente de seguridad', 'Solicitante'] as $rol) {
            $this->assertSame([], $permisos($rol), "{$rol} no debe tener Asistencia");
        }
        $this->assertTrue($this->rh->can('asistencia.justificar'));
        $this->assertFalse($this->jefe->can('asistencia.justificar'));
        $this->assertFalse($this->agente->can('asistencia.ver'));
    }

    public function test_dependencias_y_complemento_del_catalogo_comercial(): void
    {
        $this->prepararAsistencia(encender: false);
        $this->assertSame(['colaboradores', 'turnos'], DependenciasModulos::de('asistencia'));
        $this->assertSame(['asistencia' => ['colaboradores', 'turnos']], DependenciasModulos::faltantes(['asistencia']));
        $this->assertSame([], DependenciasModulos::faltantes(['asistencia', 'colaboradores', 'turnos', 'departamentos']));

        $producto = Producto::where('clave', 'asistencia')->firstOrFail();
        $this->assertSame(['asistencia'], $producto->modulos()->pluck('clave')->all());
        $paquete = Paquete::where('slug', 'asistencia')->firstOrFail();
        $this->assertSame(Paquete::SUSCRIPCION, $paquete->modalidad);
        $this->assertTrue((bool) $paquete->por_sede);
        $this->assertEqualsCanonicalizing(['esencial', 'profesional', 'corporativo'], $paquete->planes()->pluck('slug')->all());
        // Ningún plan lo trae de fábrica: es complemento
        foreach (['esencial', 'profesional', 'corporativo'] as $plan) {
            $this->assertNotContains('asistencia', Plan::where('slug', $plan)->firstOrFail()->productos()->pluck('clave')->all());
        }
    }

    public function test_migracion_revertir_y_volver_a_migrar(): void
    {
        $this->prepararAsistencia();
        $c = $this->colaborador('Ana', $this->matutino);
        $this->actingAs($this->rh)->post(route('asistencia.justificar', $c->id), ['fecha' => '2026-11-03', 'tipo' => 'falta', 'motivo' => 'permiso'])->assertSessionHasNoErrors();
        $antes = $this->foto();
        $migracion = require database_path('migrations/2026_11_21_000100_modulo_asistencia.php');

        $migracion->down();
        $this->assertFalse(Schema::hasTable('asistencia_justificaciones'));
        $this->assertFalse(Schema::hasColumn('turnos', 'dias_laborables'));
        $this->assertFalse(Modulo::where('clave', 'asistencia')->exists());
        $this->assertFalse(DB::table('acciones')->where('clave', 'justificar')->exists());
        $this->assertFalse(Producto::where('clave', 'asistencia')->exists());
        $this->assertFalse(Paquete::where('slug', 'asistencia')->exists());
        app(Autorizador::class)->olvidar();
        Autorizador::olvidarEsquema();
        $this->actingAs($this->rh)->get('/turnos')->assertOk();

        $migracion->up();
        $despues = $this->foto();
        $this->assertSame($antes['columnas'], $despues['columnas']);
        $this->assertSame($antes['acciones'], $despues['acciones']);
        $this->assertEquals($antes['permisos'], $despues['permisos'], 'Los permisos de las plantillas y de los roles de la empresa vuelven igual');
        $this->assertSame('Asistencia', Modulo::where('clave', 'asistencia')->value('seccion_menu'));
        // En una base que ya existe: no se enciende en ninguna empresa y el paquete nace sin publicar
        $this->assertSame(0, DB::table('empresa_modulos')->where('modulo_id', Modulo::where('clave', 'asistencia')->value('id'))->count());
        $this->assertFalse((bool) Paquete::where('slug', 'asistencia')->value('publicado'));
        $this->assertEqualsCanonicalizing(['esencial', 'profesional', 'corporativo'], Paquete::where('slug', 'asistencia')->firstOrFail()->planes()->pluck('slug')->all());
    }

    /** @return array<string, mixed> */
    private function foto(): array
    {
        return [
            'columnas' => Schema::getColumnListing('asistencia_justificaciones'),
            'acciones' => Modulo::where('clave', 'asistencia')->firstOrFail()->acciones()->orderBy('clave')->pluck('clave')->all(),
            'permisos' => DB::table('rol_permisos as rp')->join('roles as r', 'r.id', '=', 'rp.rol_id')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
                ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')->where('m.clave', 'asistencia')
                ->orderBy('r.id')->orderBy('a.clave')->get(['r.nombre', 'r.empresa_id', 'a.clave', 'rp.alcance'])->map(fn ($f) => (array) $f)->all(),
        ];
    }
}
