<?php

namespace Tests\Feature\Nucleo;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Llave;
use App\Models\Modulo;
use App\Models\MovimientoTransporte;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\ProcedimientoCategoria;
use App\Models\Proveedor;
use App\Models\Responsiva;
use App\Models\Rubro;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\Suscripcion;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\Accesos\MotivosVisita;
use App\Services\Permisos\Autorizador;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\RegistroTerminos;
use App\Support\Tenancy\Tenant;
use Database\Seeders\CatalogoComercialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Etapa 1 del plan de correcciones: «que el plan vendido funcione». Con los
 * clientes de ejemplo Plaza Norte (plan Esencial) y Hotel Laguna (plan
 * Profesional), su administrador da de alta lo indispensable desde las
 * pantallas y la caseta guarda: proveedor, visita, entrada de colaborador,
 * préstamo de llave, responsiva, pase de salida, ruta, vale de taxi y (en
 * Profesional) el «Leí y entendí» de un procedimiento. Además: Corporativo y
 * las empresas sin suscripción no pierden nada, y los cuatro giros ofrecen
 * sus motivos de visita.
 */
class PlanesOperanTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const CLAVE = 'Prueba123!';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => self::CLAVE])->assertSuccessful();
    }

    private function cuenta(string $usuario): User
    {
        $u = User::where('username', $usuario)->firstOrFail();
        $u->forceFill(['debe_cambiar_contrasena' => false])->save();

        return $u;
    }

    private function enEmpresa(int $empresaId, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresaId, $fn);
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Lo que pide la Etapa 1, hecho por el administrador del cliente desde las pantallas.
     *
     * @return array{empresa: int, sede: int, colaborador: int}
     */
    private function operar(string $usuario): array
    {
        $admin = $this->cuenta($usuario);
        $empresaId = (int) $admin->empresa_id;
        $sede = $this->enEmpresa($empresaId, fn () => Sede::where('activo', true)->orderBy('id')->firstOrFail());
        $ultimo = fn (string $modelo) => $this->enEmpresa($empresaId, fn () => $modelo::query()->orderByDesc('id')->firstOrFail());
        $sinErrores = fn ($r) => $r->assertSessionHasNoErrors()->assertSessionMissing('error');

        // Estructura → Organización: departamento, turno y colaborador (antes: 403 en Esencial y Profesional)
        $this->actingAs($admin)->get('/departamentos')->assertOk();
        $sinErrores($this->actingAs($admin)->post('/departamentos', ['nombre' => 'Mantenimiento', 'todas_las_sedes' => '1']));
        $departamento = $ultimo(Departamento::class);
        $sinErrores($this->actingAs($admin)->post('/turnos', ['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => '1']));
        $sinErrores($this->actingAs($admin)->post('/colaboradores', ['num_empleado' => 'E-1', 'nombre' => 'Laura', 'apellido_paterno' => 'Gómez',
            'sede_id' => $sede->id, 'departamento_id' => $departamento->id]));
        $colaborador = $ultimo(Colaborador::class);
        $this->assertSame('Laura', $colaborador->nombre);
        $sinErrores($this->actingAs($admin)->post('/proveedores', ['nombre' => 'Transportes del Caribe', 'categoria' => 'proveedor', 'todas_las_sedes' => '1']));
        $proveedor = $ultimo(Proveedor::class);

        // Caseta: proveedor, visita (a colaborador y general) y entrada de colaborador
        $acceso = fn (array $d) => $sinErrores($this->actingAs($admin)->post('/accesos', $d + ['sede_id' => $sede->id, '_dialogo' => 'ingreso']));
        $acceso(['tipo' => 'proveedor', 'nombre' => 'Mario Proveedor', 'empresa_procedencia' => 'Fumigaciones del Sur',
            'host_colaborador_id' => $colaborador->id, 'departamento_id' => $departamento->id]);
        $this->assertSame('proveedor', $ultimo(Acceso::class)->tipo);
        $acceso(['tipo' => 'visitante', 'nombre' => 'Rosa Visita', 'motivo_visita' => 'colaborador', 'visita_colaborador_id' => $colaborador->id]);
        $this->assertSame('colaborador', $ultimo(Acceso::class)->motivo_visita);
        $acceso(['tipo' => 'visitante', 'nombre' => 'Pedro Mensajero', 'motivo_visita' => 'general', 'persona_visita' => 'Entrega de documentos']);
        $this->assertSame('ENTREGA DE DOCUMENTOS', $ultimo(Acceso::class)->persona_visita);
        $acceso(['tipo' => 'colaborador', 'colaborador_id' => $colaborador->id]);
        $this->assertSame((int) $colaborador->id, (int) $ultimo(Acceso::class)->colaborador_id);

        // Préstamo de llave y responsiva
        $sinErrores($this->actingAs($admin)->post('/llaves', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'nomenclatura' => 'LL-MTO-01',
            'descripcion' => 'Cuarto de máquinas', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        $llave = $ultimo(Llave::class);
        $sinErrores($this->actingAs($admin)->post('/prestamo-llaves', ['_dialogo' => 'prestar', 'sede_id' => $sede->id, 'llave_id' => $llave->id,
            'colaborador_id' => $colaborador->id, 'tipo_garantia' => 'ine', 'folio_garantia' => 'INE-1']));
        $this->assertSame((int) $llave->id, (int) $ultimo(PrestamoLlave::class)->llave_id);
        $tipo = $this->enEmpresa($empresaId, fn () => TipoEquipo::firstOrCreate(['nombre' => 'Radio de Comunicación']));
        $sinErrores($this->actingAs($admin)->post('/equipos', ['sede_id' => $sede->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'Motorola', 'modelo' => 'DEP 450', 'numero_serie' => 'RAD-'.$empresaId]));
        $equipo = $ultimo(Equipo::class);
        $sinErrores($this->actingAs($admin)->post('/responsivas', ['_dialogo' => 'nuevo-resguardo', 'sede_id' => $sede->id, 'colaborador_id' => $colaborador->id,
            'equipos' => [$equipo->id], 'modalidades' => ['prestado'], 'firma' => $this->firma()]));
        $this->assertSame((int) $colaborador->id, (int) $ultimo(Responsiva::class)->colaborador_id);

        // Pase de salida
        $sinErrores($this->actingAs($admin)->post('/pases-salida', ['sede_id' => $sede->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador->id,
            'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor->id,
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'marca' => 'Lenovo', 'serie' => 'ABC123']]]));
        $this->assertSame((int) $colaborador->id, (int) $ultimo(PaseSalida::class)->colaborador_id);

        // Ruta sin turno (C-06) y vale de taxi
        $todos = ['LU', 'MA', 'MI', 'JU', 'VI', 'SA', 'DO'];
        $sinErrores($this->actingAs($admin)->post('/rutas', ['sede_id' => $sede->id, 'sentido' => 'llegada', 'nombre' => 'Ruta 1 - Centro', 'proveedor_id' => $proveedor->id,
            'horarios' => [['nombre' => 'Diario', 'dias' => $todos, 'hora_inicio' => '06:00', 'hora_fin' => '06:45', 'paraderos' => [['nombre' => 'Crucero', 'hora' => '06:00']]]]]));
        $ruta = $ultimo(Ruta::class);
        $this->assertNull($ruta->turno_id);
        $horario = $ruta->horarios()->firstOrFail();
        $sinErrores($this->actingAs($admin)->post('/transporte', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego',
            'ruta_horario_id' => $horario->id, 'firma_guardia' => $this->firma(), 'observaciones' => 'Se descompuso la unidad.',
            'taxis' => [
                ['placas' => 'TX-230', 'chofer' => 'Alberto Canché', 'monto' => '180', 'destino' => 'Crucero', 'tipo' => 'sedan', 'pasajeros' => [$colaborador->id], 'firma' => $this->firma()],
                // Sin registrar a los pasajeros: solo cuántos van (C-06)
                ['placas' => 'TX-231', 'chofer' => 'Pedro Pech', 'monto' => '150', 'destino' => 'Crucero', 'tipo' => 'sedan', 'cantidad_pasajeros' => '3', 'firma' => $this->firma()],
            ]]));
        $vales = $this->enEmpresa($empresaId, fn () => MovimientoTransporte::where('ruta_id', $ruta->id)->orderBy('id')->get());
        $this->assertCount(2, $vales);
        $this->assertSame([1, 3], $vales->pluck('cantidad_pax')->map(fn ($v) => (int) $v)->all());

        return ['empresa' => $empresaId, 'sede' => (int) $sede->id, 'colaborador' => (int) $colaborador->id];
    }

    public function test_plaza_norte_en_esencial_opera_la_caseta_completa(): void
    {
        $s = Suscripcion::whereHas('empresa', fn ($q) => $q->where('nombre_comercial', 'Plaza Norte'))->firstOrFail();
        $this->assertSame('esencial', $s->plan->slug);
        $this->operar('plaza.demo');

        // Lo que no trae Esencial: «No viene en tu plan» (no culpa a la Matriz de permisos)
        $admin = $this->cuenta('plaza.demo');
        $this->actingAs($admin)->get('/procedimientos')->assertForbidden()->assertSee('Esto no viene en tu plan (Esencial)')
            ->assertSee('Ver mi suscripción')->assertDontSee('Matriz de permisos');
        $this->actingAs($admin)->get('/candidatos')->assertForbidden()->assertSee('Esto no viene en tu plan');
        // El rol, en cambio, sí es cosa de la Matriz
        $agente = $this->crearUsuario(Empresa::findOrFail($s->empresa_id), 'Agente');
        $this->actingAs($agente)->get('/usuarios')->assertForbidden()->assertSee('No tienes permiso para ver esto')->assertSee('Matriz de permisos');
        // La caseta no propone «Recursos Humanos» sin RR. HH. y nada viene marcado
        $motivos = app(MotivosVisita::class)->para((int) $s->empresa_id);
        $this->assertArrayNotHasKey('rh', $motivos);
        // C-09: «Visita a colaborador» y «Visita a departamento» son un solo motivo («Visita a persona o área»)
        $this->assertSame(['general', 'huesped', 'colaborador'], array_keys($motivos));
        $this->assertNull(app(MotivosVisita::class)->porOmision((int) $s->empresa_id));
        $this->actingAs($admin)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Sin Motivo', 'sede_id' => Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->value('id'), '_dialogo' => 'ingreso'])
            ->assertSessionHasErrors(['motivo_visita' => 'Elige el motivo de la visita.']);
        $this->actingAs($admin)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Busca Empleo', 'motivo_visita' => 'rh', 'viene_a' => 'busca_empleo',
            'sede_id' => Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->value('id'), '_dialogo' => 'ingreso'])->assertSessionHasErrors('motivo_visita');
        $this->assertSame(0, DB::table('candidatos')->where('empresa_id', $s->empresa_id)->count());
        // Menú: Estructura → Organización
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Departamentos')->assertSee('Turnos');
    }

    public function test_hotel_laguna_en_profesional_opera_y_firma_procedimientos_sin_ficha_de_colaborador(): void
    {
        $s = Suscripcion::whereHas('empresa', fn ($q) => $q->where('nombre_comercial', 'Hotel Laguna'))->firstOrFail();
        $this->assertSame('profesional', $s->plan->slug);
        $r = $this->operar('laguna.demo');
        $empresa = Empresa::findOrFail($r['empresa']);
        $admin = $this->cuenta('laguna.demo');

        // Procedimiento publicado por el administrador y aprobado por el Director
        $director = $this->crearUsuario($empresa, 'Director');
        $director->forceFill(['debe_cambiar_contrasena' => false])->save();
        $categoria = $this->enEmpresa($empresa->id, function () {
            app(AdministradorProcedimientos::class)->categorias();

            return ProcedimientoCategoria::orderBy('id')->value('id');
        });
        $this->actingAs($admin)->post('/procedimientos', ['clave' => 'PRO-SEG-001', 'titulo' => 'Rondín nocturno', 'categoria_id' => $categoria,
            'objetivo' => 'Revisar la sede de noche.', 'aplica' => 'todas', 'enviar' => '1', 'pasos' => [['texto' => 'Recorrer los accesos', 'critico' => '0']]])
            ->assertSessionHasNoErrors();
        $p = $this->enEmpresa($empresa->id, fn () => Procedimiento::with('trabajo')->orderByDesc('id')->firstOrFail());
        $this->actingAs($director)->post("/procedimientos/{$p->id}/aprobar", ['version_id' => $p->trabajo->id, 'firma_modo' => 'nueva', 'firma' => $this->firma()])
            ->assertSessionHasNoErrors();
        $p = $this->enEmpresa($empresa->id, fn () => Procedimiento::with('vigente')->findOrFail($p->id));
        $this->assertSame(Procedimiento::PUBLICADO, $p->estado);

        // Un agente SIN ficha de colaborador también debe firmarlo (C-07)
        $agente = $this->crearUsuario($empresa, 'Agente', Sede::withoutGlobalScopes()->findOrFail($r['sede']));
        $agente->forceFill(['debe_cambiar_contrasena' => false])->save();
        $this->assertNull($agente->colaborador_id);
        $this->assertCount(1, $this->enEmpresa($empresa->id, fn () => app(AdministradorProcedimientos::class)->pendientesDe($agente)));
        $this->actingAs($agente)->post("/procedimientos/{$p->id}/acuse", ['version_id' => $p->vigente->id, 'entendido' => '1', 'firma_modo' => 'nueva', 'firma' => $this->firma()])
            ->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('procedimiento_acuses')->where('user_id', $agente->id)->where('version_id', $p->vigente->id)->exists());
        $this->assertCount(0, $this->enEmpresa($empresa->id, fn () => app(AdministradorProcedimientos::class)->pendientesDe($agente)));
        // La pestaña Acuses avisa cuántas cuentas no tienen ficha
        $this->actingAs($admin)->get("/procedimientos/{$p->id}?pestana=acuses")->assertOk()->assertSee('no tienen ficha de colaborador');

        // Equipos de Protección Civil va con sus recorridos (Incidentes)
        $this->assertTrue(app(Autorizador::class)->moduloActivo($empresa->id, 'equipos_pc'));
        $this->assertTrue(app(Autorizador::class)->moduloActivo($empresa->id, 'recorridos_pc'));
    }

    public function test_corporativo_y_las_empresas_sin_suscripcion_no_pierden_nada(): void
    {
        // Hotel Demo (sin suscripción): todos los módulos activos y RR. HH. completo
        $hotel = Empresa::where('nombre_comercial', 'Hotel Demo')->firstOrFail();
        $catalogo = Modulo::where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->pluck('clave');
        foreach ($catalogo as $clave) {
            $this->assertTrue(app(Autorizador::class)->moduloActivo($hotel->id, $clave), "Hotel Demo perdió {$clave}");
        }
        $rh = $this->cuenta('rh.demo');
        foreach (['colaboradores.datos_personales', 'colaboradores.aprobar', 'candidatos.ver', 'departamentos.crear', 'turnos.crear', 'autorizaciones.configurar'] as $permiso) {
            $this->assertTrue($rh->can($permiso), "rh.demo perdió {$permiso}");
        }
        $this->actingAs($rh)->get('/colaboradores')->assertOk();
        $this->actingAs($this->cuenta('admin.demo'))->get('/candidatos')->assertOk();

        // Plan Corporativo: todo lo que traía antes de la Etapa 1 (todos los productos de entonces)
        $antes = ['empresas', 'sedes', 'espacios', 'usuarios', 'roles', 'permisos', 'configuracion', 'auditoria', 'accesos', 'prestamo_llaves', 'vouchers',
            'pases_salida', 'transporte', 'proveedores', 'visitantes', 'vehiculos', 'llaves', 'gafetes', 'equipos', 'equipos_pc', 'estacionamientos', 'rutas',
            'etiquetas_qr', 'responsivas', 'novedades', 'lost_found', 'robo', 'recorridos_pc', 'procedimientos', 'colaboradores', 'departamentos', 'puestos',
            'turnos', 'recepcion_rh', 'candidatos', 'autorizaciones', 'vacantes', 'dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo'];
        $corporativo = collect(CatalogoComercialSeeder::PLANES['corporativo'])->flatMap(fn ($p) => CatalogoComercialSeeder::PRODUCTOS[$p][3])->all();
        $this->assertSame([], array_values(array_diff($antes, $corporativo)), 'El plan Corporativo perdió módulos');
    }

    public function test_los_cuatro_giros_ofrecen_sus_motivos_y_registran_su_visita(): void
    {
        $motivos = app(MotivosVisita::class);
        $hotel = Empresa::where('nombre_comercial', 'Hotel Demo')->firstOrFail();
        // C-09: un solo motivo para persona o área
        $this->assertSame(['general', 'huesped', 'colaborador', 'rh'], array_keys($motivos->para($hotel->id)));
        $corp = Empresa::whereHas('rubro', fn ($q) => $q->where('clave', 'corporativo'))->firstOrFail();
        $this->assertSame(['colaborador', 'general', 'rh'], array_keys($motivos->para($corp->id)));
        $this->assertSame('Visita a persona o área', $motivos->para($corp->id)['colaborador']);
        $condo = Empresa::whereHas('rubro', fn ($q) => $q->where('clave', 'condominio'))->firstOrFail();
        $this->assertSame(['colaborador'], array_keys($motivos->para($condo->id)));
        $this->assertSame('Visita', $motivos->para($condo->id)['colaborador']);
        $this->assertSame('colaborador', $motivos->porOmision($condo->id), 'Con un solo motivo, viene marcado');
        $fracc = $this->crearEmpresa('Fraccionamiento Las Palmas');
        $fracc->forceFill(['rubro_id' => Rubro::where('clave', 'fraccionamiento')->value('id')])->save();
        $this->assertSame(['colaborador'], array_keys($motivos->para($fracc->id)));

        // Hotel: «Visita a huésped» con su habitación; se puede apagar
        $admin = $this->cuenta('admin.demo');
        $sede = $this->enEmpresa($hotel->id, fn () => Sede::orderBy('id')->firstOrFail());
        $this->actingAs($admin)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Amigo del Huésped', 'motivo_visita' => 'huesped', 'sede_id' => $sede->id, '_dialogo' => 'ingreso'])
            ->assertSessionHasErrors(['persona_visita' => 'Escribe a qué huésped visita.']);
        $this->actingAs($admin)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Amigo del Huésped', 'motivo_visita' => 'huesped', 'persona_visita' => 'Juan Pérez',
            'habitacion' => '101', 'sede_id' => $sede->id, '_dialogo' => 'ingreso'])->assertSessionHasNoErrors();
        $a = $this->enEmpresa($hotel->id, fn () => Acceso::orderByDesc('id')->firstOrFail());
        $this->assertSame(['huesped', 'JUAN PÉREZ', '101'], [$a->motivo_visita, $a->persona_visita, $a->habitacion]);
        $this->actingAs($admin)->get('/accesos')->assertOk()->assertSee('Visita a huésped')->assertSee('Visita general');

        // Configuración: apagar «Visita a huésped» y marcar un motivo por omisión
        $this->actingAs($admin)->put('/configuracion/caseta', ['_tarjeta' => 'motivos', 'motivo_visita_omision' => 'general', 'visita_a_huesped' => 'no'])
            ->assertSessionHasNoErrors();
        app(RegistroTerminos::class)->olvidar();
        $this->assertArrayNotHasKey('huesped', $motivos->para($hotel->id));
        $this->assertSame('general', $motivos->porOmision($hotel->id));
        $this->actingAs($admin)->get('/configuracion')->assertOk()->assertSee('Motivo de la visita');

        // Condominio: la visita a un residente se sigue registrando
        $condoAdmin = $this->cuenta('condo.admin.demo');
        $condoSede = $this->enEmpresa($condo->id, fn () => Sede::orderBy('id')->firstOrFail());
        $residente = $this->enEmpresa($condo->id, fn () => Colaborador::where('activo', true)->orderBy('id')->firstOrFail());
        $this->actingAs($condoAdmin)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Visita Condominio', 'motivo_visita' => 'colaborador',
            'visita_colaborador_id' => $residente->id, 'sede_id' => $condoSede->id, '_dialogo' => 'ingreso'])->assertSessionHasNoErrors();
    }
}
