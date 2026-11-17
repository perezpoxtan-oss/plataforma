<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Pedido del dueño (13 nov 2026): nombres de fábrica más cortos para tres
 * módulos, en el menú y en el título de su pantalla. Claves, rutas y
 * permisos no cambian; el nombre que la empresa le pone en Configuración →
 * General → Nombres en el menú sigue mandando, también en el título.
 */
class NombresModulosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIGRACION = '2026_11_25_000100_nombres_modulos_recorridos_equipos_estacionamiento.php';

    /** clave => [nombre nuevo, pantalla] */
    private const MODULOS = [
        'recorridos_pc' => ['Recorridos', '/recorridos-pc'],
        'equipos_pc' => ['Equipos contra incendios', '/equipos-pc'],
        'estacionamientos' => ['Estacionamiento y almacenes', '/estacionamientos'],
    ];

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    public function test_el_menu_y_el_titulo_de_cada_pantalla_usan_el_nombre_nuevo(): void
    {
        $menu = $this->actingAs($this->admin)->get('/')->assertOk();
        foreach (self::MODULOS as $clave => [$nombre, $url]) {
            $this->assertSame($clave === 'estacionamientos' ? $nombre : null, Modulo::where('clave', $clave)->value('nombre_menu'));
            $this->assertSame($nombre, Modulo::where('clave', $clave)->value('nombre'));
            $menu->assertSee($nombre);
            $this->actingAs($this->admin)->get($url)->assertOk()
                ->assertSee('<h1>'.e($nombre).'</h1>', false)
                ->assertSee('<title>'.e($nombre), false);
        }
        $menu->assertDontSee('Recorridos de Protección Civil')->assertDontSee('Equipos de Protección Civil')->assertDontSee('Estacionamientos y zonas de descarga');
        // Rutas y permisos de siempre
        $this->assertSame(['recorridos_pc.index', 'equipos_pc.index', 'estacionamientos.index'],
            Modulo::whereIn('clave', array_keys(self::MODULOS))->orderByRaw("CASE clave WHEN 'recorridos_pc' THEN 1 WHEN 'equipos_pc' THEN 2 ELSE 3 END")->pluck('ruta')->all());
    }

    public function test_el_nombre_propio_de_la_empresa_manda_en_el_menu_y_en_el_titulo_y_restablecer_vuelve_al_nuevo(): void
    {
        $propios = ['recorridos_pc' => 'Rondas PC', 'equipos_pc' => 'Extintores', 'estacionamientos' => 'Cajones'];
        $ids = Modulo::whereIn('clave', array_keys($propios))->pluck('id', 'clave');
        $this->actingAs($this->admin)->put('/configuracion/menu', ['nombres' => collect($propios)->mapWithKeys(fn ($n, $c) => [$ids[$c] => $n])->all()])
            ->assertSessionHasNoErrors();

        $menu = $this->actingAs($this->admin)->get('/')->assertOk();
        foreach ($propios as $clave => $propio) {
            $menu->assertSee($propio);
            $this->actingAs($this->admin)->get(self::MODULOS[$clave][1])->assertOk()->assertSee('<h1>'.e($propio).'</h1>', false);
        }
        // El enlace cruzado entre recorridos y equipos también usa el nombre propio
        $this->actingAs($this->admin)->get('/recorridos-pc')->assertSee('Padrones → Extintores');

        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'menu'])->assertSessionHas('ok');
        foreach (self::MODULOS as $clave => [$nombre, $url]) {
            $this->assertNull(DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $ids[$clave])->value('nombre_visible'));
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('<h1>'.e($nombre).'</h1>', false);
        }
    }

    public function test_la_migracion_cambia_solo_los_nombres_de_fabrica_y_se_revierte(): void
    {
        $migracion = require database_path('migrations/'.self::MIGRACION);
        // Como estaba antes
        $migracion->down();
        $this->assertSame('Recorridos de Protección Civil', Modulo::where('clave', 'recorridos_pc')->value('nombre'));
        $this->assertSame('Equipos de Protección Civil', Modulo::where('clave', 'equipos_pc')->value('nombre'));
        $this->assertSame(['Estacionamientos', 'Estacionamientos y zonas de descarga'], array_values(Modulo::where('clave', 'estacionamientos')->first(['nombre', 'nombre_menu'])->only(['nombre', 'nombre_menu'])));

        // El nombre propio de la empresa no se toca
        $id = Modulo::where('clave', 'equipos_pc')->value('id');
        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $id)->update(['nombre_visible' => 'Extintores']);

        $migracion->up();
        foreach (self::MODULOS as $clave => [$nombre]) {
            $this->assertSame($nombre, Modulo::where('clave', $clave)->value('nombre'));
        }
        $this->assertSame('Estacionamiento y almacenes', Modulo::where('clave', 'estacionamientos')->value('nombre_menu'));
        $this->assertSame('Extintores', DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $id)->value('nombre_visible'));

        // Repetirla no cambia nada; un nombre que alguien cambió a mano en la base se respeta
        Modulo::where('clave', 'recorridos_pc')->update(['nombre' => 'Rondas']);
        $migracion->up();
        $migracion->down();
        $this->assertSame('Rondas', Modulo::where('clave', 'recorridos_pc')->value('nombre'));
        $this->assertSame('Equipos de Protección Civil', Modulo::where('clave', 'equipos_pc')->value('nombre'));
        $this->assertSame('Extintores', DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $id)->value('nombre_visible'));
    }
}
