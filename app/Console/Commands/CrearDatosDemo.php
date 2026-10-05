<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\Turno;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vehiculo;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Plataforma\ProvisionarEmpresa;
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
            $cuenta->fill([
                'empresa_id' => $empresa->id,
                'name' => $nombre,
                'email' => $usuario.'@demo.local',
                'password' => $contrasena,
                'activo' => true,
            ])->save();

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
        $tenant->conEmpresa($empresa->id, fn () => $this->vehiculosDemo(User::where('username', 'admin.demo')->firstOrFail()));

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
}
