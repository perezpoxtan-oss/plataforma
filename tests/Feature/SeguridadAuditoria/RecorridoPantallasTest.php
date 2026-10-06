<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Console\Commands\CrearDatosDemo;
use App\Models\AccidenteFirma;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\Modulo;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PaseSalidaFirma;
use App\Models\Proveedor;
use App\Models\RecorridoPc;
use App\Models\Responsiva;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Services\Borrado\RegistroBorrado;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as Rutas;
use Illuminate\Support\Str;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;
use Throwable;

/**
 * Revisión funcional 2026-10-06: recorre TODAS las rutas GET de la aplicación
 * con cada usuario demo (y el Super Administrador, con y sin empresa activa)
 * resolviendo los parámetros con registros reales de la empresa demo.
 *
 * Nunca debe haber un 500, un aviso de PHP (Laravel los convierte en
 * excepción) ni texto de error crudo en la página. Las respuestas válidas son
 * 200/204, 302 (validación o redirección), 403 (sin permiso), 404 (no es de
 * su sede o no existe) y 422 (validación JSON).
 */
class RecorridoPantallasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    /** Rutas que no son pantallas de la aplicación. */
    private const OMITIR = ['storage.local', 'login', 'sesion.expirada'];

    /** Texto que delata un error de PHP o de base de datos en la página. */
    private const TEXTO_DE_ERROR = [
        'Undefined variable', 'Undefined array key', 'Undefined index', 'Undefined property',
        'Undefined offset', 'SQLSTATE', 'ErrorException', 'Stack trace', 'Call to a member function',
        'Call to undefined', 'must be of type', 'Attempt to read property', 'Whoops',
        'htmlspecialchars()', 'Array to string conversion', '[object Object]',
    ];

    private Empresa $empresa;

    /** @var list<string> */
    private array $avisos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();
        $this->empresa = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();

        // Cualquier aviso registrado (incluye "deprecated" de PHP) cuenta como falla
        config(['logging.deprecations' => ['channel' => 'null', 'trace' => false]]);
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            if (in_array($e->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)
                || str_contains(strtolower($e->message), 'deprecated')) {
                $this->avisos[] = "[{$e->level}] ".Str::limit($e->message, 300);
            }
        });
    }

    public function test_cada_rol_demo_recorre_todas_las_pantallas_sin_errores(): void
    {
        $fallas = [];
        $visitas = 0;

        foreach (array_keys(CrearDatosDemo::USUARIOS) as $usuario) {
            $actor = User::where('username', $usuario)->firstOrFail();
            [$f, $v] = $this->recorrer($actor, $usuario);
            $fallas = array_merge($fallas, $f);
            $visitas += $v;
        }

        $this->assertGreaterThan(500, $visitas, 'El recorrido debe cubrir todas las rutas con todos los roles');
        $this->assertSame([], $fallas, "Fallas del recorrido:\n".implode("\n", $fallas));
        $this->assertSame([], $this->avisos, "Avisos de PHP o del log:\n".implode("\n", $this->avisos));
    }

    public function test_super_administrador_recorre_todas_las_pantallas_con_y_sin_empresa_activa(): void
    {
        $super = $this->crearSuperadmin();

        [$sinEmpresa] = $this->recorrer($super, 'superadmin (plantillas)');
        [$conEmpresa] = $this->recorrer($super, 'superadmin (Hotel Demo)', [EmpresaDeTrabajo::SESION => $this->empresa->id]);

        $fallas = array_merge($sinEmpresa, $conEmpresa);
        $this->assertSame([], $fallas, "Fallas del recorrido:\n".implode("\n", $fallas));
        $this->assertSame([], $this->avisos, "Avisos de PHP o del log:\n".implode("\n", $this->avisos));
    }

    /**
     * Una empresa recién dada de alta (sin sedes ni registros) y un usuario
     * sin ningún rol: las pantallas vacías no deben romperse, y los registros
     * de la empresa demo (ids reales) nunca se le muestran (404/403).
     */
    public function test_empresa_nueva_sin_datos_y_usuario_sin_rol_no_rompen_ni_ven_lo_ajeno(): void
    {
        $nueva = $this->crearEmpresa('Hotel Vacío');
        $admin = $this->crearUsuario($nueva, 'Administrador');
        $sinRol = $this->crearUsuario($nueva);

        [$f1] = $this->recorrer($admin, 'admin empresa nueva', [], true);
        [$f2] = $this->recorrer($sinRol, 'usuario sin rol', [], true);

        $fallas = array_merge($f1, $f2);
        $this->assertSame([], $fallas, "Fallas del recorrido:\n".implode("\n", $fallas));
        $this->assertSame([], $this->avisos, "Avisos de PHP o del log:\n".implode("\n", $this->avisos));
    }

    /**
     * @param  array<string, mixed>  $sesion
     * @return array{0: list<string>, 1: int}
     */
    private function recorrer(User $actor, string $etiqueta, array $sesion = [], bool $ajena = false): array
    {
        $fallas = [];
        $visitas = 0;

        foreach ($this->rutasGet() as $ruta) {
            $conRegistro = $ruta->parameterNames() !== [] && $ruta->getName() !== 'modulos.pendiente';
            foreach ($this->urlsDe($ruta) as [$url, $json]) {
                $visitas++;
                $this->app['auth']->forgetGuards();
                $peticion = $this->actingAs($actor)->withSession($sesion);
                try {
                    $respuesta = $json ? $peticion->getJson($url) : $peticion->get($url);
                } catch (Throwable $e) {
                    $fallas[] = "{$etiqueta} GET {$url} => excepción ".get_class($e).': '.Str::limit($e->getMessage(), 300);

                    continue;
                }

                $codigo = $respuesta->getStatusCode();
                if (getenv('RECORRIDO_DEBUG')) {
                    fwrite(STDERR, "{$etiqueta}\t{$codigo}\t{$url}\t".($respuesta->headers->get('Location') ?? '')."\n");
                }
                if (! in_array($codigo, [200, 204, 301, 302, 403, 404, 422], true)) {
                    $detalle = $respuesta->exception ? get_class($respuesta->exception).': '.Str::limit($respuesta->exception->getMessage(), 400) : '';
                    $fallas[] = "{$etiqueta} GET {$url} => {$codigo} {$detalle}";

                    continue;
                }

                if ($ajena && $conRegistro) {
                    $final = $codigo;
                    if (in_array($codigo, [301, 302], true)) {
                        // Sin JavaScript algunas fichas redirigen a la lista con ?id=: el destino tampoco debe abrir el registro ajeno
                        $this->app['auth']->forgetGuards();
                        $final = $this->actingAs($actor)->withSession($sesion)->get((string) $respuesta->headers->get('Location'))->getStatusCode();
                    }
                    if (in_array($final, [200, 204], true)) {
                        $fallas[] = "{$etiqueta} GET {$url} => {$codigo}/{$final}: un registro de otra empresa no debe abrirse";
                    }
                }

                $tipo = (string) $respuesta->headers->get('Content-Type');
                if ($codigo === 200 && (str_contains($tipo, 'html') || str_contains($tipo, 'json'))) {
                    $cuerpo = (string) $respuesta->getContent();
                    foreach (self::TEXTO_DE_ERROR as $texto) {
                        if (str_contains($cuerpo, $texto)) {
                            $fallas[] = "{$etiqueta} GET {$url} => contiene «{$texto}»";
                        }
                    }
                }
            }
        }

        return [$fallas, $visitas];
    }

    /**
     * @return list<Route>
     */
    private function rutasGet(): array
    {
        return collect(Rutas::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->reject(fn (Route $r) => in_array($r->getName(), self::OMITIR, true) || $r->uri() === 'up')
            ->values()
            ->all();
    }

    /**
     * Una o varias URL por ruta: con el primer registro de la empresa demo y,
     * cuando importa, con uno que sí tenga lo que la pantalla muestra (firma…).
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function urlsDe(Route $ruta): array
    {
        $nombre = (string) $ruta->getName();
        $parametros = $ruta->parameterNames();
        $extras = $this->consultaExtra($nombre);

        if ($parametros === []) {
            $urls = [[route($nombre), false]];
            foreach ($extras as [$consulta, $json]) {
                $urls[] = [route($nombre).'?'.http_build_query($consulta), $json];
            }

            return $urls;
        }

        $urls = [];
        foreach ($this->juegosDeParametros($nombre) as $juego) {
            $urls[] = [route($nombre, $juego), false];
        }
        // Un id que no existe: debe ser 404, nunca 500
        $inexistente = array_map(fn ($p) => match ($p) {
            'rol' => 'afectado', 'cual' => 'guardia', 'pantalla' => 'qr', 'codigo' => 'noexiste0000', 'clave' => 'no_existe', 'archivo' => 'no-existe.sql.gz',
            default => 999999,
        }, array_combine($parametros, $parametros));
        $urls[] = [route($nombre, $inexistente), false];

        return $urls;
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function juegosDeParametros(string $nombre): array
    {
        $e = $this->empresa->id;
        $primero = fn (string $modelo, ?callable $filtro = null) => (function () use ($modelo, $filtro, $e) {
            $q = $modelo::query()->withoutGlobalScopes()->where('empresa_id', $e)->orderBy('id');
            if ($filtro) {
                $filtro($q);
            }

            return $q->value('id');
        })();
        $ids = fn (array $valores) => array_values(array_unique(array_filter($valores)));

        $juegos = match ($nombre) {
            'colaboradores.datos-personales' => array_map(fn ($id) => ['colaborador' => $id], $ids([$primero(Colaborador::class)])),
            'proveedores.show' => array_map(fn ($id) => ['proveedor' => $id], $ids([$primero(Proveedor::class)])),
            'vehiculos.calcomania' => array_map(fn ($id) => ['vehiculo' => $id], $ids([$primero(Vehiculo::class)])),
            'vehiculos.qr' => array_map(fn ($c) => ['codigo' => $c], array_filter([Vehiculo::withoutGlobalScopes()->where('empresa_id', $e)->value('codigo_qr')])),
            'espacios.show' => array_map(fn ($id) => ['espacio' => $id], $ids([
                $primero(Espacio::class),
                $primero(Espacio::class, fn ($q) => $q->whereNotNull('padre_id')),
                Espacio::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'equipos.etiqueta', 'equipos.qr', 'pases-salida.equipo', 'responsivas.historial' => array_map(fn ($id) => ['equipo' => $id], $ids([$primero(Equipo::class)])),
            'equipos_pc.etiqueta', 'equipos_pc.qr', 'equipos_pc.ir' => array_map(fn ($id) => ['equipo' => $id], $ids([$primero(EquipoPc::class)])),
            'equipos_pc.anterior.equipo' => array_map(fn ($id) => ['equipo' => $id, 'pantalla' => 'etiqueta'], $ids([$primero(EquipoPc::class)])),
            'rutas.sede', 'rutas.dia' => array_map(fn ($id) => ['sede' => $id], $ids([
                $primero(Sede::class), Sede::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'rutas.itinerario' => array_map(fn ($id) => ['ruta' => $id], $ids([$primero(Ruta::class)])),
            'prestamo_llaves.historial' => array_map(fn ($id) => ['llave' => $id], $ids([$primero(Llave::class)])),
            'vouchers.imprimir' => array_map(fn ($id) => ['voucher' => $id], $ids([$primero(VoucherReposicion::class)])),
            'responsivas.firma', 'responsivas.hoja' => array_map(fn ($id) => ['responsiva' => $id], $ids([$primero(Responsiva::class)])),
            'novedades.imprimir', 'novedades.acuse' => array_map(fn ($id) => ['novedad' => $id], $ids(
                collect(array_keys(Novedad::CATEGORIAS))->map(fn ($cat) => $primero(Novedad::class, fn ($q) => $q->where('categoria', $cat)))->all()
            )),
            'novedades.firma' => AccidenteFirma::query()->withoutGlobalScopes()->whereIn('novedad_id', Novedad::withoutGlobalScopes()->where('empresa_id', $e)->select('id'))
                ->limit(3)->get(['novedad_id', 'rol'])->map(fn ($f) => ['novedad' => $f->novedad_id, 'rol' => $f->rol])->all()
                ?: [['novedad' => (int) $primero(Novedad::class), 'rol' => 'afectado']],
            'pases-salida.show', 'pases-salida.imprimir' => array_map(fn ($id) => ['pase' => $id], $ids([
                $primero(PaseSalida::class), PaseSalida::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'pases-salida.firma' => PaseSalidaFirma::query()->withoutGlobalScopes()->whereIn('pase_salida_id', PaseSalida::withoutGlobalScopes()->where('empresa_id', $e)->select('id'))
                ->limit(2)->get(['id', 'pase_salida_id'])->map(fn ($f) => ['pase' => $f->pase_salida_id, 'firma' => $f->id])->all(),
            'pases-salida.verificar' => array_map(fn ($c) => ['codigo' => $c], array_filter([PaseSalida::withoutGlobalScopes()->where('empresa_id', $e)->value('codigo_verificacion')])),
            'transporte.show', 'transporte.vale' => array_map(fn ($id) => ['movimiento' => $id], $ids([
                $primero(MovimientoTransporte::class), MovimientoTransporte::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'transporte.firma' => collect([$primero(MovimientoTransporte::class, fn ($q) => $q->whereNotNull('firma_guardia'))])->filter()
                ->flatMap(fn ($id) => [['movimiento' => $id, 'cual' => 'guardia'], ['movimiento' => $id, 'cual' => 'taxista']])->all(),
            'lost_found.articulos.show', 'lost_found.articulos.etiqueta' => array_map(fn ($id) => ['articulo' => $id], $ids([
                $primero(LostFoundArticulo::class), LostFoundArticulo::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'lost_found.entregas.firma' => array_map(fn ($id) => ['entrega' => $id], $ids([$primero(LostFoundEntrega::class)])),
            'recorridos_pc.show' => array_map(fn ($id) => ['recorrido' => $id], $ids([
                $primero(RecorridoPc::class), RecorridoPc::withoutGlobalScopes()->where('empresa_id', $e)->orderByDesc('id')->value('id'),
            ])),
            'modulos.pendiente' => Modulo::query()->where('activo', true)->pluck('clave')->map(fn ($c) => ['clave' => $c])->all(),
            'lector.ir' => collect([Vehiculo::class, Equipo::class, EquipoPc::class, Llave::class, Gafete::class, Colaborador::class, LostFoundArticulo::class])
                ->map(fn ($m) => $m::withoutGlobalScopes()->where('empresa_id', $e)->value('codigo_qr'))->filter()->map(fn ($c) => ['codigo' => $c])->values()->all(),
            'configuracion.respaldos.descargar' => [],
            // Ronda 5: QR del diálogo "Código e identificación" (cada tipo del lector) y firmas de vouchers
            'identificacion.qr' => collect(['vehiculo' => Vehiculo::class, 'equipo' => Equipo::class, 'equipo_pc' => EquipoPc::class, 'llave' => Llave::class,
                'gafete' => Gafete::class, 'colaborador' => Colaborador::class, 'lost_found' => LostFoundArticulo::class])
                ->map(fn ($m, $tipo) => ['tipo' => $tipo, 'id' => $primero($m)])->filter(fn ($j) => $j['id'] !== null)->values()->all(),
            'vouchers.firma' => collect(['seguridad', 'responsable', 'hoja'])->map(fn ($p) => ['voucher' => $primero(VoucherReposicion::class), 'parte' => $p])
                ->filter(fn ($j) => $j['voucher'] !== null)->values()->all(),
            // Eliminar definitivamente: el primer registro de cada catálogo o padrón registrado
            'borrar.revisar' => collect(RegistroBorrado::definiciones())
                ->map(fn ($d, $clave) => ['registro' => $clave, 'id' => $primero($d['modelo'])])->filter(fn ($j) => $j['id'] !== null)->values()->all(),
            default => null,
        };

        $this->assertNotNull($juegos, "La ruta {$nombre} tiene parámetros y el recorrido no sabe resolverlos: agrégala a juegosDeParametros()");

        return $juegos;
    }

    /**
     * Consultas reales de las búsquedas y filtros (además de la URL sin nada).
     *
     * @return list<array{0: array<string, mixed>, 1: bool}>
     */
    private function consultaExtra(string $nombre): array
    {
        $sede = Sede::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->orderBy('id')->value('id');
        $hoy = now()->toDateString();

        return match ($nombre) {
            'colaboradores.buscar', 'proveedores.buscar', 'personas.buscar', 'vehiculos.buscar' => [[['q' => 'a'], true], [['q' => '%_\''], true]],
            'accesos.buscar' => collect(['colaborador', 'persona', 'vehiculo', 'proveedor'])->map(fn ($que) => [['que' => $que, 'q' => 'a', 'sede' => $sede], true])->all(),
            'accesos.gafetes' => [[['sede' => $sede], true]],
            'accesos.en-sitio' => [[['sede' => $sede], true]],
            'lector.resolver' => [[['entrada' => 'abc123'], true], [['entrada' => '04:A2:3B:1C'], true]],
            'novedades.coincidencias' => [[['tipo_valor' => 'otro', 'objeto' => 'cartera', 'fecha' => $hoy], true]],
            'novedades.ficha-hechos' => [[['habitacion' => 101, 'fecha' => $hoy], false]],
            'novedades.index' => [[['categoria' => 'accidente_huesped'], false], [['pestana' => 'resueltas', 'q' => 'a'], false], [['sede' => $sede], false]],
            'novedades.exportar' => [[['pestana' => 'resueltas'], false]],
            'accesos.index' => [[['pestana' => 'historial', 'sede' => $sede], false], [['pestana' => 'pendientes'], false], [['desde' => $hoy, 'hasta' => $hoy], false]],
            'transporte.index', 'transporte.reportes' => [[['desde' => now()->subMonth()->toDateString(), 'hasta' => $hoy, 'sede' => $sede], false]],
            'recorridos_pc.reporte' => [[['desde' => now()->subMonth()->toDateString(), 'hasta' => $hoy], false]],
            'auditoria.index' => [[['q' => 'a'], false], [['modulo' => 'novedades'], false]],
            'colaboradores.index', 'personas.index', 'vehiculos.index', 'proveedores.index', 'llaves.index', 'gafetes.index', 'equipos.index' => [[['q' => 'a'], false], [['estado' => 'inactivos'], false], [['sede' => $sede], false], [['pagina' => 2, 'page' => 2], false]],
            'gafetes.imprimir' => [[['gafetes' => Gafete::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->limit(3)->pluck('id')->all()], false]],
            'llaves.imprimir' => [[['llaves' => Llave::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->limit(3)->pluck('id')->all()], false]],
            'prestamo_llaves.index', 'responsivas.index', 'pases-salida.index', 'lost_found.archivo', 'robo.index', 'recorridos_pc.index' => [[['sede' => $sede], false], [['pestana' => 'historial'], false], [['q' => 'a'], false]],
            'usuarios.index', 'roles.index', 'permisos.index' => [[['q' => 'a'], false], [['rol' => 1], false]],
            default => [],
        };
    }
}
