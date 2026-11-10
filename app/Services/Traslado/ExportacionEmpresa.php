<?php

namespace App\Services\Traslado;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Exportación de TODO lo de una empresa (portabilidad): un JSON por tabla,
 * hojas CSV de lo principal y los archivos que sus registros mencionan.
 *
 * Qué tablas entran (se decide leyendo el esquema, no con una lista fija,
 * para que un módulo nuevo entre solo):
 *   - empresas: solo su renglón;
 *   - toda tabla con columna empresa_id: sus renglones (empresa_id = la empresa);
 *   - tablas sin empresa_id que cuelgan por llave foránea de una tabla de la
 *     empresa (sede_turno, usuario_roles, rol_permisos…): solo los renglones
 *     que apuntan a registros de la empresa.
 * Nunca se hace una consulta sin filtro de empresa.
 *
 * Seguridad: no viajan contraseñas, fichas de sesión ni enlaces de acceso.
 */
class ExportacionEmpresa
{
    /** Tablas que nunca viajan (temporales, credenciales o del sistema). */
    public const EXCLUIR_TABLAS = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs',
        // Enlaces del kiosco de candidatos: su código equivale a una contraseña
        'enlaces_kiosco',
    ];

    /** Columnas que nunca viajan (contraseñas y fichas). */
    public const PATRON_COLUMNA_SENSIBLE = '/(^|_)(password|contrasena|remember_token|token|token_hash|secret|secreto|recovery_codes)$/i';

    /** Lo que el manifest declara que NO trae. */
    public const NO_INCLUYE = ['contraseñas', 'fichas de sesión y de recordarme', 'enlaces de acceso del kiosco', 'tablas temporales del sistema'];

    /**
     * Hojas CSV legibles: clave => [tabla, columnas preferidas (las demás se agregan después)].
     */
    public const HOJAS = [
        'sedes' => 'sedes',
        'colaboradores' => 'colaboradores',
        'usuarios' => 'users',
        'padron_personas' => 'personas',
        'padron_vehiculos' => 'vehiculos',
        'padron_empresas_externas' => 'proveedores',
        'padron_llaves' => 'llaves',
        'padron_gafetes' => 'gafetes',
        'padron_equipos' => 'equipos',
        'configuracion' => null,
    ];

    /** Columnas que en la hoja de usuarios no aportan (además de las sensibles). */
    private const USUARIOS_SIN = ['two_factor_secret', 'two_factor_recovery_codes'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $tablas = null;

    /**
     * Las tareas del trabajo: una por tabla, luego los archivos y las hojas.
     *
     * @return list<list<mixed>>
     */
    public function plan(int $empresaId): array
    {
        $tareas = [['tabla', 'empresas', ['tipo' => 'id']]];
        foreach ($this->tablas() as $tabla => $filtro) {
            if ($tabla !== 'empresas') {
                $tareas[] = ['tabla', $tabla, $filtro];
            }
        }
        $tareas[] = ['archivos-empresa'];
        foreach (array_keys(self::HOJAS) as $hoja) {
            $tabla = self::HOJAS[$hoja];
            if ($tabla === null || Schema::hasTable($tabla)) {
                $tareas[] = ['csv', $hoja];
            }
        }

        return $tareas;
    }

    /**
     * Tablas de la empresa y cómo se filtran.
     *
     * @return array<string, array{tipo: string, fks?: list<array{0: string, 1: string, 2: string}>}>
     */
    public function tablas(): array
    {
        if ($this->tablas !== null) {
            return $this->tablas;
        }
        $todas = collect(Schema::getTableListing(schemaQualified: false))
            ->map(fn ($t) => (string) $t)
            ->reject(fn ($t) => in_array($t, self::EXCLUIR_TABLAS, true) || str_starts_with($t, 'sqlite_'))
            ->sort()->values();

        $propias = ['empresas' => ['tipo' => 'id']];
        foreach ($todas as $tabla) {
            if ($tabla !== 'empresas' && Schema::hasColumn($tabla, 'empresa_id')) {
                $propias[$tabla] = ['tipo' => 'empresa'];
            }
        }

        // Tablas hijas (sin empresa_id) que apuntan a tablas de la empresa; dos niveles bastan hoy
        for ($nivel = 0; $nivel < 2; $nivel++) {
            foreach ($todas as $tabla) {
                if (isset($propias[$tabla])) {
                    continue;
                }
                $fks = [];
                foreach (Schema::getForeignKeys($tabla) as $fk) {
                    $padre = (string) $fk['foreign_table'];
                    if (isset($propias[$padre]) && count($fk['columns']) === 1) {
                        $fks[] = [$fk['columns'][0], $padre, $fk['foreign_columns'][0] ?? 'id'];
                    }
                }
                if ($fks !== []) {
                    $propias[$tabla] = ['tipo' => 'hijo', 'fks' => $fks];
                }
            }
        }

        return $this->tablas = $propias;
    }

    /**
     * Consulta de los renglones de la empresa en una tabla (siempre filtrada).
     *
     * @param  array<string, mixed>  $filtro
     */
    public function consulta(int $empresaId, string $tabla, array $filtro, int $profundidad = 0): Builder
    {
        $q = DB::table($tabla);

        return match ($filtro['tipo']) {
            'id' => $q->where('id', $empresaId),
            'empresa' => $q->where('empresa_id', $empresaId),
            'hijo' => $q->where(function (Builder $w) use ($empresaId, $filtro, $profundidad, $tabla) {
                if ($profundidad > 3) {
                    throw new RuntimeException("Relación demasiado profunda en {$tabla}.");
                }
                foreach ($filtro['fks'] as [$columna, $padre, $columnaPadre]) {
                    $filtroPadre = $this->tablas()[$padre] ?? null;
                    if ($filtroPadre === null) {
                        continue;
                    }
                    $w->orWhereIn($columna, $this->consulta($empresaId, $padre, $filtroPadre, $profundidad + 1)->select($columnaPadre));
                }
            }),
            default => throw new RuntimeException('Filtro no válido.'),
        };
    }

    /**
     * Escribe los renglones como JSON (un objeto por renglón, uno por línea).
     * Devuelve cuántos y qué archivos de storage mencionan («disco:ruta»).
     *
     * @param  array<string, mixed>  $filtro
     * @return array{filas: int, archivos: list<string>}
     */
    public function tablaJson(int $empresaId, string $tabla, array $filtro, string $destino): array
    {
        $f = fopen($destino, 'wb');
        if ($f === false) {
            throw new RuntimeException('No se pudo preparar la exportación.');
        }
        @chmod($destino, 0600);
        $columnas = $this->columnas($tabla);
        fwrite($f, "[\n");
        $filas = 0;
        $archivos = [];
        $q = $this->consulta($empresaId, $tabla, $filtro)->select($columnas);
        if (in_array('id', $columnas, true)) {
            $q->orderBy('id');
        }
        foreach ($q->cursor() as $fila) {
            $fila = (array) $fila;
            fwrite($f, ($filas > 0 ? ",\n" : '').json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            foreach ($fila as $valor) {
                foreach ($this->archivosEn($valor) as $clave) {
                    $archivos[$clave] = true;
                }
            }
            $filas++;
        }
        fwrite($f, "\n]\n");
        fclose($f);

        return ['filas' => $filas, 'archivos' => array_keys($archivos)];
    }

    /**
     * Hoja CSV (con BOM para que Excel respete los acentos).
     */
    public function csv(int $empresaId, string $hoja, string $destino): void
    {
        $f = fopen($destino, 'wb');
        if ($f === false) {
            throw new RuntimeException('No se pudo preparar la hoja.');
        }
        @chmod($destino, 0600);
        fwrite($f, "\xEF\xBB\xBF");

        if ($hoja === 'configuracion') {
            // Ajustes y preferencias de la empresa, uno por renglón, más sus módulos contratados
            fputcsv($f, ['ajuste', 'valor'], ',', '"', '');
            $empresa = (array) DB::table('empresas')->where('id', $empresaId)->first();
            foreach ($empresa as $clave => $valor) {
                if ($clave === 'preferencias') {
                    continue;
                }
                fputcsv($f, ["empresa.{$clave}", (string) $valor], ',', '"', '');
            }
            $preferencias = json_decode((string) ($empresa['preferencias'] ?? ''), true);
            foreach ($this->aplanar(is_array($preferencias) ? $preferencias : [], 'preferencias') as $clave => $valor) {
                fputcsv($f, [$clave, $valor], ',', '"', '');
            }
            if (Schema::hasTable('empresa_modulos') && Schema::hasTable('modulos')) {
                $modulos = DB::table('empresa_modulos')->join('modulos', 'modulos.id', '=', 'empresa_modulos.modulo_id')
                    ->where('empresa_modulos.empresa_id', $empresaId)->orderBy('modulos.clave')
                    ->get(['modulos.clave', 'modulos.nombre', 'empresa_modulos.activo']);
                foreach ($modulos as $m) {
                    fputcsv($f, ["modulo.{$m->clave}", ($m->activo ? 'contratado' : 'no contratado').' ('.$m->nombre.')'], ',', '"', '');
                }
            }
            fclose($f);

            return;
        }

        $tabla = self::HOJAS[$hoja] ?? throw new RuntimeException('Hoja desconocida.');
        $columnas = array_values(array_diff($this->columnas($tabla), $tabla === 'users' ? self::USUARIOS_SIN : []));
        fputcsv($f, $columnas, ',', '"', '');
        $q = DB::table($tabla)->where('empresa_id', $empresaId)->select($columnas);
        if (in_array('id', $columnas, true)) {
            $q->orderBy('id');
        }
        foreach ($q->cursor() as $fila) {
            fputcsv($f, array_map(fn ($v) => self::celda($v), array_values((array) $fila)), ',', '"', '');
        }
        fclose($f);
    }

    /**
     * Columnas que viajan (sin contraseñas ni fichas).
     *
     * @return list<string>
     */
    public function columnas(string $tabla): array
    {
        return array_values(array_filter(Schema::getColumnListing($tabla), fn ($c) => ! preg_match(self::PATRON_COLUMNA_SENSIBLE, $c)));
    }

    /**
     * Seguridad (CSV): una celda que empieza con = + - @ se abriría como fórmula en Excel.
     */
    public static function celda(mixed $valor): string
    {
        $texto = $valor === null ? '' : (is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor);

        return preg_match('/^[=+\-@\t\r]/', $texto) && ! is_numeric($texto) ? "'".$texto : $texto;
    }

    /**
     * Archivos de storage que menciona un valor (ruta directa o dentro de un JSON).
     *
     * @return list<string> «private:ruta» o «public:ruta»
     */
    public function archivosEn(mixed $valor, int $profundidad = 0): array
    {
        if (! is_string($valor) || $valor === '' || strlen($valor) > 65535 || $profundidad > 3) {
            return [];
        }
        $primero = $valor[0];
        if (($primero === '[' || $primero === '{') && is_array($json = json_decode($valor, true))) {
            $encontrados = [];
            array_walk_recursive($json, function ($v) use (&$encontrados, $profundidad) {
                foreach ($this->archivosEn($v, $profundidad + 1) as $c) {
                    $encontrados[$c] = true;
                }
            });

            return array_keys($encontrados);
        }
        if (strlen($valor) > 400 || ! preg_match('#^[\w\-./ ]+\.[A-Za-z0-9]{2,5}$#u', $valor) || ! ZipPorPartes::nombreSeguro($valor)) {
            return [];
        }
        $resultado = [];
        if (str_starts_with($valor, 'storage/') && is_file(Paquetes::raizDisco('public').'/'.substr($valor, 8))) {
            $resultado[] = 'public:'.substr($valor, 8);
        } elseif (is_file(Paquetes::raizDisco('private').'/'.$valor) && ! in_array(explode('/', $valor)[0], Paquetes::EXCLUIR_PRIVADO, true)) {
            $resultado[] = 'private:'.$valor;
        } elseif (is_file(Paquetes::raizDisco('public').'/'.$valor)) {
            $resultado[] = 'public:'.$valor;
        }

        return $resultado;
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<string, string>
     */
    private function aplanar(array $datos, string $prefijo): array
    {
        $plano = [];
        foreach ($datos as $clave => $valor) {
            $ruta = "{$prefijo}.{$clave}";
            if (is_array($valor) && ! array_is_list($valor)) {
                $plano += $this->aplanar($valor, $ruta);
            } else {
                $plano[$ruta] = self::celda(is_array($valor) ? implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $valor)) : (is_bool($valor) ? ($valor ? 'sí' : 'no') : $valor));
            }
        }

        return $plano;
    }
}
