<?php

namespace App\Console\Commands;

use App\Models\Acceso;
use App\Models\AccidenteFirma;
use App\Models\AcompananteAcceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\EtiquetaPlantilla;
use App\Models\Gafete;
use App\Models\GrupoEspacio;
use App\Models\ImpresionEtiquetas;
use App\Models\Llave;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundReportePerdida;
use App\Models\MovimientoTransporte;
use App\Models\Notificacion;
use App\Models\Novedad;
use App\Models\PaseSalida;
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
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\Kiosco;
use App\Services\Equipos\AdministradorEquipos;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Llaves\AdministradorLlaves;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Novedades\ArchivoLostFound;
use App\Services\Novedades\Formatos\RecorridoPc;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\RecorridosPc\AdministradorRecorridosPc;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Services\Rutas\AdministradorRutas;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Empresa ficticia con dos sedes y un usuario por cada rol, para probar en QA
 * lo que ve y puede hacer cada perfil. Nunca corre en Produccion.
 */
class CrearDatosDemo extends Command
{
    public const EMPRESA = 'Hotel Demo';

    /** Ambientes donde se permite crear el demo */
    public const AMBIENTES = ['local', 'testing', 'qa'];

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
        // Seguridad: lista de ambientes permitidos (no "todo menos production"): un
        // APP_ENV mal escrito en Produccion ("prod", "produccion") no crea cuentas demo
        if (! app()->environment(self::AMBIENTES)) {
            $this->error('Los datos demo solo se crean en '.implode(', ', self::AMBIENTES).' (este ambiente: '.app()->environment().').');

            return self::FAILURE;
        }

        $contrasena = $this->option('password') ?? (getenv('PLATAFORMA_CONTRASENA') ?: null);
        // Sin contraseña se puede completar un demo que ya existe: las cuentas
        // nuevas (p. ej. rh.demo) reciben la misma contraseña que admin.demo.
        $hashExistente = $contrasena === null
            ? User::whereIn('username', array_keys(self::USUARIOS))->orderByRaw("username = 'admin.demo' desc")->value('password')
            : null;
        if (($contrasena === null || strlen($contrasena) < 8) && $hashExistente === null) {
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
                    'password' => $contrasena ?? Str::random(40),
                    'activo' => true,
                ])->save();
                if ($contrasena === null) {
                    // El hash ya viene calculado: se escribe tal cual, sin volver a cifrarlo
                    DB::table('users')->where('id', $cuenta->id)->update(['password' => $hashExistente]);
                }
                $this->line("Usuario demo creado: {$usuario}");
            }

            $rolId = Rol::where('empresa_id', $empresa->id)->where('nombre', $rol)->value('id');
            if ($rolId === null && ($plantilla = Rol::plantillas()->with('permisos')->where('nombre', $rol)->first()) !== null) {
                // Rol base agregado después de crear la empresa demo (p. ej. Recursos Humanos)
                $rolId = $provisionar->copiarPlantillaSiFalta($plantilla, $empresa->id)?->id;
            }
            if ($rolId === null) {
                $this->warn("La empresa demo no tiene el rol «{$rol}»: {$usuario} quedó sin rol.");

                continue;
            }
            UsuarioRol::firstOrCreate(['user_id' => $cuenta->id, 'rol_id' => $rolId], [
                'sede_id' => $sede === null ? null : $sedes[$sede]->id,
            ]);
        }

        // Cada parte del demo va por separado: si una falla (por ejemplo, con
        // datos que alguien ya modificó en QA), las demás se completan igual.
        $fallas = [];
        $paso = function (string $nombre, callable $fn) use ($tenant, $empresa, &$fallas) {
            try {
                $tenant->conEmpresa($empresa->id, $fn);
            } catch (\Throwable $e) {
                $fallas[] = $nombre;
                $this->warn("No se pudo completar {$nombre}: ".$e->getMessage());
            }
        };

        $paso('espaciosDemo', fn () => $this->espaciosDemo($sedes['CEN'], User::where('username', 'admin.demo')->firstOrFail()));
        $paso('departamentosYPuestosDemo', fn () => $this->departamentosYPuestosDemo($sedes['PLA']));
        $paso('turnosDemo', fn () => $this->turnosDemo($sedes['PLA']));
        $paso('colaboradoresDemo', fn () => $this->colaboradoresDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('provisionalesDemo', fn () => $this->provisionalesDemo($sedes, User::where('username', 'agente.demo')->firstOrFail()));
        $paso('proveedoresDemo', fn () => $this->proveedoresDemo($sedes));
        // Padrón de personas: después de los proveedores (si existen) para ligar a su personal
        $paso('personasDemo', fn () => $this->personasDemo(User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'jefe.demo')->firstOrFail()));
        $paso('vehiculosDemo', fn () => $this->vehiculosDemo(User::where('username', 'admin.demo')->firstOrFail()));
        $paso('llavesDemo', fn () => $this->llavesDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('gafetesDemo', fn () => $this->gafetesDemo($empresa, $sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('equiposYEstacionamientosDemo', fn () => $this->equiposYEstacionamientosDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('rutasDemo', fn () => $this->rutasDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('accesosDemo', fn () => $this->accesosDemo($empresa, $sedes, User::where('username', 'jefe.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));
        $paso('prestamosYResponsivasDemo', fn () => $this->prestamosYResponsivasDemo($sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));
        $paso('novedadesDemo', fn () => $this->novedadesDemo($sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail(), User::where('username', 'agente2.demo')->firstOrFail()));
        $paso('pasesSalidaDemo', fn () => $this->pasesSalidaDemo($sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));
        $paso('transporteDemo', fn () => $this->transporteDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('borradoDemo', fn () => $this->borradoDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('lostFoundRoboDemo', fn () => $this->lostFoundRoboDemo($sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));
        $paso('recorridosPcDemo', fn () => $this->recorridosPcDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('ronda5Demo', fn () => $this->ronda5Demo($empresa, $sedes['CEN'], User::where('username', 'admin.demo')->firstOrFail()));
        $paso('altasPorVerificarDemo', fn () => $this->altasPorVerificarDemo($sedes, User::where('username', 'agente.demo')->firstOrFail(), User::where('username', 'admin.demo')->firstOrFail()));
        $paso('procedimientosDemo', fn () => $this->procedimientosDemo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('ronda5bDemo', fn () => $this->ronda5bDemo($sedes['CEN'], User::where('username', 'admin.demo')->firstOrFail()));
        $paso('ronda6Demo', fn () => $this->ronda6Demo($sedes, User::where('username', 'admin.demo')->firstOrFail()));
        $paso('recepcionDemo', fn () => $this->recepcionDemo($empresa, $sedes, User::where('username', 'admin.demo')->firstOrFail(), User::where('username', 'rh.demo')->firstOrFail(), User::where('username', 'jefe.demo')->firstOrFail(), User::where('username', 'agente.demo')->firstOrFail()));
        $paso('ronda7Demo', fn () => $this->ronda7Demo($sedes, User::where('username', 'admin.demo')->firstOrFail()));

        if ($fallas !== []) {
            $this->warn('Partes del demo sin completar: '.implode(', ', $fallas).'.');
        }
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
     * Bitácora de accesos de ejemplo, solo la primera vez: gente en sitio
     * (colaboradores, visitas con gafete y acompañantes, huéspedes con auto en
     * el estacionamiento, una huésped fuera en tour, un contratista con un
     * ayudante que salió por material), proveedores pendientes de autorizar
     * y visitas finalizadas hoy y ayer (incluida una emergencia y un tour
     * completo). Usa los colaboradores, personas, vehículos, proveedores,
     * gafetes y zonas demo si existen; si no, deja los datos como texto.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function accesosDemo(Empresa $empresa, $sedes, User $jefe, User $agente): void
    {
        if (Acceso::exists()) {
            return;
        }

        // Hora local de Cancún de hoy (o de hace N días), guardada en hora universal
        $a = fn (string $hora, int $diasAtras = 0) => Carbon::now('America/Cancun')->subDays($diasAtras)->setTimeFromTimeString($hora)->utc();
        $colaborador = fn (string $num) => Colaborador::where('num_empleado', $num)->first();
        $persona = fn (string $nombre) => Persona::where('nombre_completo', $nombre)->first();
        $vehiculo = fn (string $placas) => Vehiculo::where('placas', Vehiculo::normalizarPlacas($placas))->first();
        $proveedor = fn (string $nombre) => Proveedor::where('nombre', $nombre)->first();
        $zonaDe = fn (string $sede, string $nombre) => ZonaEstacionamiento::where('sede_id', $sedes[$sede]->id)->where('nombre', $nombre)->where('activo', true)->value('id');
        $gafete = fn (string $sede, string $tipo, int $n) => Gafete::where('activo', true)
            ->where('nomenclatura', AdministradorGafetes::prefijo($empresa->nombre_comercial, $sedes[$sede]->codigo, $tipo).str_pad((string) $n, 3, '0', STR_PAD_LEFT))->first();
        $nombreDe = fn ($c, string $texto) => $c ? mb_strtoupper($c->nombreCompleto()) : $texto;
        $placasDe = fn ($v, string $texto) => $v?->placas ?? Vehiculo::normalizarPlacas($texto);

        $crear = function (string $sede, array $datos, $entrada, User $autor, array $extra = []) use ($sedes): Acceso {
            $acceso = new Acceso($datos + ['sede_id' => $sedes[$sede]->id, 'entrada_at' => $entrada]);
            $acceso->forceFill($extra + ['creado_por' => $autor->id, 'actualizado_por' => $autor->id])->save();

            return $acceso;
        };
        $acompanante = function (Acceso $acceso, string $nombre, ?string $identificacion = null, ?Gafete $g = null, array $extra = []): void {
            $ac = new AcompananteAcceso(['acceso_id' => $acceso->id, 'nombre' => $nombre, 'identificacion' => $identificacion, 'gafete_id' => $g?->id, 'gafete_texto' => $g?->nomenclatura]);
            $ac->forceFill($extra + ['creado_por' => $acceso->creado_por, 'actualizado_por' => $acceso->creado_por])->save();
        };

        // ---------------- En sitio: Hotel Demo Centro ----------------
        $roberto = $colaborador('1005');
        $crear('CEN', ['tipo' => 'colaborador', 'nombre' => $nombreDe($roberto, 'ROBERTO HERNÁNDEZ CRUZ'), 'colaborador_id' => $roberto?->id, 'modo_arribo' => 'a_pie'], $a('06:52'), $agente);

        $carlos = $colaborador('1006');
        $pickup = $vehiculo('VPK-77-12');
        $crear('CEN', ['tipo' => 'colaborador', 'nombre' => $nombreDe($carlos, 'CARLOS PÉREZ GÓMEZ'), 'colaborador_id' => $carlos?->id, 'modo_arribo' => 'auto',
            'vehiculo_id' => $pickup?->id, 'placas' => $placasDe($pickup, 'VPK-77-12'), 'zona_estacionamiento_id' => $zonaDe('CEN', 'Estacionamiento Colaboradores')], $a('07:10'), $agente);

        $mariana = $colaborador('1007');
        $g1 = $gafete('CEN', 'Visitante', 1);
        $visita = $crear('CEN', ['tipo' => 'visitante', 'nombre' => 'LAURA MÉNDEZ RÍOS', 'persona_id' => $persona('Laura Méndez Ríos')?->id, 'identificacion' => 'ine',
            'motivo_visita' => 'colaborador', 'visita_colaborador_id' => $mariana?->id, 'persona_visita' => $nombreDe($mariana, 'MARIANA LÓPEZ PECH'),
            'gafete_id' => $g1?->id, 'gafete_texto' => $g1?->nomenclatura, 'modo_arribo' => 'a_pie', 'num_acompanantes' => 1], $a('09:40'), $agente);
        $acompanante($visita, 'SOFÍA MÉNDEZ', 'ine', $gafete('CEN', 'Visitante', 2));

        $jetta = $vehiculo('QRR 44 10');
        $g3 = $gafete('CEN', 'Visitante', 3);
        $crear('CEN', ['tipo' => 'visitante', 'nombre' => 'RICARDO ORTEGA VELA', 'persona_id' => $persona('Ricardo Ortega Vela')?->id, 'identificacion' => 'pasaporte',
            'motivo_visita' => 'rh', 'gafete_id' => $g3?->id, 'gafete_texto' => $g3?->nomenclatura, 'modo_arribo' => 'auto', 'vehiculo_id' => $jetta?->id,
            'placas' => $placasDe($jetta, 'QRR4410'), 'zona_estacionamiento_id' => $zonaDe('CEN', 'Estacionamiento Huéspedes')], $a('10:05'), $jefe);

        $turquesa = $proveedor('Viajes Turquesa');
        $versa = $vehiculo('ABC-123-A');
        $leticia = $crear('CEN', ['tipo' => 'huesped', 'nombre' => 'LETICIA VÁZQUEZ', 'habitacion' => '204', 'tiene_reserva' => true, 'numero_reserva' => '1234567',
            'tipo_pase' => 'estancia', 'proveedor_id' => $turquesa?->id, 'empresa_procedencia' => $turquesa ? 'VIAJES TURQUESA' : null, 'modo_arribo' => 'auto',
            'vehiculo_id' => $versa?->id, 'placas' => $placasDe($versa, 'ABC123A'), 'zona_estacionamiento_id' => $zonaDe('CEN', 'Estacionamiento Huéspedes'),
            'num_acompanantes' => 2], $a('08:30', 2), $agente);
        $acompanante($leticia, 'JORGE VÁZQUEZ');
        $acompanante($leticia, 'MARIO VÁZQUEZ');

        // Huésped fuera en tour desde las 10:30
        $ayleen = $crear('CEN', ['tipo' => 'huesped', 'nombre' => 'AYLEEN PÉREZ', 'habitacion' => '310', 'tiene_reserva' => true, 'numero_reserva' => '1234569',
            'tipo_pase' => 'estancia', 'modo_arribo' => 'a_pie'], $a('16:20', 1), $agente);
        $crear('CEN', ['tipo' => 'huesped', 'movimiento' => 'salida_temporal', 'acceso_origen_id' => $ayleen->id, 'nombre' => 'AYLEEN PÉREZ', 'habitacion' => '310',
            'modo_arribo' => 'auto', 'placas' => 'TUR450', 'conductor' => 'GUÍA TOURS DEL CARIBE'], $a('10:30'), $agente);

        // Pendientes de autorización
        $abarrotes = $proveedor('Abarrotes del Caribe');
        $camion = $vehiculo('UPS-03-CL');
        $gp = $gafete('CEN', 'Proveedor', 1);
        $crear('CEN', ['tipo' => 'proveedor', 'estado' => 'pendiente', 'nombre' => 'JESÚS BALAM TUN', 'persona_id' => $persona('Jesús Balam Tun')?->id,
            'proveedor_id' => $abarrotes?->id, 'empresa_procedencia' => 'ABARROTES DEL CARIBE', 'host_colaborador_id' => $carlos?->id, 'identificacion' => 'licencia',
            'gafete_id' => $gp?->id, 'gafete_texto' => $gp?->nomenclatura, 'tipo_visita' => 'ejecucion',
            'departamento_id' => Departamento::where('nombre', 'Alimentos y Bebidas')->value('id'), 'area_trabajo' => 'ANDÉN DE ALMACÉN',
            'actividad' => 'Entrega de abarrotes de la semana.', 'modo_arribo' => 'auto', 'vehiculo_id' => $camion?->id, 'placas' => $placasDe($camion, 'UPS03CL'),
            'zona_estacionamiento_id' => $zonaDe('CEN', 'Andén de Almacén General')], $a('11:15'), $agente);

        $patricia = $crear('CEN', ['tipo' => 'contratista', 'estado' => 'pendiente', 'nombre' => 'PATRICIA GÓMEZ SOSA', 'persona_id' => $persona('Patricia Gómez Sosa')?->id,
            'empresa_procedencia' => 'FUMIGACIONES PENINSULARES', 'host_colaborador_id' => $colaborador('1009')?->id, 'identificacion' => 'ine', 'tipo_visita' => 'levantamiento',
            'area_trabajo' => 'HABITACIONES PISO 3', 'actividad' => 'Recorrido para cotizar el control de plagas.', 'modo_arribo' => 'a_pie', 'num_acompanantes' => 1], $a('11:40'), $agente);
        $acompanante($patricia, 'LUIS SOSA', 'ine');

        // ---------------- En sitio: Hotel Demo Playa ----------------
        $daniela = $colaborador('1008');
        $crear('PLA', ['tipo' => 'colaborador', 'nombre' => $nombreDe($daniela, 'DANIELA CANUL MAY'), 'colaborador_id' => $daniela?->id, 'modo_arribo' => 'a_pie'], $a('06:58'), $agente);

        $maya = $proveedor('Constructora Maya');
        $gc1 = $gafete('PLA', 'Contratista', 1);
        $contratista = $crear('PLA', ['tipo' => 'contratista', 'nombre' => 'JOSÉ LUIS EK CAUICH', 'persona_id' => $persona('José Luis Ek Cauich')?->id,
            'proveedor_id' => $maya?->id, 'empresa_procedencia' => $maya ? 'CONSTRUCTORA MAYA' : 'CONSTRUCCIONES Y MANTENIMIENTO MAYA',
            'host_colaborador_id' => $colaborador('1011')?->id, 'identificacion' => 'ine', 'gafete_id' => $gc1?->id, 'gafete_texto' => $gc1?->nomenclatura,
            'tipo_visita' => 'ejecucion', 'area_trabajo' => 'LOBBY', 'actividad' => 'Remodelación del lobby (fase 2).', 'modo_arribo' => 'a_pie', 'num_acompanantes' => 1],
            $a('08:05'), $agente, ['autorizado_at' => $a('08:12'), 'autorizado_por' => $jefe->id]);
        // Su ayudante salió por material y aún no regresa (su gafete sigue reservado)
        $acompanante($contratista, 'ANDRÉS HERRERA KÚ', 'ine', $gafete('PLA', 'Contratista', 2), ['salida_temporal_at' => $a('11:00'), 'salida_temporal_por' => $agente->id]);

        // ---------------- Finalizados (hoy y ayer) ----------------
        $g4 = $gafete('CEN', 'Visitante', 4);
        $crear('CEN', ['tipo' => 'visitante', 'estado' => 'finalizado', 'nombre' => 'SOFÍA CASTILLO UC', 'persona_id' => $persona('Sofía Castillo Uc')?->id,
            'identificacion' => 'ine', 'motivo_visita' => 'rh', 'gafete_id' => $g4?->id, 'gafete_texto' => $g4?->nomenclatura, 'modo_arribo' => 'a_pie'],
            $a('08:15'), $agente, ['salida_at' => $a('09:05'), 'salida_por' => $agente->id]);

        $crear('CEN', ['tipo' => 'emergencia', 'estado' => 'finalizado', 'nombre' => 'CRUZ ROJA UNIDAD 12', 'tipo_emergencia' => 'ambulancia', 'modo_arribo' => 'auto',
            'placas' => 'CR012', 'observaciones' => 'Traslado de un huésped con malestar desde el lobby.'], $a('22:10', 1), $jefe, ['salida_at' => $a('22:55', 1), 'salida_por' => $jefe->id]);

        $crear('CEN', ['tipo' => 'proveedor', 'estado' => 'finalizado', 'nombre' => 'FERNANDO RIVAS LEÓN', 'persona_id' => $persona('Fernando Rivas León')?->id,
            'proveedor_id' => $proveedor('Transportes Kin-Ha')?->id, 'empresa_procedencia' => 'TRANSPORTES KIN-HA', 'host_colaborador_id' => $carlos?->id,
            'identificacion' => 'licencia', 'tipo_visita' => 'cortesia', 'modo_arribo' => 'a_pie'],
            $a('06:00', 1), $agente, ['autorizado_at' => $a('06:04', 1), 'autorizado_por' => $jefe->id, 'salida_at' => $a('06:20', 1), 'salida_por' => $agente->id]);

        // Huésped de ayer con un tour completo (salió y regresó) y su salida final
        $pamela = $crear('PLA', ['tipo' => 'huesped', 'estado' => 'finalizado', 'nombre' => 'PAMELA ALCOCER', 'tiene_reserva' => false, 'tipo_pase' => 'daypass', 'modo_arribo' => 'a_pie'],
            $a('10:00', 1), $agente, ['salida_at' => $a('18:30', 1), 'salida_por' => $agente->id]);
        $crear('PLA', ['tipo' => 'huesped', 'movimiento' => 'salida_temporal', 'acceso_origen_id' => $pamela->id, 'estado' => 'finalizado', 'nombre' => 'PAMELA ALCOCER',
            'conductor' => 'TOURS XCARET EXPRESS'], $a('12:00', 1), $agente, ['salida_at' => $a('14:30', 1), 'salida_por' => $agente->id]);
        $crear('PLA', ['tipo' => 'huesped', 'movimiento' => 'regreso', 'acceso_origen_id' => $pamela->id, 'estado' => 'finalizado', 'nombre' => 'PAMELA ALCOCER',
            'conductor' => 'TOURS XCARET EXPRESS'], $a('14:30', 1), $agente, ['salida_at' => $a('14:30', 1), 'salida_por' => $agente->id]);
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
     * Pases de salida de ejemplo, solo la primera vez: el circuito de
     * aprobación de la empresa demo (1. Jefe de Seguridad → rol "Jefe de
     * seguridad", 2. Contraloría → director.demo, 3. Gerencia → rol
     * "Administrador", solo en venta, traspaso definitivo y consignación) y un
     * pase en cada estado: pendiente en el paso 1 (bandeja de jefe.demo),
     * pendiente en Gerencia (bandeja de admin.demo), rechazado, cancelado,
     * aprobado, salió cerrado, en camino a la otra sede, en destino, en
     * tránsito de regreso, regreso parcial, regresado y uno vencido. Se
     * registran y firman con las mismas reglas de la pantalla; las firmas son
     * trazos de ejemplo. admin.demo deja guardada su firma.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function pasesSalidaDemo($sedes, User $admin, User $agente): void
    {
        if (PaseSalida::exists() || ! function_exists('imagecreatetruecolor')) {
            return;
        }
        $colaborador = fn (string $num) => Colaborador::where('num_empleado', $num)->value('id');
        $proveedor = fn (string $nombre) => Proveedor::where('nombre', $nombre)->where('activo', true)->value('id');
        $jefe = User::where('username', 'jefe.demo')->first();
        $director = User::where('username', 'director.demo')->first();
        if ($colaborador('1007') === null || $proveedor('Constructora Maya') === null || $jefe === null || $director === null) {
            return; // sin colaboradores, proveedores ni aprobadores demo no hay con quién armar el circuito
        }

        $pases = app(AdministradorPasesSalida::class);
        $previo = auth()->user();
        try {
            auth()->setUser($admin);
            $rol = fn (string $nombre) => Rol::where('empresa_id', $admin->empresa_id)->where('nombre', $nombre)->value('id');
            $pases->circuito()->guardar($admin, ['pasos' => [
                ['nombre' => 'Jefe de Seguridad', 'tipo' => 'rol', 'rol_id' => $rol('Jefe de seguridad'), 'departamento' => 'cualquiera', 'obligatorio' => 1],
                ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$director->id], 'departamento' => 'cualquiera', 'obligatorio' => 1],
                ['nombre' => 'Gerencia', 'tipo' => 'rol', 'rol_id' => $rol('Administrador'), 'departamento' => 'cualquiera', 'obligatorio' => 1,
                    'motivos' => ['venta', 'traspaso_definitivo', 'consignacion']],
            ]]);
            $pases->guardarFirmaUsuario($admin, $this->firmaDemo(99));

            $hoy = now('America/Cancun');
            $dia = fn (int $dias) => $hoy->copy()->addDays($dias)->format('Y-m-d');
            $radio = Equipo::where('numero_serie', '752TSFQ505')->first();
            $aprobadores = [$jefe, $director, $admin];
            $semilla = 0;
            $firma = function () use (&$semilla) {
                return $this->firmaDemo(++$semilla);
            };
            $aprobar = function (PaseSalida $pase, int $cuantos) use ($pases, $aprobadores, $firma) {
                for ($k = 0; $k < $cuantos; $k++) {
                    $pase->refresh()->load('aprobaciones');
                    $actual = $pases->circuito()->actual($pase);
                    if ($actual === null) {
                        return;
                    }
                    $quien = $aprobadores[$actual->orden - 1] ?? end($aprobadores);
                    auth()->setUser($quien);
                    $pases->aprobar($quien, $pase, ['aprobacion_id' => $actual->id, 'firma_modo' => 'nueva', 'firma' => $firma(),
                        'comentario' => $k === 0 ? 'Revisado, adelante.' : null]);
                }
            };
            $paso = function (PaseSalida $pase, User $quien, string $persona, array $extra = []) use ($pases, $firma) {
                $pase->refresh()->load('articulos');
                auth()->setUser($quien);
                $pases->registrarPaso($quien, $pase, $extra + [
                    'paso' => $pase->pasoFisico(), 'persona_nombre' => $persona, 'firma_persona' => $firma(), 'firma_modo' => 'nueva', 'firma' => $firma(),
                    'verificados' => $pase->articulos->pluck('id')->all(),
                    'escaneados' => $pase->articulos->whereNotNull('equipo_id')->pluck('id')->all(),
                    'regresa' => $pase->articulos->mapWithKeys(fn ($a) => [$a->id => $a->pendientes()])->all(),
                ]);
            };
            $crear = function (User $autor, array $datos, array $articulos) use ($pases) {
                auth()->setUser($autor);

                return $pases->crear($autor, $datos + ['articulos' => $articulos]);
            };

            // 1. Pendiente en el paso 1: espera a jefe.demo
            $crear($agente, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1007'), 'destino_tipo' => 'sede', 'sede_destino_id' => $sedes['PLA']->id,
                'fecha_salida_programada' => $dia(1), 'fecha_tentativa_regreso' => $dia(8)],
                [['cantidad' => 1, 'equipo' => 'Proyector', 'marca' => 'EPSON', 'modelo' => 'PowerLite X49', 'serie' => 'X49-55821', 'descripcion' => 'Con cable HDMI y control remoto, para el evento de capacitación']]);

            // 2. Venta pendiente en Gerencia: espera a admin.demo
            $venta = $crear($agente, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'venta', 'colaborador_id' => $colaborador('1002') ?? $colaborador('1007'), 'destino_tipo' => 'proveedor',
                'proveedor_id' => $proveedor('Abarrotes del Caribe') ?? $proveedor('Constructora Maya'),
                'destino_direccion' => 'Av. Andrés Quintana Roo 45, Cancún', 'destino_telefono' => '9988841020', 'fecha_salida_programada' => $dia(0)],
                [['cantidad' => 4, 'equipo' => 'Refrigerador exhibidor', 'marca' => 'IMBERA', 'modelo' => 'VR-17', 'descripcion' => 'Usados, de la tienda del lobby']]);
            $aprobar($venta, 2);

            // 3. Rechazado en Contraloría: devuelto al solicitante
            $rechazado = $crear($admin, ['sede_id' => $sedes['PLA']->id, 'motivo' => 'reparacion', 'colaborador_id' => $colaborador('1011'), 'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor('Constructora Maya'),
                'destino_direccion' => 'Calle 20 Sur 110, Cancún', 'destino_telefono' => '9981234567', 'fecha_salida_programada' => $dia(0), 'fecha_tentativa_regreso' => $dia(12)],
                [['cantidad' => 1, 'equipo' => 'Podadora', 'marca' => 'HONDA', 'modelo' => 'HRX217', 'serie' => 'HRX-20981', 'descripcion' => 'No arranca']]);
            $aprobar($rechazado, 1);
            auth()->setUser($director);
            $pases->rechazar($director, $rechazado->refresh(), ['motivo_rechazo' => 'Falta la cotización del proveedor: adjúntala y reenvía.']);

            // 4. Cancelado por quien lo registró
            $cancelado = $crear($agente, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1009'), 'destino_tipo' => 'colaborador', 'colaborador_destino_id' => $colaborador('1009'),
                'fecha_salida_programada' => $dia(0), 'fecha_tentativa_regreso' => $dia(2)],
                [['cantidad' => 1, 'equipo' => 'Escalera de aluminio', 'marca' => 'CUPRUM', 'descripcion' => '6 peldaños']]);
            auth()->setUser($agente);
            $pases->cancelar($agente, $cancelado->refresh(), ['motivo_cancelacion' => 'Ya no se necesita: la prestaron en el edificio.']);

            // 5. Aprobado, listo para salir
            $listo = $crear($admin, ['sede_id' => $sedes['PLA']->id, 'motivo' => 'reparacion', 'colaborador_id' => $colaborador('1011'), 'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor('Constructora Maya'),
                'destino_direccion' => 'Calle 20 Sur 110, Cancún', 'destino_telefono' => '9981234567', 'fecha_salida_programada' => $dia(0), 'fecha_tentativa_regreso' => $dia(10)],
                [['cantidad' => 1, 'equipo' => 'Taladro rotomartillo', 'marca' => 'DEWALT', 'modelo' => 'DCD996', 'serie' => 'DW-778120', 'descripcion' => 'No gira el mandril']]);
            $aprobar($listo, 2);

            // 6. Traspaso definitivo: salió y quedó cerrado
            $traspaso = $crear($admin, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'traspaso_definitivo', 'colaborador_id' => $colaborador('1009'), 'destino_tipo' => 'sede', 'sede_destino_id' => $sedes['PLA']->id,
                'fecha_salida_programada' => $dia(-2)],
                [['cantidad' => 1, 'equipo' => 'Lavadora industrial', 'marca' => 'SPEED QUEEN', 'modelo' => 'SC40', 'serie' => 'SQ-40-1187', 'descripcion' => 'Pasa a la lavandería de Playa']]);
            $aprobar($traspaso, 3);
            $paso($traspaso, $agente, 'GUADALUPE CHAN EK');

            // 7. Salió hacia Playa: falta confirmar la llegada (radio escaneado del padrón)
            $enCamino = $crear($agente, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1005'), 'destino_tipo' => 'sede', 'sede_destino_id' => $sedes['PLA']->id,
                'fecha_salida_programada' => $dia(-1), 'fecha_tentativa_regreso' => $dia(5)],
                [$radio ? ['cantidad' => 1, 'equipo' => 'Radio de Comunicación', 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'serie' => '752TSFQ505', 'equipo_id' => $radio->id, 'descripcion' => 'Con cargador']
                    : ['cantidad' => 1, 'equipo' => 'Radio de Comunicación', 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'serie' => '752TSFQ505']]);
            $aprobar($enCamino, 2);
            $paso($enCamino, $agente, 'ROBERTO HERNÁNDEZ CRUZ');

            // 8. En destino (Centro recibió las aspiradoras de Playa)
            $enDestino = $crear($admin, ['sede_id' => $sedes['PLA']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1008'), 'destino_tipo' => 'sede', 'sede_destino_id' => $sedes['CEN']->id,
                'fecha_salida_programada' => $dia(-4), 'fecha_tentativa_regreso' => $dia(3)],
                [['cantidad' => 2, 'equipo' => 'Aspiradora', 'marca' => 'KÄRCHER', 'modelo' => 'NT 30/1', 'descripcion' => 'Apoyo por la limpieza profunda de Centro']]);
            $aprobar($enDestino, 2);
            $paso($enDestino, $admin, 'DANIELA CANUL MAY');
            $paso($enDestino, $agente, 'DANIELA CANUL MAY');

            // 9. En tránsito de regreso (consignación: 3 aprobaciones)
            $transito = $crear($admin, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'consignacion', 'colaborador_id' => $colaborador('1007'), 'destino_tipo' => 'sede', 'sede_destino_id' => $sedes['PLA']->id,
                'fecha_salida_programada' => $dia(-6), 'fecha_tentativa_regreso' => $dia(1)],
                [['cantidad' => 1, 'equipo' => 'Carpa plegable 3x3', 'marca' => 'TRUPER', 'descripcion' => 'Color blanco, con bolsa'], ['cantidad' => 6, 'equipo' => 'Silla plegable', 'descripcion' => 'Negras']]);
            $aprobar($transito, 3);
            $paso($transito, $agente, 'MARIANA LÓPEZ PECH');
            $paso($transito, $admin, 'MARIANA LÓPEZ PECH');
            $paso($transito, $admin, 'MARIANA LÓPEZ PECH');

            // 10. Regreso parcial: regresaron 2 de 4 radios portátiles
            $parcial = $crear($admin, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1006'), 'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor('Constructora Maya'),
                'destino_direccion' => 'Calle 20 Sur 110, Cancún', 'fecha_salida_programada' => $dia(-3), 'fecha_tentativa_regreso' => $dia(4)],
                [['cantidad' => 4, 'equipo' => 'Radio portátil', 'marca' => 'KENWOOD', 'modelo' => 'TK-3402', 'descripcion' => 'Para la obra de la alberca']]);
            $aprobar($parcial, 2);
            $paso($parcial, $agente, 'CARLOS PÉREZ GÓMEZ');
            $parcial->refresh()->load('articulos');
            $paso($parcial, $agente, 'CARLOS PÉREZ GÓMEZ', ['regresa' => [$parcial->articulos->first()->id => 2],
                'comentario' => 'El proveedor regresa los otros 2 el viernes.']);

            // 11. Regresado y cerrado (laptop bajo resguardo de un colaborador)
            $regresado = $crear($admin, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador('1006'), 'destino_tipo' => 'colaborador', 'colaborador_destino_id' => $colaborador('1005'),
                'fecha_salida_programada' => $dia(-9), 'fecha_tentativa_regreso' => $dia(-2)],
                [['cantidad' => 1, 'equipo' => 'Laptop', 'marca' => 'LENOVO', 'modelo' => 'L14', 'serie' => 'ABC123LATCAT', 'descripcion' => 'Bajo resguardo de Sistemas, para home office']]);
            $aprobar($regresado, 2);
            $paso($regresado, $agente, 'ROBERTO HERNÁNDEZ CRUZ');
            $paso($regresado, $agente, 'ROBERTO HERNÁNDEZ CRUZ');

            // 12. Vencido: debió regresar del taller hace 3 días
            $vencido = $crear($admin, ['sede_id' => $sedes['CEN']->id, 'motivo' => 'reparacion', 'colaborador_id' => $colaborador('1006'), 'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor('Constructora Maya'),
                'destino_direccion' => 'Calle 20 Sur 110, Cancún', 'destino_telefono' => '9981234567', 'fecha_salida_programada' => $dia(-10), 'fecha_tentativa_regreso' => $dia(-3)],
                [['cantidad' => 1, 'equipo' => 'Puerta corrediza de cristal', 'marca' => 'MITEL', 'modelo' => '989484', 'descripcion' => 'Riel dañado; la reparan en el taller del proveedor']]);
            $aprobar($vencido, 2);
            $paso($vencido, $agente, 'CARLOS PÉREZ GÓMEZ');
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Bitácora de Novedades: tickets de cada categoría en distintos estatus
     * (abierto, pendiente de turno, resuelto), un Accidente con sus 6 firmas,
     * un Siniestro que abrió solo su Accidente, Lost & Found con artículos y
     * un reporte de pérdida, y un Recorrido PC histórico. Solo la primera vez.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function novedadesDemo($sedes, User $admin, User $agente, User $agentePlaya): void
    {
        if (Novedad::exists()) {
            return;
        }

        $novedades = app(AdministradorNovedades::class);
        $centro = $sedes['CEN'];
        $zona = $centro->zonaHoraria();
        // Fecha y hora local de la sede de hace N horas (nunca en el futuro)
        $hace = fn (int $horas) => now($zona)->subHours($horas)->format('Y-m-d\TH:i');
        $espacio = fn (string $nombre) => Espacio::where('sede_id', $centro->id)->where('nombre', $nombre)->first();
        $torre = $espacio('Torre A');
        $piso1 = $espacio('Piso 1');
        $piso2 = $espacio('Piso 2');
        $area = fn (?Espacio $piso) => $piso === null ? [] : ['area_edificio_id' => $torre?->id, 'area_piso_id' => $piso->id];
        $hab = fn (string $nombre) => $espacio($nombre)?->id;
        $colaborador = fn (string $num) => Colaborador::where('num_empleado', $num)->value('id');
        // Firma de ejemplo: un trazo distinto por rol, JPEG ligero como el del recuadro
        $firma = function (int $n): string {
            $img = imagecreatetruecolor(600, 200);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            $tinta = imagecolorallocate($img, 15, 23, 42);
            imagesetthickness($img, 3);
            for ($x = 40; $x < 520; $x += 20) {
                imageline($img, $x, (int) (100 + 40 * sin(($x + $n * 37) / 35)), $x + 20, (int) (100 + 40 * sin(($x + 20 + $n * 37) / 35)), $tinta);
            }
            ob_start();
            imagejpeg($img, null, 70);

            return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        };

        $previo = auth()->user();
        $base = fn (array $extra) => $extra + ['estatus' => 'abierto'];
        $crear = function (User $actor, array $datos) use ($novedades) {
            auth()->setUser($actor);

            return $novedades->crear($actor, $datos);
        };
        $atender = function (User $actor, Novedad $n, array $datos) use ($novedades, $base) {
            auth()->setUser($actor);

            return $novedades->actualizar($actor, $n->fresh(), $base($datos + ['categoria' => $n->categoria]));
        };

        try {
            // 1. Reporte General abierto (lo observó el agente en su ronda)
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'incidente_general', 'reportado_por' => $agente->name, 'ubicacion' => 'Puerta de servicio, andén de carga',
                'descripcion' => 'Puerta de servicio abierta y sin vigilancia durante la ronda nocturna.', 'ocurrio_en' => $hace(3)] + $area($piso1));
            $atender($agente, $n, ['ig_observados' => 'Personal de proveedor de lavandería', 'ig_actividad' => 'Descarga de blancos', 'ig_motivo' => 'Llegaron fuera de horario',
                'ig_acciones' => 'Se cerró la puerta y se avisó al supervisor de turno.', 'nueva_nota' => 'Se revisaron cámaras del andén: sin incidentes adicionales.']);

            // 2. Accidente de huésped con dictamen y las 6 firmas, pendiente para el siguiente turno
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'accidente', 'reportado_por' => 'Mariana López Pech', 'reportado_colaborador_id' => $colaborador('1007'),
                'ubicacion' => 'Escaleras de emergencia', 'descripcion' => 'Huésped resbaló al bajar las escaleras y se lastimó el pie derecho.',
                'como_sucedio' => 'Bajaba corriendo y no vio el último escalón.', 'ocurrio_en' => $hace(20)] + $area($piso1));
            $atender($admin, $n, [
                'estatus' => 'pendiente_turno', 'area_especifica_id' => $hab('103'), 'acc_tipo_afectado' => 'HUESPED',
                'h_fecha_accidente' => now($zona)->subDay()->format('Y-m-d'), 'h_hora_accidente' => '18:20', 'h_nombre' => 'John Miller', 'h_hab' => '103',
                'h_agencia' => 'Expedia', 'h_pais' => 'Estados Unidos', 'h_sexo' => 'M', 'h_edad' => '46', 'h_lugar' => 'Escaleras de emergencia, Piso 1',
                'h_explicacion' => 'Pisó mal el último escalón; el piso estaba seco.', 'h_req_medico' => '1', 'h_motivo' => 'Dolor e inflamación en tobillo', 'h_testigos' => '0',
                'm_herida' => ['Contusa'], 'm_parte' => 'Pie Der', 'm_primeros_aux' => '1', 'm_primeros_cuales' => 'Hielo y vendaje', 'm_atencion_med' => '1',
                'm_atencion_cuales' => 'Valoración del médico de guardia', 'm_diagnostico' => 'Esguince leve de tobillo derecho.', 'm_hosp' => '0',
                'm_traslado' => 'No aplica', 'm_doctor' => 'Dra. Patricia Herrera', 'm_observaciones' => 'Reposo y revisión en 48 horas.',
                'nueva_nota' => 'Falta la firma de conformidad de la gerencia (pasa al siguiente turno).',
            ] + collect(array_keys(AccidenteFirma::rolesPara('HUESPED')))->mapWithKeys(fn ($rol, $i) => ['f_'.$rol => $firma($i)])->all());

            // 3. Accidente de colaboradora, resuelto con incapacidad de RH
            $n = $crear($admin, ['sede_id' => $centro->id, 'categoria' => 'accidente', 'reportado_por' => 'Guadalupe Chan Ek', 'reportado_colaborador_id' => $colaborador('1009'),
                'ubicacion' => 'Cuarto de blancos', 'descripcion' => 'Camarista se cortó la mano con un vidrio roto.', 'ocurrio_en' => $hace(150)] + $area($piso2));
            $atender($admin, $n, [
                'acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $colaborador('1009'), 'c_fecha_accidente' => now($zona)->subDays(6)->format('Y-m-d'),
                'c_hora_accidente' => '10:15', 'c_turno_colaborador' => 'Matutino', 'c_area_trabajo' => 'Cuarto de blancos', 'c_jefe' => 'Carlos Pérez Gómez',
                'c_puesto_jefe' => 'Jefe de Seguridad', 'c_primera_vez' => '1', 'c_causa_condicion' => '1', 'c_explicacion' => 'Vidrio roto dentro de una bolsa de blancos.',
                'acc_testigos' => [['nombre' => 'Rosa María Poot Uc', 'departamento' => 'Ama de Llaves']], 'c_aviso_por' => 'Guadalupe Chan Ek', 'c_depto_aviso' => 'Ama de Llaves',
                'c_actividades' => 'Clasificación de blancos', 'c_mismas_actividades' => '1', 'm_herida' => ['Cortante'], 'm_parte' => 'Mano Izq', 'm_primeros_aux' => '1',
                'm_primeros_cuales' => 'Limpieza y vendaje', 'm_atencion_med' => '0', 'm_hosp' => '0', 'rh_dias' => '2', 'rh_fecha' => now($zona)->subDays(3)->format('Y-m-d'),
                'estatus' => 'resuelto', 'resolucion' => 'Se reincorporó a sus labores; se reforzó el uso de guantes en el cuarto de blancos.',
            ]);

            // 4. Valores a la Vista en la 102, abierto
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'habitacion', 'reportado_por' => 'Guadalupe Chan Ek', 'reportado_colaborador_id' => $colaborador('1009'),
                'ubicacion' => 'Habitación 102', 'descripcion' => 'Habitación con puerta abierta y caja fuerte abierta con valores.', 'ocurrio_en' => $hace(2), 'asignado_a' => $agente->id] + $area($piso1));
            $atender($agente, $n, [
                'area_especifica_id' => $hab('102'), 'hab_id_area_especifica' => $hab('102'), 'hab_quien_reporta' => 'Guadalupe Chan Ek', 'hab_depto_reporta' => 'Ama de Llaves',
                'hab_puesto_reporta' => 'Camarista', 'hab_actividad_reporta' => 'Limpieza de rutina', 'hab_quien_atiende' => $agente->name, 'hab_depto_atiende' => 'Seguridad',
                'hab_puesto_atiende' => 'Agente de Seguridad', 'hab_personas' => [['nombre' => 'Rosa María Poot Uc', 'departamento' => 'Ama de Llaves', 'puesto' => 'Camarista', 'actividad' => 'Apoyo', 'se_retira' => '1']],
                'hab_aperturas' => [['tipo' => 'puerta', 'estado' => 'abierta', 'es_especial' => '0'], ['tipo' => 'terraza', 'estado' => 'cerrada', 'descripcion' => 'Balcón principal']],
                'hab_caja_estado' => 'abierta_con_valores', 'hab_caja_accion' => 'cerrada_bloqueada', 'hab_valores_dentro' => 'Pasaportes y efectivo en dólares.',
                'hab_valores' => [['zona' => 'Recámara', 'descripcion' => 'Reloj y cartera sobre el buró']], 'hab_personal_retiro' => 'si', 'hab_cliente_llega' => '0', 'hab_tomo_fotos' => 'si',
                'hab_observaciones_grales' => 'Se notificó al Gerente en Turno.', 'nueva_nota' => 'Se cerró la caja fuerte en presencia de la camarista.',
            ]);

            // 5. Siniestro de Protección Civil con un lesionado: abre solo su ticket de Accidente
            $n = $crear($admin, ['sede_id' => $centro->id, 'categoria' => 'proteccion_civil', 'reportado_por' => 'Javier Ramírez Soto',
                'ubicacion' => 'Cocina del restaurante', 'descripcion' => 'Conato de incendio en la campana de la cocina.', 'ocurrio_en' => $hace(50)] + $area($piso2));
            $atender($admin, $n, [
                'pc_tipo_evento' => 'CONATO DE INCENDIO', 'pc_fecha_control' => $hace(49), 'pc_alarma' => '1', 'pc_evacuacion' => '1', 'pc_num_evacuados' => '35',
                'pc_punto_reunion' => 'Estacionamiento norte', 'pc_servicios' => [0 => ['activo' => '1', 'hora' => '14:25'], 1 => ['activo' => '1', 'hora' => '14:30']],
                'pc_hubo_lesionados' => '1', 'pc_num_lesionados' => '1', 'siniestro_equipos' => [['identificador' => 'EXT-07', 'estado_uso' => 'utilizado']],
                'siniestro_danos' => [['zona' => 'Cocina', 'descripcion' => 'Campana y filtros dañados por humo']],
                'siniestro_testigos' => [['nombre' => 'Luis Fernando Díaz Kú', 'departamento' => 'Alimentos y Bebidas']],
                'pc_causa_probable' => 'Acumulación de grasa en los filtros.', 'pc_acciones_tomadas' => 'Se usó el extintor tipo K; se programó limpieza profunda.',
            ]);

            // 6. Lost & Found con artículos y un reporte de pérdida (para "Buscar Coincidencias")
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'lost_found', 'reportado_por' => 'Rosa María Poot Uc',
                'ubicacion' => 'Habitación 201', 'descripcion' => 'Objetos olvidados encontrados durante la limpieza.', 'ocurrio_en' => $hace(1)] + $area($piso2));
            $atender($agente, $n, [
                'area_especifica_id' => $hab('201'),
                'lf_articulos' => [
                    ['objeto' => 'Teléfono celular', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'Samsung', 'color' => 'Negro', 'area_especifica_id' => $hab('201'), 'ubicacion_bodega' => 'Bodega de Seguridad, Caja 1'],
                    ['objeto' => 'Sombrero', 'tipo_valor' => 'ROPA', 'color' => 'Beige', 'area_especifica_id' => $hab('201'), 'ubicacion_bodega' => 'Anaquel 3'],
                ],
                'rp_reportes' => [['objeto' => 'Teléfono', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'Samsung', 'color' => 'Negro', 'nombre_huesped' => 'Laura Gómez',
                    'area_especifica_id' => $hab('201'), 'fecha_aproximada' => now($zona)->subDay()->format('Y-m-d'), 'telefono' => '9981234567', 'descripcion' => 'Funda azul con iniciales L.G.']],
            ]);
            // El sombrero lleva 25 días en resguardo (semáforo "Por vencer")
            LostFoundArticulo::where('novedad_id', $n->id)->where('objeto', 'SOMBRERO')->update(['created_at' => now()->subDays(25)]);

            // 7. Robo abierto en la 202
            $n = $crear($admin, ['sede_id' => $centro->id, 'categoria' => 'robo', 'reportado_por' => 'Laura Gómez', 'ubicacion' => 'Habitación 202',
                'descripcion' => 'Huésped reporta que le robaron una laptop de la habitación.', 'ocurrio_en' => $hace(26)] + $area($piso2));
            $atender($admin, $n, [
                'area_especifica_id' => $hab('202'), 'robo_hora_aproximada' => '15:30', 'robo_lugar_exacto' => 'Escritorio de la habitación',
                'robo_objetos_descripcion' => 'Laptop Dell gris con estuche negro.', 'robo_valor_estimado' => '18000', 'robo_hay_sospechoso' => '0',
                'robo_testigos' => [['nombre' => 'Daniela Canul May', 'departamento' => 'Recepción', 'declaracion' => 'Vio salir a una persona con mochila negra.']],
                'robo_se_dio_parte_policia' => '1', 'robo_folio_policial' => 'FGE-2026-1458', 'robo_canalizado_gerencia' => '1',
                'robo_observaciones_investigacion' => 'Se solicitaron las grabaciones del pasillo del piso 2.',
            ]);

            // 8. Playa: uno sin clasificar (agente de playa) y un Reporte General resuelto
            // Ronda 8: ya no se despacha sin clasificar; queda como los tickets que se importen de SEGCAT
            $crear($agentePlaya, ['sede_id' => $sedes['PLA']->id, 'categoria' => 'incidente_general', 'reportado_por' => $agentePlaya->name,
                'ubicacion' => 'Cuarto de máquinas', 'descripcion' => 'Ruido extraño en el cuarto de máquinas de la alberca.', 'ocurrio_en' => $hace(4)])
                ->forceFill(['categoria' => 'sin_clasificar'])->saveQuietly();
            $n = $crear($admin, ['sede_id' => $sedes['PLA']->id, 'categoria' => 'incidente_general', 'reportado_por' => 'Daniela Canul May',
                'ubicacion' => 'Lobby', 'descripcion' => 'Vendedor ambulante dentro del lobby.', 'ocurrio_en' => $hace(75)]);
            $atender($admin, $n, ['ig_acciones' => 'Se le pidió retirarse con cortesía.', 'estatus' => 'resuelto', 'resolucion' => 'La persona se retiró sin incidentes.']);

            // 9. Recorrido PC histórico (ya no se crea desde aquí; viene de SEGCAT)
            $n = $crear($admin, ['sede_id' => $centro->id, 'categoria' => 'incidente_general', 'reportado_por' => $admin->name, 'ubicacion' => 'Torre A', 'descripcion' => 'Recorrido de inspección de extintores (histórico).',
                'ocurrio_en' => $hace(240)]);
            $n->forceFill(['categoria' => 'recorrido_pc'])->save();
            $atender($admin, $n, ['rpc_puntos' => [
                ['identificador' => 'EXT-01', 'categoria' => 'EXTINTOR', 'edificio' => 'Torre A', 'nivel' => 'Piso 1', 'area' => 'Pasillo',
                    'criterios' => array_fill_keys(array_keys(RecorridoPc::piezas('EXTINTOR')), '1')],
                ['identificador' => 'EXT-02', 'categoria' => 'EXTINTOR', 'edificio' => 'Torre A', 'nivel' => 'Piso 2', 'area' => 'Elevadores',
                    'criterios' => ['cilindro' => '1', 'manguera' => '1'], 'observaciones' => 'Manómetro en zona roja.'],
            ]]);

            // 10. Más casos en otros estatus: Lost & Found y Valores a la Vista resueltos, Robo pendiente de turno
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'lost_found', 'reportado_por' => 'Guadalupe Chan Ek',
                'ubicacion' => 'Habitación 104', 'descripcion' => 'Lentes de sol olvidados en el buró.', 'ocurrio_en' => $hace(96)] + $area($piso1));
            $atender($agente, $n, ['area_especifica_id' => $hab('104'), 'lf_articulos' => [['objeto' => 'Lentes de sol', 'tipo_valor' => 'OTRO', 'marca' => 'Ray-Ban',
                'color' => 'Negro', 'area_especifica_id' => $hab('104'), 'ubicacion_bodega' => 'Anaquel 1']],
                'estatus' => 'resuelto', 'resolucion' => 'El huésped pasó por ellos a recepción antes de su salida.']);
            $n = $crear($agente, ['sede_id' => $centro->id, 'categoria' => 'habitacion', 'reportado_por' => 'Rosa María Poot Uc',
                'ubicacion' => 'Habitación 101', 'descripcion' => 'Caja fuerte abierta sin valores al hacer la limpieza.', 'ocurrio_en' => $hace(120)] + $area($piso1));
            $atender($agente, $n, ['area_especifica_id' => $hab('101'), 'hab_id_area_especifica' => $hab('101'), 'hab_quien_reporta' => 'Rosa María Poot Uc',
                'hab_quien_atiende' => $agente->name, 'hab_caja_estado' => 'abierta_sin_valores', 'hab_personal_retiro' => 'si', 'hab_tomo_fotos' => 'no',
                'estatus' => 'resuelto', 'resolucion' => 'Se cerró la caja fuerte y se avisó a recepción.']);
            $n = $crear($agentePlaya, ['sede_id' => $sedes['PLA']->id, 'categoria' => 'robo', 'reportado_por' => 'Huésped camastro 12',
                'ubicacion' => 'Playa, zona de camastros', 'descripcion' => 'Huésped reporta que le tomaron una bolsa de playa mientras nadaba.', 'ocurrio_en' => $hace(6)]);
            $atender($agentePlaya, $n, ['robo_objetos_descripcion' => 'Bolsa de playa azul con toalla y bloqueador.', 'robo_hay_sospechoso' => '0',
                'estatus' => 'pendiente_turno', 'nueva_nota' => 'Se revisarán las cámaras de la palapa con el siguiente turno.']);
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

    /**
     * Bitácora de transporte de ejemplo, solo la primera vez: una semana de
     * llegadas y salidas de las rutas demo (con algunos retrasos) registradas
     * por los agentes de cada sede, y dos fallas de fletera con taxis: una con
     * un taxi dentro del tope (ya autorizado) y otra con dos taxis, uno arriba
     * del tope con su justificación (pendiente de Vo.Bo.). Se capturan con las
     * mismas reglas de la pantalla (BitacoraTransporte).
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function transporteDemo($sedes, User $admin): void
    {
        if (MovimientoTransporte::exists() || ! Ruta::exists()) {
            return;
        }

        $bitacora = app(BitacoraTransporte::class);
        $agentes = ['CEN' => User::where('username', 'agente.demo')->first() ?? $admin, 'PLA' => User::where('username', 'agente2.demo')->first() ?? $admin];
        // Una firma de ejemplo (garabato) si el servidor tiene GD; si no, los vales quedan para firmar a mano
        $firma = null;
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(600, 200);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            $tinta = imagecolorallocate($img, 15, 23, 42);
            imagesetthickness($img, 3);
            for ($x = 60, $y = 120; $x < 520; $x += 20) {
                $ny = 70 + (int) (60 * abs(sin($x / 37)));
                imageline($img, $x, $y, $x + 20, $ny, $tinta);
                $y = $ny;
            }
            ob_start();
            imagejpeg($img, null, 70);
            $firma = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        }
        $conFirmas = $firma !== null;

        $unidades = [
            'CEN' => [['TKH-101', 'autobus', 'MERCEDES-BENZ', 'BOXER OF', '101', 40, 'JUAN CARLOS POOT CHAN'], ['SHR-033', 'van', 'TOYOTA', 'HIACE', '33', 15, 'MARIO EK CANUL']],
            'PLA' => [['TKH-205', 'autobus', 'VOLVO', '7300', '205', 45, 'RAÚL CHI DZIB'], ['SHR-041', 'van', 'NISSAN', 'URVAN', '41', 15, 'MARIO EK CANUL']],
        ];

        $previo = auth()->user();
        try {
            foreach (['CEN', 'PLA'] as $codigo) {
                $sede = $sedes[$codigo];
                $agente = $agentes[$codigo];
                auth()->setUser($agente);
                $horarios = $bitacora->horariosPara([$sede->id]);
                // Cada transportista con su propia unidad y chofer
                $transportistas = $horarios->pluck('ruta.proveedor_id')->unique()->values();
                $ahora = $bitacora->ahoraEn($sede);
                $n = 0;
                for ($dias = 6; $dias >= 0; $dias--) {
                    $dia = $ahora->startOfDay()->subDays($dias);
                    foreach ($horarios as $h) {
                        if (! $h->aplicaEn($dia->dayOfWeekIso)) {
                            continue;
                        }
                        $cuando = $dia->setTimeFromTimeString($bitacora->horaDeCaseta($h));
                        if ($cuando->gt($ahora)) {
                            continue;
                        }
                        $n++;
                        $retraso = $n % 5 === 0;
                        $u = $unidades[$codigo][min(1, (int) $transportistas->search($h->ruta->proveedor_id))];
                        $m = $bitacora->registrar($agente, [
                            'sede_id' => $sede->id, 'tipo_movimiento' => $h->ruta->sentido, 'estatus' => $retraso ? 'retraso' : 'a_tiempo',
                            'ruta_horario_id' => $h->id, 'placas' => $u[0], 'tipo_unidad' => $u[1], 'marca' => $u[2], 'modelo' => $u[3],
                            'economico' => $u[4], 'capacidad' => $u[5], 'chofer' => $u[6], 'cantidad_pax' => 8 + ($n * 7) % 25,
                            'observaciones' => $retraso ? 'Tráfico en Av. Kabah.' : null,
                        ], false, null)->first();
                        $this->fechaDemo($m, $cuando->addMinutes($retraso ? 25 : 3));
                    }
                }
            }

            // Fallas de fletera (Centro)
            $sede = $sedes['CEN'];
            $agente = $agentes['CEN'];
            auth()->setUser($agente);
            $pasajeros = Colaborador::where('sede_id', $sede->id)->where('activo', true)->whereNull('fusionado_en_id')->orderBy('num_empleado')->pluck('id')->all();
            $horarios = $bitacora->horariosPara([$sede->id]);
            $llegada = $horarios->first(fn ($h) => $h->ruta->sentido === 'llegada' && $h->ruta->costo_maximo_taxi !== null);
            $salida = $horarios->first(fn ($h) => $h->ruta->sentido === 'salida' && $h->ruta->costo_maximo_taxi !== null);
            if ($pasajeros === [] || $llegada === null || $salida === null) {
                return;
            }
            $ahora = $bitacora->ahoraEn($sede);
            $taxi = fn (array $datos) => $datos + ['tipo' => 'sedan', 'firma' => $firma];

            $vales = $bitacora->registrar($agente, [
                'sede_id' => $sede->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego', 'ruta_horario_id' => $llegada->id,
                'firma_guardia' => $firma, 'observaciones' => 'La unidad de la fletera se descompuso en el crucero de la Región 94.',
                'taxis' => [$taxi(['placas' => 'TX-2301', 'chofer' => 'ALBERTO CANCHÉ MAY', 'monto' => '180', 'destino' => $llegada->paradas()->with('paradero')->first()?->paradero?->nombre ?? 'REGIÓN 94 (CRUCERO)',
                    'marca' => 'NISSAN', 'modelo' => 'VERSA', 'economico' => 'T-230', 'chofer_telefono' => '9981234567', 'pasajeros' => array_slice($pasajeros, 0, 3)])],
            ], $conFirmas, null);
            $this->fechaDemo($vales->first(), $ahora->subDays(2)->setTimeFromTimeString($llegada->fin())->addMinutes(20));
            auth()->setUser($admin);
            $bitacora->autorizar($admin, $vales->first());
            $vales->first()->forceFill(['autorizado_en' => $ahora->subDays(2)->setTime(18, 5)->utc()])->saveQuietly();
            auth()->setUser($agente);

            $tope = (float) $salida->ruta->costo_maximo_taxi;
            $vales = $bitacora->registrar($agente, [
                'sede_id' => $sede->id, 'tipo_movimiento' => 'salida', 'estatus' => 'no_llego', 'ruta_horario_id' => $salida->id,
                'firma_guardia' => $firma, 'observaciones' => 'La fletera no envió unidad. Se despacharon dos taxis.',
                'taxis' => [
                    $taxi(['placas' => 'TX-4410', 'chofer' => 'PEDRO PECH UC', 'monto' => (string) ($tope + 70), 'destino' => 'REGIÓN 94 (CRUCERO)',
                        'justificacion' => 'Lluvia intensa: ninguna plataforma tenía tarifa menor y el personal salía de turno.', 'tipo' => 'suv', 'marca' => 'KIA', 'modelo' => 'SPORTAGE',
                        'pasajeros' => array_slice($pasajeros, 0, 2)]),
                    $taxi(['placas' => 'TX-1187', 'chofer' => 'ALBERTO CANCHÉ MAY', 'monto' => '200', 'destino' => 'CHEDRAUI PORTILLO', 'pasajeros' => array_slice($pasajeros, 2, 3)]),
                ],
            ], $conFirmas, null);
            foreach ($vales as $vale) {
                $this->fechaDemo($vale, $ahora->subDay()->setTimeFromTimeString($salida->inicio())->addMinutes(15));
            }
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    private function fechaDemo(MovimientoTransporte $m, CarbonImmutable $cuando): void
    {
        $m->forceFill(['fecha' => $cuando->toDateString(), 'created_at' => $cuando->utc(), 'updated_at' => $cuando->utc()])->saveQuietly();
    }

    /**
     * Lost & Found (archivo) y Robo — Seguimiento, solo la primera vez: el
     * teléfono vinculado al reporte de pérdida de Laura Gómez (listo para
     * entregar), unos lentes ya devueltos con firma, artículos de alberca con
     * el semáforo en rojo y ámbar (una gorra donada a un colaborador), un
     * ticket sin artículos capturados y un robo con sospechoso sin parte a la
     * policía.
     */
    private function lostFoundRoboDemo($sedes, User $admin, User $agente): void
    {
        if (LostFoundEntrega::exists() || ! LostFoundArticulo::exists()) {
            return;
        }

        $novedades = app(AdministradorNovedades::class);
        $archivo = app(ArchivoLostFound::class);
        $centro = $sedes['CEN'];
        $zona = $centro->zonaHoraria();
        $hace = fn (int $horas) => now($zona)->subHours($horas)->format('Y-m-d\\TH:i');
        $previo = auth()->user();

        try {
            // 1. El teléfono encontrado en la 201 es el que reportó Laura Gómez
            $telefono = LostFoundArticulo::where('objeto', 'TELÉFONO CELULAR')->first();
            $reporte = LostFoundReportePerdida::where('estatus', LostFoundReportePerdida::BUSCANDO)->where('nombre_huesped', 'LAURA GÓMEZ')->first();
            if ($telefono !== null && $reporte !== null) {
                auth()->setUser($admin);
                $novedades->vincularPerdida($admin, $reporte->load('novedad'), $telefono);
            }

            // 2. Los lentes de sol ya se devolvieron al huésped, con su firma
            $lentes = LostFoundArticulo::where('objeto', 'LENTES DE SOL')->where('estatus', LostFoundArticulo::EN_RESGUARDO)->first();
            if ($lentes !== null) {
                auth()->setUser($agente);
                $archivo->cerrar($agente, $lentes, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'externo', 'nombre_recibe' => 'Mark Johnson',
                    'tipo_identificacion' => 'PASAPORTE', 'correo_recibe' => 'mark.johnson@example.com', 'firma' => $this->firmaDemo(11),
                    'observaciones' => 'Pasó a recepción antes de su salida.']);
            }

            // 3. Objetos de la alberca: semáforo en rojo y ámbar; la gorra se donó a un colaborador
            auth()->setUser($agente);
            $n = $novedades->crear($agente, ['sede_id' => $centro->id, 'categoria' => 'lost_found', 'reportado_por' => 'Salvavidas de turno',
                'ubicacion' => 'Alberca principal', 'descripcion' => 'Objetos olvidados en los camastros de la alberca.', 'ocurrio_en' => $hace(3)]);
            $novedades->actualizar($agente, $n->fresh(), ['categoria' => 'lost_found', 'estatus' => 'abierto', 'lf_articulos' => [
                ['objeto' => 'Bufanda', 'tipo_valor' => 'ROPA', 'color' => 'Roja', 'lugar_detalle' => 'Camastro 8', 'ubicacion_bodega' => 'Anaquel 3'],
                ['objeto' => 'Gorra', 'tipo_valor' => 'ROPA', 'marca' => 'Nike', 'color' => 'Azul', 'lugar_detalle' => 'Camastro 3', 'ubicacion_bodega' => 'Anaquel 3'],
                ['objeto' => 'Audífonos inalámbricos', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'JBL', 'color' => 'Blanco', 'lugar_detalle' => 'Bar de la alberca', 'ubicacion_bodega' => 'Bodega de Seguridad, Caja 2'],
                ['objeto' => 'Termo', 'tipo_valor' => 'OTRO', 'color' => 'Verde', 'lugar_detalle' => 'Regaderas', 'ubicacion_bodega' => 'Anaquel 1'],
            ]]);
            foreach (['BUFANDA' => 40, 'GORRA' => 35, 'AUDÍFONOS INALÁMBRICOS' => 130, 'TERMO' => 10] as $objeto => $dias) {
                LostFoundArticulo::where('novedad_id', $n->id)->where('objeto', $objeto)->update(['created_at' => now()->subDays($dias)]);
            }
            $gorra = LostFoundArticulo::where('novedad_id', $n->id)->where('objeto', 'GORRA')->first();
            $colaborador = Colaborador::where('activo', true)->whereNull('fusionado_en_id')->where('sede_id', $centro->id)->where('provisional', false)->orderBy('id')->first();
            if ($gorra !== null && $colaborador !== null) {
                $archivo->cerrar($agente, $gorra, ['tipo_cierre' => 'DONADO', 'colaborador_id' => $colaborador->id, 'firma' => $this->firmaDemo(12),
                    'observaciones' => 'Venció su tiempo de resguardo; autorizó el jefe de seguridad.']);
            }

            // 4. Un ticket de Lost & Found al que todavía no le capturan los objetos
            $novedades->crear($agente, ['sede_id' => $centro->id, 'categoria' => 'lost_found', 'reportado_por' => 'Recepción',
                'ubicacion' => 'Lobby', 'descripcion' => 'Un huésped entregó una bolsa con objetos encontrados en el lobby.', 'ocurrio_en' => $hace(2)]);

            // 5. Robo con sospechoso y sin parte a la policía (para los filtros de Robo — Seguimiento)
            auth()->setUser($admin);
            $robo = $novedades->crear($admin, ['sede_id' => $centro->id, 'categoria' => 'robo', 'reportado_por' => 'Carlos Pérez',
                'ubicacion' => 'Alberca principal', 'descripcion' => 'Huésped reporta que le sacaron la cartera de su mochila en el camastro.', 'ocurrio_en' => $hace(20)]);
            $novedades->actualizar($admin, $robo->fresh(), ['categoria' => 'robo', 'estatus' => 'abierto', 'robo_hora_aproximada' => '13:15',
                'robo_lugar_exacto' => 'Camastro 5', 'robo_objetos_descripcion' => 'Cartera café de piel con identificaciones y $2,000 en efectivo.',
                'robo_valor_estimado' => '2500', 'robo_hay_sospechoso' => '1', 'robo_descripcion_sospechoso' => 'Hombre de gorra negra y playera blanca, no es huésped.',
                'robo_se_dio_parte_policia' => '0', 'robo_testigos' => [['nombre' => 'Salvavidas de turno', 'departamento' => 'Recreación', 'declaracion' => 'Vio a una persona revisar mochilas.']],
                'nueva_nota' => 'Se pidió a Seguridad revisar las cámaras de la alberca.']);
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Recorridos de Protección Civil, solo la primera vez: catálogo de equipos
     * en las dos sedes (con su ubicación en la Torre A de Centro) y cuatro
     * recorridos hechos con las mismas reglas de la pantalla: uno COMPLETO,
     * uno CON HALLAZGOS (abre su ticket en la Bitácora de Novedades), uno EN
     * PROCESO para continuarlo y uno de Playa.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function recorridosPcDemo($sedes, User $admin): void
    {
        if (EquipoPc::exists()) {
            return;
        }

        $centro = $sedes['CEN'];
        $espacio = fn (string $nombre) => Espacio::where('sede_id', $centro->id)->where('nombre', $nombre)->value('id');
        // [sede, categoría, ID, ubicación, referencia, activo, etiqueta NFC]
        $catalogo = [
            ['CEN', 'EXTINTOR', 'EXT-01', 'Piso 1', 'Junto al elevador', true, '04:A2:3B:1C:5D:80:01'],
            ['CEN', 'EXTINTOR', 'EXT-02', 'Piso 2', 'Pasillo de habitaciones', true, null],
            ['CEN', 'EXTINTOR', 'EXT-03', 'Torre A', 'Cocina principal', true, null],
            ['CEN', 'HIDRANTE', 'HID-01', 'Piso 1', 'Gabinete frente a recepción', true, null],
            ['CEN', 'DETECTOR_HUMO', 'DH-101', '101', null, true, null],
            ['CEN', 'DETECTOR_HUMO', 'DH-201', '201', null, true, null],
            ['CEN', 'SALIDA_EMERGENCIA', 'SAL-01', 'Piso 1', 'Escalera de emergencia norte', true, null],
            ['CEN', 'BOTIQUIN', 'BOT-01', 'Torre A', 'Caseta de seguridad', true, null],
            ['CEN', 'LAMPARA_EMERGENCIA', 'LAM-01', 'Piso 2', 'Salida de escalera', true, null],
            ['CEN', 'TABLERO_ELECTRICO', 'TAB-01', 'Torre A', 'Cuarto de máquinas', true, null],
            ['CEN', 'GAS_LP', 'GAS-01', null, 'Patio de servicio', true, null],
            ['CEN', 'EXTINTOR', 'EXT-99', null, 'Retirado: se envió a recarga', false, null],
            ['PLA', 'EXTINTOR', 'EXT-P01', null, 'Lobby, junto a recepción', true, null],
            ['PLA', 'HIDRANTE', 'HID-P01', null, 'Acceso a la playa', true, null],
            ['PLA', 'BOTIQUIN', 'BOT-P01', null, 'Caseta de playa', true, null],
        ];
        $equipos = [];
        foreach ($catalogo as [$sede, $categoria, $serie, $lugar, $referencia, $activo, $nfc]) {
            $e = new EquipoPc(['sede_id' => $sedes[$sede]->id, 'categoria' => $categoria, 'numero_serie' => $serie,
                'espacio_id' => $lugar !== null && $sede === 'CEN' ? $espacio($lugar) : null, 'referencia' => $referencia, 'etiqueta_nfc' => $nfc]);
            $e->forceFill(['activo' => $activo, 'creado_por' => $admin->id, 'actualizado_por' => $admin->id])->save();
            $equipos[$serie] = $e;
        }

        $servicio = app(AdministradorRecorridosPc::class);
        $agente = User::where('username', 'agente.demo')->first() ?? $admin;
        $agentePlaya = User::where('username', 'agente2.demo')->first() ?? $admin;
        $ahora = CarbonImmutable::now($centro->zonaHoraria());
        // Todas las piezas sanas, menos las indicadas
        $sano = fn (string $serie, array $malas = []) => array_fill_keys(array_diff(array_keys(EquipoPc::criterios($equipos[$serie]->categoria)), $malas), '1');
        $previo = auth()->user();

        $recorrido = function (User $actor, Sede $sede, ?string $zona, ?string $obs, array $puntos, bool $finalizar, CarbonImmutable $cuando) use ($servicio, $equipos, $sano, $espacio) {
            auth()->setUser($actor);
            $r = $servicio->iniciar($actor, ['sede_id' => $sede->id, 'espacio_id' => $zona !== null ? $espacio($zona) : null, 'observaciones_generales' => $obs]);
            $minuto = 0;
            foreach ($puntos as [$serie, $malas, $observaciones]) {
                $p = $servicio->registrarPunto($actor, $r->fresh(), ['equipo_pc_id' => $equipos[$serie]->id, 'criterios' => $sano($serie, $malas), 'observaciones' => $observaciones]);
                $p->forceFill(['created_at' => $cuando->addMinutes($minuto += 4)->utc(), 'updated_at' => $cuando->addMinutes($minuto)->utc()])->saveQuietly();
            }
            $r = $r->fresh();
            if ($finalizar) {
                $r = $servicio->guardar($actor, $r, ['observaciones_generales' => $obs], true);
                $r->forceFill(['finalizado_en' => $cuando->addMinutes($minuto + 3)->utc()])->saveQuietly();
            }
            $r->forceFill(['created_at' => $cuando->utc(), 'updated_at' => $cuando->addMinutes($minuto + 3)->utc()])->saveQuietly();
            if ($r->novedad_id !== null) {
                Novedad::whereKey($r->novedad_id)->update(['created_at' => $cuando->addMinutes(8)->utc(), 'updated_at' => $cuando->addMinutes(8)->utc(), 'ocurrio_en' => $cuando->addMinutes(8)->utc()]);
            }

            return $r;
        };

        try {
            $recorrido($agente, $centro, 'Torre A', null, [
                ['EXT-01', [], null], ['EXT-02', [], null], ['HID-01', [], null], ['SAL-01', [], null],
            ], true, $ahora->subDays(2)->setTime(7, 10));
            $recorrido($agente, $centro, 'Torre A', 'Ronda matutina. Se avisó a mantenimiento de los hallazgos.', [
                ['EXT-01', [], null],
                ['EXT-02', ['manometro', 'precinto'], 'Manómetro en zona roja y precinto roto.'],
                ['DH-101', [], null],
                ['LAM-01', ['foco_izq'], 'Faro izquierdo fundido.'],
            ], true, $ahora->subDay()->setTime(7, 5));
            $recorrido($agente, $centro, null, null, [
                ['EXT-01', [], null], ['BOT-01', [], null],
            ], false, $ahora->subMinutes(40));
            $recorrido($agentePlaya, $sedes['PLA'], null, null, [
                ['EXT-P01', [], null], ['BOT-P01', [], null],
            ], true, $ahora->subDay()->setTime(8, 20));
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Eliminar definitivamente: registros "capturados por error" que nadie usa,
     * para probar el borrado (los demás datos demo sí tienen historial y solo
     * se pueden dar de baja). Solo la primera vez: si ya existen o ya se
     * eliminó alguno, no se vuelven a crear.
     */
    private function borradoDemo($sedes, User $admin): void
    {
        // Clases por nombre: así este bloque no toca la lista de "use" (compartida con otros módulos)
        [$tipoGafete, $paradero] = ['App\Models\TipoGafete', 'App\Models\Paradero'];
        if (Departamento::where('nombre', 'Compras (duplicado)')->exists()
            || DB::table('auditoria')->where('evento', 'like', '%.eliminado_definitivo')->exists()) {
            return;
        }

        $previo = auth()->user();
        auth()->setUser($admin);
        try {
            Departamento::create(['nombre' => 'Compras (duplicado)']);
            Puesto::create(['nombre' => 'Puesto de prueba', 'tipo' => Puesto::OPERATIVO]);
            $tipoGafete::create(['nombre' => 'VIP (prueba)']);
            $paradero::create(['sede_id' => $sedes['CEN']->id, 'nombre' => 'PARADERO DE PRUEBA']);
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Ronda 5 (solo la primera vez): un segundo edificio con pisos y cuartos
     * para ver la cascada Zona → Piso → Área en Llaves, costos de reposición
     * (uno fijo y uno variable) y los correos de las copias del voucher con cobro.
     */
    private function ronda5Demo(Empresa $empresa, Sede $centro, User $actor): void
    {
        if (Llave::whereNotNull('costo_reposicion')->exists()) {
            return;
        }

        if (! Espacio::where('nombre', 'Villas')->where('nivel', Espacio::EDIFICIO)->exists()) {
            $espacios = app(AdministradorEspacios::class);
            $tipo = fn (string $nivel, string $nombre) => TipoEspacio::whereNull('empresa_id')->where('nivel', $nivel)->where('nombre', $nombre)->value('id');
            $villas = $espacios->crear($actor, $centro, null, Espacio::EDIFICIO, ['nombre' => 'Villas', 'codigo' => 'VI', 'tipo_espacio_id' => $tipo(Espacio::EDIFICIO, 'Torre')], false);
            $planta = $espacios->crear($actor, $centro, $villas, Espacio::AREA, ['nombre' => 'Planta baja', 'tipo_espacio_id' => $tipo(Espacio::AREA, 'Piso')], false);
            $espacios->crearLote($actor, $planta, ['V01', 'V02', 'V03'], null, $tipo(Espacio::AREA_ESPECIFICA, 'Habitación'));
        }

        // HDC-TA-ZONA: costo fijo; HDC-BOD-01: costo variable (se sugiere 350 y se ajusta en cada baja)
        Llave::where('nomenclatura', 'HDC-TA-ZONA')->update(['costo_reposicion' => 250, 'costo_variable' => false]);
        Llave::where('nomenclatura', 'HDC-BOD-01')->update(['costo_reposicion' => 350, 'costo_variable' => true]);

        $preferencias = $empresa->fresh()->preferencias ?? [];
        $preferencias['avisos_destinatarios'] = ($preferencias['avisos_destinatarios'] ?? []) + [
            'voucher_seguridad' => ['seguridad@hoteldemo.mx'],
            'voucher_recepcion' => ['recepcion@hoteldemo.mx'],
            'voucher_administracion' => ['administracion@hoteldemo.mx'],
        ];
        $empresa->forceFill(['preferencias' => $preferencias])->save();
    }

    /**
     * Altas por verificar (ADR-0006): lo que agente.demo registró en la caseta
     * de Centro (Bitácora de accesos) y aún no estaba en los padrones, para que
     * admin.demo lo vea en Inicio y practique Aceptar, Rechazar y Unir:
     *  - parecidos a uno que ya existía (para "Ya existía"): placas ABC128A
     *    (existe ABC123A), «Abarrotes del Caribe S.A. de C.V.» y «Laura Mendez Rios»;
     *  - nuevos de verdad (para "Es correcto"): XTR901C, «Plomería Express Cancún», «Pedro Canul Dzib»;
     *  - uno ya rechazado por admin.demo, para ver cómo se queda como historia.
     * Solo la primera vez.
     */
    private function altasPorVerificarDemo($sedes, User $agente, User $admin): void
    {
        if (Vehiculo::where('verificacion', '!=', Vehiculo::VERIFICADO)->whereIn('placas', ['ABC128A', 'XTR901C', 'ZZZ000'])->exists()) {
            return;
        }

        $centro = $sedes['CEN']->id;
        $pendiente = fn ($modelo, array $extra = []) => $modelo->forceFill(array_merge([
            'verificacion' => Vehiculo::PENDIENTE, 'origen_alta' => 'accesos', 'sede_alta_id' => $centro,
            'creado_por' => $agente->id, 'actualizado_por' => $agente->id,
        ], $extra))->save();

        foreach ([['ABC128A', 'NISSAN', 'VERSA', 'BLANCO'], ['XTR901C', 'KIA', 'SOUL', 'NARANJA']] as [$placas, $marca, $modelo, $color]) {
            if (! Vehiculo::where('placas', $placas)->exists()) {
                $pendiente(new Vehiculo(['placas' => $placas, 'propiedad' => 'propio_visitante', 'tipo' => 'sedan', 'marca' => $marca, 'modelo' => $modelo, 'color' => $color]));
            }
        }
        foreach ([['Abarrotes del Caribe S.A. de C.V.', 'proveedor'], ['Plomería Express Cancún', 'contratista']] as [$nombre, $categoria]) {
            if (! Proveedor::where('nombre', $nombre)->exists()) {
                $proveedor = new Proveedor(['nombre' => $nombre, 'categoria' => $categoria, 'todas_las_sedes' => false]);
                $pendiente($proveedor);
                $proveedor->sedes()->sync([$centro]);
            }
        }
        foreach ([['Laura Mendez Rios', 'visitante'], ['Pedro Canul Dzib', 'visitante']] as [$nombre, $tipo]) {
            if (! Persona::where('nombre_completo', $nombre)->exists()) {
                $pendiente(new Persona(['tipo' => $tipo, 'categoria' => 'general', 'nombre_completo' => $nombre, 'motivo_visita' => 'Registrada en la caseta (Bitácora de accesos).']));
            }
        }
        // Uno ya revisado y rechazado: se queda como historia y la caseta ya no lo puede usar
        if (! Vehiculo::where('placas', 'ZZZ000')->exists()) {
            $pendiente(new Vehiculo(['placas' => 'ZZZ000', 'propiedad' => 'propio_visitante', 'tipo' => 'sedan', 'marca' => 'SIN DATOS', 'color' => 'SIN DATOS']), [
                'verificacion' => Vehiculo::RECHAZADO, 'activo' => false, 'motivo_rechazo' => 'Placas de prueba capturadas por error: la unidad no existe.',
                'verificado_por' => $admin->id, 'verificado_en' => now(),
            ]);
        }
    }

    /**
     * Procedimientos de ejemplo, solo la primera vez, capturados con las
     * reglas de la pantalla (AdministradorProcedimientos):
     *  - PRO-SEG-001 Robo en habitación: publicado en versión 2 (con historial;
     *    agente.demo firmó la 1 y debe firmar otra vez la 2);
     *  - PRO-PC-002 Conato de incendio: publicado, firmado por supervisor.demo;
     *  - PRO-SEG-003 Entrega de turno: en revisión, espera la aprobación de admin.demo;
     *  - PRO-ACC-004 Pérdida de llave maestra: borrador;
     *  - PRO-PC-005 Huracán: publicado con adjuntos (PDF e imagen) y su versión 2 en borrador.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function procedimientosDemo($sedes, User $admin): void
    {
        // Clases por nombre: así este bloque no toca la lista de "use" (compartida con otros módulos)
        [$modelo, $servicio] = ['App\Models\Procedimiento', 'App\Services\Procedimientos\AdministradorProcedimientos'];
        if ($modelo::exists() || ! function_exists('imagecreatetruecolor')) {
            return;
        }
        $usuario = fn (string $u) => User::where('username', $u)->first();
        [$jefe, $director, $supervisor, $agente, $agente2] = [$usuario('jefe.demo'), $usuario('director.demo'), $usuario('supervisor.demo'), $usuario('agente.demo'), $usuario('agente2.demo')];
        if ($jefe === null || $director === null || $supervisor === null || $agente === null) {
            return;
        }

        $srv = app($servicio);
        $categorias = $srv->categorias()->pluck('id', 'nombre');
        $depto = fn (string $n) => Departamento::where('nombre', $n)->value('id');
        $previo = auth()->user();
        $semilla = 40;
        $como = function (User $u, callable $fn) {
            auth()->setUser($u);
            app(Autorizador::class)->olvidar();

            return $fn();
        };
        $pasos = fn (array $lista) => array_map(fn ($p) => is_array($p) ? ['texto' => $p[0], 'responsable' => $p[1] ?? null, 'critico' => $p[2] ?? false] : ['texto' => $p], $lista);
        $firmar = function (User $u, $p) use ($srv, &$semilla, $como) {
            $p->refresh();
            $como($u, fn () => $srv->aprobar($u, $p, ['version_id' => $p->trabajo()->value('id'), 'firma_modo' => 'nueva', 'firma' => $this->firmaDemo(++$semilla)]));
        };
        $acusar = function (User $u, $p) use ($srv, &$semilla, $como) {
            $p->refresh();
            $como($u, fn () => $srv->acusar($u, $p, ['version_id' => $p->vigente()->value('id'), 'entendido' => '1', 'firma_modo' => 'nueva', 'firma' => $this->firmaDemo(++$semilla)]));
        };

        try {
            // ===== PRO-SEG-001 Robo en habitación (v1 publicada → v2 publicada) =====
            $robo = $como($jefe, fn () => $srv->crear($jefe, [
                'clave' => 'PRO-SEG-001', 'titulo' => 'Robo en habitación', 'categoria_id' => $categorias['Emergencias'],
                'objetivo' => 'Atender el reporte de robo de un huésped con calma, cuidando su seguridad y conservando las evidencias para la investigación.',
                'alcance' => 'Cualquier reporte de faltante o robo en habitaciones de huéspedes.',
                'responsables' => 'Agente de caseta, Supervisor de turno, Gerente de guardia.',
                'aplica' => 'todas', 'departamentos' => array_filter([$depto('Seguridad'), $depto('Recepción')]),
                'pasos' => $pasos([
                    ['Escuchar al huésped y anotar: nombre, habitación, qué falta y cuándo lo vio por última vez.', 'Agente de caseta'],
                    ['No tocar nada en la habitación y no dejar entrar a nadie hasta que llegue el supervisor.', 'Agente de caseta', true],
                    ['Avisar por radio al Supervisor de turno y al Gerente de guardia.', 'Agente de caseta'],
                    ['Revisar las cámaras del pasillo y la bitácora de llaves de esa habitación.', 'Supervisor de turno'],
                    ['Levantar el ticket de Robo en la Bitácora de novedades con lo que se sabe.', 'Supervisor de turno'],
                ]),
                'notas' => 'Nunca acusar a nadie frente al huésped. Si el huésped quiere denunciar, ofrecerle el teléfono de la Fiscalía.',
                'enviar' => '1',
            ]));
            $firmar($admin, $robo);
            $acusar($agente, $robo);
            $como($jefe, fn () => $srv->nuevaVersion($jefe, $robo->refresh()));
            $v2 = $robo->refresh()->trabajo()->with('pasos', 'aplicaciones')->first();
            $como($jefe, fn () => $srv->actualizar($jefe, $robo, [
                'clave' => 'PRO-SEG-001', 'titulo' => 'Robo en habitación', 'categoria_id' => $categorias['Emergencias'],
                'objetivo' => $v2->objetivo, 'alcance' => $v2->alcance, 'responsables' => $v2->responsables, 'notas' => $v2->notas,
                'aplica' => 'todas', 'departamentos' => $v2->idsDe('departamento'),
                'pasos' => $pasos([
                    ['Escuchar al huésped y anotar: nombre, habitación, qué falta y cuándo lo vio por última vez.', 'Agente de caseta'],
                    ['No tocar nada en la habitación y no dejar entrar a nadie hasta que llegue el supervisor.', 'Agente de caseta', true],
                    ['Avisar por radio al Supervisor de turno y al Gerente de guardia.', 'Agente de caseta'],
                    ['Si hay violencia o el sospechoso sigue en el hotel, llamar al 911 de inmediato.', 'Supervisor de turno', true],
                    ['Revisar las cámaras del pasillo y la bitácora de llaves de esa habitación.', 'Supervisor de turno'],
                    ['Levantar el ticket de Robo en la Bitácora de novedades con lo que se sabe.', 'Supervisor de turno'],
                ]),
                'resumen_cambios' => 'Se agregó el paso 4: llamar al 911 si hay violencia o el sospechoso sigue en el hotel.',
                'enviar' => '1',
            ]));
            $firmar($director, $robo);
            $acusar($supervisor, $robo);
            if ($agente2 !== null) {
                $acusar($agente2, $robo);
            }

            // ===== PRO-PC-002 Conato de incendio =====
            $incendio = $como($jefe, fn () => $srv->crear($jefe, [
                'clave' => 'PRO-PC-002', 'titulo' => 'Conato de incendio', 'categoria_id' => $categorias['Emergencias'],
                'objetivo' => 'Controlar un fuego pequeño en sus primeros minutos y, si no se puede, evacuar a tiempo.',
                'alcance' => 'Fuego pequeño en cualquier área: cocina, cuarto eléctrico, habitación o bodega.',
                'responsables' => 'Todo el personal; brigada contra incendio.',
                'aplica' => 'todas',
                'pasos' => $pasos([
                    ['Dar la voz de alarma: «¡Fuego!» y la ubicación exacta, por radio y en voz alta.', null, true],
                    ['Si es seguro, usar el extintor más cercano (PAS: jalar el seguro, apuntar a la base, apretar y barrer).', 'Brigada contra incendio'],
                    ['Cortar la energía o el gas del área si se puede hacer sin riesgo.', 'Mantenimiento'],
                    ['Si el fuego no se apaga en 30 segundos, salir, cerrar la puerta y activar la evacuación.', null, true],
                    ['Llamar a Bomberos (911) y esperarlos en la entrada principal para guiarlos.', 'Agente de caseta'],
                ]),
                'notas' => 'Nunca usar agua en fuego eléctrico o de aceite.',
                'enviar' => '1',
            ]));
            $firmar($director, $incendio);
            $acusar($supervisor, $incendio);

            // ===== PRO-SEG-003 Entrega de turno (en revisión, la aprueba admin.demo) =====
            $como($supervisor, fn () => $srv->crear($supervisor, [
                'clave' => 'PRO-SEG-003', 'titulo' => 'Entrega de turno en caseta', 'categoria_id' => $categorias['Operación de caseta'],
                'objetivo' => 'Que el turno que entra sepa todo lo pendiente y reciba completo el equipo de la caseta.',
                'responsables' => 'Agente que entrega y agente que recibe.',
                'aplica' => 'sedes', 'sedes' => [$sedes['CEN']->id], 'departamentos' => array_filter([$depto('Seguridad')]),
                'pasos' => $pasos([
                    ['Revisar juntos la Bitácora de novedades: tickets abiertos y pendientes de turno.', 'Agente que entrega'],
                    ['Contar radios, lámparas y llaves de la caseta contra la responsiva.', 'Agente que recibe', true],
                    ['Revisar «Gente en sitio» en la Bitácora de accesos y quién no ha salido.', 'Agente que recibe'],
                    ['Firmar la entrega en el libro de turno.', 'Ambos'],
                ]),
                'enviar' => '1',
            ]));

            // ===== PRO-ACC-004 Pérdida de llave maestra (borrador) =====
            $como($jefe, fn () => $srv->crear($jefe, [
                'clave' => 'PRO-ACC-004', 'titulo' => 'Pérdida de llave maestra', 'categoria_id' => $categorias['Accesos'],
                'objetivo' => 'Reducir el riesgo cuando se pierde una llave maestra o una tarjeta con acceso a varias áreas.',
                'aplica' => 'todas',
                'pasos' => $pasos([
                    ['Reportarlo de inmediato al Supervisor de turno.', 'Quien la perdió', true],
                    ['Bloquear la tarjeta en el sistema de cerraduras o cambiar el cilindro.', 'Mantenimiento'],
                    ['Registrar la baja con voucher en el Catálogo de llaves.', 'Supervisor de turno'],
                ]),
            ]));

            // ===== PRO-PC-005 Huracán (publicado con adjuntos; v2 en borrador) =====
            $adjuntos = $this->adjuntosProcedimientoDemo();
            $huracan = $como($jefe, fn () => $srv->crear($jefe, [
                'clave' => 'PRO-PC-005', 'titulo' => 'Huracán: antes, durante y después', 'categoria_id' => $categorias['Protección civil'],
                'objetivo' => 'Proteger a huéspedes y personal ante un huracán siguiendo las alertas de Protección Civil.',
                'alcance' => 'Desde la alerta amarilla del Sistema de Alerta Temprana hasta que se levanta la alerta.',
                'responsables' => 'Comité de Protección Civil, Jefe de seguridad, brigadas.',
                'aplica' => 'todas',
                'pasos' => $pasos([
                    ['Alerta amarilla: revisar plantas de emergencia, agua, linternas y radios cargados.', 'Mantenimiento'],
                    ['Alerta naranja: retirar objetos sueltos de terrazas y albercas; informar a los huéspedes.', 'Brigadas'],
                    ['Alerta roja: llevar a todos a los refugios internos señalados en el plano (ver adjunto).', 'Jefe de seguridad', true],
                    ['Durante el huracán: nadie sale de los refugios hasta que lo indique Protección Civil.', null, true],
                    ['Después: revisar daños, cables caídos y fugas antes de reabrir áreas.', 'Mantenimiento'],
                ]),
                'adjuntos' => $adjuntos,
                'enviar' => '1',
            ]));
            $firmar($admin, $huracan);
            $como($jefe, fn () => $srv->nuevaVersion($jefe, $huracan->refresh()));
        } finally {
            foreach ($adjuntos ?? [] as $archivo) {
                @unlink($archivo->getRealPath());
            }
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
            app(Autorizador::class)->olvidar();
        }
    }

    /**
     * Un PDF sencillo y una imagen (plano de refugios) para el procedimiento demo de Huracán.
     *
     * @return list<UploadedFile>
     */
    private function adjuntosProcedimientoDemo(): array
    {
        $texto = 'BT /F1 18 Tf 60 740 Td (Directorio de emergencia - Huracan) Tj 0 -30 Td /F1 12 Tf (Proteccion Civil municipal: 998 000 0000) Tj 0 -20 Td (Bomberos y emergencias: 911) Tj ET';
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($texto).' >>'."\nstream\n".$texto."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($posiciones as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }
        $pdf .= "trailer\n<< /Size ".(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        $rutaPdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($rutaPdf, $pdf);

        $img = imagecreatetruecolor(800, 500);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $azul = imagecolorallocate($img, 37, 99, 235);
        $verde = imagecolorallocate($img, 22, 163, 74);
        $negro = imagecolorallocate($img, 15, 23, 42);
        imagesetthickness($img, 4);
        imagerectangle($img, 40, 40, 760, 460, $negro);
        imageline($img, 400, 40, 400, 460, $negro);
        imageline($img, 40, 250, 760, 250, $negro);
        imagefilledrectangle($img, 70, 290, 360, 430, $verde);
        imagefilledrectangle($img, 440, 70, 730, 220, $verde);
        imagestring($img, 5, 90, 350, 'REFUGIO 1 - Salon Maya', imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 460, 140, 'REFUGIO 2 - Comedor', imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 60, 60, 'Plano de refugios internos', $azul);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        $rutaPng = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($rutaPng, $png);

        return [
            new UploadedFile($rutaPdf, 'Directorio de emergencia.pdf', 'application/pdf', null, true),
            new UploadedFile($rutaPng, 'Plano de refugios.png', 'image/png', null, true),
        ];
    }

    /**
     * Ronda 5, parte 2 (solo la primera vez): una zona desactivada, «Torre
     * Jardín» (TJ), para practicar el aviso en vivo «Ya existe… está
     * desactivado, ¿lo reactivas?» al querer darla de alta otra vez.
     */
    private function ronda5bDemo(Sede $centro, User $actor): void
    {
        if (Espacio::where('nombre', 'Torre Jardín')->where('nivel', Espacio::EDIFICIO)->exists()) {
            return;
        }
        $espacios = app(AdministradorEspacios::class);
        $tipo = TipoEspacio::whereNull('empresa_id')->where('nivel', Espacio::EDIFICIO)->where('nombre', 'Torre')->value('id');
        $jardin = $espacios->crear($actor, $centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre Jardín', 'codigo' => 'TJ', 'tipo_espacio_id' => $tipo], false);
        $espacios->cambiarEstado($actor, $jardin, false);
    }

    /**
     * Ronda 6 (solo la primera vez):
     *  - ES-02: «Patio de Maniobras» (Centro), zona de descarga con capacidad de 4 vehículos.
     *  - GV-03: la llave HDC-101 trae la etiqueta NFC 04A1B2C3D4, para ver el aviso
     *    en vivo «ya la tiene la llave HDC-101» al asignarla a otro registro.
     *  - GV-04: el voucher de la llave HDP-MANT-02 (Playa, con cobro) se marca
     *    «Recuperado» con el cobro ya pagado: queda «Reembolso pendiente» y la
     *    llave vuelve a estar activa. La lámpara LT-0002 sigue de baja (EQ-04).
     *  - RT-01: «PARADERO DE PRUEBA» (de la práctica de Eliminar definitivamente)
     *    queda desactivado: Centro muestra sus 6 paraderos activos.
     *
     * @param  Collection<string, Sede>  $sedes
     */
    private function ronda6Demo($sedes, User $admin): void
    {
        [$zonaClase, $paraderoClase, $voucherClase] = ['App\Models\ZonaEstacionamiento', 'App\Models\Paradero', 'App\Models\VoucherReposicion'];
        if ($zonaClase::where('nombre', 'Patio de Maniobras')->exists()) {
            return;
        }
        $previo = auth()->user();
        auth()->setUser($admin);
        try {
            app('App\Services\Estacionamientos\AdministradorEstacionamientos')->crear($admin, [
                'sede_id' => $sedes['CEN']->id, 'nombre' => 'Patio de Maniobras', 'tipo' => 'zona_descarga', 'cupo_total' => 4,
            ]);

            $llave = Llave::where('nomenclatura', 'HDC-101')->where('sede_id', $sedes['CEN']->id)->first();
            if ($llave !== null && $llave->etiqueta_nfc === null && ! Llave::where('etiqueta_nfc', '04A1B2C3D4')->exists()) {
                $llave->forceFill(['etiqueta_nfc' => '04A1B2C3D4'])->save();
            }

            $paraderoClase::where('sede_id', $sedes['CEN']->id)->where('nombre', 'PARADERO DE PRUEBA')->where('activo', true)
                ->each(fn ($p) => $p->forceFill(['activo' => false])->save());

            $mantenimiento = Llave::where('nomenclatura', 'HDP-MANT-02')->first();
            $voucher = $mantenimiento ? $voucherClase::where('origen_tipo', 'llave')->where('origen_id', $mantenimiento->id)->where('estado', 'vigente')->latest('id')->first() : null;
            if ($voucher !== null && $voucher->aplica_cobro) {
                app('App\Services\Vouchers\RecuperacionVouchers')->recuperar($admin, $voucher, [
                    'cobro_pagado' => true, 'comentario' => 'Apareció en el taller de Mantenimiento; ya se le había descontado en nómina.',
                ]);
            }
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }

    /**
     * Recepción, candidatos y autorizaciones, solo la primera vez:
     *  - las visitas a un departamento esperan la autorización de su responsable;
     *  - responsables: Seguridad → jefe.demo (titular) y supervisor.demo (suplente en
     *    Centro); Alimentos y Bebidas → director.demo; Recursos Humanos → rh.demo;
     *  - director.demo delegó en admin.demo («No molestar», activa) y jefe.demo
     *    tiene una delegación programada para la próxima semana;
     *  - un candidato en cada etapa; Karla espera ahora mismo con su QR del kiosco;
     *  - una visita a Seguridad «Esperando autorización» de jefe.demo;
     *  - visitas ya respondidas de días anteriores (para los tiempos de espera).
     */
    private function recepcionDemo(Empresa $empresa, $sedes, User $admin, User $rh, User $jefe, User $agente): void
    {
        if (Candidato::exists()) {
            return;
        }
        $previo = auth()->user();
        $supervisor = User::where('username', 'supervisor.demo')->firstOrFail();
        $director = User::where('username', 'director.demo')->firstOrFail();
        $depto = fn (string $n) => Departamento::where('nombre', $n)->firstOrFail();
        $puesto = fn (string $n) => Puesto::where('nombre', $n)->value('id');
        $candidatos = app(AdministradorCandidatos::class);
        $autorizaciones = app(Autorizaciones::class);
        $hace = fn (int $minutos) => now()->subMinutes($minutos);

        try {
            $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['recepcion' => ['visitas_requieren_autorizacion' => true]])])->save();

            // ---------- Responsables y delegaciones ----------
            auth()->setUser($admin);
            $responsable = fn (string $d, User $u, bool $suplente = false, ?int $sede = null) => DepartamentoResponsable::create([
                'departamento_id' => $depto($d)->id, 'user_id' => $u->id, 'es_suplente' => $suplente, 'sede_id' => $sede]);
            $responsable('Seguridad', $jefe);
            $responsable('Seguridad', $supervisor, true, $sedes['CEN']->id);
            $responsable('Alimentos y Bebidas', $director);
            $responsable('Recursos Humanos', $rh);
            Delegacion::create(['user_id' => $director->id, 'delegado_id' => $admin->id, 'desde' => now()->subDay(), 'hasta' => now()->addDays(3),
                'motivo' => 'Viaje de trabajo a Mérida']);
            Delegacion::create(['user_id' => $jefe->id, 'delegado_id' => $supervisor->id, 'desde' => now()->addDays(7)->startOfDay()->addHours(13),
                'hasta' => now()->addDays(10)->startOfDay()->addHours(13), 'motivo' => 'Vacaciones']);

            // ---------- Candidatos (la caseta los registra y RR. HH. los avanza) ----------
            $acceso = function (string $nombre, int $minutos, bool $abierto, array $extra = []) use ($sedes, $agente, $candidatos, $hace): Acceso {
                $persona = $candidatos->personaDelPadron($agente, $nombre, null);
                // Como las registra la caseta desde Operación (ADR-0006), ya verificadas
                $persona->forceFill(['origen_alta' => 'accesos', 'sede_alta_id' => $sedes['CEN']->id])->save();
                $a = new Acceso($extra + ['sede_id' => $sedes['CEN']->id, 'tipo' => 'visitante', 'nombre' => mb_strtoupper($nombre), 'persona_id' => $persona->id,
                    'motivo_visita' => 'rh', 'identificacion' => 'ine', 'modo_arribo' => 'a_pie', 'entrada_at' => $hace($minutos)]);
                $a->forceFill(['estado' => $abierto ? 'en_sitio' : 'finalizado', 'creado_por' => $agente->id, 'actualizado_por' => $agente->id]
                    + ($abierto ? [] : ['salida_at' => $hace(max(0, $minutos - 90)), 'salida_por' => $agente->id]))->save();

                return $a;
            };
            $cv = [
                'escolaridad' => [['nivel' => 'bachillerato', 'institucion' => 'CBTIS 111', 'titulo' => null, 'concluido' => true]],
                'experiencia' => [['empresa' => 'Hotel Sol Caribe', 'puesto' => 'Ayudante general', 'anos' => 2, 'motivo_salida' => 'Cambio de domicilio']],
                'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233', 'relacion' => 'Jefa anterior']],
                'habilidades' => 'Atención a huéspedes, trabajo en equipo', 'idiomas' => 'Español, inglés básico', 'disponibilidad' => 'inmediata',
                'ciudad' => 'Cancún',
            ];
            $nuevo = function (string $nombre, int $minutos, string $dep, ?string $pue, bool $abierto = false, array $datos = []) use ($acceso, $agente, $candidatos, $depto, $puesto, $cv) {
                auth()->setUser($agente);
                $a = $acceso($nombre, $minutos, $abierto);
                $c = $candidatos->desdeAcceso($agente, $a, ['departamento_id' => $depto($dep)->id, 'puesto_id' => $pue ? $puesto($pue) : null, 'vacante' => $pue ? null : 'Ayudante de cocina']);
                $c->forceFill($datos + $cv + ['avisado_rh_en' => $a->entrada_at->copy()->addMinute(), 'telefono' => '998'.random_int(1000000, 9999999), 'privacidad_aceptada_en' => $a->entrada_at, 'privacidad_ip' => '10.0.0.15',
                    'privacidad_version' => app(AjustesRecepcion::class)->versionPrivacidad(Empresa::findOrFail($c->empresa_id)), 'privacidad_medio' => 'rh'])->save();

                return $c;
            };
            $mover = function ($c, array $etapas, ?string $comentario = null) use ($rh, $candidatos) {
                auth()->setUser($rh);
                foreach ($etapas as $e) {
                    $candidatos->cambiarEtapa($rh, $c->fresh(), $e, $e === 'descartado' ? $comentario : null);
                }

                return $c->fresh();
            };
            // Tiempos realistas desde la llegada (minutos después de llegar a caseta); $dias queda por compatibilidad
            $atras = function ($c, int $dias) {
                $minutos = ['avisado_rh_en' => 1, 'revision_en' => 9, 'aprobado_rh_en' => 35, 'enviado_departamento_en' => 35,
                    'respuesta_departamento_en' => 52, 'entrevista_en' => 70, 'decision_en' => 110, 'contratado_en' => 1440];
                $cambios = [];
                foreach ($minutos as $campo => $min) {
                    if ($c->{$campo} !== null) {
                        $cambios[$campo] = $c->llegada_en->copy()->addMinutes($min);
                    }
                }
                $c->forceFill($cambios)->save();
            };

            // Esperando ahora, con su QR del kiosco
            $karla = $nuevo('Karla Pérez Uc', 12, 'Ama de Llaves', 'Camarista', true, ['escolaridad' => null, 'experiencia' => null, 'referencias' => null,
                'habilidades' => null, 'privacidad_aceptada_en' => null, 'privacidad_ip' => null, 'privacidad_version' => null, 'privacidad_medio' => null]);
            auth()->setUser($rh);
            app(Kiosco::class)->generar($rh, $karla);

            // En revisión: llenó su CV en el kiosco y falta que RR. HH. lo revise
            $luis = $nuevo('Luis Ángel Chi Canul', 35, 'Mantenimiento', 'Técnico de Mantenimiento', true, ['privacidad_medio' => 'kiosco']);
            $mover($luis, ['revision']);
            $luis->forceFill(['autocaptura_pendiente' => true, 'autocaptura_en' => now()->subMinutes(20), 'origen' => 'caseta'])->save();

            // Evidencia de caseta (foto de Luis) y documentos que subió en el kiosco (archivos de ejemplo en el disco privado)
            $imagen = function (int $ancho, int $alto, array $fondo, string $texto): string {
                $img = imagecreatetruecolor($ancho, $alto);
                imagefill($img, 0, 0, imagecolorallocate($img, ...$fondo));
                $blanco = imagecolorallocate($img, 255, 255, 255);
                imagefilledellipse($img, intdiv($ancho, 2), intdiv($alto, 2) - 20, intdiv($ancho, 3), intdiv($ancho, 3), $blanco);
                imagestring($img, 5, 12, $alto - 30, $texto, $blanco);
                ob_start();
                imagejpeg($img, null, 85);

                return (string) ob_get_clean();
            };
            $rutaFoto = 'accesos/'.$empresa->id.'/fotos/demo/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($rutaFoto, $imagen(360, 360, [37, 99, 235], 'FOTO DE CASETA (DEMO)'));
            Acceso::whereKey($luis->acceso_id)->update(['foto_persona' => $rutaFoto]);
            foreach ([['cv', 'CV Luis Chi.pdf', 'application/pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"],
                ['ine', 'INE frente.jpg', 'image/jpeg', $imagen(480, 300, [100, 116, 139], 'INE (DEMO)')]] as [$tipo, $nombreArchivo, $mime, $contenido]) {
                $ruta = 'candidatos/'.$empresa->id.'/'.$luis->id.'/'.Str::uuid().($tipo === 'cv' ? '.pdf' : '.jpg');
                Storage::disk('local')->put($ruta, $contenido);
                $doc = new CandidatoDocumento(['candidato_id' => $luis->id, 'tipo' => $tipo, 'nombre_original' => $nombreArchivo, 'ruta' => $ruta,
                    'mime' => $mime, 'bytes' => strlen($contenido), 'origen' => 'kiosco']);
                $doc->forceFill(['empresa_id' => $empresa->id])->save();
            }

            // Aprobada por RR. HH.: espera a Alimentos y Bebidas (director.demo delegó en admin.demo)
            $fernanda = $nuevo('Fernanda Ruiz Kú', 95, 'Alimentos y Bebidas', 'Mesero', true);
            $mover($fernanda, ['revision', 'aprobado_rh']);

            // Entrevista (el departamento pidió bajarlo)
            $jorge = $nuevo('Jorge Tun Pech', 60 * 26, 'Seguridad', 'Agente de Seguridad');
            $mover($jorge, ['revision', 'aprobado_rh']);
            $pendienteJorge = Autorizacion::where('candidato_id', $jorge->id)->where('estado', 'pendiente')->first();
            if ($pendienteJorge) {
                auth()->setUser($jefe);
                $autorizaciones->responder($jefe, $pendienteJorge, 'entrevistar', 'Que suba mañana a las 10:00 con su solicitud.');
            }
            $atras($jorge->fresh(), 1);

            // Seleccionada
            $mariela = $nuevo('Mariela Canché Dzib', 60 * 50, 'Recepción', 'Recepcionista');
            $mover($mariela, ['revision', 'entrevista', 'seleccionado']);
            $atras($mariela->fresh(), 2);

            // Contratado: ya es colaborador
            $ramon = $nuevo('Ramón Ek Balam', 60 * 75, 'Mantenimiento', 'Técnico de Mantenimiento');
            $mover($ramon, ['revision', 'entrevista', 'seleccionado']);
            auth()->setUser($rh);
            $candidatos->contratar($rh, $ramon->fresh(), ['num_empleado' => '2001', 'nombre' => 'Ramón', 'apellido_paterno' => 'Ek', 'apellido_materno' => 'Balam']);
            $atras($ramon->fresh(), 3);

            // En cartera y descartado
            $silvia = $nuevo('Silvia Mena Couoh', 60 * 100, 'Recepción', 'Recepcionista');
            $mover($silvia, ['revision', 'cartera']);
            $atras($silvia->fresh(), 4);
            $pedro = $nuevo('Pedro Uicab Noh', 60 * 120, 'Alimentos y Bebidas', null);
            $mover($pedro, ['revision', 'descartado'], 'No cubre el horario nocturno que pide la vacante.');
            $atras($pedro->fresh(), 5);

            // Los avisos de candidatos de días anteriores ya se leyeron
            Notificacion::where('referencia_tipo', 'candidato')->whereIn('referencia_id', [$jorge->id, $mariela->id, $ramon->id, $silvia->id, $pedro->id])
                ->update(['leida_en' => now()]);

            // ---------- Visita que espera la autorización de jefe.demo ----------
            auth()->setUser($agente);
            $visita = $acceso('Ingrid Solís Paredes', 6, true, ['motivo_visita' => 'departamento', 'departamento_id' => $depto('Seguridad')->id,
                'empresa_procedencia' => 'CÁMARAS Y ALARMAS DEL SURESTE']);
            $visita->forceFill(['estado' => 'pendiente', 'autorizacion' => 'esperando'])->save();
            $solicitud = $autorizaciones->solicitarVisita($agente, $visita, $depto('Seguridad')->id);
            $solicitud?->forceFill(['solicitada_en' => $visita->entrada_at->copy()->addMinute()])->save();

            // ---------- Visitas respondidas de días anteriores (tiempos de espera) ----------
            $quien = ['Seguridad' => $jefe, 'Alimentos y Bebidas' => $director, 'Recursos Humanos' => $rh];
            foreach ([[1, 'Seguridad', 4, 'autorizada', 'Óscar Méndez Lara'], [1, 'Alimentos y Bebidas', 17, 'autorizada', 'Claudia Ríos Batún'],
                [2, 'Seguridad', 9, 'rechazada', 'Iván Torres Pool'], [3, 'Recursos Humanos', 6, 'autorizada', 'Lucía Gamboa Ku'],
                [4, 'Alimentos y Bebidas', 26, 'autorizada', 'Raúl Herrera Chan'], [5, 'Seguridad', 3, 'autorizada', 'Elena Vargas Mex']] as $i => [$dias, $dep, $min, $estado, $nombre]) {
                $llegada = now()->subDays($dias)->setTime(9 + $i, 15);
                $responde = $quien[$dep];
                $v = $acceso($nombre, (int) $llegada->diffInMinutes(now()), false, ['motivo_visita' => 'departamento', 'departamento_id' => $depto($dep)->id]);
                $v->forceFill(['autorizacion' => $estado, 'autorizado_at' => $estado === 'autorizada' ? $llegada->copy()->addMinutes($min) : null,
                    'autorizado_por' => $estado === 'autorizada' ? $responde->id : null])->save();
                $aut = new Autorizacion(['sede_id' => $sedes['CEN']->id, 'departamento_id' => $depto($dep)->id, 'tipo' => 'visita', 'acceso_id' => $v->id,
                    'solicitada_en' => $llegada->copy()->addMinute()]);
                $aut->forceFill(['estado' => $estado, 'respondida_en' => $llegada->copy()->addMinutes($min + 1), 'respondida_por' => $responde->id, 'respuesta_medio' => 'plataforma',
                    'creado_por' => $agente->id, 'actualizado_por' => $responde->id])->save();
            }
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }
    // Fin Recepción de candidatos

    /**
     * Ronda 7 (gestor de impresión QR): las 4 plantillas de siempre, una
     * plantilla de rollo Zebra de 2 × 1 pulgadas solo para Centro, una
     * impresión de 3 llaveros de Centro y la reimpresión de uno de ellos.
     */
    private function ronda7Demo($sedes, User $admin): void
    {
        if (ImpresionEtiquetas::exists()) {
            return;
        }
        $previo = auth()->user();
        auth()->setUser($admin);
        try {
            $plantillas = app('App\Services\Lector\PlantillasEtiquetas');
            $plantillas->asegurar();
            $plantillas->guardar($admin, [
                'nombre' => 'Zebra 2 × 1 pulgadas (Centro)', 'sede_id' => $sedes['CEN']->id, 'formato' => 'rollo',
                'ancho_mm' => '50.8', 'alto_mm' => '25.4', 'separacion_vertical_mm' => '0', 'orientacion' => 'horizontal', 'qr_mm' => '21',
                'mostrar_titulo' => '1', 'mostrar_codigo' => '1', 'mostrar_tipo' => '1', 'mostrar_ubicacion' => '1', 'mostrar_fecha' => '1', 'mostrar_logo' => '0',
            ]);

            $llaves = Llave::where('sede_id', $sedes['CEN']->id)->where('activo', true)->orderBy('id')->limit(3)->get();
            if ($llaves->isEmpty()) {
                return;
            }
            $masivas = app('App\Services\Lector\EtiquetasMasivas');
            $impresiones = app('App\Services\Lector\ImpresionesEtiquetas');
            $llavero = EtiquetaPlantilla::where('clave', 'llavero')->firstOrFail();
            $etiquetas = $masivas->paraImprimir($admin, $llaves->map(fn (Llave $l) => 'llave-'.$l->id)->all());
            $original = $impresiones->registrar($admin, $llavero, $etiquetas);
            $original->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
            $impresiones->registrar($admin, $llavero, $etiquetas->take(1), $original);
        } finally {
            $previo ? auth()->setUser($previo) : auth()->forgetUser();
        }
    }
}
