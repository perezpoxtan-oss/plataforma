<?php

namespace App\Services\BaseDatos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Limpieza de la base de pruebas (docs/tecnico/despliegue.md, «QA: limpieza
 * única de la base»). Enfoque de lista blanca: se toman TODAS las tablas de la
 * base y, salvo las que se conservan, se vacían. Así una tabla nueva que nadie
 * anotó aquí también se limpia (lo que no se conoce no se conserva).
 *
 * Se conserva solo lo de nivel plataforma: catálogo base y comercial, módulos,
 * permisos, menús, rubros, plantillas de rol, tipos de espacio globales,
 * configuración de la plataforma (identidad, correo, textos legales), el aviso
 * de privacidad de la plataforma, su bitácora y los Super Administradores
 * (con contraseña, dos pasos y códigos de respaldo). Los archivos de
 * respaldos y de traslado nunca se tocan.
 */
class LimpiezaQa
{
    /** Tablas que se conservan completas (todo es de plataforma). */
    public const CONSERVAR = [
        'migrations',
        // Catálogo base (CatalogoSeeder, RubrosSeeder, MenuSeeder)
        'areas', 'modulos', 'acciones', 'modulo_acciones', 'menus', 'rubros',
        // Catálogo comercial (CatalogoComercialSeeder y lo que el Super Administrador da de alta)
        'productos', 'planes', 'paquetes', 'plan_producto', 'paquete_producto', 'paquete_plan', 'modulo_producto',
        'promociones', 'promocion_plan',
        // Configuración global: identidad, correo de la plataforma, textos legales, traslado, catálogo comercial…
        'configuracion_plataforma',
    ];

    /**
     * Tablas que mezclan renglones de plataforma (empresa_id NULL) con renglones
     * de empresa: solo se borran los de empresa.
     */
    public const SOLO_DE_EMPRESAS = [
        'roles',              // plantillas de rol (RolesPlantillaSeeder)
        'tipos_espacio',      // tipos globales (TiposEspacioSeeder)
        'avisos_privacidad',  // aviso de privacidad de la plataforma
        'auditoria',          // bitácora de la plataforma (la de empresas se borra)
    ];

    /** Carpetas de storage/app/private que nunca se borran. */
    public const CARPETAS_PRIVADAS_CONSERVADAS = ['respaldos', 'traslados'];

    /** Carpetas de storage/app/public que nunca se borran (Identidad de la plataforma). */
    public const CARPETAS_PUBLICAS_CONSERVADAS = ['identidad'];

    /** Archivos de control de las carpetas de storage (no son datos). */
    private const ARCHIVOS_CONTROL = ['.gitignore', '.htaccess'];

    /**
     * Qué se borraría, tabla por tabla, sin cambiar nada.
     *
     * @return array<string, array{modo: string, renglones: int}> tabla => [conservar|vaciar|solo_empresas|super_administradores|dependiente, renglones a borrar]
     */
    public function plan(): array
    {
        $plan = [];
        foreach ($this->tablas() as $tabla) {
            $modo = $this->modo($tabla);
            $plan[$tabla] = ['modo' => $modo, 'renglones' => $modo === 'conservar' ? 0 : $this->aBorrar($tabla, $modo)->count()];
        }

        return $plan;
    }

    /**
     * Borra según el plan, con las llaves foráneas desactivadas y en una sola
     * transacción (MariaDB y SQLite). Si algo falla, no queda nada a medias.
     *
     * @return array<string, int> tabla => renglones borrados (solo las que tenían algo)
     */
    public function ejecutar(): array
    {
        $plan = $this->plan();
        $conexion = DB::connection();
        $driver = $conexion->getDriverName();
        $sqliteFuera = $driver === 'sqlite' && $conexion->transactionLevel() === 0;

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $conexion->statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($sqliteFuera) {
            // En SQLite el interruptor solo surte efecto fuera de una transacción
            $conexion->statement('PRAGMA foreign_keys = OFF');
        } elseif ($driver !== 'sqlite') {
            throw new RuntimeException("Limpieza no disponible para {$driver}.");
        }

        try {
            $conexion->transaction(function () use ($conexion, $driver, $sqliteFuera, $plan) {
                if ($driver === 'sqlite' && ! $sqliteFuera) {
                    // Ya dentro de una transacción: la revisión de llaves se pospone al final
                    $conexion->statement('PRAGMA defer_foreign_keys = ON');
                }
                $this->soltarReferencias();
                foreach ($this->ordenHijosPrimero(array_keys($plan)) as $tabla) {
                    if ($plan[$tabla]['modo'] !== 'conservar' && $plan[$tabla]['renglones'] > 0) {
                        $this->aBorrar($tabla, $plan[$tabla]['modo'])->delete();
                    }
                }
            });
        } finally {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $conexion->statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($sqliteFuera) {
                $conexion->statement('PRAGMA foreign_keys = ON');
            }
        }

        return collect($plan)->filter(fn ($p) => $p['renglones'] > 0)->map(fn ($p) => $p['renglones'])->all();
    }

    /**
     * Archivos subidos de empresas (fotos, documentos, firmas, credenciales,
     * importaciones, logotipos…): carpeta => número de archivos.
     *
     * @return array<string, int>
     */
    public function archivos(): array
    {
        $r = [];
        foreach ($this->carpetasABorrar() as $nombre => $ruta) {
            $r[$nombre] = is_dir($ruta) ? count(File::allFiles($ruta, true)) : 1;
        }

        return array_filter($r);
    }

    /**
     * Borra los archivos subidos de empresas. Nunca toca respaldos ni traslados.
     */
    public function borrarArchivos(): int
    {
        $n = 0;
        foreach ($this->carpetasABorrar() as $ruta) {
            if (is_dir($ruta)) {
                $n += count(File::allFiles($ruta, true));
                File::deleteDirectory($ruta);
            } elseif (@unlink($ruta)) {
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ interno

    /**
     * Todas las tablas de ESTA base (nunca las de otra base que el usuario de
     * la base alcance a ver en el mismo servidor).
     *
     * @return list<string>
     */
    public function tablas(): array
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
                ->map(fn ($fila) => (string) array_values((array) $fila)[0])->sort()->values()->all();
        }

        return collect(Schema::getTableListing('main', false))->sort()->values()->all();
    }

    private function modo(string $tabla): string
    {
        return match (true) {
            in_array($tabla, self::CONSERVAR, true) => 'conservar',
            $tabla === 'users' => 'super_administradores',
            in_array($tabla, ['rol_permisos', 'usuario_roles'], true) => 'dependiente',
            in_array($tabla, self::SOLO_DE_EMPRESAS, true) => 'solo_empresas',
            default => 'vaciar',
        };
    }

    private function aBorrar(string $tabla, string $modo)
    {
        $q = DB::table($tabla);

        return match ($modo) {
            'super_administradores' => $q->where('es_superadmin', false),
            'solo_empresas' => $q->whereNotNull('empresa_id'),
            // Permisos de los roles que se van y roles asignados a cuentas o roles que se van
            'dependiente' => $tabla === 'rol_permisos'
                ? $q->whereNotIn('rol_id', DB::table('roles')->whereNull('empresa_id')->select('id'))
                : $q->where(fn ($w) => $w->whereNotIn('rol_id', DB::table('roles')->whereNull('empresa_id')->select('id'))
                    ->orWhereNotIn('user_id', DB::table('users')->where('es_superadmin', true)->select('id'))),
            default => $q,
        };
    }

    /**
     * Lo que se conserva no debe apuntar a lo que se borra: los Super
     * Administradores quedan sin empresa ni colaborador ligado y la bitácora
     * de la plataforma sin sede.
     */
    private function soltarReferencias(): void
    {
        $quedan = DB::table('users')->where('es_superadmin', true)->pluck('id');
        DB::table('users')->where('es_superadmin', true)->update(['empresa_id' => null, 'colaborador_id' => null, 'numero_colaborador' => null]);
        foreach (['creado_por', 'actualizado_por'] as $columna) {
            DB::table('users')->where('es_superadmin', true)->whereNotNull($columna)->whereNotIn($columna, $quedan)->update([$columna => null]);
        }
        DB::table('auditoria')->whereNull('empresa_id')->whereNotNull('sede_id')->update(['sede_id' => null]);
    }

    /**
     * Las tablas hijas primero (por si la base aún revisa llaves al borrar).
     *
     * @param  list<string>  $tablas
     * @return list<string>
     */
    private function ordenHijosPrimero(array $tablas): array
    {
        $padres = [];
        foreach ($tablas as $t) {
            $padres[$t] = collect(Schema::getForeignKeys($t))->pluck('foreign_table')
                ->filter(fn ($p) => $p !== $t && in_array($p, $tablas, true))->unique()->values()->all();
        }
        $orden = [];
        $visitando = [];
        $visitar = function (string $t) use (&$visitar, &$orden, &$visitando, $padres, $tablas) {
            if (isset($orden[$t]) || isset($visitando[$t])) {
                return;
            }
            $visitando[$t] = true;
            // Antes que $t van todas las tablas que lo usan como padre
            foreach ($tablas as $hija) {
                if (in_array($t, $padres[$hija], true)) {
                    $visitar($hija);
                }
            }
            $orden[$t] = true;
        };
        foreach ($tablas as $t) {
            $visitar($t);
        }

        return array_keys($orden);
    }

    /**
     * @return array<string, string> nombre visible => ruta
     */
    private function carpetasABorrar(): array
    {
        $r = [];
        foreach (['private' => self::CARPETAS_PRIVADAS_CONSERVADAS, 'public' => self::CARPETAS_PUBLICAS_CONSERVADAS] as $disco => $conservar) {
            $raiz = storage_path('app/'.$disco);
            foreach (is_dir($raiz) ? (scandir($raiz) ?: []) : [] as $nombre) {
                if ($nombre === '.' || $nombre === '..' || in_array($nombre, $conservar, true) || in_array($nombre, self::ARCHIVOS_CONTROL, true)) {
                    continue;
                }
                $r[$disco.'/'.$nombre] = $raiz.'/'.$nombre;
            }
        }

        return $r;
    }
}
