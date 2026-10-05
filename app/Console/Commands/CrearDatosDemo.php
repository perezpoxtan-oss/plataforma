<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Espacio;
use App\Models\Gafete;
use App\Models\GrupoEspacio;
use App\Models\Llave;
use App\Models\Persona;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Responsiva;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoEspacio;
use App\Models\Turno;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Equipos\AdministradorEquipos;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Llaves\AdministradorLlaves;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Services\Rutas\AdministradorRutas;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Empresa ficticia con dos sedes y un usuario por cada rol, para probar en QA
 * lo que ve y puede hacer cada perfil. Nunca corre en Produccion.
 */
class CrearDatosDemo extends Command
{
    public const EMPRESA = 'Hotel Demo';

    /** usuario => [nombre, rol, sede (null = todas)] */
    public const USUARIOS = [
        'admin.demo' => ['Ana Administradora', 'Administrador', null],
        'director.demo' => ['Diego Director', 'Director', null],
        'rh.demo' => ['Rita Recursos Humanos', 'Recursos Humanos', null],
        'jefe.demo' => ['Julia Jefa de Seguridad', 'Jefe de seguridad', null],
        'supervisor.demo' => ['Sergio Supervisor', 'Supervisor', 'CEN'],
        'agente.demo' => ['Andrea Agente', 'Agente', 'CEN'],
        'agente2.demo' => ['Pablo Agente Playa', 'Agente', 'PLA'],
    ];

    protected $signature = 'plataforma:demo
        {--password= : Contrasena de los usuarios demo (si no, se toma de PLATAFORMA_CONTRASENA)}';

    protected $description = 'Crea la empresa demo y un usuario por rol (solo QA y desarrollo)';

    public function handle(ProvisionarEmpresa $provisionar, Tenant $tenant): int
    {
        if (app()->isProduction()) {
            $this->error('Los datos demo no se crean en Produccion.');

            return self::FAILURE;
        }

        $contrasena = $this->option('password') ?? (getenv('PLATAFORMA_CONTRASENA') ?: null);
        if ($contrasena === null || strlen($contrasena) < 8) {
            $this->error('Indica una contrasena de al menos 8 caracteres (--password o PLATAFORMA_CONTRASENA).');

            return self::FAILURE;
        }

        $empresa = Empresa::where('nombre_comercial', self::EMPRESA)->first()
            ?? $provisionar->crear(Rubro::where('clave', 'hotel')->firstOrFail(), [
                'nombre_comercial' => self::EMPRESA,
                'razon_social' => 'Hotel Demo S.A. de C.V.',
                'rfc' => 'HDE200101AA1',
                'zona_horaria' => 'America/Cancun',
            ]);

        $sedes = $tenant->conEmpresa($empresa->id, fn () => collect([
            'CEN' => ['Hotel Demo Centro', 'Av. Tulum 200', 'Centro', '77500'],
            'PLA' => ['Hotel Demo Playa', 'Blvd. Kukulcán km 9', 'Zona Hotelera', '77500'],
        ])->map(fn ($d, $codigo) => Sede::updateOrCreate(['codigo' => $codigo], [
            'nombre' => $d[0], 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo',
            'direccion' => $d[1], 'colonia' => $d[2], 'codigo_postal' => $d[3],
        ])));

        foreach (self::USUARIOS as $usuario => [$nombre, $rol, $sede]) {
            $cuenta = User::firstOrNew(['username' => $usuario]);
            // Al completar el demo en QA no se tocan las cuentas que ya existen
            // (quien prueba pudo cambiarles el correo o la contraseña): solo se
            // crean las que faltan y se asegura su rol.
            if (! $cuenta->exists) {
                $cuenta->fill([
                    'empresa_id' => $empresa->id,
                    'name' => $nombre,
                    'email' => $usuario.'@demo.local',
                    'password' => $contrasena,
                    'activo' => true,
                ])->save();
                $this->line("Usuario demo creado: {$usuario}");
            }

            $rolId = Rol::where('empresa_id', $empresa->id)->where('nombre', $rol)->value('id');
            UsuarioRol::firstOrCreate(['user_id' => $cuenta->id, 'rol_id' => $rolId], [
                'sede_id' => $sede === null ? null : $sedes[$sede]->id,
            ]);
        }

        $tenant->conEmpresa($empresa->id, fn () => $this->espaciosDemo($sedes['CEN'], User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->departamentosYPuestosDemo($sedes['PLA']));
        $tenant->conEmpresa($empresa->id, fn () => $this->turnosDemo($sedes['PLA']));
        $tenant->conEmpresa($empresa->id, fn () => $this->colaboradoresDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->provisionalesDemo($sedes, User::where('username', 'agente.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->proveedoresDemo($sedes));
        // Padrón de personas: después de los proveedores (si existen) para ligar a su personal
        $tenant->conEmpresa($empresa->id, fn () => $this->personasDemo(User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'jefe.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->vehiculosDemo(User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->llavesDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->gafetesDemo($empresa, $sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->equiposYEstacionamientosDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->rutasDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $tenant->conEmpresa($empresa->id, fn () => $this->prestamosYResponsivasDemo($sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));

        $this->info('Empresa demo lista: '.self::EMPRESA.' con '.count(self::USUARIOS).' usuarios ('.implode(', ', array_keys(self::USUARIOS)).').');

        return self::SUCCESS;
    }

    /**
     * Departamentos y puestos típicos de un hotel, solo la primera vez.
     */
    private function departamentosYPuestosDemo(Sede $playa): void
    {
        if (Departamento::exists()) {
            return;
        }

        $deptos = collect(['Seguridad', 'Recepción', 'Ama de Llaves', 'Mantenimiento', 'Alimentos y Bebidas', 'Recursos Humanos'])
            ->mapWithKeys(fn ($n) => [$n => Departamento::create(['nombre' => $n])]);
        // Club de Playa solo existe en la sede de playa
        $club = Departamento::create(['nombre' => 'Club de Playa', 'todas_las_sedes' => false]);
        $club->sedes()->sync([$playa->id]);

        $puestos = [
            'Agente de Seguridad' => [Puesto::OPERATIVO, ['Seguridad']],
            'Supervisor de Seguridad' => [Puesto::OPERATIVO, ['Seguridad']],
            'Jefe de Seguridad' => [Puesto::ADMINISTRATIVO, ['Seguridad']],
            'Recepcionista' => [Puesto::OPERATIVO, ['Recepción']],
            'Camarista' => [Puesto::OPERATIVO, ['Ama de Llaves']],
            'Técnico de Mantenimiento' => [Puesto::OPERATIVO, ['Mantenimiento']],
            'Mesero' => [Puesto::OPERATIVO, ['Alimentos y Bebidas']],
            'Gerente' => [Puesto::ADMINISTRATIVO, []],
            'Auxiliar Administrativo' => [Puesto::ADMINISTRATIVO, ['Recursos Humanos', 'Recepción']],
        ];
        foreach ($puestos as $nombre => [$tipo, $de]) {
            Puesto::create(['nombre' => $nombre, 'tipo' => $tipo])->departamentos()->sync(collect($de)->map(fn ($d) => $deptos[$d]->id)->all());
        }
    }

    /**
     * Turnos de un hotel (uno cruza la medianoche), solo la primera vez.
     */
    private function turnosDemo(Sede $playa): void
    {
        if (Turno::exists()) {
            return;
        }

        foreach ([['Matutino', '07:00', '15:00'], ['Vespertino', '15:00', '23:00'], ['Nocturno', '23:00', '07:00']] as [$nombre, $inicio, $fin]) {
            Turno::create(['nombre' => $nombre, 'hora_inicio' => "{$inicio}:00", 'hora_fin' => "{$fin}:00"]);
        }
        // Mixto Playa solo se usa en la sede de playa
        Turno::create(['nombre' => 'Mixto Playa', 'hora_inicio' => '10:00:00', 'hora_fin' => '18:00:00', 'todas_las_sedes' => false])
            ->sedes()->sync([$playa->id]);
    }

    /**
     * Personal de ejemplo en las dos sedes, solo la primera vez. CURP, RFC y
     * NSS son ficticios pero con formato válido. Las cuentas demo de
     * administración, supervisión y caseta quedan vinculadas a su colaborador.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function colaboradoresDemo($sedes, User $admin): void
    {
        if (Colaborador::exists()) {
            return;
        }

        $depto = fn (string $n) => Departamento::where('nombre', $n)->value('id');
        $puesto = fn (string $n) => Puesto::where('nombre', $n)->value('id');

        // num => [nombre, paterno, materno, sede (null = corporativo), departamento, puesto, nacimiento, sexo, estado, usuario]
        $personas = [
            '1001' => ['Ana', 'Administradora', null, null, 'Recursos Humanos', 'Gerente', '1985-03-14', 'M', 'Yucatán', 'admin.demo'],
            '1002' => ['Sergio', 'Supervisor', null, 'CEN', 'Seguridad', 'Supervisor de Seguridad', '1988-07-02', 'H', 'Quintana Roo', 'supervisor.demo'],
            '1003' => ['Andrea', 'Agente', null, 'CEN', 'Seguridad', 'Agente de Seguridad', '1996-11-21', 'M', 'Quintana Roo', 'agente.demo'],
            '1004' => ['Pablo', 'Agente', 'Playa', 'PLA', 'Seguridad', 'Agente de Seguridad', '1994-01-30', 'H', 'Campeche', 'agente2.demo'],
            '1005' => ['Roberto', 'Hernández', 'Cruz', 'CEN', 'Seguridad', 'Agente de Seguridad', '1990-05-09', 'H', 'Tabasco', null],
            '1006' => ['Carlos', 'Pérez', 'Gómez', 'CEN', 'Seguridad', 'Jefe de Seguridad', '1982-09-17', 'H', 'Veracruz', null],
            '1007' => ['Mariana', 'López', 'Pech', 'CEN', 'Recepción', 'Recepcionista', '1998-02-12', 'M', 'Yucatán', null],
            '1008' => ['Daniela', 'Canul', 'May', 'PLA', 'Recepción', 'Recepcionista', '1999-08-25', 'M', 'Quintana Roo', null],
            '1009' => ['Guadalupe', 'Chan', 'Ek', 'CEN', 'Ama de Llaves', 'Camarista', '1987-12-03', 'M', 'Yucatán', null],
            '1010' => ['Rosa María', 'Poot', 'Uc', 'PLA', 'Ama de Llaves', 'Camarista', '1991-04-18', 'M', 'Yucatán', null],
            '1011' => ['Javier', 'Ramírez', 'Soto', 'PLA', 'Mantenimiento', 'Técnico de Mantenimiento', '1986-10-07', 'H', 'Chiapas', null],
            '1012' => ['Luis Fernando', 'Díaz', 'Kú', 'PLA', 'Alimentos y Bebidas', 'Mesero', '2000-06-29', 'H', 'Extranjero', null],
            '1013' => ['Verónica', 'Ruiz', 'Ortega', 'CEN', 'Recepción', 'Auxiliar Administrativo', '1993-03-05', 'M', 'Ciudad de México', null],
        ];

        $n = 0;
        foreach ($personas as $clave => [$nombre, $paterno, $materno, $sede, $dep, $pue, $nacimiento, $sexo, $estado, $usuario]) {
            $n++;
            $num = (string) $clave; // las claves numéricas llegan como int
            [$curp, $rfc] = $this->identificadoresDemo($nombre, $paterno, $materno, $nacimiento, $sexo, $n);
            $colaborador = Colaborador::create([
                'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'sede_id' => $sede === null ? null : $sedes[$sede]->id, 'departamento_id' => $depto($dep), 'puesto_id' => $puesto($pue),
                'telefono' => '998'.str_pad((string) (1000000 + $n * 7919), 7, '0', STR_PAD_LEFT),
                'fecha_nacimiento' => $nacimiento, 'lugar_nacimiento' => $estado, 'nacionalidad' => $estado === 'Extranjero' ? 'Guatemalteca' : 'Mexicana',
                'curp' => $curp, 'rfc' => $rfc, 'nss' => sprintf('%011d', 12345678900 + $n * 101),
                'correo_personal' => null, 'direccion_completa' => null, 'activo' => $num !== '1013',
            ]);
            $colaborador->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();

            if ($usuario !== null) {
                User::where('username', $usuario)->update(['colaborador_id' => $colaborador->id, 'numero_colaborador' => $num]);
            }
        }

        // Roberto cubre también la sede de playa
        Colaborador::where('num_empleado', '1005')->firstOrFail()->sedesAdicionales()->sync([$sedes['PLA']->id]);
    }

    /**
     * Dos altas provisionales de la caseta para que Recursos Humanos practique:
     * una persona nueva (se valida) y un duplicado de Roberto Hernández (se une).
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function provisionalesDemo($sedes, User $agente): void
    {
        if (Colaborador::where('provisional', true)->exists()) {
            return;
        }

        foreach ([
            ['Jorge', 'Méndez', 'Tun', 'Mantenimiento'],
            ['Beto', 'Hernandez', null, 'Seguridad'],
        ] as [$nombre, $paterno, $materno, $dep]) {
            $nuevo = new Colaborador([
                'nombre' => $nombre, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'sede_id' => $sedes['CEN']->id, 'departamento_id' => Departamento::where('nombre', $dep)->value('id'),
            ]);
            $nuevo->forceFill(['provisional' => true, 'creado_por' => $agente->id, 'actualizado_por' => $agente->id])->save();
        }
    }

    /**
     * CURP y RFC ficticios con formato válido (no corresponden a nadie).
     *
     * @return array{0: string, 1: string}
     */
    private function identificadoresDemo(string $nombre, string $paterno, ?string $materno, string $nacimiento, string $sexo, int $n): array
    {
        $limpio = fn (?string $t) => strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $t)));
        $p = $limpio($paterno);
        $m = $limpio($materno) ?: 'X';
        $nom = $limpio($nombre);
        $vocal = preg_match('/[AEIOU]/', substr($p, 1), $v) ? $v[0] : 'X';
        $consonante = fn (string $t) => preg_match('/[B-DF-HJ-NP-TV-Z]/', substr($t, 1), $c) ? $c[0] : 'X';
        $raiz = $p[0].$vocal.$m[0].$nom[0];
        $fecha = substr(str_replace('-', '', $nacimiento), 2);

        $curp = $raiz.$fecha.$sexo.'QR'.$consonante($p).$consonante($m.'X').$consonante($nom).'0'.($n % 10);
        $rfc = $raiz.$fecha.'A'.str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT);

        return [$curp, $rfc];
    }

    /**
     * Torre A con dos pisos y algunas habitaciones con detalle, solo la primera vez.
     */
    private function espaciosDemo(Sede $sede, User $actor): void
    {
        if (Espacio::where('nivel', Espacio::EDIFICIO)->exists()) {
            return;
        }

        $espacios = app(AdministradorEspacios::class);
        $tipo = fn (string $nivel, string $nombre) => TipoEspacio::whereNull('empresa_id')->where('nivel', $nivel)->where('nombre', $nombre)->value('id');

        $torre = $espacios->crear($actor, $sede, null, Espacio::EDIFICIO, ['nombre' => 'Torre A', 'codigo' => 'TA', 'tipo_espacio_id' => $tipo(Espacio::EDIFICIO, 'Torre')], false);
        foreach ([1, 2] as $n) {
            $piso = $espacios->crear($actor, $sede, $torre, Espacio::AREA, ['nombre' => "Piso {$n}", 'tipo_espacio_id' => $tipo(Espacio::AREA, 'Piso')], false);
            $espacios->crearLote($actor, $piso, array_map(fn ($h) => $n.'0'.$h, range(1, 4)), null, $tipo(Espacio::AREA_ESPECIFICA, 'Habitación'));
        }

        $seccion = $espacios->crearGrupo($actor, $sede, 'Vista al mar');
        $espacios->asignarGrupo($actor, $seccion, Espacio::whereIn('nombre', ['101', '102', '201', '202'])->pluck('id')->all());

        $hab = Espacio::where('nombre', '101')->firstOrFail();
        foreach (['Recámara' => ['Cama', 'Televisión', 'Caja fuerte'], 'Baño' => ['Lavabo', 'Regadera', 'Inodoro']] as $area => $elementos) {
            $nodo = $espacios->crear($actor, $sede, $hab, Espacio::SUBAREA, ['nombre' => '', 'tipo_espacio_id' => $tipo(Espacio::SUBAREA, $area)], false);
            foreach ($elementos as $el) {
                $espacios->crear($actor, $sede, $nodo, Espacio::ELEMENTO, ['nombre' => '', 'tipo_espacio_id' => $tipo(Espacio::ELEMENTO, $el)], false);
            }
        }
    }

    /**
     * Empresas externas de un hotel en Cancún (una por categoría, casi todas en
     * todas las sedes), solo la primera vez.
     */
    private function proveedoresDemo($sedes): void
    {
        if (Proveedor::exists()) {
            return;
        }

        $lista = [
            ['Abarrotes del Caribe', 'proveedor', 'ACA150312KJ8', '9988841020', 'Av. Andrés Quintana Roo 45, Cancún', null],
            ['Transportes Kin-Ha', 'transporte_personal', 'TKH0905217T3', '9988872233', 'Av. Kabah Mz 3 Lt 12, Cancún', null],
            ['Shuttle Riviera', 'transporte_huespedes', null, '9982001122', null, null],
            ['Constructora Maya', 'contratista', 'CMA1102148W1', '9981234567', 'Calle 20 Sur 110, Cancún', ['PLA']],
            ['Renta de Autos Caribe Sur', 'agencia_autos', null, '9988850011', null, ['CEN']],
            ['Taxis Aeropuerto', 'taxi', null, '9988860000', 'Terminal 3, Aeropuerto de Cancún', null],
            ['Viajes Turquesa', 'agencia_viajes', 'VTU180606AB2', '+529981112233', null, null],
            ['Tours Xcaret Express', 'agencia_tours', null, '9982223344', null, ['PLA']],
        ];
        foreach ($lista as [$nombre, $categoria, $rfc, $telefono, $direccion, $soloEn]) {
            $proveedor = Proveedor::create([
                'nombre' => $nombre, 'categoria' => $categoria, 'rfc' => $rfc, 'telefono' => $telefono,
                'direccion' => $direccion, 'todas_las_sedes' => $soloEn === null,
            ]);
            $proveedor->sedes()->sync(collect($soloEn ?? [])->map(fn ($codigo) => $sedes[$codigo]->id)->all());
        }
        // Una dada de baja (vetada), para ver el estado en la lista
        Proveedor::create(['nombre' => 'Fletes Rápidos del Sureste', 'categoria' => 'transportadora', 'telefono' => '9997001234', 'activo' => false]);
    }

    /**
     * Padrón de personas: visitantes, prospectos, un familiar, personal de
     * proveedores y contratistas, solo la primera vez. Folios ficticios con
     * formato realista. Si los proveedores demo existen, el personal se liga
     * a ellos; si no, su empresa queda como texto de procedencia.
     */
    private function personasDemo(User $admin, User $jefe): void
    {
        if (Persona::exists()) {
            return;
        }

        // Busca el proveedor por nombre (o el primero activo de la categoría); null si no hay
        $proveedor = fn (array $nombres, string $categoria) => Proveedor::whereIn('nombre', $nombres)->value('id')
            ?? Proveedor::where('categoria', $categoria)->where('activo', true)->orderBy('id')->value('id');

        $insumos = $proveedor(['Distribuidora de Alimentos del Sureste', 'Abarrotes y Alimentos del Sureste'], 'proveedor');
        $transporte = $proveedor(['Transportes Turísticos del Caribe', 'Transportes del Caribe'], 'transporte_personal');
        $contratista = $proveedor(['Construcciones y Mantenimiento Maya', 'Mantenimiento Maya'], 'contratista');

        // [tipo, categoria, nombre, proveedor_id, procedencia, identificación, folio, teléfono, motivo, activo, autor]
        $personas = [
            ['visitante', 'general', 'Laura Méndez Ríos', null, 'Particular', 'ine', 'MNRSLR85031423M700', '9981457820', 'Reunión con Gerencia General.', true, $admin],
            ['visitante', 'general', 'Ricardo Ortega Vela', null, 'Auditoría Peninsular', 'pasaporte', 'G48291736', '9997218845', 'Auditoría externa de seguridad.', true, $admin],
            ['visitante', 'prospecto_rrhh', 'Sofía Castillo Uc', null, null, 'ine', 'CSUCSF99071223M400', '9982236714', 'Entrevista de trabajo para Recepción.', true, $jefe],
            ['visitante', 'prospecto_rrhh', 'Miguel Ángel Pech Chi', null, null, 'curp', 'PECM980212HYNCHG04', '9983340912', 'Entrevista para Mantenimiento.', true, $admin],
            ['visitante', 'familiar', 'Carmen Poot Canché', null, null, 'ine', 'PTCNCR70102031M900', '9984412287', 'Visita familiar a colaboradora de Ama de Llaves.', true, $jefe],
            ['proveedor', 'general', 'Jesús Balam Tun', $insumos, $insumos ? null : 'Distribuidora de Alimentos del Sureste', 'licencia', 'Q0284516', '9985567301', 'Entrega de abarrotes (martes y viernes).', true, $admin],
            ['proveedor', 'general', 'Fernando Rivas León', $transporte, $transporte ? null : 'Transportes Turísticos del Caribe', 'licencia', 'Q0391287', '9986672945', 'Chofer del transporte de personal.', true, $admin],
            ['proveedor', 'general', 'Alejandra Núñez Torres', null, 'Lavandería Industrial Cancún', 'ine', 'NZTRAL92050423M100', '9987783012', 'Recolección y entrega de blancos.', true, $admin],
            ['contratista', 'general', 'José Luis Ek Cauich', $contratista, $contratista ? null : 'Construcciones y Mantenimiento Maya', 'ine', 'EKCSJS88111523H800', '9988894123', 'Mantenimiento preventivo de elevadores.', true, $admin],
            ['contratista', 'general', 'Andrés Herrera Kú', $contratista, $contratista ? null : 'Construcciones y Mantenimiento Maya', 'id_imss', '82139045671', '9989905234', 'Ayudante de obra en remodelación del lobby.', true, $admin],
            ['contratista', 'general', 'Patricia Gómez Sosa', null, 'Fumigaciones Peninsulares', 'cedula', '12847563', '9981016345', 'Control de plagas mensual.', true, $jefe],
            ['visitante', 'general', 'Raúl Domínguez Can', null, 'Paquetería del Caribe', null, null, null, 'Mensajería (ya no presta el servicio).', false, $admin],
        ];

        foreach ($personas as [$tipo, $categoria, $nombre, $proveedorId, $procedencia, $tipoId, $folio, $telefono, $motivo, $activo, $autor]) {
            $persona = new Persona([
                'tipo' => $tipo, 'categoria' => $categoria, 'nombre_completo' => $nombre, 'proveedor_id' => $proveedorId,
                'empresa_procedencia' => $procedencia, 'tipo_identificacion' => $tipoId,
                'folio_identificacion' => Persona::normalizarFolio($folio), 'telefono' => $telefono,
                'motivo_visita' => $motivo, 'activo' => $activo,
            ]);
            $persona->forceFill(['creado_por' => $autor->id, 'actualizado_por' => $autor->id])->save();
        }
    }

    /**
     * Padrón vehicular de ejemplo, solo la primera vez: autos de huéspedes,
     * visitantes y colaboradores, taxis, una unidad rentada y la flotilla de
     * transporte. Los proveedores se usan si ya existen (si no, quedan sin
     * empresa propietaria); los colaboradores, si existen.
     */
    private function vehiculosDemo(User $admin): void
    {
        if (Vehiculo::exists()) {
            return;
        }

        $colaborador = fn (string $num) => Colaborador::where('num_empleado', $num)->value('id');
        $proveedor = fn (array $categorias) => Proveedor::where('activo', true)->whereIn('categoria', $categorias)->orderBy('id')->value('id');
        $transporte = $proveedor(['transporte_personal', 'transporte_huespedes', 'transportadora']);
        $taxi = $proveedor(['taxi']);
        $agencia = $proveedor(['agencia_autos']);
        $cualquiera = $proveedor(array_keys(Proveedor::CATEGORIAS));

        // [placas, propiedad, tipo, marca, modelo, color, extra]
        $vehiculos = [
            ['ABC-123-A', 'propio_huesped', 'sedan', 'NISSAN', 'VERSA', 'BLANCO', []],
            ['YUC-552-1', 'propio_huesped', 'suv', 'MAZDA', 'CX-5', 'ROJO', []],
            ['QRR 44 10', 'propio_visitante', 'sedan', 'VOLKSWAGEN', 'JETTA', 'GRIS', []],
            ['UZX-902-A', 'propio_familiar', 'suv', 'HONDA', 'CR-V', 'AZUL', []],
            ['URB-1830', 'propio_colaborador', 'sedan', 'CHEVROLET', 'AVEO', 'PLATA', ['colaborador_id' => $colaborador('1003')]],
            ['N8T-2Z', 'propio_colaborador', 'motocicleta', 'ITALIKA', 'FT150', 'NEGRO', ['colaborador_id' => $colaborador('1011')]],
            ['VPK-77-12', 'propio_colaborador', 'pickup', 'FORD', 'RANGER', 'BLANCO', ['colaborador_id' => $colaborador('1006')]],
            ['A-4521-TX', 'taxi_app', 'sedan', 'NISSAN', 'TSURU', 'BLANCO Y VERDE', ['numero_economico' => 'T-045', 'proveedor_id' => $taxi]],
            ['B-1187-TX', 'taxi_app', 'suv', 'TOYOTA', 'AVANZA', 'BLANCO', ['numero_economico' => 'T-112', 'proveedor_id' => $taxi]],
            ['TP-07-QR', 'transporte_personal', 'autobus', 'MERCEDES-BENZ', 'SPRINTER', 'BLANCO', ['numero_economico' => 'TP-07', 'capacidad' => 20, 'proveedor_id' => $transporte]],
            ['RNT-220-B', 'agencia_renta', 'sedan', 'KIA', 'RIO', 'BLANCO', ['numero_economico' => 'R-22', 'proveedor_id' => $agencia]],
            // La flotilla de un proveedor exige proveedor: sin ninguno, queda como transporte de personal
            ['UPS-03-CL', $cualquiera ? 'empresa_proveedor' : 'transporte_personal', 'camion_ligero', 'ISUZU', 'ELF 300', 'BLANCO', ['numero_economico' => 'U-03', 'capacidad' => 3, 'proveedor_id' => $cualquiera]],
            ['DEF-567-8', 'propio_visitante', 'otro', 'CLUB CAR', 'ONWARD', 'BEIGE', ['descripcion_otro' => 'Carrito de golf', 'activo' => false]],
        ];

        foreach ($vehiculos as [$placas, $propiedad, $tipo, $marca, $modelo, $color, $extra]) {
            $vehiculo = new Vehiculo(array_merge([
                'placas' => Vehiculo::normalizarPlacas($placas), 'propiedad' => $propiedad, 'tipo' => $tipo,
                'marca' => $marca, 'modelo' => $modelo, 'color' => $color,
            ], $extra));
            $vehiculo->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
        }
    }

    /**
     * Catálogo de llaves de ejemplo, solo la primera vez: llave maestra,
     * llaves de zona, piso, cuarto y sección (de Zonas y áreas, si existen),
     * una por vencer, una vencida y una dada de baja con voucher.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function llavesDemo($sedes, User $admin): void
    {
        if (Llave::exists()) {
            return;
        }

        $cen = $sedes['CEN'];
        $pla = $sedes['PLA'];
        $espacio = fn (string $nivel, string $nombre) => Espacio::where('sede_id', $cen->id)->where('nivel', $nivel)->where('nombre', $nombre)->value('id');
        $seccion = GrupoEspacio::where('sede_id', $cen->id)->where('nombre', 'Vista al mar')->value('id');
        $depto = fn (string $n) => Departamento::where('nombre', $n)->value('id');
        $puesto = fn (string $n) => Puesto::where('nombre', $n)->value('id');
        $dias = fn (int $n) => now(HoraLocal::ZONA_PLATAFORMA)->addDays($n)->format('Y-m-d');
        $torre = $espacio(Espacio::EDIFICIO, 'Torre A');
        $piso1 = $espacio(Espacio::AREA, 'Piso 1');
        $hab101 = $espacio(Espacio::AREA_ESPECIFICA, '101');

        // [nomenclatura, sede, descripción, tipo, alcance, lugares (espacios o secciones), extra, horarios]
        $llaves = [
            ['HDC-MASTER-01', $cen, 'Llave maestra de la sede Centro', 'electronica_rfid', 'global', [],
                ['departamento_id' => $depto('Seguridad'), 'id_externo' => 'VC-000187', 'plataforma_externa' => 'VingCard', 'fecha_caducidad' => $dias(200)], []],
            ['HDC-TA-ZONA', $cen, 'Acceso general a la Torre A', 'metalica', $torre ? 'zona' : 'otra', $torre ? [$torre] : [],
                ['departamento_id' => $depto('Mantenimiento'), 'puesto_id' => $puesto('Técnico de Mantenimiento'), 'alcance_otro' => $torre ? null : 'Torre A'], [['Mantenimiento', '07:00', '15:00']]],
            ['HDC-P1-AMA', $cen, 'Habitaciones del Piso 1 para Ama de Llaves', 'electronica_rfid', $piso1 ? 'piso' : 'otra', $piso1 ? [$piso1] : [],
                ['departamento_id' => $depto('Ama de Llaves'), 'puesto_id' => $puesto('Camarista'), 'id_externo' => 'S-4471', 'plataforma_externa' => 'Salto', 'fecha_caducidad' => $dias(20), 'alcance_otro' => $piso1 ? null : 'Piso 1'],
                [['Turno Limpieza', '08:00', '16:00']]],
            ['HDC-101', $cen, 'Habitación 101 (llave de respaldo)', 'electronica_rfid', $hab101 ? 'area' : 'otra', $hab101 ? [$hab101] : [],
                ['fecha_caducidad' => $dias(-5), 'alcance_otro' => $hab101 ? null : 'Habitación 101'], []],
            ['HDC-VISTA-MAR', $cen, 'Habitaciones con vista al mar', 'electronica_rfid', $seccion ? 'seccion' : 'otra', $seccion ? [$seccion] : [],
                ['departamento_id' => $depto('Ama de Llaves'), 'id_externo' => 'VC-000233', 'plataforma_externa' => 'VingCard', 'fecha_caducidad' => $dias(90), 'alcance_otro' => $seccion ? null : 'Vista al mar'],
                [['Turno Matutino', '07:00', '15:00'], ['Turno Nocturno', '23:00', '07:00']]],
            ['HDC-SITE-TI', $cen, 'Site central de TI', 'biometrica', 'otra', [],
                ['alcance_otro' => 'Site central de TI', 'plataforma_externa' => null, 'colaborador_id' => Colaborador::where('num_empleado', '1006')->value('id')], []],
            ['HDC-BOD-01', $cen, 'Bodega de blancos', 'metalica', 'otra', [],
                ['alcance_otro' => 'Bodega de blancos', 'departamento_id' => $depto('Ama de Llaves')], [['Apertura', '07:00', '19:00']]],
            ['HDP-MASTER-01', $pla, 'Llave maestra de la sede Playa', 'metalica', 'global', [], ['departamento_id' => $depto('Seguridad')], []],
            ['HDP-ALBERCA', $pla, 'Reja de la alberca', 'clave_pin', 'otra', [],
                ['alcance_otro' => 'Reja de alberca', 'departamento_id' => $depto('Club de Playa')], [['Apertura', '06:00', '22:00']]],
            ['HDP-MANT-02', $pla, 'Cuarto de máquinas', 'metalica', 'otra', [],
                ['alcance_otro' => 'Cuarto de máquinas', 'departamento_id' => $depto('Mantenimiento')], []],
        ];

        foreach ($llaves as [$nomenclatura, $sede, $descripcion, $tipo, $alcance, $lugares, $extra, $horarios]) {
            $llave = new Llave(array_merge([
                'sede_id' => $sede->id, 'nomenclatura' => $nomenclatura, 'descripcion' => $descripcion,
                'tipo_dispositivo' => $tipo, 'alcance' => $alcance,
            ], $extra));
            $llave->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            if ($alcance === 'seccion') {
                $llave->grupos()->sync($lugares);
            } elseif ($lugares !== []) {
                $llave->espacios()->sync($lugares);
            }
            foreach ($horarios as [$nombre, $inicio, $fin]) {
                $llave->horarios()->create(['nombre' => $nombre, 'hora_inicio' => "{$inicio}:00", 'hora_fin' => "{$fin}:00"]);
            }
        }

        // Una llave extraviada: baja con voucher de reposición con cobro al responsable
        $extraviada = Llave::where('nomenclatura', 'HDP-MANT-02')->firstOrFail();
        $responsable = Colaborador::where('num_empleado', '1011')->value('id');
        app(AdministradorLlaves::class)->darDeBaja($admin, $extraviada, [
            'motivo' => 'extraviado', 'descripcion' => 'Se perdió durante la ronda nocturna de mantenimiento.',
            'aplica_cobro' => $responsable !== null, 'monto' => '350', 'colaborador_id' => $responsable,
        ]);
    }

    /**
     * Inventario de gafetes de ejemplo, solo la primera vez: en cada sede un
     * lote de Visitante (10), Proveedor (5) y Contratista (5); dos dados de
     * baja con su voucher (uno con cobro a un colaborador demo).
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function gafetesDemo(Empresa $empresa, $sedes, User $admin): void
    {
        if (Gafete::exists()) {
            return;
        }

        $gafetes = app(AdministradorGafetes::class);
        $tipos = $gafetes->tipos($admin, true)->pluck('id', 'nombre');
        foreach ($sedes as $sede) {
            foreach (['Visitante' => 10, 'Proveedor' => 5, 'Contratista' => 5] as $tipo => $cantidad) {
                $gafetes->generarLote($admin, $empresa->id, ['sede_id' => $sede->id, 'tipo_gafete_id' => $tipos[$tipo], 'cantidad' => $cantidad]);
            }
        }

        $codigo = fn (Sede $sede, string $tipo, int $n) => AdministradorGafetes::prefijo($empresa->nombre_comercial, $sede->codigo, $tipo).str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $responsable = Colaborador::where('num_empleado', '1005')->value('id');

        $perdido = Gafete::where('nomenclatura', $codigo($sedes['CEN'], 'Visitante', 10))->first();
        // Desde la consola no hay sesión: el voucher se firma a nombre del administrador demo
        $firmar = fn ($voucher) => $voucher->forceFill(['creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
        if ($perdido !== null) {
            $firmar($gafetes->darDeBaja($admin, $perdido, [
                'motivo' => 'extraviado',
                'descripcion' => 'El visitante se retiró sin devolver el gafete; no contestó al teléfono que dejó en caseta.',
                'aplica_cobro' => $responsable !== null,
                'monto' => $responsable !== null ? '150.00' : null,
                'colaborador_id' => $responsable,
            ]));
        }
        $roto = Gafete::where('nomenclatura', $codigo($sedes['PLA'], 'Proveedor', 5))->first();
        if ($roto !== null) {
            $firmar($gafetes->darDeBaja($admin, $roto, ['motivo' => 'danado', 'descripcion' => 'La mica se rompió y el plástico quedó doblado.', 'aplica_cobro' => false]));
        }
    }

    /**
     * Equipos de seguridad y zonas de estacionamiento de ejemplo, cada uno
     * solo la primera vez: radios, lámparas, detectores, chalecos y
     * botiquines en las dos sedes (uno en mantenimiento y uno de baja con su
     * voucher) y 2–3 zonas por sede, incluida una zona de descarga.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function equiposYEstacionamientosDemo($sedes, User $admin): void
    {
        if (! Equipo::exists()) {
            $tipos = collect(['Radio de Comunicación', 'Lámpara Táctica', 'Detector de Metales', 'Chaleco Reflejante', 'Botiquín de Primeros Auxilios'])
                ->mapWithKeys(fn ($nombre) => [$nombre => TipoEquipo::firstOrCreate(['nombre' => $nombre])->id]);

            // [sede, tipo, marca, modelo, serie, costo, estado, observaciones]
            $equipos = [
                ['CEN', 'Radio de Comunicación', 'MOTOROLA', 'DEP 450', '752TSFQ504', 4800, 'disponible', 'Radio de caseta principal'],
                ['CEN', 'Radio de Comunicación', 'MOTOROLA', 'DEP 450', '752TSFQ505', 4800, 'disponible', null],
                ['CEN', 'Radio de Comunicación', 'MOTOROLA', 'SL500E', '130TXP1568', 5800, 'en_mantenimiento', 'La batería no retiene carga: en servicio técnico'],
                ['PLA', 'Radio de Comunicación', 'MOTOROLA', 'DEP 450', '752TSFQ610', 4800, 'disponible', null],
                ['CEN', 'Lámpara Táctica', 'STREAMLIGHT', 'STINGER 2020', 'LT-0001', 1650, 'disponible', null],
                ['PLA', 'Lámpara Táctica', 'STREAMLIGHT', 'STINGER 2020', 'LT-0002', 1650, 'disponible', null],
                ['CEN', 'Detector de Metales', 'GARRETT', 'SUPER SCANNER V', 'DM-1187', 3200, 'disponible', null],
                ['PLA', 'Detector de Metales', 'GARRETT', 'SUPER SCANNER V', 'DM-1188', 3200, 'disponible', null],
                ['CEN', 'Chaleco Reflejante', 'TRUPER', 'CHR-CLASE 2', 'CH-001', 350, 'disponible', null],
                ['PLA', 'Chaleco Reflejante', 'TRUPER', 'CHR-CLASE 2', 'CH-002', 350, 'disponible', null],
                ['CEN', 'Botiquín de Primeros Auxilios', null, null, 'BK-CEN-01', 900, 'disponible', 'Revisar caducidades cada mes'],
                ['PLA', 'Botiquín de Primeros Auxilios', null, null, 'BK-PLA-01', 900, 'disponible', null],
            ];

            foreach ($equipos as [$sede, $tipo, $marca, $modelo, $serie, $costo, $estado, $observaciones]) {
                $equipo = new Equipo([
                    'sede_id' => $sedes[$sede]->id, 'tipo_equipo_id' => $tipos[$tipo], 'marca' => $marca, 'modelo' => $modelo,
                    'numero_serie' => $serie, 'costo' => $costo, 'observaciones' => $observaciones,
                ]);
                $equipo->forceFill(['estado' => $estado, 'creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            }

            // Una lámpara extraviada en Playa, con su voucher y cobro al responsable (si hay colaboradores)
            $lampara = Equipo::where('numero_serie', 'LT-0002')->firstOrFail();
            $responsable = Colaborador::where('num_empleado', '1003')->value('id');
            app(AdministradorEquipos::class)->darDeBaja($admin, $lampara, [
                'motivo' => 'extraviado',
                'descripcion' => 'Se quedó en la playa al terminar el rondín nocturno; no apareció al día siguiente.',
                'aplica_cobro' => $responsable !== null,
                'monto' => $responsable !== null ? '1650.00' : null,
                'colaborador_id' => $responsable,
            ]);
        }

        if (! ZonaEstacionamiento::exists()) {
            // [sede, nombre, tipo, cupo, activa]
            $zonas = [
                ['CEN', 'Estacionamiento Huéspedes', 'estacionamiento', 40, true],
                ['CEN', 'Estacionamiento Colaboradores', 'estacionamiento', 25, true],
                ['CEN', 'Andén de Almacén General', 'zona_descarga', null, true],
                ['PLA', 'Sótano 1A', 'estacionamiento', 8, true],
                ['PLA', 'Lobby', 'zona_descarga', null, true],
                ['PLA', 'Estacionamiento Temporal (obra)', 'estacionamiento', 10, false],
            ];
            foreach ($zonas as [$sede, $nombre, $tipo, $cupo, $activa]) {
                $zona = new ZonaEstacionamiento(['sede_id' => $sedes[$sede]->id, 'nombre' => $nombre, 'tipo' => $tipo, 'cupo_total' => $cupo]);
                $zona->forceFill(['activo' => $activa, 'creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            }
        }
    }

    /**
     * Rutas de transporte de ejemplo, solo la primera vez: por sede, dos
     * llegadas y dos salidas con los turnos y el transporte de personal demo,
     * paraderos en colonias de Cancún, un horario que cruza la medianoche y
     * una ruta suspendida. Se dan de alta con las mismas reglas de la
     * pantalla (AdministradorRutas), como si las capturara admin.demo.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function rutasDemo($sedes, User $admin): void
    {
        if (Ruta::exists()) {
            return;
        }

        $turno = fn (string $nombre) => Turno::where('nombre', $nombre)->where('activo', true)->value('id') ?? Turno::where('activo', true)->orderBy('id')->value('id');
        $proveedor = fn (array $nombres, array $categorias) => Proveedor::whereIn('nombre', $nombres)->where('activo', true)->value('id')
            ?? Proveedor::where('activo', true)->whereIn('categoria', $categorias)->where('todas_las_sedes', true)->orderBy('id')->value('id');
        $personal = $proveedor(['Transportes Kin-Ha'], ['transporte_personal', 'transportadora']);
        $shuttle = $proveedor(['Shuttle Riviera'], ['transporte_huespedes', 'transporte_personal']) ?? $personal;
        if ($turno('Matutino') === null || $personal === null) {
            return; // sin turnos ni transportista no hay rutas que mostrar
        }

        $paradas = fn (array $lista) => array_map(fn ($p) => ['nombre' => $p[0], 'hora' => $p[1]], $lista);
        $laborales = ['LU', 'MA', 'MI', 'JU', 'VI'];

        // sede => [paraderos extra (sin ruta), rutas [sentido, nombre, turno, proveedor, costo taxi, activa, horarios]]
        $plan = [
            'CEN' => [['AV. TALLERES'], [
                ['llegada', 'RUTA 1 - REGIÓN 94', 'Matutino', $personal, 250, true, [
                    ['Lunes a viernes', $laborales, '05:45', '06:40', $paradas([['REGIÓN 94 (CRUCERO)', '05:45'], ['SUPERMANZANA 63 (MERCADO 28)', '06:00'], ['AV. KABAH CON LEONA VICARIO', '06:10'], ['CHEDRAUI PORTILLO', '06:20']])],
                    ['Fin de semana', ['SA', 'DO'], '06:15', '07:00', $paradas([['REGIÓN 94 (CRUCERO)', '06:15'], ['CHEDRAUI PORTILLO', '06:35']])],
                ]],
                ['llegada', 'RUTA 2 - KABAH', 'Vespertino', $personal, null, true, [
                    ['Todos los días', [], '13:25', '14:45', $paradas([['AV. KABAH CON LEONA VICARIO', '13:25'], ['PLAZA LAS AMÉRICAS', '13:50'], ['SUPERMANZANA 63 (MERCADO 28)', '14:05']])],
                ]],
                ['salida', 'RUTA 1 - REGIÓN 94', 'Matutino', $personal, 250, true, [
                    ['Todos los días', [], '15:10', '16:05', $paradas([['CHEDRAUI PORTILLO', '15:35'], ['SUPERMANZANA 63 (MERCADO 28)', '15:45'], ['REGIÓN 94 (CRUCERO)', '16:05']])],
                ]],
                // Cruza la medianoche: sale 23:20 y llega 00:30 del día siguiente
                ['salida', 'RUTA 3 - NOCTURNA KABAH', 'Vespertino', $shuttle, 300, true, [
                    ['Todos los días', [], '23:20', '00:30', $paradas([['PLAZA LAS AMÉRICAS', '23:40'], ['AV. KABAH CON LEONA VICARIO', '23:55'], ['REGIÓN 94 (CRUCERO)', '00:30']])],
                ]],
                ['llegada', 'RUTA 9 - TEMPORADA ALTA', 'Matutino', $shuttle, null, false, [
                    ['Sábados', ['SA'], '06:30', '07:10', $paradas([['PLAZA LAS AMÉRICAS', '06:30']])],
                ]],
            ]],
            'PLA' => [['MERCADO 23'], [
                ['llegada', 'RUTA 1 - BONFIL', 'Matutino', $personal, 280, true, [
                    ['Todos los días', [], '06:00', '06:50', $paradas([['BONFIL (GLORIETA)', '06:00'], ['VILLAS OTOCH', '06:15'], ['AV. TULUM CON COBÁ', '06:35']])],
                ]],
                ['llegada', 'RUTA 2 - CIELO NUEVO', 'Mixto Playa', $shuttle, null, true, [
                    ['Lunes a sábado', [...$laborales, 'SA'], '09:00', '09:50', $paradas([['CIELO NUEVO (COPPEL)', '09:00'], ['VILLAS OTOCH', '09:15'], ['AV. TULUM CON COBÁ', '09:30']])],
                ]],
                ['salida', 'RUTA 1 - BONFIL', 'Matutino', $personal, 280, true, [
                    ['Todos los días', [], '15:10', '16:00', $paradas([['AV. TULUM CON COBÁ', '15:30'], ['VILLAS OTOCH', '15:45'], ['BONFIL (GLORIETA)', '16:00']])],
                ]],
                ['salida', 'RUTA 2 - CIELO NUEVO', 'Mixto Playa', $shuttle, null, true, [
                    ['Lunes a sábado', [...$laborales, 'SA'], '18:15', '19:05', $paradas([['AV. TULUM CON COBÁ', '18:30'], ['CIELO NUEVO (COPPEL)', '19:05']])],
                ]],
            ]],
        ];

        $rutas = app(AdministradorRutas::class);
        // Las reglas de la pantalla registran autor y auditoría con el usuario en sesión
        $previo = auth()->user();
        auth()->setUser($admin);
        try {
            foreach ($plan as $codigo => [$extras, $lista]) {
                $sede = $sedes[$codigo];
                foreach ($lista as [$sentido, $nombre, $nombreTurno, $proveedorId, $costo, $activa, $horarios]) {
                    $ruta = $rutas->crear($admin, $sede, [
                        'sentido' => $sentido, 'nombre' => $nombre, 'turno_id' => $turno($nombreTurno),
                        'proveedor_id' => $proveedorId, 'costo_maximo_taxi' => $costo,
                        'horarios' => array_map(fn ($h) => ['nombre' => $h[0], 'dias' => $h[1], 'hora_inicio' => $h[2], 'hora_fin' => $h[3], 'paraderos' => $h[4]], $horarios),
                    ]);
                    if (! $activa) {
                        $rutas->cambiarEstado($admin, $ruta, false);
                    }
                }
                foreach ($extras as $extra) {
                    $rutas->crearParadero($admin, $sede, $extra);
                }
            }
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Préstamo de llaves y Responsivas de ejemplo, cada uno solo la primera
     * vez: llaves fuera y devueltas (una anulada por captura equivocada) y
     * resguardos de equipo en campo y uno ya recibido, con firma. Se capturan
     * con las mismas reglas de la pantalla, como agente.demo en Centro y
     * admin.demo en Playa.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function prestamosYResponsivasDemo($sedes, User $admin, User $agente): void
    {
        $colaborador = fn (string $num) => Colaborador::where('num_empleado', $num)->value('id');
        $llave = fn (string $nomenclatura) => Llave::where('nomenclatura', $nomenclatura)->where('activo', true)->value('id');
        $equipo = fn (string $serie) => Equipo::where('numero_serie', $serie)->where('estado', 'disponible')->value('id');
        $previo = auth()->user();

        try {
            if (! PrestamoLlave::exists() && Llave::exists() && Colaborador::exists()) {
                $prestamos = app(AdministradorPrestamosLlaves::class);
                // [actor, sede, llave, colaborador, garantía, folio, hace (minutos), qué pasa después]
                $plan = [
                    [$agente, 'CEN', 'HDC-TA-ZONA', '1006', 'ine', 'INE-0457', 1500, 'recibir'],
                    [$agente, 'CEN', 'HDC-P1-AMA', '1007', 'gafete_interno', null, 190, 'anular'],
                    [$agente, 'CEN', 'HDC-MASTER-01', '1005', 'ine', 'INE-8841', 150, null],
                    [$agente, 'CEN', 'HDC-BOD-01', '1009', 'gafete_interno', 'DEPTO AMA DE LLAVES', 45, null],
                    [$admin, 'PLA', 'HDP-MASTER-01', '1011', 'licencia', null, 2900, 'recibir'],
                    [$admin, 'PLA', 'HDP-ALBERCA', '1008', 'ninguna', null, 30, null],
                ];
                foreach ($plan as [$actor, $sede, $nomenclatura, $num, $garantia, $folio, $hace, $despues]) {
                    $llaveId = $llave($nomenclatura);
                    $colaboradorId = $colaborador($num);
                    if ($llaveId === null || $colaboradorId === null) {
                        continue;
                    }
                    auth()->setUser($actor);
                    $p = $prestamos->prestar($actor, ['sede_id' => $sedes[$sede]->id, 'llave_id' => $llaveId, 'colaborador_id' => $colaboradorId,
                        'tipo_garantia' => $garantia, 'folio_garantia' => $folio]);
                    $p->forceFill(['prestado_en' => now()->subMinutes($hace)])->save();
                    if ($despues === 'recibir') {
                        $prestamos->recibir($actor, $p);
                        $p->forceFill(['devuelto_en' => now()->subMinutes((int) ($hace * 0.6))])->save();
                    } elseif ($despues === 'anular') {
                        // Anular es de supervisión (el Agente no anula): lo hace admin.demo
                        auth()->setUser($admin);
                        $prestamos->anular($admin, $p);
                    }
                }
            }

            if (! Responsiva::exists() && Equipo::exists() && Colaborador::exists() && function_exists('imagecreatetruecolor')) {
                $responsivas = app(AdministradorResponsivas::class);
                // [actor, sede, colaborador, [serie => modalidad], hace (minutos), recibir]
                $plan = [
                    [$agente, 'CEN', '1005', ['DM-1187' => 'asignado'], 2000, true],
                    [$agente, 'CEN', '1003', ['752TSFQ504' => 'prestado', 'LT-0001' => 'prestado'], 120, false],
                    [$admin, 'PLA', '1004', ['752TSFQ610' => 'prestado', 'CH-002' => 'asignado'], 300, false],
                ];
                foreach ($plan as $n => [$actor, $sede, $num, $equipos, $hace, $recibir]) {
                    $ids = array_filter(array_map(fn ($serie) => $equipo($serie), array_keys($equipos)));
                    $colaboradorId = $colaborador($num);
                    if (count($ids) !== count($equipos) || $colaboradorId === null) {
                        continue;
                    }
                    auth()->setUser($actor);
                    $r = $responsivas->crear($actor, ['sede_id' => $sedes[$sede]->id, 'colaborador_id' => $colaboradorId,
                        'equipos' => array_values($ids), 'modalidades' => array_values($equipos), 'firma' => $this->firmaDemo($n)]);
                    $r->forceFill(['entregado_en' => now()->subMinutes($hace)])->save();
                    if ($recibir) {
                        $responsivas->recibir($actor, $r);
                        $r->forceFill(['devuelto_en' => now()->subMinutes((int) ($hace * 0.4))])->save();
                    }
                }
            }
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Firma de ejemplo (un trazo a mano alzada), como la deja el recuadro de firma.
     */
    private function firmaDemo(int $semilla): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $tinta = imagecolorallocate($img, 15, 23, 42);
        imagesetthickness($img, 3);
        $x = 60;
        $y = 120;
        for ($i = 0; $i < 90; $i++) {
            $nx = $x + 5;
            $ny = (int) (110 + sin(($i + $semilla * 7) / 4) * 45 + cos(($i + $semilla) / 9) * 18);
            imageline($img, $x, $y, $nx, $ny, $tinta);
            [$x, $y] = [$nx, $ny];
        }
        imageline($img, 80, 165, 520, 160, $tinta);
        ob_start();
        imagejpeg($img, null, 70);
        $binario = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,'.base64_encode($binario);
    }
}
