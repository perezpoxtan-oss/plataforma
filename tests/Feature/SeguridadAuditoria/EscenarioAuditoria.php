<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Rutas;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;

/**
 * Escenario de la auditoría de autorización (docs/seguridad/auditoria-2026-10-06-autorizacion.md):
 * la empresa demo completa (plataforma:demo: dos sedes, un usuario por rol
 * y registros en todos los módulos) y una segunda empresa "intrusa" con su
 * propio administrador.
 */
trait EscenarioAuditoria
{
    use CreaDatosNucleo;

    protected Empresa $demo;

    protected Empresa $intrusa;

    protected Sede $centro;

    protected Sede $playa;

    protected User $adminIntruso;

    protected function prepararEscenario(): void
    {
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();

        $this->demo = Empresa::where('nombre_comercial', 'Hotel Demo')->firstOrFail();
        $this->centro = Sede::withoutGlobalScopes()->where('empresa_id', $this->demo->id)->where('codigo', 'CEN')->firstOrFail();
        $this->playa = Sede::withoutGlobalScopes()->where('empresa_id', $this->demo->id)->where('codigo', 'PLA')->firstOrFail();

        $this->intrusa = $this->crearEmpresa('Hotel Intruso');
        $this->crearSede($this->intrusa, 'INT');
        $this->adminIntruso = $this->crearUsuario($this->intrusa, 'Administrador');

        app(Tenant::class)->establecer(null);
        app(Autorizador::class)->olvidar();
    }

    protected function usuario(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /**
     * Rutas de la aplicación que exigen sesión (sin las del framework).
     *
     * @return list<Route>
     */
    protected function rutasConSesion(): array
    {
        return collect(Rutas::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->reject(fn (Route $r) => $r->getName() === 'logout')
            ->values()
            ->all();
    }

    /**
     * Tabla de la que sale cada parámetro de ruta.
     */
    private function tablaDe(string $ruta, string $parametro): ?string
    {
        if ($parametro === 'equipo') {
            return str_starts_with($ruta, 'recorridos_pc.') ? 'equipos_pc' : 'equipos';
        }
        // Eliminar definitivamente (/borrar/{registro}/{id}): se recorre con llaves (registro de una sede)
        if ($parametro === 'id' && str_starts_with($ruta, 'borrar.')) {
            return 'llaves';
        }

        return [
            'acompanante' => 'acompanantes_acceso',
            'acceso' => 'accesos',
            'colaborador' => 'colaboradores',
            'departamento' => 'departamentos',
            'grupo' => 'grupos_espacio',
            'espacio' => 'espacios',
            'zona' => 'zonas_estacionamiento',
            'gafete' => 'gafetes',
            'llave' => 'llaves',
            'articulo' => 'lost_found_articulos',
            'entrega' => 'lost_found_entregas',
            'reporte' => 'lost_found_reportes_perdida',
            'novedad' => 'novedades',
            'pase' => 'pases_salida',
            'persona' => 'personas',
            'prestamo' => 'prestamos_llaves',
            'proveedor' => 'proveedores',
            'puesto' => 'puestos',
            'recorrido' => 'recorridos_pc',
            'responsiva' => 'responsivas',
            'paradero' => 'paraderos',
            'ruta' => 'rutas',
            'movimiento' => 'movimientos_transporte',
            'turno' => 'turnos',
            'vehiculo' => 'vehiculos',
            'voucher' => 'vouchers_reposicion',
        ][$parametro] ?? null;
    }

    /**
     * Un registro real de la empresa demo para cada parámetro, de la sede
     * indicada (Centro por omisión) cuando la tabla tiene sede.
     */
    protected function valorParametro(string $ruta, string $parametro, ?Sede $sede = null): mixed
    {
        $sede ??= $this->centro;

        switch ($parametro) {
            case 'empresa':
                return $this->demo->id;
            case 'sede':
                return $sede->id;
            case 'usuario':
                return $this->usuario($sede->is($this->playa) ? 'agente2.demo' : 'agente.demo')->id;
            case 'rol':
                return $ruta === 'novedades.firma'
                    ? 'seguridad'
                    : DB::table('roles')->where('empresa_id', $this->demo->id)->where('nombre', 'Agente')->value('id');
            case 'cual':
                return 'guardia';
            case 'clave':
                return 'accesos';
            case 'registro':
                return 'llaves';
            case 'archivo':
                return 'respaldo.zip';
            case 'firma':
                $pase = $this->consulta('pases_salida', $sede)->pluck('id');

                return DB::table('pases_salida_firmas')->whereIn('pase_salida_id', $pase)->orderBy('id')->value('id');
            case 'codigo':
                return $this->consulta($ruta === 'vehiculos.qr' ? 'vehiculos' : 'llaves', $sede)->orderBy('id')->value('codigo_qr');
        }

        $tabla = $this->tablaDe($ruta, $parametro);
        if ($tabla === null) {
            throw new \RuntimeException("Parámetro sin resolver: {$ruta} {{$parametro}}");
        }

        return $this->consulta($tabla, $sede)->orderBy($tabla.'.id')->value($tabla.'.id');
    }

    /**
     * Registros de la empresa demo que pertenecen SOLO a esa sede (no a
     * todas, ni compartidos con la otra sede por destino o sedes adicionales).
     * Las tablas sin sede (vehículos, personas, catálogos) son de toda la empresa.
     */
    private function consulta(string $tabla, Sede $sede): Builder
    {
        $otra = $sede->is($this->centro) ? $this->playa->id : $this->centro->id;
        $consulta = DB::table($tabla)->where($tabla.'.empresa_id', $this->demo->id);

        switch ($tabla) {
            case 'colaboradores':
                return $consulta->where('sede_id', $sede->id)->whereNotExists(fn ($q) => $q->from('colaborador_sede')
                    ->whereColumn('colaborador_sede.colaborador_id', 'colaboradores.id')->where('colaborador_sede.sede_id', $otra));
            case 'proveedores':
                return $consulta->where('todas_las_sedes', false)
                    ->whereExists(fn ($q) => $q->from('proveedor_sede')->whereColumn('proveedor_sede.proveedor_id', 'proveedores.id')->where('proveedor_sede.sede_id', $sede->id))
                    ->whereNotExists(fn ($q) => $q->from('proveedor_sede')->whereColumn('proveedor_sede.proveedor_id', 'proveedores.id')->where('proveedor_sede.sede_id', $otra));
            case 'pases_salida':
                return $consulta->where('sede_id', $sede->id)->where(fn ($q) => $q->whereNull('sede_destino_id')->orWhere('sede_destino_id', $sede->id));
            case 'acompanantes_acceso':
                return $consulta->whereIn('acceso_id', DB::table('accesos')->where('sede_id', $sede->id)->select('id'));
            case 'lost_found_entregas':
                return $consulta->whereIn('articulo_id', DB::table('lost_found_articulos')->where('sede_id', $sede->id)->select('id'));
        }

        return Schema::hasColumn($tabla, 'sede_id') ? $consulta->where('sede_id', $sede->id) : $consulta;
    }

    /**
     * URI de la ruta con registros de la empresa demo (null si la demo no
     * tiene un registro así). Con $inexistente, los ids no existen.
     */
    protected function uri(Route $ruta, ?Sede $sede = null, bool $inexistente = false): ?string
    {
        $parametros = [];
        foreach ($ruta->parameterNames() as $nombre) {
            $esTexto = in_array($nombre, ['cual', 'clave', 'archivo', 'registro'], true) || ($nombre === 'rol' && $ruta->getName() === 'novedades.firma');
            $valor = match (true) {
                ! $inexistente || $esTexto => $this->valorParametro((string) $ruta->getName(), $nombre, $sede),
                $nombre === 'codigo' => 'zzzzzzzzzzzzzzzzzzzzzzzz',
                default => 987654,
            };
            if ($valor === null) {
                return null;
            }
            $parametros[$nombre] = $valor;
        }

        return route($ruta->getName(), $parametros, false);
    }

    /**
     * ¿El registro de esta ruta es de una sede? (los de toda la empresa no aplican en las pruebas de sede)
     */
    protected function rutaConSede(Route $ruta): bool
    {
        foreach ($ruta->parameterNames() as $nombre) {
            $tabla = $this->tablaDe((string) $ruta->getName(), $nombre);
            if (in_array($nombre, ['sede', 'usuario', 'firma', 'codigo'], true)
                || ($tabla !== null && (Schema::hasColumn($tabla, 'sede_id') || in_array($tabla, ['acompanantes_acceso', 'lost_found_entregas'], true)))) {
                return ! ($nombre === 'codigo' && $ruta->getName() === 'vehiculos.qr');
            }
        }

        return false;
    }

    /** Columna => tabla a la que apunta (para revisar referencias entre empresas). */
    private const REFERENCIAS = [
        'sede_id' => 'sedes', 'sede_destino_id' => 'sedes',
        'colaborador_id' => 'colaboradores', 'visita_colaborador_id' => 'colaboradores', 'host_colaborador_id' => 'colaboradores',
        'reportado_colaborador_id' => 'colaboradores', 'colaborador_destino_id' => 'colaboradores', 'fusionado_en_id' => 'colaboradores',
        'persona_id' => 'personas', 'chofer_id' => 'personas', 'proveedor_id' => 'proveedores', 'gafete_id' => 'gafetes', 'vehiculo_id' => 'vehiculos',
        'zona_estacionamiento_id' => 'zonas_estacionamiento', 'departamento_id' => 'departamentos', 'puesto_id' => 'puestos',
        'novedad_id' => 'novedades', 'origen_novedad_id' => 'novedades', 'accidente_novedad_id' => 'novedades',
        'acceso_id' => 'accesos', 'acceso_origen_id' => 'accesos', 'tipo_equipo_id' => 'tipos_equipo', 'tipo_gafete_id' => 'tipos_gafete',
        'espacio_id' => 'espacios', 'area_id' => 'espacios', 'area_especifica_id' => 'espacios', 'grupo_espacio_id' => 'grupos_espacio',
        'responsiva_id' => 'responsivas', 'equipo_id' => 'equipos', 'equipo_pc_id' => 'equipos_pc', 'llave_id' => 'llaves',
        'articulo_id' => 'lost_found_articulos', 'articulo_vinculado_id' => 'lost_found_articulos',
        'movimiento_transporte_id' => 'movimientos_transporte', 'ruta_id' => 'rutas', 'ruta_horario_id' => 'ruta_horarios',
        'paradero_id' => 'paraderos', 'pase_salida_id' => 'pases_salida', 'recorrido_pc_id' => 'recorridos_pc', 'turno_id' => 'turnos',
    ];

    /**
     * Registros que apuntan a un registro de OTRA empresa (p. ej. una llave
     * de la empresa A con la sede de la empresa B). Debe salir vacío.
     *
     * @return list<string>
     */
    protected function referenciasCruzadas(): array
    {
        $tablas = collect(Schema::getTableListing())->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)->all();
        $errores = [];

        foreach ($tablas as $tabla) {
            $columnas = Schema::getColumnListing($tabla);
            $conEmpresa = in_array('empresa_id', $columnas, true);
            $refs = [];
            foreach ($columnas as $columna) {
                $destino = $columna === 'padre_id' && $tabla === 'espacios' ? 'espacios' : (self::REFERENCIAS[$columna] ?? null);
                if ($destino !== null && in_array($destino, $tablas, true) && Schema::hasColumn($destino, 'empresa_id')) {
                    $refs[$columna] = $destino;
                }
            }
            if ($conEmpresa) {
                foreach ($refs as $columna => $destino) {
                    $n = DB::table($tabla.' as t')->join($destino.' as d', 'd.id', '=', 't.'.$columna)
                        ->whereNotNull('d.empresa_id')->whereColumn('d.empresa_id', '!=', 't.empresa_id')->count();
                    if ($n > 0) {
                        $errores[] = "{$tabla}.{$columna} -> {$destino}: {$n}";
                    }
                }
            } elseif (count($refs) === 2) {
                // Tabla puente (colaborador_sede, proveedor_sede, sede_turno…): los dos lados de la misma empresa
                [$c1, $c2] = array_keys($refs);
                $n = DB::table($tabla.' as t')->join($refs[$c1].' as a', 'a.id', '=', 't.'.$c1)->join($refs[$c2].' as b', 'b.id', '=', 't.'.$c2)
                    ->whereColumn('a.empresa_id', '!=', 'b.empresa_id')->count();
                if ($n > 0) {
                    $errores[] = "{$tabla} ({$c1}, {$c2}): {$n}";
                }
            }
        }

        // Cuentas con roles de otra empresa o plantillas de la plataforma
        $n = DB::table('usuario_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')->join('roles as r', 'r.id', '=', 'ur.rol_id')
            ->where(fn ($q) => $q->whereNull('r.empresa_id')->orWhereColumn('r.empresa_id', '!=', 'u.empresa_id'))->count();
        if ($n > 0) {
            $errores[] = "usuario_roles con rol ajeno o plantilla: {$n}";
        }

        return $errores;
    }

    /**
     * Arma, a partir de un formulario HTML, una petición "válida" (campos
     * obligatorios con valores de ejemplo) y la regresa como
     * [método, ruta, datos]. Los campos *_id y sedes[] se pueden reemplazar
     * con ids ajenos para buscar referencias cruzadas.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected function formularios(string $html): array
    {
        preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $formas, PREG_SET_ORDER);
        $resultado = [];
        foreach ($formas as [, $atributos, $cuerpo]) {
            if (! preg_match('/action="([^"]+)"/i', $atributos, $a) || ! preg_match('/method="post"/i', $atributos)) {
                continue;
            }
            $ruta = parse_url(html_entity_decode($a[1]), PHP_URL_PATH) ?: '/';
            if (in_array($ruta, ['/logout', '/empresa-activa'], true)) {
                continue;
            }
            $metodo = preg_match('/name="_method"\s+value="([A-Za-z]+)"/i', $cuerpo, $m) ? strtoupper($m[1]) : 'POST';
            $datos = [];
            preg_match_all('/<input\b([^>]*)>/i', $cuerpo, $entradas);
            foreach ($entradas[1] as $e) {
                if (! preg_match('/name="([^"]+)"/', $e, $n) || in_array($n[1], ['_token', '_method'], true)) {
                    continue;
                }
                $tipo = preg_match('/type="([a-z]+)"/i', $e, $t) ? strtolower($t[1]) : 'text';
                $valor = preg_match('/value="([^"]*)"/', $e, $v) ? html_entity_decode($v[1]) : '';
                if (in_array($tipo, ['checkbox', 'radio'], true) && $valor === '') {
                    $valor = '1';
                }
                if ($valor === '') {
                    $valor = match ($tipo) {
                        'number' => '1', 'date' => now()->toDateString(), 'time' => '08:00', 'email' => 'prueba@ejemplo.mx',
                        'tel' => '9981234567', 'datetime-local' => now()->format('Y-m-d\TH:i'), 'file', 'hidden' => '',
                        default => 'PRUEBA '.substr(md5($n[1]), 0, 6),
                    };
                }
                $this->asignar($datos, html_entity_decode($n[1]), $valor);
            }
            preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $cuerpo, $selects, PREG_SET_ORDER);
            foreach ($selects as [, $s, $opciones]) {
                if (preg_match('/name="([^"]+)"/', $s, $n) && preg_match_all('/<option[^>]*value="([^"]+)"/i', $opciones, $o) && $o[1] !== []) {
                    $this->asignar($datos, html_entity_decode($n[1]), html_entity_decode($o[1][0]));
                }
            }
            preg_match_all('/<textarea\b[^>]*name="([^"]+)"/i', $cuerpo, $areas);
            foreach ($areas[1] as $n) {
                $this->asignar($datos, html_entity_decode($n), 'Texto de prueba');
            }
            $resultado[] = [$metodo, $ruta, $datos];
        }

        return $resultado;
    }

    private function asignar(array &$datos, string $nombre, mixed $valor): void
    {
        // "a[b][c]" / "a[]" a arreglo anidado
        $partes = preg_split('/\[|\]\[|\]/', $nombre, -1, PREG_SPLIT_NO_EMPTY);
        $partes = $partes === [] ? [$nombre] : $partes;
        $ref = &$datos;
        foreach ($partes as $i => $p) {
            if ($i === count($partes) - 1) {
                str_ends_with($nombre, '[]') ? $ref[$p][] = $valor : $ref[$p] = $valor;
            } else {
                $ref[$p] ??= [];
                $ref = &$ref[$p];
            }
        }
    }

    /**
     * Reemplaza en los datos todo campo *_id (y sedes[]) por un id de la
     * empresa demo de la sede indicada.
     */
    protected function conIdsAjenos(array $datos, Sede $sede): array
    {
        $resultado = [];
        foreach ($datos as $clave => $valor) {
            if (is_array($valor)) {
                $resultado[$clave] = $clave === 'sedes' ? [$sede->id] : $this->conIdsAjenos($valor, $sede);

                continue;
            }
            $tabla = is_string($clave) ? (self::REFERENCIAS[$clave] ?? ($clave === 'padre_id' ? 'espacios' : null)) : null;
            $resultado[$clave] = $tabla === null ? $valor
                : ($tabla === 'sedes' ? $sede->id : ($this->consulta($tabla, $sede)->orderBy($tabla.'.id')->value($tabla.'.id') ?? $valor));
        }

        return $resultado;
    }

    /**
     * Huella de todos los datos de negocio: si una petición rechazada cambia
     * algo, la huella cambia.
     */
    protected function huellaDatos(): string
    {
        $ignorar = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];
        $huella = '';
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
            if (! in_array($tabla, $ignorar, true)) {
                $huella .= $tabla.':'.md5(json_encode(DB::table($tabla)->get()->all())).';';
            }
        }

        return md5($huella);
    }
}
