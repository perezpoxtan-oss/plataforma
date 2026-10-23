<?php

namespace Tests\Feature\Nucleo;

use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\MovimientoTransporte;
use App\Models\MovimientoTransportePasajero;
use App\Models\ProcedimientoPaso;
use App\Models\Proveedor;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Respaldos\Respaldos;
use App\Services\Rutas\AdministradorRutas;
use App\Services\Traslado\ImportadorSql;
use App\Support\BaseDatos\CatalogoChecks;
use App\Support\BaseDatos\Restricciones;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md):
 * 1. llaves foráneas restrict en las tablas con historia (sin romper los
 *    flujos que borran a propósito);
 * 2. empresa_id en los pasajeros de transporte, con su filtro;
 * 3. autoría en empresa_modulos y procedimiento_pasos;
 * 4. un solo valor verdad (rutas, candidatos) y plataforma:revisar-coherencia;
 * 5. CHECK en estados, etapas y categorías (y respaldo → restauración).
 *
 * Corre en SQLite (suite normal) y en MariaDB (paso propio de la CI). Las
 * pruebas que revierten migraciones solo corren en SQLite: en MariaDB un
 * ALTER cierra la transacción de la prueba; ahí lo cubre migrar → revertir →
 * migrar de la CI.
 */
class IntegridadBaseDatosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const M_EMPRESAS = 'database/migrations/2026_11_09_000100_foraneas_restrict_desde_empresas.php';

    private const M_PADRES = 'database/migrations/2026_11_09_000200_foraneas_restrict_hacia_padres_con_historia.php';

    private const M_PASAJEROS = 'database/migrations/2026_11_09_000300_empresa_en_pasajeros_de_transporte.php';

    private const M_AUTORIA = 'database/migrations/2026_11_09_000400_autoria_en_modulos_de_empresa_y_pasos.php';

    private const M_NORMALIZAR = 'database/migrations/2026_11_09_000500_normalizar_estados_antes_de_check.php';

    private const M_CHECK = 'database/migrations/2026_11_09_000600_check_en_estados_y_categorias.php';

    private Empresa $empresa;

    private Sede $sede;

    private User $admin;

    private ?string $temporal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Íntegro');
        $this->sede = $this->crearSede($this->empresa, 'INT');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    protected function tearDown(): void
    {
        if ($this->temporal !== null) {
            File::deleteDirectory($this->temporal);
            @unlink($this->temporal.'_destino.sqlite');
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------ Apoyo

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function fila(string $tabla, array $datos, ?Empresa $empresa = null): int
    {
        return DB::table($tabla)->insertGetId(['empresa_id' => ($empresa ?? $this->empresa)->id, 'created_at' => now(), 'updated_at' => now()] + $datos);
    }

    private function soloSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Revierte migraciones: en MariaDB lo cubre migrar → revertir → migrar de la CI.');
        }
    }

    private function migracion(string $ruta): object
    {
        return require base_path($ruta);
    }

    private function revertir(string ...$rutas): void
    {
        foreach ($rutas as $ruta) {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $ruta, '--force' => true]), Artisan::output());
        }
    }

    private function migrar(string ...$rutas): void
    {
        foreach ($rutas as $ruta) {
            $this->assertSame(0, Artisan::call('migrate', ['--path' => $ruta, '--force' => true]), Artisan::output());
        }
    }

    /** Una novedad con una nota y un acceso: historia mínima de la empresa. */
    private function historia(?Empresa $empresa = null, ?Sede $sede = null): array
    {
        $sede ??= $this->sede;
        $novedad = $this->fila('novedades', ['sede_id' => $sede->id, 'numero' => 1, 'reportado_por' => 'Caseta', 'ubicacion' => 'Lobby', 'descripcion' => 'Ronda'], $empresa);
        $nota = $this->fila('novedad_notas', ['novedad_id' => $novedad, 'autor_nombre' => 'Caseta', 'texto' => 'Sin novedad'], $empresa);
        $acceso = $this->fila('accesos', ['sede_id' => $sede->id, 'tipo' => 'visitante', 'nombre' => 'Ana Pech', 'entrada_at' => now(), 'estado' => 'en_sitio'], $empresa);

        return compact('novedad', 'nota', 'acceso');
    }

    private function ruta(): Ruta
    {
        [$turno, $proveedor] = $this->enEmpresa(fn () => [
            Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00:00', 'hora_fin' => '15:00:00', 'todas_las_sedes' => true, 'activo' => true]),
            Proveedor::create(['nombre' => 'Transportes Kin-Ha', 'categoria' => 'transporte_personal', 'activo' => true, 'todas_las_sedes' => true]),
        ]);

        return $this->enEmpresa(fn () => app(AdministradorRutas::class)->crear($this->admin, $this->sede, [
            'sentido' => 'llegada', 'nombre' => 'RUTA 1', 'turno_id' => $turno->id, 'proveedor_id' => $proveedor->id, 'costo_maximo_taxi' => 250,
            'horarios' => [
                ['nombre' => 'Fin de semana', 'dias' => ['SA', 'DO'], 'hora_inicio' => '07:00', 'hora_fin' => '07:40', 'paraderos' => [['nombre' => 'Chedraui', 'hora' => '']]],
                ['nombre' => 'Entre semana', 'dias' => ['LU', 'MA', 'MI', 'JU', 'VI'], 'hora_inicio' => '06:00', 'hora_fin' => '06:40', 'paraderos' => [['nombre' => 'Chedraui', 'hora' => '']]],
            ],
        ]));
    }

    private function movimiento(Ruta $ruta): MovimientoTransporte
    {
        return $this->enEmpresa(fn () => tap(new MovimientoTransporte([
            'sede_id' => $this->sede->id, 'ruta_id' => $ruta->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego', 'fecha' => '2026-11-09',
        ]))->save());
    }

    private function colaborador(string $num, ?Empresa $empresa = null): int
    {
        return $this->fila('colaboradores', ['nombre' => 'Colaborador '.$num, 'apellido_paterno' => 'Can', 'num_empleado' => $num, 'sede_id' => $this->sede->id], $empresa);
    }

    // ------------------------------------------------------------ 1. Llaves foráneas restrict

    public function test_las_tablas_con_historia_quedan_en_restrict_y_las_de_configuracion_no(): void
    {
        foreach ($this->migracion(self::M_EMPRESAS)::TABLAS as $tabla) {
            $this->assertSame('restrict', Restricciones::accionAlBorrar($tabla, 'empresa_id'), "{$tabla}.empresa_id");
        }
        foreach ($this->migracion(self::M_PADRES)::FORANEAS as $tabla => $columnas) {
            foreach (array_keys($columnas) as $columna) {
                $this->assertSame('restrict', Restricciones::accionAlBorrar($tabla, $columna), "{$tabla}.{$columna}");
            }
        }
        $this->assertSame('restrict', Restricciones::accionAlBorrar('movimiento_transporte_pasajeros', 'empresa_id'));
        // Configuración y catálogos siguen en cascada; el borrado de un candidato (ARCO, caducidad) también
        foreach (['llaves.empresa_id', 'roles.empresa_id', 'ruta_horarios.ruta_id', 'postulaciones.candidato_id', 'candidato_eventos.candidato_id',
            'evaluaciones_candidato.postulacion_id', 'candidato_documentos.candidato_id', 'autorizaciones.candidato_id'] as $llave) {
            [$tabla, $columna] = explode('.', $llave);
            $this->assertSame('cascade', Restricciones::accionAlBorrar($tabla, $columna), $llave);
        }
    }

    public function test_la_base_no_borra_una_empresa_ni_un_padre_con_historia(): void
    {
        $h = $this->historia();

        try {
            DB::table('empresas')->where('id', $this->empresa->id)->delete();
            $this->fail('La base borró una empresa con historia');
        } catch (QueryException) {
        }
        $this->assertSame(1, DB::table('novedades')->where('id', $h['novedad'])->count());

        try {
            DB::table('novedades')->where('id', $h['novedad'])->delete();
            $this->fail('La base borró una novedad con notas');
        } catch (QueryException) {
        }
        $this->assertSame(1, DB::table('novedad_notas')->where('id', $h['nota'])->count());

        // El detalle sí se puede quitar (los formatos reescriben sus renglones)
        DB::table('novedad_notas')->where('id', $h['nota'])->delete();
        $this->assertSame(0, DB::table('novedad_notas')->count());
    }

    public function test_la_baja_de_empresa_sigue_y_una_empresa_sin_historia_se_borra_en_cascada(): void
    {
        $this->historia();
        // Dar de baja (Estructura → Empresas) y SoftDeletes no tocan las llaves
        $this->enEmpresa(fn () => Empresa::findOrFail($this->empresa->id)->update(['activo' => false]));
        Empresa::findOrFail($this->empresa->id)->delete();
        $this->assertSoftDeleted('empresas', ['id' => $this->empresa->id]);
        $this->assertSame(1, DB::table('accesos')->where('empresa_id', $this->empresa->id)->count());

        // Sedes y usuarios ya impedían borrar una empresa (restrict desde su creación)
        $this->assertSame('restrict', Restricciones::accionAlBorrar('sedes', 'empresa_id'));
        $this->assertSame('restrict', Restricciones::accionAlBorrar('users', 'empresa_id'));
        // Una empresa capturada por error, sin sedes ni historia: su configuración se va en cascada como antes
        $vacia = $this->crearEmpresa('Hotel Error');
        $this->assertGreaterThan(0, DB::table('roles')->where('empresa_id', $vacia->id)->count());
        DB::table('empresas')->where('id', $vacia->id)->delete();
        $this->assertSame(0, DB::table('roles')->where('empresa_id', $vacia->id)->count());
    }

    public function test_borrar_un_candidato_sigue_llevandose_su_historia_en_cascada(): void
    {
        // ARCO «Eliminar definitivamente» y la caducidad borran la ficha completa a propósito
        $candidato = $this->fila('candidatos', ['sede_id' => $this->sede->id, 'nombre_completo' => 'Luis Chi', 'etapa' => 'con_rh']);
        $this->fila('postulaciones', ['sede_id' => $this->sede->id, 'candidato_id' => $candidato, 'etapa' => 'con_rh']);
        $this->fila('candidato_eventos', ['candidato_id' => $candidato, 'evento' => 'registrado']);

        $this->enEmpresa(fn () => Candidato::findOrFail($candidato)->delete());

        $this->assertSame(0, DB::table('postulaciones')->where('candidato_id', $candidato)->count());
        $this->assertSame(0, DB::table('candidato_eventos')->where('candidato_id', $candidato)->count());
    }

    public function test_eliminar_definitivamente_un_departamento_usado_en_reservas_sigue_explicando_por_que_no(): void
    {
        $depto = $this->fila('departamentos', ['nombre' => 'Banquetes']);
        $espacio = $this->fila('espacios', ['sede_id' => $this->sede->id, 'nombre' => 'Salón Maya', 'nivel' => 'edificio']);
        $area = $this->fila('reserva_areas', ['sede_id' => $this->sede->id, 'espacio_id' => $espacio]);
        $reserva = $this->fila('reservas', ['sede_id' => $this->sede->id, 'reserva_area_id' => $area, 'espacio_id' => $espacio, 'titulo' => 'Boda',
            'inicio' => now(), 'fin' => now()->addHour(), 'bloqueo_desde' => now(), 'bloqueo_hasta' => now()->addHour()]);
        $this->fila('reserva_ordenes', ['reserva_id' => $reserva, 'departamento_id' => $depto]);

        $this->actingAs($this->admin)->getJson("/borrar/departamentos/{$depto}")->assertOk()->assertJson(['puede_eliminar' => false]);
        $this->actingAs($this->admin)->deleteJson("/borrar/departamentos/{$depto}", ['confirmacion' => 'Banquetes'])->assertStatus(409);
        $this->assertSame(1, DB::table('departamentos')->where('id', $depto)->count());
    }

    public function test_las_migraciones_de_llaves_se_revierten_a_cascada_y_se_vuelven_a_aplicar_con_datos(): void
    {
        $this->soloSqlite();
        $h = $this->historia();

        $this->revertir(self::M_CHECK, self::M_NORMALIZAR, self::M_AUTORIA, self::M_PASAJEROS, self::M_PADRES, self::M_EMPRESAS);
        $this->assertSame('cascade', Restricciones::accionAlBorrar('accesos', 'empresa_id'));
        $this->assertSame('cascade', Restricciones::accionAlBorrar('novedad_notas', 'novedad_id'));
        $this->assertSame('cascade', Restricciones::accionAlBorrar('movimiento_transporte_pasajeros', 'movimiento_transporte_id'));
        $this->assertSame([], Restricciones::checks('accesos'));

        $this->migrar(self::M_EMPRESAS, self::M_PADRES, self::M_PASAJEROS, self::M_AUTORIA, self::M_NORMALIZAR, self::M_CHECK);
        $this->assertSame('restrict', Restricciones::accionAlBorrar('accesos', 'empresa_id'));
        $this->assertSame('restrict', Restricciones::accionAlBorrar('novedad_notas', 'novedad_id'));
        $this->assertSame(['chk_accesos_estado' => 'estado'], Restricciones::checks('accesos'));
        // Los datos siguen ahí y la base está sana
        $this->assertSame(1, DB::table('novedad_notas')->where('id', $h['nota'])->count());
        $this->assertSame('ok', DB::scalar('pragma integrity_check'));
        $this->assertSame([], DB::select('pragma foreign_key_check'));

        // Idempotente: correr la lógica otra vez no cambia nada
        $this->migracion(self::M_EMPRESAS)->up();
        $this->migracion(self::M_CHECK)->up();
        $this->assertSame(['chk_accesos_estado' => 'estado'], Restricciones::checks('accesos'));
    }

    // ------------------------------------------------------------ 2. Pasajeros con empresa

    public function test_los_pasajeros_guardan_la_empresa_de_su_movimiento_y_usan_el_filtro_de_empresa(): void
    {
        $m = $this->movimiento($this->ruta());
        $a = $this->colaborador('1005');
        $b = $this->colaborador('1007');
        $this->enEmpresa(fn () => $m->pasajeros()->sync([$a, $b]));

        $this->assertSame([$this->empresa->id], DB::table('movimiento_transporte_pasajeros')->distinct()->pluck('empresa_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$a, $b], $this->enEmpresa(fn () => $m->pasajeros()->pluck('colaboradores.id')->map(fn ($v) => (int) $v)->sort()->values()->all()));

        // Sin empresa activa (consola) se toma la del movimiento
        $c = $this->colaborador('1009');
        $m->pasajeros()->attach($c);
        $this->assertSame($this->empresa->id, (int) DB::table('movimiento_transporte_pasajeros')->where('colaborador_id', $c)->value('empresa_id'));

        // Filtro de empresa: otra empresa no ve estos pasajeros
        $otra = $this->crearEmpresa('Hotel Otro');
        $this->assertSame(3, $this->enEmpresa(fn () => MovimientoTransportePasajero::count()));
        $this->assertSame(0, $this->enEmpresa(fn () => MovimientoTransportePasajero::count(), $otra));

        // Quitar pasajeros al editar el vale sigue funcionando
        $this->enEmpresa(fn () => $m->pasajeros()->sync([$a]));
        $this->assertSame(1, DB::table('movimiento_transporte_pasajeros')->count());

        // La empresa es obligatoria y existe
        $this->expectException(QueryException::class);
        DB::table('movimiento_transporte_pasajeros')->insert(['movimiento_transporte_id' => $m->id, 'colaborador_id' => $b, 'empresa_id' => null]);
    }

    public function test_la_migracion_llena_la_empresa_de_los_pasajeros_desde_su_movimiento(): void
    {
        $this->soloSqlite();
        $m = $this->movimiento($this->ruta());
        $a = $this->colaborador('1005');
        $this->revertir(self::M_CHECK, self::M_NORMALIZAR, self::M_AUTORIA, self::M_PASAJEROS);
        $this->assertFalse(Schema::hasColumn('movimiento_transporte_pasajeros', 'empresa_id'));
        DB::table('movimiento_transporte_pasajeros')->insert(['movimiento_transporte_id' => $m->id, 'colaborador_id' => $a]);

        $this->migrar(self::M_PASAJEROS, self::M_AUTORIA, self::M_NORMALIZAR, self::M_CHECK);

        $this->assertSame($this->empresa->id, (int) DB::table('movimiento_transporte_pasajeros')->value('empresa_id'));
        $this->assertTrue(collect(Schema::getIndexes('movimiento_transporte_pasajeros'))->contains('name', 'mov_transporte_pasajero_empresa_idx'));
        $this->assertSame('restrict', Restricciones::accionAlBorrar('movimiento_transporte_pasajeros', 'empresa_id'));
        $this->assertSame('restrict', Restricciones::accionAlBorrar('movimiento_transporte_pasajeros', 'movimiento_transporte_id'));
    }

    // ------------------------------------------------------------ 3. Autoría

    public function test_la_autoria_se_llena_en_modulos_de_empresa_y_pasos_de_procedimiento(): void
    {
        $sa = $this->crearSuperadmin();
        $accesos = Modulo::where('clave', 'accesos')->value('id');
        $this->actingAs($sa)->put("/empresas/{$this->empresa->id}/modulos", ['modulos' => [$accesos]])->assertSessionHas('ok');
        $fila = DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $accesos)->first();
        $this->assertSame($sa->id, (int) $fila->actualizado_por);

        // Fila nueva (el módulo no existía para la empresa): creado_por y actualizado_por
        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $accesos)->delete();
        $this->actingAs($sa)->put("/empresas/{$this->empresa->id}/modulos", ['modulos' => [$accesos]])->assertSessionHas('ok');
        $fila = DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $accesos)->first();
        $this->assertSame([$sa->id, $sa->id], [(int) $fila->creado_por, (int) $fila->actualizado_por]);

        $categoria = $this->fila('procedimiento_categorias', ['nombre' => 'Operación']);
        $procedimiento = $this->fila('procedimientos', ['clave' => 'PR-01', 'categoria_id' => $categoria, 'titulo' => 'Rondín', 'codigo_qr' => 'qr-pr-01']);
        $version = $this->fila('procedimiento_versiones', ['procedimiento_id' => $procedimiento, 'numero' => 1, 'categoria_id' => $categoria, 'titulo' => 'Rondín', 'objetivo' => 'Revisar']);
        $this->actingAs($this->admin);
        $paso = $this->enEmpresa(fn () => ProcedimientoPaso::create(['version_id' => $version, 'orden' => 1, 'texto' => 'Revisar puertas']));
        $this->assertSame([$this->admin->id, $this->admin->id], [(int) $paso->creado_por, (int) $paso->actualizado_por]);
    }

    public function test_la_migracion_de_autoria_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $this->soloSqlite();
        $this->revertir(self::M_CHECK, self::M_NORMALIZAR, self::M_AUTORIA);
        $this->assertFalse(Schema::hasColumn('empresa_modulos', 'creado_por'));
        $this->assertFalse(Schema::hasColumn('procedimiento_pasos', 'actualizado_por'));
        $this->migrar(self::M_AUTORIA, self::M_NORMALIZAR, self::M_CHECK);
        $this->assertTrue(Schema::hasColumn('empresa_modulos', 'creado_por'));
        $this->assertTrue(Schema::hasColumn('procedimiento_pasos', 'actualizado_por'));
    }

    // ------------------------------------------------------------ 4. Un solo valor verdad

    public function test_el_resumen_de_la_ruta_sale_siempre_de_sus_horarios(): void
    {
        $ruta = $this->ruta();
        $this->assertSame(['hora_inicio' => '06:00:00', 'hora_fin' => '06:40:00', 'dias' => 'LU,MA,MI,JU,VI,SA,DO'], Ruta::normalizarResumen($ruta->fresh()->only(['hora_inicio', 'hora_fin', 'dias'])));

        // Si alguien cambia un horario directo, recalcularResumen() lo corrige desde los horarios
        $this->enEmpresa(fn () => $ruta->horarios()->where('nombre', 'Entre semana')->update(['hora_inicio' => '08:00:00', 'hora_fin' => null]));
        $this->assertTrue($this->enEmpresa(fn () => $ruta->fresh()->recalcularResumen()));
        $this->assertSame(['hora_inicio' => '07:00:00', 'hora_fin' => '07:40:00', 'dias' => 'LU,MA,MI,JU,VI,SA,DO'], Ruta::normalizarResumen($ruta->fresh()->only(['hora_inicio', 'hora_fin', 'dias'])));
        $this->assertFalse($this->enEmpresa(fn () => $ruta->fresh()->recalcularResumen()));
    }

    public function test_revisar_coherencia_informa_sin_tocar_y_con_reparar_corrige(): void
    {
        $ruta = $this->ruta();
        DB::table('rutas')->where('id', $ruta->id)->update(['hora_inicio' => '05:00:00', 'dias' => 'LU']);
        $candidato = $this->fila('candidatos', ['sede_id' => $this->sede->id, 'nombre_completo' => 'Rosa May', 'etapa' => 'registrado']);
        $this->fila('postulaciones', ['sede_id' => $this->sede->id, 'candidato_id' => $candidato, 'etapa' => 'rechazado', 'created_at' => now()->subDay()]);
        $activa = $this->fila('postulaciones', ['sede_id' => $this->sede->id, 'candidato_id' => $candidato, 'etapa' => 'con_rh']);
        $coherente = $this->fila('candidatos', ['sede_id' => $this->sede->id, 'nombre_completo' => 'Juan Ek', 'etapa' => 'elegido']);
        $this->fila('postulaciones', ['sede_id' => $this->sede->id, 'candidato_id' => $coherente, 'etapa' => 'elegido']);
        $m = $this->movimiento($ruta);
        $this->enEmpresa(fn () => $m->pasajeros()->sync([$this->colaborador('1005')]));
        $otra = $this->crearEmpresa('Hotel Otro');
        DB::table('movimiento_transporte_pasajeros')->update(['empresa_id' => $otra->id]);

        // Solo lectura: informa y sale con 1
        $this->assertSame(1, Artisan::call('plataforma:revisar-coherencia'));
        $salida = Artisan::output();
        $this->assertStringContainsString("Ruta #{$ruta->id} «RUTA 1»", $salida);
        $this->assertStringContainsString("Candidato #{$candidato} (empresa {$this->empresa->id}): etapa «registrado», su postulación activa #{$activa} dice «con_rh»", $salida);
        $this->assertStringContainsString('Coherencia: 1 ruta(s), 1 candidato(s), 1 pasajero(s) con diferencias.', $salida);
        $this->assertSame('05:00:00', DB::table('rutas')->where('id', $ruta->id)->value('hora_inicio'));
        $this->assertSame('registrado', DB::table('candidatos')->where('id', $candidato)->value('etapa'));

        // --reparar corrige desde la fuente y lo deja en la auditoría
        $this->assertSame(0, Artisan::call('plataforma:revisar-coherencia', ['--reparar' => true]));
        $this->assertStringContainsString('3 reparado(s)', Artisan::output());
        $this->assertSame(['hora_inicio' => '06:00:00', 'hora_fin' => '06:40:00', 'dias' => 'LU,MA,MI,JU,VI,SA,DO'],
            Ruta::normalizarResumen((array) DB::table('rutas')->where('id', $ruta->id)->first(['hora_inicio', 'hora_fin', 'dias'])));
        $this->assertSame('con_rh', DB::table('candidatos')->where('id', $candidato)->value('etapa'));
        $this->assertSame($this->empresa->id, (int) DB::table('movimiento_transporte_pasajeros')->value('empresa_id'));
        $this->assertSame(2, Auditoria::where('evento', 'plataforma.coherencia_reparada')->count());
        $this->assertSame(['etapa' => 'registrado'], Auditoria::where('evento', 'plataforma.coherencia_reparada')->where('auditable_type', Candidato::class)->value('antes'));

        $this->assertSame(0, Artisan::call('plataforma:revisar-coherencia'));
        $this->assertStringContainsString('Coherencia: sin diferencias.', Artisan::output());
    }

    public function test_la_tarea_diaria_corre_una_vez_despues_de_las_4(): void
    {
        $ruta = $this->ruta();
        DB::table('rutas')->where('id', $ruta->id)->update(['dias' => 'LU']);

        // 3:00 en la plataforma: todavía no toca
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00', 'America/Cancun'));
        $this->assertSame(0, Artisan::call('plataforma:revisar-coherencia', ['--si-toca' => true, '--reparar' => true]));
        $this->assertSame('LU', DB::table('rutas')->where('id', $ruta->id)->value('dias'));
        $this->assertNull(ConfiguracionPlataforma::where('clave', 'coherencia_revision')->value('valor'));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 04:05', 'America/Cancun'));
        $this->assertSame(0, Artisan::call('plataforma:revisar-coherencia', ['--si-toca' => true, '--reparar' => true]));
        $this->assertSame('LU,MA,MI,JU,VI,SA,DO', DB::table('rutas')->where('id', $ruta->id)->value('dias'));
        $anotado = ConfiguracionPlataforma::where('clave', 'coherencia_revision')->value('valor');
        $this->assertSame(['2026-11-10', 1, 1], [$anotado['fecha'], $anotado['rutas'], $anotado['reparados']]);

        // Ya corrió hoy: aunque aparezca otra diferencia, espera a mañana
        DB::table('rutas')->where('id', $ruta->id)->update(['dias' => 'MA']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00', 'America/Cancun'));
        Artisan::call('plataforma:revisar-coherencia', ['--si-toca' => true, '--reparar' => true]);
        $this->assertSame('MA', DB::table('rutas')->where('id', $ruta->id)->value('dias'));
    }

    public function test_desplegar_corre_la_revision_en_proceso_una_vez_al_dia(): void
    {
        $script = file_get_contents(base_path('despliegue/desplegar.sh'));
        $this->assertStringContainsString('artisan plataforma:revisar-coherencia --si-toca --reparar', $script);
        $this->assertStringContainsString("grep -q '^plataforma:revisar-coherencia'", $script);
    }

    // ------------------------------------------------------------ 5. CHECK

    public function test_los_check_existen_con_los_valores_de_las_constantes(): void
    {
        foreach (CatalogoChecks::definiciones() as $tabla => $columnas) {
            foreach ($columnas as $columna => $valores) {
                sort($valores);
                $this->assertSame($valores, Restricciones::valoresDelCheck($tabla, $columna),
                    "El CHECK de {$tabla}.{$columna} no coincide con las constantes del modelo: agrega una migración que lo actualice (docs/tecnico/integridad-bd.md).");
            }
        }
    }

    public function test_la_base_rechaza_estados_etapas_y_categorias_fuera_de_catalogo(): void
    {
        $h = $this->historia();
        $casos = [
            ['accesos', $h['acceso'], 'estado', 'en_el_limbo'],
            ['novedades', $h['novedad'], 'estatus', 'RESUELTO'],
            ['novedades', $h['novedad'], 'categoria', 'chisme'],
        ];
        $candidato = $this->fila('candidatos', ['sede_id' => $this->sede->id, 'nombre_completo' => 'Rosa May']);
        $casos[] = ['candidatos', $candidato, 'etapa', 'descartado'];
        foreach ($casos as [$tabla, $id, $columna, $valor]) {
            try {
                DB::table($tabla)->where('id', $id)->update([$columna => $valor]);
                $this->fail("La base aceptó {$tabla}.{$columna} = {$valor}");
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('chk_'.$tabla.'_'.$columna, $e->getMessage());
            }
        }
        // Los valores válidos pasan
        DB::table('accesos')->where('id', $h['acceso'])->update(['estado' => 'finalizado']);
        DB::table('novedades')->where('id', $h['novedad'])->update(['estatus' => 'resuelto', 'categoria' => 'lost_found']);
        DB::table('candidatos')->where('id', $candidato)->update(['etapa' => 'rechazado']);
        $this->assertSame('finalizado', DB::table('accesos')->where('id', $h['acceso'])->value('estado'));
    }

    public function test_antes_del_check_se_normalizan_los_datos_y_lo_irreconocible_se_reporta_sin_fallar(): void
    {
        $this->soloSqlite();
        $h = $this->historia();
        $candidato = $this->fila('candidatos', ['sede_id' => $this->sede->id, 'nombre_completo' => 'Rosa May']);
        $this->revertir(self::M_CHECK, self::M_NORMALIZAR);
        $this->assertSame([], Restricciones::checks('novedades'));

        DB::table('novedades')->where('id', $h['novedad'])->update(['estatus' => 'RESUELTO', 'categoria' => 'LOST_FOUND']);
        DB::table('candidatos')->where('id', $candidato)->update(['etapa' => 'descartado']);
        $otro = $this->fila('accesos', ['sede_id' => $this->sede->id, 'tipo' => 'visitante', 'nombre' => 'Eva', 'entrada_at' => now(), 'estado' => 'En Sitio ']);
        DB::table('accesos')->where('id', $h['acceso'])->update(['estado' => 'quien_sabe']);

        $this->migrar(self::M_NORMALIZAR, self::M_CHECK);

        $this->assertSame(['resuelto', 'lost_found'], array_values((array) DB::table('novedades')->where('id', $h['novedad'])->first(['estatus', 'categoria'])));
        $this->assertSame('rechazado', DB::table('candidatos')->where('id', $candidato)->value('etapa'));
        $this->assertSame('en_sitio', DB::table('accesos')->where('id', $otro)->value('estado'));
        // Lo irreconocible no se toca: ese CHECK se omite y queda anotado; los demás sí se ponen
        $this->assertSame('quien_sabe', DB::table('accesos')->where('id', $h['acceso'])->value('estado'));
        $this->assertSame([], Restricciones::checks('accesos'));
        $this->assertSame(['chk_novedades_categoria' => 'categoria', 'chk_novedades_estatus' => 'estatus'], Restricciones::checks('novedades'));
        $informe = ConfiguracionPlataforma::where('clave', 'restricciones_check')->value('valor');
        $this->assertSame(['accesos.estado' => 1], $informe['omitidos']);
        $this->assertSame(['quien_sabe' => 1], $informe['sin_reconocer']['accesos.estado']);
        $this->assertSame(1, $informe['normalizados']['novedades.estatus']['RESUELTO → resuelto']);

        // La revisión de coherencia lo sigue reportando
        Artisan::call('plataforma:revisar-coherencia');
        $this->assertStringContainsString('Valores fuera de catálogo en accesos.estado: 1 fila(s)', Artisan::output());

        // Corregido a mano, la migración (idempotente) pone el que faltaba
        DB::table('accesos')->where('id', $h['acceso'])->update(['estado' => 'finalizado']);
        $this->migracion(self::M_CHECK)->up();
        $this->assertSame(['chk_accesos_estado' => 'estado'], Restricciones::checks('accesos'));
    }

    public function test_respaldo_y_restauracion_conservan_los_check_y_las_llaves_restrict(): void
    {
        $this->soloSqlite(); // en MariaDB: .github/scripts/respaldo-mariadb.php
        $this->temporal = sys_get_temp_dir().'/integridad_prueba_'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->temporal.'/app/private');
        $this->app->useStoragePath($this->temporal);
        config(['filesystems.disks.local.root' => $this->temporal.'/app/private']);
        $this->app->forgetInstance('filesystem');
        $h = $this->historia();

        $respaldo = app(Respaldos::class)->crear('manual');
        $archivo = $this->temporal.'_destino.sqlite';
        touch($archivo);
        config(['database.connections.destino' => ['driver' => 'sqlite', 'database' => $archivo, 'prefix' => '', 'foreign_key_constraints' => true]]);
        (new ImportadorSql)->importar($respaldo['ruta'], DB::connection('destino'));
        // La importación apaga la revisión de llaves en su conexión; la plataforma restaurada abre conexiones nuevas
        DB::purge('destino');
        $destino = DB::connection('destino');
        $this->assertSame([], $destino->select('pragma foreign_key_check'));

        $this->assertSame('en_sitio', $destino->table('accesos')->where('id', $h['acceso'])->value('estado'));
        $this->assertSame(1, $destino->table('novedad_notas')->count());
        foreach ([fn () => $destino->table('accesos')->where('id', $h['acceso'])->update(['estado' => 'en_el_limbo']),
            fn () => $destino->table('novedades')->where('id', $h['novedad'])->delete()] as $i => $prohibido) {
            try {
                $prohibido();
                $this->fail("La base restaurada aceptó el caso {$i}");
            } catch (QueryException) {
            }
        }
        $this->assertSame(1, $destino->table('novedades')->count());
        DB::purge('destino');
    }
}
