<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Borrado\RegistroBorrado;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * "Eliminar definitivamente" (borrado físico controlado de catálogos y
 * padrones): por cada módulo registrado se borra sin dependencias, se rechaza
 * con una dependencia real, otra empresa recibe 404 y sin permiso 403.
 */
class EliminarDefinitivoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    /** Inserta una fila de la empresa indicada (con fechas y código QR si la tabla los tiene). */
    private function fila(string $tabla, array $valores, ?Empresa $empresa = null): int
    {
        $this->n++;
        $columnas = Schema::getColumnListing($tabla);
        $base = ['created_at' => now(), 'updated_at' => now()];
        if (in_array('empresa_id', $columnas, true)) {
            $base['empresa_id'] = ($empresa ?? $this->empresa)->id;
        }
        if (in_array('codigo_qr', $columnas, true)) {
            $base['codigo_qr'] = 'qrborrar'.str_pad((string) $this->n, 6, '0', STR_PAD_LEFT);
        }

        return DB::table($tabla)->insertGetId(array_merge($base, $valores));
    }

    private function sedeDe(?Empresa $empresa): int
    {
        if ($empresa === null || $empresa->is($this->empresa)) {
            return $this->centro->id;
        }

        return (int) (Sede::withoutGlobalScopes()->where('empresa_id', $empresa->id)->value('id') ?? $this->crearSede($empresa, 'OT'.$this->n)->id);
    }

    /**
     * clave => [crear(empresa): id, dependencia(id): void, texto esperado del mensaje]
     *
     * @return array<string, array{0: \Closure, 1: \Closure, 2: string}>
     */
    private function casos(): array
    {
        $proveedor = fn (?Empresa $e = null) => $this->fila('proveedores', ['nombre' => 'Transportes '.$this->n], $e);
        $turno = fn (?Empresa $e = null) => $this->fila('turnos', ['nombre' => 'Turno '.$this->n, 'hora_inicio' => '07:00', 'hora_fin' => '15:00'], $e);
        $ruta = fn (?Empresa $e = null) => $this->fila('rutas', ['sede_id' => $this->sedeDe($e), 'sentido' => 'entrada', 'nombre' => 'Ruta '.$this->n,
            'turno_id' => $turno($e), 'proveedor_id' => $proveedor($e)], $e);
        $tipoGafete = fn (?Empresa $e = null) => $this->fila('tipos_gafete', ['nombre' => 'Tipo '.$this->n], $e);
        $tipoEquipo = fn (?Empresa $e = null) => $this->fila('tipos_equipo', ['nombre' => 'Tipo '.$this->n], $e);
        $acceso = fn (array $extra) => $this->fila('accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Visita', 'entrada_at' => now()] + $extra);
        $voucher = fn (string $tipo, int $id) => $this->fila('vouchers_reposicion', ['folio' => 'V-'.$this->n, 'origen_tipo' => $tipo, 'origen_id' => $id,
            'origen_descripcion' => 'Prueba', 'motivo' => 'extraviado']);

        return [
            'sedes' => [
                fn (?Empresa $e = null) => $e === null || $e->is($this->empresa) ? $this->crearSede($this->empresa, 'B'.$this->n++)->id : $this->crearSede($e, 'B'.$this->n++)->id,
                fn (int $id) => $this->fila('zonas_estacionamiento', ['sede_id' => $id, 'nombre' => 'Sótano']),
                '1 zona de estacionamiento',
            ],
            'espacios' => [
                fn (?Empresa $e = null) => $this->fila('espacios', ['sede_id' => $this->sedeDe($e), 'nivel' => 'edificio', 'nombre' => 'Torre '.$this->n], $e),
                fn (int $id) => $this->fila('espacios', ['sede_id' => $this->centro->id, 'nivel' => 'area', 'padre_id' => $id, 'nombre' => 'Piso 1']),
                '1 zona o área dentro',
            ],
            'departamentos' => [
                fn (?Empresa $e = null) => $this->fila('departamentos', ['nombre' => 'Compras '.$this->n], $e),
                fn (int $id) => $this->fila('colaboradores', ['nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'departamento_id' => $id]),
                '1 colaborador',
            ],
            'puestos' => [
                fn (?Empresa $e = null) => $this->fila('puestos', ['nombre' => 'Auxiliar '.$this->n], $e),
                fn (int $id) => $this->fila('colaboradores', ['nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'puesto_id' => $id]),
                '1 colaborador',
            ],
            'turnos' => [
                $turno,
                fn (int $id) => $this->fila('rutas', ['sede_id' => $this->centro->id, 'sentido' => 'entrada', 'nombre' => 'Ruta', 'turno_id' => $id, 'proveedor_id' => $proveedor()]),
                '1 ruta de transporte',
            ],
            'colaboradores' => [
                fn (?Empresa $e = null) => $this->fila('colaboradores', ['sede_id' => $this->sedeDe($e), 'nombre' => 'Luis', 'apellido_paterno' => 'Mena', 'num_empleado' => 'B-'.$this->n], $e),
                fn (int $id) => $this->fila('vehiculos', ['placas' => 'COL-'.$this->n, 'colaborador_id' => $id]),
                '1 vehículo',
            ],
            'roles' => [
                fn (?Empresa $e = null) => Rol::create(['empresa_id' => ($e ?? $this->empresa)->id, 'nombre' => 'Temporal '.$this->n, 'nivel_jerarquia' => 70 + $this->n++])->id,
                fn (int $id) => UsuarioRol::create(['user_id' => $this->crearUsuario($this->empresa)->id, 'rol_id' => $id, 'sede_id' => null]),
                '1 usuario asignado',
            ],
            'proveedores' => [
                $proveedor,
                fn (int $id) => $this->fila('personas', ['nombre_completo' => 'Chofer', 'proveedor_id' => $id]),
                '1 persona del padrón',
            ],
            'personas' => [
                fn (?Empresa $e = null) => $this->fila('personas', ['nombre_completo' => 'María López '.$this->n], $e),
                fn (int $id) => $acceso(['persona_id' => $id]),
                '1 registro en la bitácora de accesos',
            ],
            'vehiculos' => [
                fn (?Empresa $e = null) => $this->fila('vehiculos', ['placas' => 'BOR-'.$this->n], $e),
                fn (int $id) => $acceso(['vehiculo_id' => $id]),
                '1 registro en la bitácora de accesos',
            ],
            'llaves' => [
                fn (?Empresa $e = null) => $this->fila('llaves', ['sede_id' => $this->sedeDe($e), 'nomenclatura' => 'LL-'.$this->n, 'descripcion' => 'Bodega',
                    'tipo_dispositivo' => 'metalica', 'alcance' => 'zona'], $e),
                fn (int $id) => $voucher('llave', $id),
                '1 voucher',
            ],
            'gafetes' => [
                fn (?Empresa $e = null) => $this->fila('gafetes', ['sede_id' => $this->sedeDe($e), 'tipo_gafete_id' => $tipoGafete($e), 'nomenclatura' => 'GF-'.$this->n, 'consecutivo' => $this->n], $e),
                fn (int $id) => $acceso(['gafete_id' => $id]),
                '1 registro en la bitácora de accesos',
            ],
            'tipos_gafete' => [
                $tipoGafete,
                fn (int $id) => $this->fila('gafetes', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $id, 'nomenclatura' => 'GF-'.$this->n, 'consecutivo' => $this->n]),
                '1 gafete',
            ],
            'equipos' => [
                fn (?Empresa $e = null) => $this->fila('equipos', ['sede_id' => $this->sedeDe($e), 'tipo_equipo_id' => $tipoEquipo($e), 'numero_serie' => 'RAD-'.$this->n], $e),
                fn (int $id) => $voucher('equipo', $id),
                '1 voucher',
            ],
            'tipos_equipo' => [
                $tipoEquipo,
                fn (int $id) => $this->fila('equipos', ['sede_id' => $this->centro->id, 'tipo_equipo_id' => $id, 'numero_serie' => 'RAD-'.$this->n]),
                '1 equipo de seguridad',
            ],
            'equipos_pc' => [
                fn (?Empresa $e = null) => $this->fila('equipos_pc', ['sede_id' => $this->sedeDe($e), 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-'.$this->n], $e),
                fn (int $id) => $this->fila('recorrido_pc_revisiones', ['recorrido_pc_id' => $this->fila('recorridos_pc', ['sede_id' => $this->centro->id, 'numero' => $this->n]),
                    'equipo_pc_id' => $id, 'identificador' => 'EXT', 'categoria' => 'EXTINTOR', 'criterios' => '[]', 'resultado' => 'ok']),
                '1 revisión de Protección Civil',
            ],
            'estacionamientos' => [
                fn (?Empresa $e = null) => $this->fila('zonas_estacionamiento', ['sede_id' => $this->sedeDe($e), 'nombre' => 'Sótano '.$this->n], $e),
                fn (int $id) => $acceso(['zona_estacionamiento_id' => $id]),
                '1 registro en la bitácora de accesos',
            ],
            'rutas' => [
                $ruta,
                fn (int $id) => $this->fila('movimientos_transporte', ['sede_id' => $this->centro->id, 'ruta_id' => $id, 'tipo_movimiento' => 'entrada', 'estatus' => 'llegada', 'fecha' => now()->toDateString()]),
                '1 movimiento de transporte',
            ],
            'paraderos' => [
                fn (?Empresa $e = null) => $this->fila('paraderos', ['sede_id' => $this->sedeDe($e), 'nombre' => 'Glorieta '.$this->n], $e),
                fn (int $id) => $this->fila('ruta_paradas', ['ruta_horario_id' => $this->fila('ruta_horarios', ['ruta_id' => $ruta(), 'nombre' => 'L-V', 'dias' => '1,2,3,4,5',
                    'hora_inicio' => '06:00', 'hora_fin' => '07:00']), 'paradero_id' => $id]),
                '1 parada en una ruta',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modulosRegistrados(): array
    {
        $claves = ['sedes', 'espacios', 'departamentos', 'puestos', 'turnos', 'colaboradores', 'roles', 'proveedores', 'personas', 'vehiculos',
            'llaves', 'gafetes', 'tipos_gafete', 'equipos', 'tipos_equipo', 'equipos_pc', 'estacionamientos', 'rutas', 'paraderos'];

        return array_combine($claves, array_map(fn ($c) => [$c], $claves));
    }

    public function test_el_registro_cubre_exactamente_los_modulos_aprobados(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::modulosRegistrados()), array_keys(RegistroBorrado::definiciones()));
        $this->assertEqualsCanonicalizing(array_keys(self::modulosRegistrados()), array_keys($this->casos()));
    }

    #[DataProvider('modulosRegistrados')]
    public function test_sin_dependencias_se_elimina_con_confirmacion_tecleada_y_queda_copia_en_auditoria(string $clave): void
    {
        $definicion = RegistroBorrado::de($clave);
        $id = ($this->casos()[$clave][0])();
        $tabla = (new $definicion['modelo'])->getTable();
        $antes = (array) DB::table($tabla)->find($id);

        $revision = $this->actingAs($this->admin)->getJson("/borrar/{$clave}/{$id}")->assertOk()
            ->assertJson(['puede_eliminar' => true, 'mensaje' => null, 'baja' => null])
            ->json();
        $this->assertNotSame('', $revision['confirmar']);

        // Sin teclear (o con otro texto) no se borra; el mensaje sale en el diálogo
        $this->deleteJson("/borrar/{$clave}/{$id}", ['confirmacion' => ''])->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', "Para confirmar escribe exactamente «{$revision['confirmar']}».");
        $this->deleteJson("/borrar/{$clave}/{$id}", ['confirmacion' => 'otra cosa'])->assertStatus(422);
        $this->assertDatabaseHas($tabla, ['id' => $id]);

        // Mayúsculas y espacios de más no importan
        $this->deleteJson("/borrar/{$clave}/{$id}", ['confirmacion' => '  '.mb_strtoupper($revision['confirmar']).' '])->assertOk()
            ->assertJson(['ok' => true])->assertJsonPath('mensaje', ucfirst($revision['tipo'])." «{$revision['nombre']}» se eliminó definitivamente.");
        $this->assertDatabaseMissing($tabla, ['id' => $id]);

        $auditoria = Auditoria::where('evento', $definicion['modulo'].'.eliminado_definitivo')->where('auditable_id', $id)->sole();
        $this->assertSame($definicion['modelo'], $auditoria->auditable_type);
        $this->assertSame($this->admin->id, $auditoria->user_id);
        $this->assertSame($this->empresa->id, $auditoria->empresa_id);
        $this->assertNull($auditoria->despues);
        // Copia completa: todas las columnas con su valor
        foreach ($antes as $columna => $valor) {
            $this->assertArrayHasKey($columna, $auditoria->antes, "Falta {$columna} en la copia de {$clave}");
            $this->assertEquals($valor, $auditoria->antes[$columna]);
        }
    }

    #[DataProvider('modulosRegistrados')]
    public function test_con_una_dependencia_real_no_se_elimina_y_se_explica_que_lo_usa(string $clave): void
    {
        [$crear, $depender, $texto] = $this->casos()[$clave];
        $definicion = RegistroBorrado::de($clave);
        $id = $crear();
        $depender($id);
        $tabla = (new $definicion['modelo'])->getTable();

        $revision = $this->actingAs($this->admin)->getJson("/borrar/{$clave}/{$id}")->assertOk()
            ->assertJson(['puede_eliminar' => false])->json();
        $this->assertStringContainsString("Tiene {$texto}", $revision['mensaje']);
        $this->assertStringEndsWith(': no se puede eliminar; puedes '.($definicion['tipo'][1] === 'f' ? 'darla' : 'darlo').' de baja.', $revision['mensaje']);
        $this->assertNotNull($revision['baja'], "{$clave}: se ofrece la baja o una indicación");

        $this->deleteJson("/borrar/{$clave}/{$id}", ['confirmacion' => $revision['confirmar']])->assertStatus(409)
            ->assertJsonPath('mensaje', $revision['mensaje']);
        $this->assertDatabaseHas($tabla, ['id' => $id]);
        $this->assertFalse(Auditoria::where('evento', 'like', '%.eliminado_definitivo')->exists());
    }

    #[DataProvider('modulosRegistrados')]
    public function test_otra_empresa_recibe_404_y_sin_permiso_403(string $clave): void
    {
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $this->crearSede($otra, 'AJ1');
        $this->crearSede($otra, 'AJ2');
        $ajeno = ($this->casos()[$clave][0])($otra);
        $tabla = (new (RegistroBorrado::de($clave)['modelo']))->getTable();

        // Administrador de esta empresa (con el permiso) sobre un registro ajeno: no existe
        $this->actingAs($this->admin)->getJson("/borrar/{$clave}/{$ajeno}")->assertNotFound();
        $this->deleteJson("/borrar/{$clave}/{$ajeno}", ['confirmacion' => 'x'])->assertNotFound();
        $this->deleteJson("/borrar/{$clave}/987654", ['confirmacion' => 'x'])->assertNotFound();
        $this->assertDatabaseHas($tabla, ['id' => $ajeno]);

        // Jefe de seguridad y Agente no tienen "borrar": 403 en su propia empresa
        $propio = ($this->casos()[$clave][0])();
        foreach (['Jefe de seguridad', 'Agente', 'Supervisor', 'Recursos Humanos'] as $rol) {
            $usuario = $this->crearUsuario($this->empresa, $rol, $rol === 'Recursos Humanos' ? null : $this->centro);
            $this->actingAs($usuario)->getJson("/borrar/{$clave}/{$propio}")->assertForbidden();
            $this->deleteJson("/borrar/{$clave}/{$propio}", ['confirmacion' => 'x'])->assertForbidden();
        }
        $this->assertDatabaseHas($tabla, ['id' => $propio]);
    }

    public function test_las_bitacoras_evidencias_empresas_y_usuarios_no_se_pueden_eliminar_definitivamente(): void
    {
        foreach (['accesos', 'novedades', 'lost_found', 'robo', 'recorridos_pc', 'prestamo_llaves', 'responsivas', 'pases_salida',
            'transporte', 'vouchers', 'firmas', 'auditoria', 'empresas', 'usuarios', 'accidentes'] as $clave) {
            $this->assertNull(RegistroBorrado::de($clave), "{$clave} no debe estar registrado");
            $this->actingAs($this->admin)->deleteJson("/borrar/{$clave}/1", ['confirmacion' => 'x'])->assertNotFound();
            $this->assertFalse(
                ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', $clave))->whereHas('accion', fn ($q) => $q->where('clave', 'borrar'))->exists(),
                "{$clave} no debe tener la acción «Eliminar definitivamente» en el catálogo",
            );
        }

        // La baja (eliminar) sigue siendo otra acción en los módulos registrados
        $this->assertSame('Eliminar definitivamente', DB::table('acciones')->where('clave', 'borrar')->value('nombre'));
        foreach (RegistroBorrado::modulos() as $modulo) {
            $acciones = ModuloAccion::with('accion')->whereHas('modulo', fn ($q) => $q->where('clave', $modulo))->get()->pluck('accion.clave');
            $this->assertContains('borrar', $acciones, $modulo);
            $this->assertContains('eliminar', $acciones, $modulo);
        }
    }

    public function test_solo_el_administrador_recibe_eliminar_definitivamente_con_alcance_de_empresa(): void
    {
        foreach ([null, $this->empresa->id] as $empresaId) {
            $roles = Rol::where('empresa_id', $empresaId)->get();
            $this->assertNotEmpty($roles);
            foreach ($roles as $rol) {
                $permisos = RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')->where('rol_id', $rol->id)->get()
                    ->filter(fn ($p) => $p->moduloAccion->accion->clave === 'borrar');
                if ($rol->nombre === 'Administrador') {
                    $this->assertEqualsCanonicalizing(RegistroBorrado::modulos(), $permisos->map(fn ($p) => $p->moduloAccion->modulo->clave)->values()->all());
                    $permisos->each(fn ($p) => $this->assertSame(Alcance::Empresa, $p->alcance));
                } else {
                    $this->assertCount(0, $permisos, "{$rol->nombre} no debe tener «Eliminar definitivamente»");
                }
            }
        }
    }

    public function test_la_migracion_agrega_la_accion_a_los_administradores_de_las_bases_existentes(): void
    {
        $migracion = require database_path('migrations/2026_10_10_000390_agregar_accion_eliminar_definitivamente.php');
        $migracion->down();
        $this->assertNull(DB::table('acciones')->where('clave', 'borrar')->value('id'));

        // Un Administrador personalizado sin "eliminar" en Vehículos no recibe "borrar" ahí
        $administrador = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Administrador')->sole();
        $vehiculosEliminar = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'vehiculos'))->whereHas('accion', fn ($q) => $q->where('clave', 'eliminar'))->sole();
        RolPermiso::where('rol_id', $administrador->id)->where('modulo_accion_id', $vehiculosEliminar->id)->delete();

        $migracion->up();
        $migracion->up(); // idempotente

        $borrar = fn (Rol $rol) => RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')->where('rol_id', $rol->id)->get()
            ->filter(fn ($p) => $p->moduloAccion->accion->clave === 'borrar');
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(RegistroBorrado::modulos(), ['vehiculos'])),
            $borrar($administrador)->map(fn ($p) => $p->moduloAccion->modulo->clave)->values()->all(),
        );
        $borrar($administrador)->each(fn ($p) => $this->assertSame(Alcance::Empresa, $p->alcance));
        $this->assertCount(count(RegistroBorrado::modulos()), $borrar(Rol::whereNull('empresa_id')->where('nombre', 'Administrador')->sole()));
        foreach (Rol::where('nombre', '!=', 'Administrador')->get() as $rol) {
            $this->assertCount(0, $borrar($rol), $rol->nombre);
        }
    }

    public function test_alcance_de_sede_solo_borra_en_sus_sedes_y_los_catalogos_de_empresa_exigen_alcance_de_empresa(): void
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Depurador de sede', 'nivel_jerarquia' => 80]);
        foreach (['llaves.ver', 'llaves.borrar', 'departamentos.ver', 'departamentos.borrar'] as $clave) {
            [$modulo, $accion] = explode('.', $clave);
            $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', $modulo))->whereHas('accion', fn ($q) => $q->where('clave', $accion))->sole();
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        }
        $usuario = $this->crearUsuario($this->empresa);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $this->playa->id]);
        app(Autorizador::class)->olvidar();

        $deCentro = $this->fila('llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'LL-CEN', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'zona']);
        $dePlaya = $this->fila('llaves', ['sede_id' => $this->playa->id, 'nomenclatura' => 'LL-PLA', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'zona']);
        $departamento = $this->fila('departamentos', ['nombre' => 'Sistemas']);

        $this->actingAs($usuario)->getJson("/borrar/llaves/{$deCentro}")->assertNotFound();
        $this->deleteJson("/borrar/llaves/{$deCentro}", ['confirmacion' => 'LL-CEN'])->assertNotFound();
        $this->deleteJson("/borrar/llaves/{$dePlaya}", ['confirmacion' => 'll-pla'])->assertOk();
        $this->assertDatabaseHas('llaves', ['id' => $deCentro]);
        $this->assertDatabaseMissing('llaves', ['id' => $dePlaya]);

        $this->getJson("/borrar/departamentos/{$departamento}")->assertForbidden()
            ->assertJsonPath('message', 'Es de toda la empresa: eliminarlo definitivamente requiere el permiso con alcance de empresa.');
        $this->assertDatabaseHas('departamentos', ['id' => $departamento]);
    }

    public function test_un_colaborador_con_sedes_adicionales_fuera_de_su_alcance_no_se_borra(): void
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Depurador RH', 'nivel_jerarquia' => 81]);
        $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'colaboradores'))->whereHas('accion', fn ($q) => $q->where('clave', 'borrar'))->sole();
        RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        $usuario = $this->crearUsuario($this->empresa);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $this->centro->id]);

        $id = $this->fila('colaboradores', ['sede_id' => $this->centro->id, 'nombre' => 'Eva', 'apellido_paterno' => 'Uc', 'num_empleado' => 'E-1']);
        DB::table('colaborador_sede')->insert(['colaborador_id' => $id, 'sede_id' => $this->playa->id]);

        $this->actingAs($usuario)->deleteJson("/borrar/colaboradores/{$id}", ['confirmacion' => 'E-1'])->assertForbidden();
        $this->assertDatabaseHas('colaboradores', ['id' => $id]);

        // El administrador sí: la sede adicional (tabla puente) se borra con él y queda en la copia
        $this->actingAs($this->admin)->deleteJson("/borrar/colaboradores/{$id}", ['confirmacion' => 'e-1'])->assertOk();
        $this->assertDatabaseMissing('colaborador_sede', ['colaborador_id' => $id]);
        $copia = Auditoria::where('evento', 'colaboradores.eliminado_definitivo')->sole()->antes;
        $this->assertSame([['colaborador_id' => $id, 'sede_id' => $this->playa->id]], $copia['relaciones']['colaborador_sede']);
    }

    public function test_las_tablas_hijas_propias_y_las_tablas_puente_se_borran_con_el_registro(): void
    {
        $llave = $this->fila('llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'LL-H', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'zona']);
        $this->fila('horarios_llave', ['llave_id' => $llave, 'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '15:00']);
        $zona = $this->fila('espacios', ['sede_id' => $this->centro->id, 'nivel' => 'edificio', 'nombre' => 'Torre A']);
        DB::table('espacio_llave')->insert(['llave_id' => $llave, 'espacio_id' => $zona]);

        // La zona tiene una llave que la abre: eso sí cuenta para la zona
        $this->actingAs($this->admin)->getJson("/borrar/espacios/{$zona}")->assertJsonPath('puede_eliminar', false)
            ->assertJsonPath('mensaje', 'Tiene 1 llave que la abre: no se puede eliminar; puedes darla de baja.');

        // Para la llave son suyos: horarios y lugares se borran con ella
        $this->getJson("/borrar/llaves/{$llave}")->assertJsonPath('puede_eliminar', true);
        $this->deleteJson("/borrar/llaves/{$llave}", ['confirmacion' => 'LL-H'])->assertOk();
        $this->assertDatabaseMissing('horarios_llave', ['llave_id' => $llave]);
        $this->assertDatabaseMissing('espacio_llave', ['llave_id' => $llave]);
        $this->assertDatabaseHas('espacios', ['id' => $zona]);
        $copia = Auditoria::where('evento', 'llaves.eliminado_definitivo')->sole()->antes;
        $this->assertSame('Mañana', $copia['relaciones']['horarios_llave'][0]['nombre']);
        $this->assertSame($zona, $copia['relaciones']['espacio_llave'][0]['espacio_id']);

        // Una ruta se lleva sus horarios y paradas; el paradero queda
        $ruta = $this->fila('rutas', ['sede_id' => $this->centro->id, 'sentido' => 'entrada', 'nombre' => 'Ruta Norte',
            'turno_id' => $this->fila('turnos', ['nombre' => 'Mixto', 'hora_inicio' => '07:00', 'hora_fin' => '15:00']),
            'proveedor_id' => $this->fila('proveedores', ['nombre' => 'Autobuses del Sur'])]);
        $horario = $this->fila('ruta_horarios', ['ruta_id' => $ruta, 'nombre' => 'L-V', 'dias' => '1,2,3,4,5', 'hora_inicio' => '06:00', 'hora_fin' => '07:00']);
        $paradero = $this->fila('paraderos', ['sede_id' => $this->centro->id, 'nombre' => 'Glorieta']);
        $this->fila('ruta_paradas', ['ruta_horario_id' => $horario, 'paradero_id' => $paradero]);

        $this->getJson("/borrar/paraderos/{$paradero}")->assertJsonPath('mensaje', 'Tiene 1 parada en una ruta: no se puede eliminar; puedes darlo de baja.');
        $this->getJson("/borrar/rutas/{$ruta}")->assertJsonPath('puede_eliminar', true);
        $this->deleteJson("/borrar/rutas/{$ruta}", ['confirmacion' => 'ruta norte'])->assertOk()
            ->assertJsonPath('redirect', route('rutas.sede', $this->centro->id));
        $this->assertDatabaseMissing('ruta_horarios', ['id' => $horario]);
        $this->assertDatabaseMissing('ruta_paradas', ['ruta_horario_id' => $horario]);
        $this->assertDatabaseHas('paraderos', ['id' => $paradero]);
        $this->getJson("/borrar/paraderos/{$paradero}")->assertJsonPath('puede_eliminar', true);

        // Un horario de la ruta usado en la bitácora de transporte impide borrar la ruta
        $ruta2 = $this->fila('rutas', ['sede_id' => $this->centro->id, 'sentido' => 'salida', 'nombre' => 'Ruta Sur',
            'turno_id' => $this->fila('turnos', ['nombre' => 'Noche', 'hora_inicio' => '22:00', 'hora_fin' => '06:00']),
            'proveedor_id' => $this->fila('proveedores', ['nombre' => 'Autobuses del Norte'])]);
        $horario2 = $this->fila('ruta_horarios', ['ruta_id' => $ruta2, 'nombre' => 'Diario', 'dias' => '1,2,3,4,5,6,7', 'hora_inicio' => '06:00', 'hora_fin' => '07:00']);
        $otraRuta = $this->fila('rutas', ['sede_id' => $this->centro->id, 'sentido' => 'salida', 'nombre' => 'Ruta Este',
            'turno_id' => $this->fila('turnos', ['nombre' => 'Tarde', 'hora_inicio' => '15:00', 'hora_fin' => '22:00']),
            'proveedor_id' => $this->fila('proveedores', ['nombre' => 'Autobuses del Este'])]);
        $this->fila('movimientos_transporte', ['sede_id' => $this->centro->id, 'ruta_id' => $otraRuta, 'ruta_horario_id' => $horario2,
            'tipo_movimiento' => 'entrada', 'estatus' => 'llegada', 'fecha' => now()->toDateString()]);
        $this->getJson("/borrar/rutas/{$ruta2}")->assertJsonPath('mensaje', 'Tiene 1 movimiento de transporte: no se puede eliminar; puedes darla de baja.');
    }

    public function test_varias_dependencias_se_listan_juntas_y_un_registro_dado_de_baja_lo_indica(): void
    {
        $llave = $this->fila('llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'LL-V', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'zona', 'activo' => false]);
        $colaborador = $this->fila('colaboradores', ['nombre' => 'Raúl', 'apellido_paterno' => 'Can']);
        foreach ([1, 2, 3] as $i) {
            $this->fila('prestamos_llaves', array_filter([
                'sede_id' => $this->centro->id, 'llave_id' => $llave, 'colaborador_id' => $colaborador, 'estado' => 'devuelta',
            ]) + $this->requeridos('prestamos_llaves', $i));
        }
        $this->fila('vouchers_reposicion', ['folio' => 'V-9', 'origen_tipo' => 'llave', 'origen_id' => $llave, 'origen_descripcion' => 'LL-V', 'motivo' => 'extraviado']);

        $this->actingAs($this->admin)->getJson("/borrar/llaves/{$llave}")
            ->assertJsonPath('mensaje', 'Tiene 3 préstamos de llave y 1 voucher: no se puede eliminar; puedes darla de baja.')
            ->assertJsonPath('baja.texto', 'Ya está dada de baja: así puede quedarse; su historial se conserva.');

        // Una sede activa con dependencias ofrece el formulario de baja (PATCH estado)
        $this->fila('zonas_estacionamiento', ['sede_id' => $this->playa->id, 'nombre' => 'Lobby']);
        $this->getJson("/borrar/sedes/{$this->playa->id}")
            ->assertJsonPath('baja.url', route('sedes.estado', $this->playa->id))
            ->assertJsonPath('baja.metodo', 'PATCH')
            ->assertJsonPath('baja.campos', ['activo' => 0]);
    }

    /** Columnas obligatorias de una tabla operativa con valores de prueba. */
    private function requeridos(string $tabla, int $i): array
    {
        $valores = [];
        foreach (Schema::getColumns($tabla) as $c) {
            if ($c['nullable'] || $c['default'] !== null || $c['auto_increment'] || in_array($c['name'], ['id', 'empresa_id', 'sede_id', 'llave_id', 'colaborador_id', 'created_at', 'updated_at'], true)) {
                continue;
            }
            $tipo = strtolower($c['type_name']);
            $valores[$c['name']] = match (true) {
                str_contains($tipo, 'int') => $i,
                str_contains($tipo, 'date') || str_contains($tipo, 'time') => now(),
                default => 'P-'.$i,
            };
        }

        return $valores;
    }

    public function test_la_unica_sede_de_la_empresa_no_se_elimina(): void
    {
        $otra = $this->crearEmpresa('Hotel Chico');
        $unica = $this->crearSede($otra, 'UNI');
        $admin = $this->crearUsuario($otra, 'Administrador');

        $this->actingAs($admin)->getJson("/borrar/sedes/{$unica->id}")->assertJsonPath('puede_eliminar', false)
            ->assertJsonPath('mensaje', 'Es la única sede de la empresa: no se puede eliminar; puedes darla de baja.');
        $this->deleteJson("/borrar/sedes/{$unica->id}", ['confirmacion' => 'UNI'])->assertStatus(409);
        $this->assertDatabaseHas('sedes', ['id' => $unica->id, 'deleted_at' => null]);
    }

    public function test_roles_solo_de_la_empresa_sin_usuarios_y_de_nivel_inferior(): void
    {
        $plantilla = Rol::whereNull('empresa_id')->where('nombre', 'Agente')->sole();
        $this->actingAs($this->admin)->getJson("/borrar/roles/{$plantilla->id}")->assertNotFound();
        $this->deleteJson("/borrar/roles/{$plantilla->id}", ['confirmacion' => 'Agente'])->assertNotFound();

        // Su propio rol (nivel igual) no lo administra
        $propio = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Administrador')->sole();
        $this->getJson("/borrar/roles/{$propio->id}")->assertForbidden();

        // Un rol de la empresa sin usuarios sí; sus permisos se van con él y se olvidan
        $temporal = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Temporal', 'nivel_jerarquia' => 90]);
        $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'llaves'))->whereHas('accion', fn ($q) => $q->where('clave', 'ver'))->sole();
        RolPermiso::create(['rol_id' => $temporal->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        $this->deleteJson("/borrar/roles/{$temporal->id}", ['confirmacion' => 'temporal'])->assertOk();
        $this->assertDatabaseMissing('rol_permisos', ['rol_id' => $temporal->id]);
        $this->assertSame($ma->id, Auditoria::where('evento', 'roles.eliminado_definitivo')->sole()->antes['relaciones']['rol_permisos'][0]['modulo_accion_id']);
    }

    public function test_super_administrador_borra_en_la_empresa_de_trabajo_elegida(): void
    {
        $superadmin = $this->crearSuperadmin();
        $id = $this->fila('puestos', ['nombre' => 'Puesto por error']);

        // Sin empresa de trabajo no hay registros que borrar
        $this->actingAs($superadmin)->getJson("/borrar/puestos/{$id}")->assertNotFound();

        $this->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->deleteJson("/borrar/puestos/{$id}", ['confirmacion' => 'Puesto por error'])->assertOk();
        $this->assertDatabaseMissing('puestos', ['id' => $id]);
        $this->assertSame($this->empresa->id, Auditoria::where('evento', 'puestos.eliminado_definitivo')->sole()->empresa_id);
    }

    public function test_sin_javascript_regresa_con_el_aviso_en_la_pantalla(): void
    {
        $id = $this->fila('departamentos', ['nombre' => 'Mantenimiento']);
        $this->fila('colaboradores', ['nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'departamento_id' => $id]);

        $this->actingAs($this->admin)->from('/departamentos')->delete("/borrar/departamentos/{$id}", ['confirmacion' => 'Mantenimiento'])
            ->assertRedirect('/departamentos')->assertSessionHas('error', 'Tiene 1 colaborador: no se puede eliminar; puedes darlo de baja.');

        $libre = $this->fila('departamentos', ['nombre' => 'Compras']);
        $this->delete("/borrar/departamentos/{$libre}", ['confirmacion' => 'Compras'])
            ->assertRedirect(route('departamentos.index'))->assertSessionHas('ok', 'El departamento «Compras» se eliminó definitivamente.');
    }

    public function test_el_boton_solo_aparece_a_quien_puede_borrar(): void
    {
        $this->fila('tipos_gafete', ['nombre' => 'VIP por error']);
        $this->fila('proveedores', ['nombre' => 'Limpieza Total']);
        $paginas = ['/sedes', '/espacios', '/departamentos', '/puestos', '/turnos', '/colaboradores', '/roles', '/proveedores',
            '/personas', '/vehiculos', '/llaves', '/gafetes', '/equipos', '/equipos-pc', '/estacionamientos', '/rutas/sede/'.$this->centro->id];
        foreach ($paginas as $pagina) {
            $this->actingAs($this->admin)->get($pagina)->assertOk()
                ->assertSee('data-borrar-definitivo', false)
                ->assertSee('id="dialogoBorrarDefinitivo"', false)
                ->assertSee('Eliminar definitivamente');
        }
        // Tipos de gafete sin usar: lista plegada con su botón
        $this->get('/gafetes')->assertSee('Eliminar tipos de gafete sin usar')->assertSee('VIP por error');

        // El Agente consulta los padrones pero no ve el botón ni el diálogo
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        foreach (['/llaves', '/gafetes', '/vehiculos', '/personas', '/estacionamientos'] as $pagina) {
            $this->actingAs($agente)->get($pagina)->assertOk()
                ->assertDontSee('data-borrar-definitivo', false)
                ->assertDontSee('dialogoBorrarDefinitivo', false);
        }
    }
}
