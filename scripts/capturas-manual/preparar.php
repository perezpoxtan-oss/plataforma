<?php

/*
 * Prepara la base LOCAL para tomar las capturas del manual:
 *  - cambia usuarios, correos, nombres y empresas de prueba por nombres neutros
 *    (en las capturas no debe verse «admin.demo», «Hotel Demo», etc.);
 *  - crea la cuenta de Super Administrador «soporte» con verificación en dos
 *    pasos de clave conocida (el script de capturas genera el código);
 *  - deja un bloqueo de Reservas en la Sala de juntas Ceiba (sin reservas afectadas).
 * Solo corre con APP_ENV=local y base SQLite. Uso (después de plataforma:demo):
 *   php scripts/capturas-manual/preparar.php
 */

use App\Models\Acceso;
use App\Models\ArticuloAcceso;
use App\Models\AsistenciaJustificacion;
use App\Models\Colaborador;
use App\Models\MarcajeEquipoAcceso;
use App\Models\Modulo;
use App\Models\ReservaArea;
use App\Models\ReservaBloqueo;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\EquiposAcceso\AdministradorEquipos;
use App\Services\Reservas\Disponibilidad;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
    fwrite(STDERR, "Solo se usa en una base local SQLite (APP_ENV=local).\n");
    exit(1);
}

const CLAVE_SUPERADMIN = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

$usuarios = [
    'corp.admin.demo' => ['carla.ibarra', 'Carla Ibarra', 'Carla Administradora Corporativo'],
    'corp.jefe.demo' => ['jorge.castillo', 'Jorge Castillo', 'Jorge Jefe de Seguridad Corporativo'],
    'corp.agente.demo' => ['carmen.vega', 'Carmen Vega', 'Carmen Agente Corporativo'],
    'condo.admin.demo' => ['olga.fuentes', 'Olga Fuentes', 'Olga Administradora Condominio'],
    'condo.jefe.demo' => ['nestor.salas', 'Néstor Salas', 'Néstor Jefe de Seguridad Condominio'],
    'condo.agente.demo' => ['oscar.pech', 'Óscar Pech', 'Óscar Agente Condominio'],
    'frac.admin.demo' => ['fernanda.aguilar', 'Fernanda Aguilar', 'Fernanda Administradora Fraccionamiento'],
    'frac.jefe.demo' => ['felipe.mora', 'Felipe Mora', 'Felipe Jefe de Seguridad Fraccionamiento'],
    'frac.agente.demo' => ['fabian.cruz', 'Fabián Cruz', 'Fabián Agente Fraccionamiento'],
    'jefedepto.demo' => ['julieta.ramos', 'Julieta Ramos', 'Julieta Jefa de Recepción'],
    'solicitante.demo' => ['sofia.vega', 'Sofía Vega', 'Sofía Solicitante'],
    'supervisor.demo' => ['sergio.diaz', 'Sergio Díaz', 'Sergio Supervisor'],
    'director.demo' => ['diego.navarro', 'Diego Navarro', 'Diego Director'],
    'agente2.demo' => ['pablo.mendez', 'Pablo Méndez', 'Pablo Agente Playa'],
    'agente.demo' => ['andrea.ruiz', 'Andrea Ruiz', 'Andrea Agente'],
    'admin.demo' => ['ana.lopez', 'Ana López', 'Ana Administradora'],
    'jefe.demo' => ['julia.torres', 'Julia Torres', 'Julia Jefa de Seguridad'],
    'rh.demo' => ['rita.campos', 'Rita Campos', 'Rita Recursos Humanos'],
    'laguna.demo' => ['laura.mendez', 'Laura Méndez', null],
    'plaza.demo' => ['mario.ruiz', 'Mario Ruiz', null],
    'centro.demo' => ['carmen.ortiz', 'Carmen Ortiz', null],
];

// Reemplazos de texto, en orden (los más largos primero)
$reemplazos = [];
foreach ($usuarios as $viejo => [$nuevo, $nombre, $nombreViejo]) {
    if ($nombreViejo !== null) {
        $reemplazos[$nombreViejo] = $nombre;
    }
}
$reemplazos['Julieta Jefa Recepción'] = 'Julieta Ramos';
foreach ($reemplazos as $viejo => $nuevo) {
    $reemplazos[mb_strtoupper($viejo)] = mb_strtoupper($nuevo);
}
foreach ($usuarios as $viejo => [$nuevo]) {
    $reemplazos[$viejo] = $nuevo;
}
$reemplazos += [
    '@cliente.demo.local' => '@ejemplo.mx',
    '@corporativo.demo.local' => '@ejemplo.mx',
    '@condominio.demo.local' => '@ejemplo.mx',
    '@fraccionamiento.demo.local' => '@ejemplo.mx',
    '@demo.local' => '@ejemplo.mx',
    'Hotel Demo S.A. de C.V.' => 'Grupo Hotelero Ejemplo S.A. de C.V.',
    'Hotel Demo Centro' => 'Hotel Centro',
    'Hotel Demo Playa' => 'Hotel Playa',
    'Hotel Demo' => 'Grupo Hotelero Ejemplo',
    'Corporativo Demo' => 'Corporativo Norte',
    'Residencial Demo' => 'Residencial Las Palmas',
    'Condominio Demo' => 'Condominio Las Palmas',
    'Fraccionamiento Demo' => 'Fraccionamiento Los Robles',
    'hoteldemo' => 'hotelejemplo',
    'hotel-demo' => 'hotel-ejemplo',
    'ARCO-DEMO' => 'ARCO-2026',
    'SC-DEMO' => 'SC-2026',
    'DEMO-TRANSF' => 'TRANSF',
];

// Columnas con rutas de archivos: no se tocan (romperían el archivo)
$esRuta = fn (string $c) => (bool) preg_match('/(ruta|foto|archivo|path|logo|imagen|adjunto|firma_ruta)/i', $c);
$omitir = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

$cambios = 0;
DB::transaction(function () use ($reemplazos, $esRuta, $omitir, &$cambios) {
    foreach (DB::select("select name from sqlite_master where type='table'") as $t) {
        if (in_array($t->name, $omitir, true)) {
            continue;
        }
        $columnas = array_values(array_filter(Schema::getColumnListing($t->name), fn ($c) => ! $esRuta($c)));
        if (! in_array('id', Schema::getColumnListing($t->name), true)) {
            continue;
        }
        foreach (DB::table($t->name)->get() as $fila) {
            $nuevo = [];
            foreach ($columnas as $c) {
                $v = $fila->$c ?? null;
                if (! is_string($v) || $c === 'password' || str_starts_with($c, 'two_factor')) {
                    continue;
                }
                $r = preg_replace('/\b([a-z0-9]+)\.demo\b/i', '$1', strtr($v, $reemplazos));
                if ($r !== $v) {
                    $nuevo[$c] = $r;
                }
            }
            if ($nuevo !== []) {
                DB::table($t->name)->where('id', $fila->id)->update($nuevo);
                $cambios++;
            }
        }
    }
});

// Colaboradores con el nombre del rol de prueba (nombre y apellidos van en columnas separadas)
$colaboradores = [
    ['Ana', 'Administradora', 'López'], ['Sergio', 'Supervisor', 'Díaz'], ['Andrea', 'Agente', 'Ruiz'],
    ['Pablo', 'Agente', 'Méndez'], ['Sofía', 'Solicitante', 'Vega'], ['Julieta', 'Jefa', 'Ramos'],
];
foreach ($colaboradores as [$nombre, $paterno, $nuevo]) {
    DB::table('colaboradores')->where('nombre', $nombre)->where('apellido_paterno', $paterno)
        ->update(['apellido_paterno' => $nuevo, 'apellido_materno' => DB::raw("CASE WHEN apellido_materno IN ('Playa', 'Recepción') THEN NULL ELSE apellido_materno END")]);
}

// Correos de las cuentas: usuario@ejemplo.mx
foreach (User::query()->get() as $u) {
    $u->forceFill(['email' => $u->username.'@ejemplo.mx'])->saveQuietly();
}

// Una cuenta bloqueada por intentos fallidos (para «Cómo desbloquear una cuenta»)
User::query()->where('username', 'sofia.vega')->update(['intentos_fallidos' => 5, 'bloqueado_hasta' => now()->addHours(4)]);

// Super Administrador para las pantallas de la plataforma
$sa = User::query()->firstOrNew(['username' => 'soporte']);
$sa->forceFill([
    'name' => 'Roberto Salas',
    'email' => 'soporte@ejemplo.mx',
    'password' => Hash::make('Prueba.Local.2026'),
    'empresa_id' => null,
    'es_superadmin' => true,
    'activo' => true,
    'debe_cambiar_contrasena' => false,
    'two_factor_secret' => CLAVE_SUPERADMIN,
    'two_factor_confirmado_en' => now(),
    'two_factor_ultimo_paso' => null,
])->save();

// Escenario de Asistencia y Equipos de acceso en el hotel (empresa 1): el módulo encendido,
// turnos asignados y entradas y salidas de los últimos días (con nombres de colaboradores del ejemplo)
if (! DB::table('equipos_acceso')->where('empresa_id', 1)->exists()) {
    DB::table('empresa_modulos')->updateOrInsert(
        ['empresa_id' => 1, 'modulo_id' => Modulo::where('clave', 'asistencia')->value('id')],
        ['activo' => true, 'solo_lectura' => false, 'created_at' => now(), 'updated_at' => now()]
    );
    app(Tenant::class)->conEmpresa(1, function () {
        DB::table('turnos')->where('empresa_id', 1)->where('nombre', 'Matutino')->update(['dias_laborables' => '1,2,3,4,5,6']);
        $turno = fn (string $n) => DB::table('turnos')->where('empresa_id', 1)->where('nombre', $n)->value('id');
        foreach ([5 => 'Matutino', 6 => 'Matutino', 7 => 'Matutino', 9 => 'Matutino', 10 => 'Vespertino', 11 => 'Nocturno'] as $id => $t) {
            Colaborador::query()->whereKey($id)->update(['turno_id' => $turno($t)]);
        }
        $c = fn (int $id) => Colaborador::query()->findOrFail($id);
        $z = 'America/Cancun';
        $hoy = CarbonImmutable::now($z)->startOfDay();
        $m = function (Colaborador $col, CarbonImmutable $dia, string $ent, ?string $sal = null) {
            $a = Acceso::create(['sede_id' => $col->sede_id, 'tipo' => 'colaborador', 'colaborador_id' => $col->id, 'nombre' => mb_strtoupper($col->nombreCompleto()),
                'estado' => 'en_sitio', 'entrada_at' => $dia->setTimeFromTimeString($ent)->utc()]);
            if ($sal) {
                $a->forceFill(['estado' => 'finalizado', 'salida_at' => (str_starts_with($sal, '+') ? $dia->addDay()->setTimeFromTimeString(substr($sal, 1)) : $dia->setTimeFromTimeString($sal))->utc()])->save();
            }
        };
        [$roberto, $carlos, $mariana, $guadalupe, $rosa, $javier] = [$c(5), $c(6), $c(7), $c(9), $c(10), $c(11)];
        for ($d = $hoy->subDays(9); $d->lt($hoy); $d = $d->addDay()) {
            if ($d->dayOfWeekIso !== 7) {
                $m($roberto, $d, '06:52', '15:04');
                $d->day % 4 === 0 ? null : $m($mariana, $d, $d->day % 3 === 0 ? '07:25' : '06:58', '15:02');
                $m($carlos, $d, '06:55', $d->day % 5 === 0 ? '13:40' : '15:10');
                $m($guadalupe, $d, '06:49', '15:01');
            }
            $m($rosa, $d, '14:51', '23:05');
            $m($javier, $d, '22:48', '+07:02');
        }
        AsistenciaJustificacion::create(['colaborador_id' => $mariana->id, 'sede_id' => $mariana->sede_id, 'fecha' => $hoy->subDays(4)->format('Y-m-d'), 'tipo' => 'falta', 'motivo' => 'permiso', 'nota' => 'Cita médica con aviso a su jefa']);
        $m($mariana, $hoy, '07:24');
        $m($guadalupe, $hoy, '06:59');

        // Equipos de acceso (checador y torniquete) con sus últimos marcajes
        $ana = User::query()->where('username', 'ana.lopez')->firstOrFail();
        $adm = app(AdministradorEquipos::class);
        [$zk] = $adm->crear($ana, ['sede_id' => 1, 'nombre' => 'Checador de personal', 'marca' => 'zkteco', 'modelo' => 'SpeedFace', 'numero_serie' => 'CQZ7232460321', 'puerta' => 'Acceso de personal', 'sentido' => 'ambos']);
        [$hik] = $adm->crear($ana, ['sede_id' => 1, 'nombre' => 'Torniquete de servicio', 'marca' => 'hikvision', 'numero_serie' => 'DS-K1T341-0001', 'puerta' => 'Andén de servicio', 'sentido' => 'entrada']);
        $zk->forceFill(['ultimo_contacto_at' => now()->subSeconds(20), 'ultimo_marcaje_at' => now()->subMinutes(12)])->saveQuietly();
        $hik->forceFill(['ultimo_contacto_at' => now()->subHours(3), 'ultimo_marcaje_at' => now()->subHours(3)])->saveQuietly();
        foreach ([[$roberto, 'entrada', 50], [$mariana, 'entrada', 30], [$roberto, 'salida', 12]] as $i => [$col, $r, $min]) {
            MarcajeEquipoAcceso::create(['equipo_acceso_id' => $zk->id, 'clave' => hash('sha256', 'manual'.$i), 'marcado_at' => now()->subMinutes($min),
                'identificador' => '••'.$col->num_empleado, 'resultado' => $r, 'colaborador_id' => $col->id, 'sentido' => $r]);
        }
    });
}

// Un contratista en sitio que trajo herramienta (para «Sale con lo mismo») y un evento de Reservas hoy (para «Eventos de hoy»)
if (! DB::table('accesos')->where('empresa_id', 1)->where('nombre', 'JUAN CHAN PECH')->exists()) {
    app(Tenant::class)->conEmpresa(1, function () {
        $a = Acceso::create(['sede_id' => 1, 'tipo' => 'contratista', 'nombre' => 'JUAN CHAN PECH', 'proveedor_id' => 4, 'empresa_procedencia' => 'CONSTRUCTORA MAYA',
            'identificacion' => 'ine', 'modo_arribo' => 'pie', 'tipo_visita' => 'ejecucion', 'area_trabajo' => 'Cuarto de máquinas', 'actividad' => 'Mantenimiento de bombas',
            'estado' => 'en_sitio', 'entrada_at' => now()->subHours(2)]);
        foreach ([['TALADRO', 1, 'DW-123'], ['ESCALERA', 1, null]] as [$art, $n, $serie]) {
            ArticuloAcceso::create(['empresa_id' => 1, 'acceso_id' => $a->id, 'articulo' => $art, 'cantidad' => $n, 'serie' => $serie]);
        }
        $base = (array) DB::table('reservas')->where('id', 3)->first();
        $z = 'America/Cancun';
        $dia = CarbonImmutable::now($z)->startOfDay();
        unset($base['id']);
        DB::table('reservas')->insert(array_merge($base, [
            'folio' => 'RES-000099', 'titulo' => 'Congreso de médicos', 'asistentes' => 100,
            'inicio' => $dia->setTime(11, 0)->utc(), 'fin' => $dia->setTime(17, 0)->utc(),
            'bloqueo_desde' => $dia->setTime(10, 0)->utc(), 'bloqueo_hasta' => $dia->setTime(17, 0)->utc(),
            'montaje_minutos' => 60, 'desmontaje_minutos' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]));
    });
}

// Un bloqueo de Reservas (para «Bloquear un sitio de reservas»): la Sala de juntas Ceiba por mantenimiento,
// el primer día hábil desde pasado mañana sin reservas en ella (así no afecta a las capturas de las reservas)
if (Schema::hasTable('reserva_bloqueos') && ! DB::table('reserva_bloqueos')->where('empresa_id', 1)->where('motivo', 'Mantenimiento del aire acondicionado')->exists()) {
    app(Tenant::class)->conEmpresa(1, function () {
        $ana = User::query()->where('username', 'ana.lopez')->firstOrFail();
        $sala = ReservaArea::whereHas('espacio', fn ($q) => $q->where('nombre', 'Sala de juntas Ceiba'))->first();
        if ($sala === null) {
            return;
        }
        $z = app(Disponibilidad::class)->zona($sala);
        for ($dia = CarbonImmutable::now($z)->startOfDay()->addDays(2); ; $dia = $dia->addDay()) {
            if ($dia->isWeekday() && app(Disponibilidad::class)->choques($sala, $dia->utc(), $dia->addDay()->utc())->isEmpty()) {
                break;
            }
        }
        $b = ReservaBloqueo::create(['empresa_id' => 1, 'sede_id' => $sala->sede_id, 'reserva_area_id' => $sala->id, 'espacio_id' => $sala->espacio_id,
            'desde' => $dia->setTime(8, 0)->utc(), 'hasta' => $dia->setTime(14, 0)->utc(), 'motivo' => 'Mantenimiento del aire acondicionado']);
        $b->forceFill(['creado_por' => $ana->id, 'actualizado_por' => $ana->id])->save();
    });
}

// Página pública de planes publicada (para «Catálogo comercial» y la calculadora)
$ajustes = app(AjustesComerciales::class);
$ajustes->guardar(array_merge($ajustes->todos(), ['landing_publicada' => true]));

echo "Filas cambiadas: {$cambios}. Super Administrador: soporte.\n";
