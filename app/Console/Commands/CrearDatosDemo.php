<?php

namespace App\Console\Commands;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

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
}
