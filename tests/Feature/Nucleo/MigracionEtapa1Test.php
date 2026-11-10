<?php

namespace Tests\Feature\Nucleo;

use App\Models\ConfiguracionPlataforma;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Models\User;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\Aprovisionamiento;
use App\Services\Suscripciones\ModulosEfectivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Migración de la Etapa 1 (C-01, C-05, DD-05): sobre una base «como estaba»
 * (catálogos del personal en RR. HH., Equipos PC en Padrones, sin Reservas
 * en ningún producto y un módulo agregado a mano a un cliente), la migración
 * ordena el catálogo y sincroniza; down() lo regresa tal cual; se puede volver a aplicar.
 */
class MigracionEtapa1Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const PERSONAL = ['colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones'];

    /** Catálogo comercial de antes (CatalogoComercialSeeder hasta la Etapa 1). */
    private const ANTES = [
        'estructura' => ['empresas', 'sedes', 'espacios', 'usuarios', 'roles', 'permisos', 'configuracion', 'auditoria'],
        'padrones' => ['proveedores', 'visitantes', 'vehiculos', 'llaves', 'gafetes', 'equipos', 'equipos_pc', 'estacionamientos', 'rutas', 'etiquetas_qr', 'responsivas'],
        'incidentes' => ['novedades', 'lost_found', 'robo', 'recorridos_pc', 'procedimientos'],
        'recursos_humanos' => ['colaboradores', 'departamentos', 'puestos', 'turnos', 'recepcion_rh', 'candidatos', 'autorizaciones', 'vacantes'],
    ];

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_10_000200_organizacion_base_y_paquetes_coherentes.php');
    }

    /** @return list<string> */
    private function activos(int $empresaId): array
    {
        return DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('em.activo', true)->pluck('m.clave')->sort()->values()->all();
    }

    /** Deja la base como estaba antes de la Etapa 1. */
    private function comoAntes(): Suscripcion
    {
        $rh = DB::table('areas')->where('clave', 'recursos_humanos')->value('id');
        $menuRh = DB::table('menus')->where('clave', 'recursos_humanos')->value('id');
        foreach (self::PERSONAL as $i => $clave) {
            DB::table('modulos')->where('clave', $clave)->update(['area_id' => $rh] + ($clave === 'autorizaciones' ? [] : ['menu_id' => $menuRh, 'seccion_menu' => 'Catálogos del personal', 'orden_menu' => 11 + $i]));
        }
        $modulos = Modulo::pluck('id', 'clave');
        foreach (self::ANTES as $producto => $claves) {
            Producto::where('clave', $producto)->firstOrFail()->modulos()->sync(collect($claves)->map(fn ($c) => $modulos[$c]));
        }
        Producto::where('clave', 'estructura')->update(['descripcion' => 'Empresas, sedes, zonas, usuarios, roles, permisos y bitácora de auditoría.']);
        Paquete::where('slug', 'reservas-y-eventos')->delete();
        Producto::where('clave', 'reservas')->delete();
        app(Autorizador::class)->olvidar();

        // Cliente Esencial con los módulos de antes (sin la lista de dependencias)
        [$sol] = app(CatalogoPublico::class)->registrar(['nombre' => 'Mario Ruiz', 'empresa' => 'Plaza Norte', 'correo' => 'plaza@ejemplo.com.mx', 'sedes' => 1,
            'plan_id' => Plan::where('slug', 'esencial')->value('id'), 'paquetes' => []], '127.0.0.1');
        $s = app(Aprovisionamiento::class)->confirmarPago($sol, $this->crearSuperadmin(), 'T-1');
        $antes = app(ModulosEfectivos::class);
        $antes->conDependencias = false;
        DB::table('empresa_modulos')->where('empresa_id', $s->empresa_id)->delete();
        foreach ($antes->calcular($s) as $id) {
            DB::table('empresa_modulos')->insert(['empresa_id' => $s->empresa_id, 'modulo_id' => $id, 'activo' => true]);
        }
        // El Super Administrador le prendió Reservas a mano en Empresas → Módulos (A-09)
        DB::table('empresa_modulos')->insert(['empresa_id' => $s->empresa_id, 'modulo_id' => $modulos['reservas'], 'activo' => true]);
        app(Autorizador::class)->olvidar();

        return $s;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        RateLimiter::clear('comercial-contratar|127.0.0.1');
    }

    public function test_en_una_instalacion_nueva_no_hace_nada(): void
    {
        $this->migracion()->up();
        $this->assertFalse(ConfiguracionPlataforma::where('clave', 'etapa1_cimientos')->exists());
    }

    public function test_ordena_el_catalogo_sincroniza_revierte_y_se_vuelve_a_aplicar(): void
    {
        $s = $this->comoAntes();
        $empresaId = (int) $s->empresa_id;
        $admin = User::where('empresa_id', $empresaId)->firstOrFail();
        $antes = $this->activos($empresaId);
        $this->assertNotContains('colaboradores', $antes);
        $this->assertContains('equipos_pc', $antes);
        $this->assertContains('reservas', $antes);
        $this->assertFalse($admin->can('colaboradores.ver'));

        $this->migracion()->up();
        app(Autorizador::class)->olvidar();
        $direccion = DB::table('areas')->where('clave', 'direccion')->value('id');
        $estructura = DB::table('menus')->where('clave', 'estructura')->value('id');
        foreach (self::PERSONAL as $clave) {
            $this->assertSame((int) $direccion, (int) Modulo::where('clave', $clave)->value('area_id'), $clave);
        }
        $this->assertSame((int) $estructura, (int) Modulo::where('clave', 'turnos')->value('menu_id'));
        $this->assertSame('Organización', Modulo::where('clave', 'colaboradores')->value('seccion_menu'));
        $this->assertContains('colaboradores', Producto::where('clave', 'estructura')->first()->modulos()->pluck('clave')->all());
        $this->assertEqualsCanonicalizing(['recepcion_rh', 'candidatos', 'vacantes'], Producto::where('clave', 'recursos_humanos')->first()->modulos()->pluck('clave')->all());
        $this->assertContains('equipos_pc', Producto::where('clave', 'incidentes')->first()->modulos()->pluck('clave')->all());
        $this->assertTrue(Paquete::where('slug', 'reservas-y-eventos')->exists());
        $this->assertStringContainsString('directorio de colaboradores', Producto::where('clave', 'estructura')->value('descripcion'));

        // El cliente: organización base, Equipos PC en solo lectura, Reservas conservada como excepción
        $despues = $this->activos($empresaId);
        $this->assertContains('colaboradores', $despues);
        $this->assertContains('reservas', $despues);
        $this->assertNotContains('equipos_pc', $despues);
        $this->assertTrue(SuscripcionExcepcion::where('empresa_id', $empresaId)->where('tipo', 'modulo_agregado')->where('modulo_id', Modulo::where('clave', 'reservas')->value('id'))->exists());
        $this->assertTrue($admin->can('colaboradores.crear'));
        $this->assertTrue($admin->can('equipos_pc.ver'));
        $this->assertFalse($admin->can('equipos_pc.crear'));
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $admin->id)->where('titulo', 'Algunos módulos quedaron en solo consulta')->exists());
        // Idempotente
        $this->migracion()->up();
        $this->assertSame(1, SuscripcionExcepcion::where('empresa_id', $empresaId)->count());

        // down(): todo como estaba
        $this->migracion()->down();
        app(Autorizador::class)->olvidar();
        $this->assertSame((int) DB::table('areas')->where('clave', 'recursos_humanos')->value('id'), (int) Modulo::where('clave', 'colaboradores')->value('area_id'));
        $this->assertSame('Catálogos del personal', Modulo::where('clave', 'turnos')->value('seccion_menu'));
        $this->assertContains('equipos_pc', Producto::where('clave', 'padrones')->first()->modulos()->pluck('clave')->all());
        $this->assertNotContains('colaboradores', Producto::where('clave', 'estructura')->first()->modulos()->pluck('clave')->all());
        $this->assertFalse(Paquete::where('slug', 'reservas-y-eventos')->exists());
        $this->assertSame(0, SuscripcionExcepcion::where('empresa_id', $empresaId)->count());
        $this->assertSame($antes, $this->activos($empresaId));
        $this->assertSame('Empresas, sedes, zonas, usuarios, roles, permisos y bitácora de auditoría.', Producto::where('clave', 'estructura')->value('descripcion'));

        // Y se vuelve a aplicar
        $this->migracion()->up();
        $this->assertContains('colaboradores', $this->activos($empresaId));
        $this->assertTrue(Paquete::where('slug', 'reservas-y-eventos')->exists());
    }

    public function test_la_columna_de_solo_lectura_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $m = require database_path('migrations/2026_11_10_000100_modulos_en_solo_lectura.php');
        $m->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('empresa_modulos', 'solo_lectura'));
        Autorizador::olvidarEsquema();
        // Sin la columna el motor de permisos sigue funcionando
        $empresa = $this->crearEmpresa('Hotel Uno');
        $this->assertTrue($this->crearUsuario($empresa, 'Administrador')->can('accesos.crear'));
        $m->up();
        Autorizador::olvidarEsquema();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('empresa_modulos', 'solo_lectura'));
    }
}
