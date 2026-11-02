<?php

namespace Tests\Feature\Organizacion;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Importacion;
use App\Models\Llave;
use App\Models\Modulo;
use App\Models\Puesto;
use App\Models\Rubro;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Importacion\FaltantesDeCatalogo;
use App\Services\Lector\EtiquetasMasivas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 7 · C-43: pulido. Confirmación con el impacto al cambiar el giro
 * (A-16), filtros de Colaboradores según el alcance (A-18), «Crear los que
 * faltan» al importar (A-19), Etiquetas QR que filtran antes del límite
 * (GC-12), íconos de la ficha de llave en el celular (GC-14), Transporte
 * con estatus sugerido y rutas que cruzan la medianoche (D-21) y exportar
 * Colaboradores, Pases, Responsivas y Vouchers con su permiso (E-13).
 */
class EtapaSietePulidoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    // ------------------------------------------------------------- A-16 giro

    public function test_cambiar_el_giro_pide_confirmar_con_el_impacto(): void
    {
        $super = $this->crearSuperadmin();
        $condominio = Rubro::where('clave', 'condominio')->firstOrFail();
        $datos = ['nombre_comercial' => $this->empresa->nombre_comercial, 'razon_social' => $this->empresa->razon_social ?? 'Hotel Uno SA',
            'rfc' => $this->empresa->rfc ?? 'HUN200101AB1', 'zona_horaria' => $this->empresa->zona_horaria ?? 'America/Cancun', 'rubro_id' => $condominio->id];

        $this->actingAs($super)->put("/empresas/{$this->empresa->id}", $datos)->assertSessionHas('confirmar_giro');
        $this->assertNotSame($condominio->id, (int) $this->empresa->fresh()->rubro_id, 'Sin confirmar no cambia');
        $mensaje = (string) session('error');
        $this->assertStringContainsString('Condominio', $mensaje);
        $this->assertStringContainsString('no se borra', mb_strtolower($mensaje));

        // La pantalla muestra el aviso y la casilla de confirmación
        $this->actingAs($super)->get('/empresas')->assertOk()->assertSee('name="confirmar_giro"', false);

        $this->actingAs($super)->put("/empresas/{$this->empresa->id}", $datos + ['confirmar_giro' => '1'])->assertSessionHas('ok');
        $this->assertSame($condominio->id, (int) $this->empresa->fresh()->rubro_id);

        // Sin cambiar el giro no pregunta nada
        $this->actingAs($super)->put("/empresas/{$this->empresa->id}", $datos)->assertSessionHas('ok')->assertSessionMissing('confirmar_giro');
    }

    // ------------------------------------------------- A-18 filtros por alcance

    public function test_los_filtros_de_colaboradores_solo_ofrecen_lo_de_sus_sedes(): void
    {
        [$soloPlaya, $todas, $turnoPlaya] = $this->enEmpresa(function () {
            $soloPlaya = Departamento::create(['nombre' => 'Club de Playa', 'todas_las_sedes' => false]);
            $soloPlaya->sedes()->attach($this->playa->id);
            $todas = Departamento::create(['nombre' => 'Seguridad', 'todas_las_sedes' => true]);
            $turno = Turno::create(['nombre' => 'Playa tarde', 'hora_inicio' => '14:00', 'hora_fin' => '22:00', 'todas_las_sedes' => false, 'activo' => true]);
            $turno->sedes()->attach($this->playa->id);

            return [$soloPlaya, $todas, $turno];
        });
        $filtro = function (string $html, string $cual): string {
            preg_match('/<select[^>]*data-filtro-colab="'.$cual.'".*?<\/select>/s', $html, $m);

            return $m[0] ?? '';
        };
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $html = $this->actingAs($jefe)->get('/colaboradores')->assertOk()->getContent();
        $this->assertStringNotContainsString('Club de Playa', $filtro($html, 'depto'), 'Departamento de otra sede');
        $this->assertStringNotContainsString('Playa tarde', $filtro($html, 'turno'), 'Turno de otra sede');

        // Quien ve toda la empresa sigue viendo todo
        $html = $this->actingAs($this->admin)->get('/colaboradores')->assertOk()->getContent();
        $this->assertStringContainsString('Club de Playa', $filtro($html, 'depto'));
        $this->assertStringContainsString('Playa tarde', $filtro($html, 'turno'));
        unset($todas, $turnoPlaya, $soloPlaya);
    }

    // ------------------------------------------- A-19 crear los que faltan

    public function test_importar_colaboradores_crea_los_catalogos_que_faltan(): void
    {
        $csv = "Número de empleado *,Nombre(s) *,Apellido paterno *,Sede,Departamento,Puesto,Turno\n"
            ."E-1,Ana,Uc,CEN,Ama de llaves,Camarista,Matutino 07:00-15:00\n"
            ."E-2,Luis,Chi,CEN,Ama de llaves,Camarista,Nocturno\n";
        $this->actingAs($this->admin)->post('/importar/colaboradores', ['archivo' => UploadedFile::fake()->createWithContent('plantilla.csv', $csv)])->assertRedirect();
        $i = Importacion::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['error', 'error'], collect($i->filas)->pluck('estado')->all());

        $html = $this->actingAs($this->admin)->get("/importaciones/{$i->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Guardar · crear los que faltan', $html);
        $this->assertStringContainsString('Ama de llaves', $html);

        $this->actingAs($this->admin)->post("/importaciones/{$i->id}/crear-faltantes")->assertRedirect("/importaciones/{$i->id}")->assertSessionHas('ok');
        [$d, $p, $t] = $this->enEmpresa(fn () => [Departamento::where('nombre', 'Ama de llaves')->first(), Puesto::where('nombre', 'Camarista')->first(), Turno::where('nombre', 'Matutino 07:00-15:00')->first()]);
        $this->assertNotNull($d);
        $this->assertTrue((bool) $d->todas_las_sedes);
        $this->assertSame([$d->id], $this->enEmpresa(fn () => $p->departamentos()->pluck('departamentos.id')->all()), 'El puesto queda ligado a su departamento');
        $this->assertSame('07:00', substr((string) $t->hora_inicio, 0, 5));
        $this->assertNull($this->enEmpresa(fn () => Turno::where('nombre', 'Nocturno')->first()), 'Sin horario no se crea');
        $this->assertSame('sin_horario', 'sin_horario');

        // La vista previa se vuelve a revisar: la primera fila ya es nueva; la segunda espera su turno
        $i->refresh();
        $this->assertSame(['nueva', 'error'], collect($i->filas)->pluck('estado')->all());
        $auditoria = Auditoria::query()->withoutGlobalScopes()->where('evento', 'departamentos.creado')->latest('id')->firstOrFail();
        $this->assertSame(['nombre' => 'Ama de llaves', 'origen' => 'importacion', 'importacion_id' => $i->id], (array) $auditoria->despues);

        // Sin permiso de crear departamentos no se ofrece ni se puede
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Capturista', 'nivel_jerarquia' => 45]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, ['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa]);
        $capturista = $this->crearUsuario($this->empresa, 'Capturista');
        $this->assertFalse(app(FaltantesDeCatalogo::class)->puede($capturista, 'departamento'));
        $this->assertSame(['07:00', '15:00'], FaltantesDeCatalogo::horario('Matutino 7:00 a 15:00'));
    }

    // ------------------------------------------ GC-12 etiquetas antes del límite

    public function test_etiquetas_qr_filtran_antes_del_limite_de_500(): void
    {
        $this->enEmpresa(function () {
            $filas = [];
            for ($n = 1; $n <= EtiquetasMasivas::POR_TIPO + 20; $n++) {
                $filas[] = ['empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'nomenclatura' => sprintf('LL-%04d', $n), 'descripcion' => 'Habitación', 'tipo_dispositivo' => 'metalica',
                    'alcance' => 'global', 'activo' => true, 'codigo_qr' => sprintf('QRLL%06d', $n), 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($filas, 200) as $lote) {
                DB::table('llaves')->insert($lote);
            }
        });
        $buscada = sprintf('LL-%04d', EtiquetasMasivas::POR_TIPO + 15); // fuera de las primeras 500
        $lista = $this->enEmpresa(fn () => app(EtiquetasMasivas::class)->lista($this->admin, ['tipo' => 'llave', 'sede' => null, 'estado' => 'activos', 'q' => $buscada]));
        $this->assertSame([$buscada], $lista->pluck('titulo')->all());
        $this->assertSame(EtiquetasMasivas::POR_TIPO, $this->enEmpresa(fn () => app(EtiquetasMasivas::class)->lista($this->admin, ['tipo' => 'llave', 'sede' => null, 'estado' => 'todos', 'q' => '']))->count());
        unset($this->admin->llaves);
        $this->assertTrue(Llave::query()->withoutGlobalScopes()->count() > EtiquetasMasivas::POR_TIPO);
    }

    // -------------------------------------------- GC-14 ficha de llave en celular

    public function test_la_ficha_de_llave_acomoda_sus_iconos_en_el_celular(): void
    {
        $this->enEmpresa(fn () => DB::table('llaves')->insert(['empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'nomenclatura' => 'LL-1', 'descripcion' => 'Habitación',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global', 'activo' => true, 'codigo_qr' => 'QRLL1', 'created_at' => now(), 'updated_at' => now()]));
        $this->actingAs($this->admin)->get('/llaves')->assertOk()->assertSee('acciones-ficha-llave', false);
        $css = (string) file_get_contents(public_path('css/plataforma.css'));
        $this->assertStringContainsString('.acciones-ficha-llave', $css);
    }

    // -------------------------------------------- D-21 transporte

    private function horario(string $sentido, string $inicio, string $fin, string $dias = 'LU,MA,MI,JU,VI,SA,DO'): RutaHorario
    {
        $h = new RutaHorario(['dias' => $dias, 'hora_inicio' => $inicio.':00', 'hora_fin' => $fin.':00']);
        $h->setRelation('ruta', new Ruta(['sentido' => $sentido, 'nombre' => 'R']));

        return $h;
    }

    public function test_transporte_sugiere_el_estatus_y_entiende_la_medianoche(): void
    {
        $t = app(BitacoraTransporte::class);
        $ahora = CarbonImmutable::parse('2026-12-01 06:45', 'America/Cancun'); // martes
        $llegada = $this->horario('llegada', '06:00', '06:40');
        $this->assertSame('a_tiempo', $t->estatusSugerido($llegada, $ahora));
        $this->assertSame('retraso', $t->estatusSugerido($llegada, $ahora->addMinutes(20)));

        // Sale el lunes a las 23:20 y llega a las 00:30: el martes a la 01:00 sigue siendo el servicio del lunes
        $nocturna = $this->horario('llegada', '23:20', '00:30', 'LU');
        $martes = CarbonImmutable::parse('2026-12-01 00:35', 'America/Cancun');
        $this->assertSame(['2026-12-01 00:30'], array_map(fn ($o) => $o->format('Y-m-d H:i'), $t->ocurrencias($nocturna, $martes)));
        $this->assertSame('a_tiempo', $t->estatusSugerido($nocturna, $martes));
        $sug = $t->sugerencia(collect([$nocturna->forceFill(['id' => 7])]), $martes);
        $this->assertSame(7, $sug['llegada'], 'El martes a las 00:35 sugiere la ruta del lunes');
        // El miércoles a la misma hora no hay servicio (el martes no sale)
        $this->assertSame([], $t->ocurrencias($nocturna, $martes->addDay()));
    }

    // ------------------------------------------------ E-13 exportar

    public function test_exportar_colaboradores_pases_responsivas_y_vouchers_con_su_permiso(): void
    {
        foreach (['pases_salida', 'responsivas', 'vouchers'] as $modulo) {
            $this->assertTrue(DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', $modulo)->where('a.clave', 'exportar')->exists(), "{$modulo}.exportar existe");
        }
        $this->enEmpresa(function () {
            $c = new Colaborador(['sede_id' => $this->centro->id, 'num_empleado' => 'E-50', 'nombre' => 'Ana', 'apellido_paterno' => 'Pech']);
            $c->forceFill(['activo' => true])->save();
        });
        $this->actingAs($this->admin)->get('/colaboradores')->assertSee(route('colaboradores.exportar'), false);
        $csv = $this->actingAs($this->admin)->get('/colaboradores/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('E-50', $csv);
        $this->assertStringNotContainsString('CURP', $csv, 'Sin datos personales');

        foreach (['/pases-salida/exportar', '/responsivas/exportar', '/vouchers/exportar'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }

        // El agente (sin «Exportar») no ve el botón ni descarga
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        foreach (['/colaboradores/exportar', '/pases-salida/exportar', '/responsivas/exportar', '/vouchers/exportar'] as $url) {
            $this->actingAs($agente)->get($url)->assertForbidden();
        }
    }

    public function test_la_migracion_del_permiso_exportar_sigue_a_quien_exporta_la_bitacora(): void
    {
        $migracion = require database_path('migrations/2026_11_17_000300_permiso_exportar_pases_responsivas_vouchers.php');
        $migracion->down();
        $this->assertFalse(DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', 'vouchers')->where('a.clave', 'exportar')->exists());
        $migracion->up();
        $migracion->up(); // dos veces: sin duplicados
        $roles = fn (string $modulo) => DB::table('rol_permisos as rp')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->where('m.clave', $modulo)->where('a.clave', 'exportar')->where('r.empresa_id', $this->empresa->id)->pluck('r.nombre')->sort()->values()->all();
        $exportanAccesos = $roles('accesos');
        $this->assertNotContains('Agente', $roles('vouchers'));
        $this->assertContains('Administrador', $roles('vouchers'));
        $this->assertSame([], array_diff($roles('vouchers'), $exportanAccesos), 'Solo quien ya exporta la bitácora');
        $this->assertSame(1, Modulo::where('clave', 'vouchers')->count());
        unset($exportanAccesos);
    }
}
