<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
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
                'zona_horaria' => 'America/Cancun',
            ]);

        $sedes = $tenant->conEmpresa($empresa->id, fn () => collect([
            'CEN' => 'Hotel Demo Centro',
            'PLA' => 'Hotel Demo Playa',
        ])->map(fn ($nombre, $codigo) => Sede::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre])));

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

        $this->info('Empresa demo lista: '.self::EMPRESA.' con '.count(self::USUARIOS).' usuarios ('.implode(', ', array_keys(self::USUARIOS)).').');

        return self::SUCCESS;
    }
}
