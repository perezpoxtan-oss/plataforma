<?php

namespace App\Console\Commands\Concerns;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Novedad;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaTipoUso;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\Reservas\AdministradorReservas;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Giros (docs/tecnico/giros.md): además de Hotel Demo, una empresa demo de
 * «Corporativo / industria», una de «Condominio» y (Etapa 5) una de
 * «Fraccionamiento» (prefijo `frac.`), cada una con sus sedes,
 * su árbol de espacios con su terminología, usuarios propios (prefijos
 * `corp.` y `condo.`), accesos, reservas de áreas comunes o salas e
 * incidentes. Cada parte se crea solo la primera vez y nunca toca Hotel Demo.
 */
trait DemosPorGiro
{
    public const EMPRESA_CORPORATIVO = 'Corporativo Demo';

    public const EMPRESA_CONDOMINIO = 'Condominio Demo';

    public const EMPRESA_FRACCIONAMIENTO = 'Fraccionamiento Demo';

    /** usuario => [nombre, rol, sede (null = todas)] */
    public const USUARIOS_CORPORATIVO = [
        'corp.admin.demo' => ['Carla Administradora Corporativo', 'Administrador', null],
        'corp.jefe.demo' => ['Jorge Jefe de Seguridad Corporativo', 'Jefe de seguridad', null],
        'corp.agente.demo' => ['Carmen Agente Corporativo', 'Agente', 'TOR'],
    ];

    public const USUARIOS_CONDOMINIO = [
        'condo.admin.demo' => ['Olga Administradora Condominio', 'Administrador', null],
        'condo.jefe.demo' => ['Néstor Jefe de Seguridad Condominio', 'Jefe de seguridad', null],
        'condo.agente.demo' => ['Óscar Agente Condominio', 'Agente', 'NOR'],
    ];

    /** Etapa 5 (C-10): demo de fraccionamiento (casas, residentes y su personal). */
    public const USUARIOS_FRACCIONAMIENTO = [
        'frac.admin.demo' => ['Fernanda Administradora Fraccionamiento', 'Administrador', null],
        'frac.jefe.demo' => ['Felipe Jefe de Seguridad Fraccionamiento', 'Jefe de seguridad', null],
        'frac.agente.demo' => ['Fabián Agente Fraccionamiento', 'Agente', 'LOM'],
    ];

    /**
     * Crea (o completa) los demos de corporativo y condominio. Las cuentas
     * nuevas reciben la misma contraseña que las de Hotel Demo.
     */
    private function demosPorGiro(ProvisionarEmpresa $provisionar, Tenant $tenant, ?string $contrasena, ?string $hashExistente, array &$fallas): void
    {
        $hash = $contrasena === null ? ($hashExistente ?? User::where('username', 'admin.demo')->value('password')) : null;

        $giros = [
            'corporativo' => [self::EMPRESA_CORPORATIVO, 'Corporativo Demo S.A. de C.V.', 'CDE200101AA1', 'America/Mexico_City', [
                'TOR' => ['Corporativo Demo Torre Reforma', 'Paseo de la Reforma 250', 'Juárez', '06600', 'Ciudad de México', 'Ciudad de México'],
                'PLA' => ['Corporativo Demo Planta Querétaro', 'Av. Industrias 120', 'Parque Industrial', '76120', 'Querétaro', 'Querétaro'],
            ], self::USUARIOS_CORPORATIVO, 'corporativoDemo'],
            'condominio' => [self::EMPRESA_CONDOMINIO, 'Condominio Demo A.C.', 'CDO200101AA1', 'America/Mexico_City', [
                'NOR' => ['Residencial Demo Norte', 'Av. Patria 1500', 'Jardines', '45110', 'Zapopan', 'Jalisco'],
                'SUR' => ['Residencial Demo Sur', 'Av. López Mateos 3200', 'Las Águilas', '45080', 'Zapopan', 'Jalisco'],
            ], self::USUARIOS_CONDOMINIO, 'condominioDemo'],
            'fraccionamiento' => [self::EMPRESA_FRACCIONAMIENTO, 'Fraccionamiento Demo A.C.', 'FDE200101AA1', 'America/Mexico_City', [
                'LOM' => ['Fraccionamiento Demo Las Lomas', 'Av. de las Lomas 400', 'Las Lomas', '76090', 'Querétaro', 'Querétaro'],
            ], self::USUARIOS_FRACCIONAMIENTO, 'fraccionamientoDemo'],
        ];

        foreach ($giros as $rubro => [$nombre, $razon, $rfc, $zona, $datosSedes, $usuarios, $llenar]) {
            // Correos de otro dominio que los de Hotel Demo (usuario@<giro>.demo.local): no se confunden con sus cuentas
            try {
                $empresa = Empresa::where('nombre_comercial', $nombre)->first()
                    ?? $provisionar->crear(Rubro::where('clave', $rubro)->firstOrFail(), [
                        'nombre_comercial' => $nombre, 'razon_social' => $razon, 'rfc' => $rfc, 'zona_horaria' => $zona,
                    ]);
                $sedes = $tenant->conEmpresa($empresa->id, fn () => collect($datosSedes)->map(fn ($d, $codigo) => Sede::updateOrCreate(['codigo' => $codigo], [
                    'nombre' => $d[0], 'direccion' => $d[1], 'colonia' => $d[2], 'codigo_postal' => $d[3], 'ciudad' => $d[4], 'entidad' => $d[5],
                ])));
                $this->usuariosDeGiro($provisionar, $empresa, $sedes, $usuarios, $contrasena, $hash, $rubro);
                $tenant->conEmpresa($empresa->id, fn () => $this->{$llenar}($empresa, $sedes));
            } catch (\Throwable $e) {
                $fallas[] = 'demo '.$rubro;
                $this->warn("No se pudo completar el demo de {$rubro}: ".$e->getMessage());
            } finally {
                auth()->forgetGuards();
                app(Autorizador::class)->olvidar();
            }
        }
    }

    /**
     * @param  Collection<string, Sede>  $sedes
     * @param  array<string, array{0: string, 1: string, 2: ?string}>  $usuarios
     */
    private function usuariosDeGiro(ProvisionarEmpresa $provisionar, Empresa $empresa, $sedes, array $usuarios, ?string $contrasena, ?string $hash, string $rubro): void
    {
        foreach ($usuarios as $usuario => [$nombre, $rol, $sede]) {
            $cuenta = User::firstOrNew(['username' => $usuario]);
            if (! $cuenta->exists) {
                $cuenta->fill([
                    'empresa_id' => $empresa->id, 'name' => $nombre, 'email' => $usuario.'@'.$rubro.'.demo.local',
                    'password' => $contrasena ?? Str::random(40), 'activo' => true,
                ])->save();
                if ($contrasena === null && $hash !== null) {
                    DB::table('users')->where('id', $cuenta->id)->update(['password' => $hash]);
                }
                $this->line("Usuario demo creado: {$usuario}");
            }
            $rolId = Rol::where('empresa_id', $empresa->id)->where('nombre', $rol)->value('id');
            if ($rolId === null && ($plantilla = Rol::plantillas()->with('permisos')->where('nombre', $rol)->first()) !== null) {
                $rolId = $provisionar->copiarPlantillaSiFalta($plantilla, $empresa->id)?->id;
            }
            if ($rolId !== null) {
                UsuarioRol::firstOrCreate(['user_id' => $cuenta->id, 'rol_id' => $rolId], ['sede_id' => $sede === null ? null : $sedes[$sede]->id]);
            }
        }
    }

    // ------------------------------------------------------------ Corporativo

    /**
     * @param  Collection<string, Sede>  $sedes
     */
    private function corporativoDemo(Empresa $empresa, $sedes): void
    {
        $admin = User::where('username', 'corp.admin.demo')->firstOrFail();
        $agente = User::where('username', 'corp.agente.demo')->firstOrFail();
        $torre = $sedes['TOR'];

        $this->comoUsuarioDemo($admin, function () use ($admin, $torre, $sedes) {
            if (! Espacio::where('nivel', Espacio::EDIFICIO)->exists()) {
                $espacios = app(AdministradorEspacios::class);
                $edificio = $espacios->crear($admin, $torre, null, Espacio::EDIFICIO, ['nombre' => 'Torre Reforma', 'codigo' => 'TR'], false);
                $piso = $espacios->crear($admin, $torre, $edificio, Espacio::AREA, ['nombre' => 'Piso 10'], false);
                $espacios->crearLote($admin, $piso, ['1001', '1002', '1003', '1004'], null, $this->tipoEspacioDemo('Oficina'));
                $espacios->crear($admin, $torre, $piso, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala de juntas Roble'], false);
                $nave = $espacios->crear($admin, $sedes['PLA'], null, Espacio::EDIFICIO, ['nombre' => 'Nave 1'], false);
                $planta = $espacios->crear($admin, $sedes['PLA'], $nave, Espacio::AREA, ['nombre' => 'Planta baja'], false);
                $espacios->crearLote($admin, $planta, ['Oficina de producción', 'Oficina de calidad'], null, $this->tipoEspacioDemo('Oficina'));
            }
            if (! Departamento::exists()) {
                foreach (['Dirección', 'Recursos Humanos', 'Sistemas', 'Recepción', 'Seguridad', 'Producción'] as $d) {
                    Departamento::create(['nombre' => $d]);
                }
            }
        });

        if (! Colaborador::exists()) {
            $depto = fn (string $n) => Departamento::where('nombre', $n)->value('id');
            foreach ([
                'C-101' => ['Ricardo', 'Salinas', 'Mora', 'TOR', 'Dirección'],
                'C-102' => ['Fernanda', 'Ibarra', 'Luna', 'TOR', 'Recursos Humanos'],
                'C-103' => ['Hugo', 'Ventura', 'Solís', 'TOR', 'Sistemas'],
                'C-104' => ['Carmen', 'Agente', null, 'TOR', 'Seguridad'],
                'C-105' => ['Teresa', 'Navarro', 'Pineda', 'PLA', 'Producción'],
            ] as $num => [$nombre, $paterno, $materno, $sede, $dep]) {
                $c = Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                    'sede_id' => $sedes[$sede]->id, 'departamento_id' => $depto($dep), 'activo' => true]);
                $c->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            }
            User::where('username', 'corp.agente.demo')->update(['colaborador_id' => Colaborador::where('num_empleado', 'C-104')->value('id'), 'numero_colaborador' => 'C-104']);
        }

        if (! Acceso::exists()) {
            $a = fn (string $hora, int $dias = 0) => Carbon::now('America/Mexico_City')->subDays($dias)->setTimeFromTimeString($hora)->utc();
            $col = fn (string $num) => Colaborador::where('num_empleado', $num)->first();
            $hugo = $col('C-103');
            $fernanda = $col('C-102');
            $ricardo = $col('C-101');
            $this->accesoDemo($torre, ['tipo' => 'colaborador', 'nombre' => mb_strtoupper($hugo->nombreCompleto()), 'colaborador_id' => $hugo->id, 'modo_arribo' => 'a_pie'], $a('08:05'), $agente);
            $this->accesoDemo($torre, ['tipo' => 'visitante', 'nombre' => 'PATRICIA GÓMEZ ROJAS', 'identificacion' => 'ine', 'motivo_visita' => 'colaborador',
                'visita_colaborador_id' => $fernanda->id, 'persona_visita' => mb_strtoupper($fernanda->nombreCompleto()), 'modo_arribo' => 'a_pie'], $a('09:30'), $agente);
            $this->accesoDemo($torre, ['tipo' => 'proveedor', 'estado' => 'pendiente', 'nombre' => 'MIGUEL ÁNGEL TORRES', 'empresa_procedencia' => 'PAPELERÍA DEL CENTRO',
                'host_colaborador_id' => $ricardo->id, 'identificacion' => 'ine', 'tipo_visita' => 'cortesia', 'modo_arribo' => 'a_pie'], $a('10:10'), $agente);
            $this->accesoDemo($torre, ['tipo' => 'contratista', 'estado' => 'finalizado', 'nombre' => 'ERNESTO CÁRDENAS', 'empresa_procedencia' => 'CLIMAS INDUSTRIALES',
                'host_colaborador_id' => $hugo->id, 'identificacion' => 'licencia', 'tipo_visita' => 'ejecucion', 'actividad' => 'Mantenimiento del aire acondicionado del site',
                'modo_arribo' => 'a_pie'], $a('11:00', 1), $agente, ['salida_at' => $a('15:30', 1), 'salida_por' => $agente->id]);
            $this->accesoDemo($torre, ['tipo' => 'visitante', 'estado' => 'finalizado', 'nombre' => 'LORENA PAREDES', 'identificacion' => 'ine', 'motivo_visita' => 'rh',
                'modo_arribo' => 'a_pie'], $a('12:00', 1), $agente, ['salida_at' => $a('12:50', 1), 'salida_por' => $agente->id]);
        }

        $this->comoUsuarioDemo($admin, function () use ($admin, $empresa, $torre) {
            if (! ReservaArea::exists()) {
                $sala = Espacio::where('sede_id', $torre->id)->where('nombre', 'Sala de juntas Roble')->first();
                if ($sala !== null) {
                    $this->salaReservableDemo($admin, $empresa, $sala, 12, 'Mesa para 12 personas, pantalla y videoconferencia.',
                        'Junta mensual de dirección', 'Reunión');
                }
            }
            $this->incidenteDemo($admin, $torre, 'Recepción, planta baja', 'Visitante dejó una mochila sin identificar en la recepción; se resguardó en la caseta.');
        });
    }

    // ------------------------------------------------------------- Condominio

    /**
     * @param  Collection<string, Sede>  $sedes
     */
    private function condominioDemo(Empresa $empresa, $sedes): void
    {
        $admin = User::where('username', 'condo.admin.demo')->firstOrFail();
        $agente = User::where('username', 'condo.agente.demo')->firstOrFail();
        $norte = $sedes['NOR'];

        $this->comoUsuarioDemo($admin, function () use ($admin, $norte, $sedes) {
            if (! Espacio::where('nivel', Espacio::EDIFICIO)->exists()) {
                $espacios = app(AdministradorEspacios::class);
                $torre = $espacios->crear($admin, $norte, null, Espacio::EDIFICIO, ['nombre' => 'Torre A', 'codigo' => 'TA'], false);
                foreach ([1, 2] as $n) {
                    $piso = $espacios->crear($admin, $norte, $torre, Espacio::AREA, ['nombre' => "Piso {$n}"], false);
                    $espacios->crearLote($admin, $piso, array_map(fn ($d) => $n.'0'.$d, range(1, 4)), null, null);
                }
                $comunes = $espacios->crear($admin, $norte, null, Espacio::EDIFICIO, ['nombre' => 'Áreas comunes'], false);
                $planta = $espacios->crear($admin, $norte, $comunes, Espacio::AREA, ['nombre' => 'Planta baja'], false);
                foreach (['Salón de usos múltiples', 'Asador', 'Alberca'] as $area) {
                    $espacios->crear($admin, $norte, $planta, Espacio::AREA_ESPECIFICA, ['nombre' => $area], false);
                }
                $torreB = $espacios->crear($admin, $sedes['SUR'], null, Espacio::EDIFICIO, ['nombre' => 'Torre B', 'codigo' => 'TB'], false);
                $pisoB = $espacios->crear($admin, $sedes['SUR'], $torreB, Espacio::AREA, ['nombre' => 'Piso 1'], false);
                $espacios->crearLote($admin, $pisoB, ['B-101', 'B-102'], null, null);
            }
            if (! Departamento::exists()) {
                foreach (['Administración', 'Mantenimiento', 'Seguridad'] as $d) {
                    Departamento::create(['nombre' => $d]);
                }
            }
        });

        // C-10: residentes con su vivienda (Padrones → Residentes) y el personal de la administración (Estructura → Personal)
        if (! Colaborador::exists()) {
            foreach ([
                'R-101' => ['Alejandra', 'Fuentes', 'Ríos', 'NOR', '101'],
                'R-102' => ['Tomás', 'Herrera', 'Gil', 'NOR', '102'],
                'R-201' => ['Beatriz', 'Campos', 'Ochoa', 'NOR', '201'],
                'R-204' => ['Gerardo', 'Quiroz', 'Lara', 'NOR', '204'],
                'R-B101' => ['Mónica', 'Estrada', 'Vela', 'SUR', 'B-101'],
            ] as $num => [$nombre, $paterno, $materno, $sede, $vivienda]) {
                $c = Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno,
                    'apellido_materno' => $materno, 'sede_id' => $sedes[$sede]->id, 'telefono' => '33 1000 '.str_pad((string) (crc32($num) % 10000), 4, '0', STR_PAD_LEFT),
                    'espacio_id' => Espacio::where('sede_id', $sedes[$sede]->id)->where('nivel', Espacio::AREA_ESPECIFICA)->where('nombre', $vivienda)->value('id'), 'activo' => true]);
                $c->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            }
            $this->personalDemo($admin, $sedes['NOR'], ['P-01' => ['Rogelio', 'Navarro', 'Mantenimiento'], 'P-02' => ['Silvia', 'Ortega', 'Administración']]);
        }

        if (! Acceso::exists()) {
            $a = fn (string $hora, int $dias = 0) => Carbon::now('America/Mexico_City')->subDays($dias)->setTimeFromTimeString($hora)->utc();
            $res = fn (string $num) => Colaborador::where('num_empleado', $num)->first();
            $tomas = $res('R-102');
            $alejandra = $res('R-101');
            $beatriz = $res('R-201');
            $viv = fn (string $n) => Espacio::where('sede_id', $norte->id)->where('nivel', Espacio::AREA_ESPECIFICA)->where('nombre', $n)->value('id'); // C-30
            $this->accesoDemo($norte, ['tipo' => 'colaborador', 'nombre' => mb_strtoupper($tomas->nombreCompleto()), 'colaborador_id' => $tomas->id, 'modo_arribo' => 'auto',
                'placas' => 'JAL4521'], $a('07:45'), $agente);
            $this->accesoDemo($norte, ['tipo' => 'visitante', 'nombre' => 'DANIEL ROMERO', 'identificacion' => 'ine', 'motivo_visita' => 'colaborador',
                'visita_colaborador_id' => $alejandra->id, 'persona_visita' => mb_strtoupper($alejandra->nombreCompleto()), 'habitacion' => '101', 'espacio_id' => $viv('101'),
                'modo_arribo' => 'a_pie', 'num_acompanantes' => 1], $a('10:20'), $agente);
            $this->accesoDemo($norte, ['tipo' => 'proveedor', 'estado' => 'pendiente', 'nombre' => 'JUANA PÉREZ', 'empresa_procedencia' => 'LIMPIEZA DEL HOGAR',
                'host_colaborador_id' => $beatriz->id, 'visita_colaborador_id' => $beatriz->id, 'identificacion' => 'ine', 'tipo_visita' => 'ejecucion', 'actividad' => 'Limpieza de la vivienda 201',
                'modo_arribo' => 'a_pie'], $a('11:05'), $agente);
            $this->accesoDemo($norte, ['tipo' => 'contratista', 'estado' => 'finalizado', 'nombre' => 'RAÚL MEDINA', 'empresa_procedencia' => 'PLOMERÍA MEDINA',
                'host_colaborador_id' => $tomas->id, 'visita_colaborador_id' => $tomas->id, 'identificacion' => 'ine', 'tipo_visita' => 'ejecucion', 'actividad' => 'Reparación de fuga en el baño',
                'modo_arribo' => 'a_pie'], $a('09:00', 1), $agente, ['salida_at' => $a('12:40', 1), 'salida_por' => $agente->id]);
            $this->accesoDemo($norte, ['tipo' => 'visitante', 'estado' => 'finalizado', 'nombre' => 'SANDRA LUGO', 'identificacion' => 'ine', 'motivo_visita' => 'colaborador',
                'visita_colaborador_id' => $beatriz->id, 'persona_visita' => mb_strtoupper($beatriz->nombreCompleto()), 'habitacion' => '201', 'espacio_id' => $viv('201'),
                'modo_arribo' => 'a_pie'], $a('18:10', 1), $agente, ['salida_at' => $a('21:00', 1), 'salida_por' => $agente->id]);
        }

        $this->comoUsuarioDemo($admin, function () use ($admin, $empresa, $norte) {
            if (! ReservaArea::exists()) {
                $salon = Espacio::where('sede_id', $norte->id)->where('nombre', 'Salón de usos múltiples')->first();
                if ($salon !== null) {
                    $this->salaReservableDemo($admin, $empresa, $salon, 40, 'Salón para reuniones y fiestas de los residentes, con cocineta y baños.',
                        'Asamblea de residentes', 'Reunión');
                }
                $asador = Espacio::where('sede_id', $norte->id)->where('nombre', 'Asador')->first();
                if ($asador !== null) {
                    $this->salaReservableDemo($admin, $empresa, $asador, 15, 'Asador techado junto a la alberca.', null, null);
                }
            }
            $this->incidenteDemo($admin, $norte, 'Estacionamiento de visitas', 'Vehículo de visita estacionado en el cajón del departamento 204; se avisó al residente.');
        });
    }

    // --------------------------------------------------------- Fraccionamiento

    /**
     * Etapa 5 (C-10): casas en calles, residentes con su casa, personal de la
     * administración, Mantenimiento con responsable (recibe los tickets que se
     * le canalizan, C-28), accesos, la Casa club reservable y un incidente.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function fraccionamientoDemo(Empresa $empresa, $sedes): void
    {
        $admin = User::where('username', 'frac.admin.demo')->firstOrFail();
        $agente = User::where('username', 'frac.agente.demo')->firstOrFail();
        $lomas = $sedes['LOM'];

        $this->comoUsuarioDemo($admin, function () use ($admin, $lomas) {
            if (! Espacio::where('nivel', Espacio::EDIFICIO)->exists()) {
                $espacios = app(AdministradorEspacios::class);
                foreach (['Calle Robles' => ['Manzana 1', ['1', '2', '3', '4']], 'Calle Encinos' => ['Manzana 2', ['10', '11', '12']]] as $calle => [$manzana, $casas]) {
                    $c = $espacios->crear($admin, $lomas, null, Espacio::EDIFICIO, ['nombre' => $calle], false);
                    $m = $espacios->crear($admin, $lomas, $c, Espacio::AREA, ['nombre' => $manzana], false);
                    $espacios->crearLote($admin, $m, $casas, null, null);
                }
                $comunes = $espacios->crear($admin, $lomas, null, Espacio::EDIFICIO, ['nombre' => 'Áreas comunes'], false);
                $club = $espacios->crear($admin, $lomas, $comunes, Espacio::AREA, ['nombre' => 'Club'], false);
                foreach (['Casa club', 'Alberca', 'Cancha'] as $area) {
                    $espacios->crear($admin, $lomas, $club, Espacio::AREA_ESPECIFICA, ['nombre' => $area], false);
                }
            }
            if (! Departamento::exists()) {
                foreach (['Administración', 'Mantenimiento', 'Seguridad'] as $d) {
                    Departamento::create(['nombre' => $d]);
                }
            }
            // Quien atiende Mantenimiento y Administración (tickets canalizados y visitas a la administración)
            foreach (['Mantenimiento', 'Administración'] as $d) {
                $depto = Departamento::where('nombre', $d)->first();
                if ($depto !== null && ! DepartamentoResponsable::where('departamento_id', $depto->id)->exists()) {
                    DepartamentoResponsable::create(['departamento_id' => $depto->id, 'sede_id' => null, 'user_id' => $admin->id, 'es_suplente' => false]);
                }
            }
        });

        if (! Colaborador::exists()) {
            $casa = fn (string $n) => Espacio::where('sede_id', $lomas->id)->where('nivel', Espacio::AREA_ESPECIFICA)->where('nombre', $n)->value('id');
            foreach ([['Lucía', 'Benítez', 'Paz', '1'], ['Andrés', 'Salinas', 'Mora', '2'], ['Claudia', 'Rivas', 'León', '3'], ['Héctor', 'Lozano', 'Cruz', '10']] as $i => [$nombre, $paterno, $materno, $n]) {
                $c = Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'nombre' => $nombre, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                    'sede_id' => $lomas->id, 'espacio_id' => $casa($n), 'telefono' => '442 200 00'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'activo' => true]);
                $c->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            }
            $this->personalDemo($admin, $lomas, ['F-01' => ['Ramiro', 'Solís', 'Mantenimiento'], 'F-02' => ['Patricia', 'Montes', 'Administración']]);
        }

        if (! Acceso::exists()) {
            $a = fn (string $hora, int $dias = 0) => Carbon::now('America/Mexico_City')->subDays($dias)->setTimeFromTimeString($hora)->utc();
            $lucia = Colaborador::residentes()->where('nombre', 'Lucía')->first();
            $claudia = Colaborador::residentes()->where('nombre', 'Claudia')->first();
            $this->accesoDemo($lomas, ['tipo' => 'colaborador', 'nombre' => mb_strtoupper($lucia->nombreCompleto()), 'colaborador_id' => $lucia->id, 'modo_arribo' => 'auto',
                'placas' => 'QRO1234'], $a('08:10'), $agente);
            $this->accesoDemo($lomas, ['tipo' => 'visitante', 'nombre' => 'MARIO GUTIÉRREZ', 'identificacion' => 'ine', 'motivo_visita' => 'colaborador',
                'visita_colaborador_id' => $claudia->id, 'persona_visita' => mb_strtoupper($claudia->nombreCompleto()), 'habitacion' => '3',
                'espacio_id' => $claudia->espacio_id, 'modo_arribo' => 'a_pie'], $a('11:30'), $agente, ['autorizacion' => Acceso::POR_LLAMADA, 'autorizado_por' => $agente->id]);
            $this->accesoDemo($lomas, ['tipo' => 'proveedor', 'estado' => 'finalizado', 'nombre' => 'ENRIQUE DÍAZ', 'empresa_procedencia' => 'GAS DEL BAJÍO',
                'visita_colaborador_id' => $lucia->id, 'host_colaborador_id' => $lucia->id, 'persona_visita' => mb_strtoupper($lucia->nombreCompleto()),
                'habitacion' => '1', 'espacio_id' => $lucia->espacio_id, 'identificacion' => 'ine', 'tipo_visita' => 'ejecucion', 'actividad' => 'Carga de gas estacionario',
                'modo_arribo' => 'auto', 'placas' => 'GAS777'], $a('10:00', 1), $agente, ['salida_at' => $a('10:40', 1), 'salida_por' => $agente->id]);
        }

        $this->comoUsuarioDemo($admin, function () use ($admin, $empresa, $lomas) {
            if (! ReservaArea::exists()) {
                $club = Espacio::where('sede_id', $lomas->id)->where('nombre', 'Casa club')->first();
                if ($club !== null) {
                    $this->salaReservableDemo($admin, $empresa, $club, 60, 'Casa club con terraza para fiestas y reuniones de los vecinos.', null, null);
                }
            }
            $this->incidenteDemo($admin, $lomas, 'Calle Robles', 'Luminaria apagada frente a la casa 2; se reporta a Mantenimiento.');
        });
    }

    /**
     * Personal de la administración (C-10): guardias, mantenimiento, oficina.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $personas  número => [nombre, apellido, departamento]
     */
    private function personalDemo(User $admin, Sede $sede, array $personas): void
    {
        foreach ($personas as $num => [$nombre, $paterno, $depto]) {
            $c = Colaborador::create(['tipo' => Colaborador::PERSONAL, 'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno,
                'sede_id' => $sede->id, 'departamento_id' => Departamento::where('nombre', $depto)->value('id'), 'activo' => true]);
            $c->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
        }
    }

    // ---------------------------------------------------------------- Ayudas

    private function comoUsuarioDemo(User $usuario, callable $fn): void
    {
        $previo = auth()->user();
        try {
            auth()->setUser($usuario);
            app(Autorizador::class)->olvidar();
            $fn();
        } finally {
            $previo !== null ? auth()->setUser($previo) : auth()->forgetGuards();
            app(Autorizador::class)->olvidar();
        }
    }

    private function tipoEspacioDemo(string $nombre): ?int
    {
        return TipoEspacio::whereNull('empresa_id')->where('nivel', Espacio::AREA_ESPECIFICA)->where('nombre', $nombre)->value('id');
    }

    private function accesoDemo(Sede $sede, array $datos, $entrada, User $autor, array $extra = []): Acceso
    {
        $acceso = new Acceso($datos + ['sede_id' => $sede->id, 'entrada_at' => $entrada]);
        $acceso->forceFill($extra + ['creado_por' => $autor->id, 'actualizado_por' => $autor->id])->save();

        return $acceso;
    }

    /** Un área reservable de confirmación inmediata (de lunes a domingo, 8 a 22 h) y, si se indica, una reserva de ejemplo para mañana. */
    private function salaReservableDemo(User $admin, Empresa $empresa, Espacio $espacio, int $aforo, string $descripcion, ?string $titulo, ?string $tipoUso): void
    {
        app(AjustesReservas::class)->guardar($admin, $empresa->refresh(), ['responsable_id' => $admin->id, 'suplente_id' => null, 'recordar_horas' => 4, 'escalar_horas' => 24]);
        $catalogos = app(CatalogosReservas::class);
        $catalogos->asegurar();
        $area = $catalogos->marcarReservable($admin, $espacio);
        $semana = collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => ['abierto' => '1', 'desde' => '08:00', 'hasta' => '22:00']])->all();
        $catalogos->guardarArea($admin, $area, ['aforo_maximo' => $aforo, 'confirmacion_inmediata' => '1', 'con_horario' => '1', 'horario' => $semana,
            'descripcion' => $descripcion, 'montajes' => [ReservaMontaje::where('nombre', 'Libre')->value('id') => ['usar' => '1', 'aforo' => $aforo]]]);
        if ($titulo !== null) {
            $manana = now($espacio->sede?->zonaHoraria() ?? 'America/Mexico_City')->addDay()->toDateString();
            app(AdministradorReservas::class)->crear($admin, [
                'reserva_area_id' => $area->id, 'fecha' => $manana, 'hora_inicio' => '18:00', 'hora_fin' => '20:00', 'titulo' => $titulo,
                'tipo_uso_id' => ReservaTipoUso::where('nombre', $tipoUso)->value('id'), 'asistentes' => min(10, $aforo), 'para_cliente' => '0',
            ]);
        }
    }

    private function incidenteDemo(User $actor, Sede $sede, string $ubicacion, string $descripcion): void
    {
        if (Novedad::exists()) {
            return;
        }
        app(AdministradorNovedades::class)->crear($actor, ['sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => $actor->name,
            'ubicacion' => $ubicacion, 'descripcion' => $descripcion, 'ocurrio_en' => now($sede->zonaHoraria())->subHours(2)->format('Y-m-d\TH:i')]);
    }
}
