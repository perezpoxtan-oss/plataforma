<?php

use App\Models\ConfiguracionPlataforma;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\ModulosEfectivos;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 1 (C-01 y C-05): que el plan vendido funcione.
 *
 * 1. Organización base en todos los planes (C-01, DD-01, DD-02):
 *    Colaboradores (directorio), Departamentos, Puestos, Turnos y
 *    Autorizaciones (Responsables y «No molestar») pasan de Recursos Humanos
 *    a Dirección (menú Estructura → Organización) y al producto «Estructura
 *    y usuarios». Recursos Humanos se queda con Recepción, Candidatos y
 *    Vacantes (y el expediente, por la acción colaboradores.datos_personales).
 * 2. Catálogo comercial coherente (C-05, DD-03): Equipos de Protección Civil
 *    pasa de Padrones a Incidentes y Reservas se vende como complemento
 *    «Reservas y eventos» (producto y paquete para los tres planes).
 * 3. Sincroniza las suscripciones (DD-05): primero convierte en excepciones
 *    los módulos que el Super Administrador cambió a mano en Empresas →
 *    Módulos (A-09: no se pierden), luego recalcula. Lo que un plan ya no
 *    trae queda en solo lectura con aviso al administrador.
 *
 * Todo lo que cambia se anota en configuracion_plataforma
 * («etapa1_cimientos») para que down() lo regrese tal cual.
 * Es idempotente: si ya se aplicó, no hace nada.
 */
return new class extends Migration
{
    private const CLAVE = 'etapa1_cimientos';

    /** Módulos que pasan a Dirección. */
    private const ORGANIZACION = ['colaboradores' => true, 'departamentos' => true, 'puestos' => true, 'turnos' => true, 'autorizaciones' => true];

    /** Las de menú (Autorizaciones no tiene menú propio: su entrada la arma ConstructorMenu). */
    private const CON_MENU = ['colaboradores', 'departamentos', 'puestos', 'turnos'];

    /** producto => [módulos que salen, módulos que entran] */
    private const PRODUCTOS = [
        'estructura' => [[], ['colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones']],
        'recursos_humanos' => [['colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones'], []],
        'padrones' => [['equipos_pc'], []],
        'incidentes' => [[], ['equipos_pc']],
    ];

    /** Textos del catálogo de ejemplo: se cambian solo si nadie los editó. tabla.clave => [antes, después] */
    private const TEXTOS = [
        'productos.estructura' => ['Empresas, sedes, zonas, usuarios, roles, permisos y bitácora de auditoría.',
            'Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores, departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.'],
        'productos.padrones' => ['Personas, vehículos, empresas externas, llaves, gafetes, equipos, estacionamientos, rutas y etiquetas QR.',
            'Personas, vehículos, empresas externas, llaves, gafetes, equipos de seguridad, responsivas, estacionamientos, rutas y etiquetas QR.'],
        'productos.incidentes' => ['Bitácora de novedades, objetos olvidados, robos, recorridos de Protección Civil y procedimientos.',
            'Bitácora de novedades, objetos olvidados, robos, equipos y recorridos de Protección Civil y procedimientos.'],
        'productos.recursos_humanos' => ['Colaboradores, turnos, recepción de candidatos, vacantes y autorizaciones del departamento.',
            'Recepción de candidatos, vacantes y bolsa de trabajo, entrevistas y expediente del colaborador.'],
        'planes.corporativo' => ['Toda la plataforma, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.',
            'Seguridad, incidentes, informes y Recursos Humanos, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.'],
    ];

    public const MOTIVO_EXCEPCION = 'Cambiado a mano en Empresas → Módulos; se conserva al ordenar los planes.';

    public function up(): void
    {
        if (! Schema::hasTable('modulos') || ConfiguracionPlataforma::where('clave', self::CLAVE)->exists()) {
            return;
        }
        $direccion = DB::table('areas')->where('clave', 'direccion')->value('id');
        $colaboradores = DB::table('modulos')->where('clave', 'colaboradores')->value('area_id');
        // Catálogo comercial de antes: el producto RR. HH. todavía vende Colaboradores
        $rhConPersonal = Schema::hasTable('productos') && DB::table('modulo_producto as mp')->join('productos as p', 'p.id', '=', 'mp.producto_id')
            ->join('modulos as m', 'm.id', '=', 'mp.modulo_id')->where('p.clave', 'recursos_humanos')->where('m.clave', 'colaboradores')->exists();
        // Menú de antes: Colaboradores en Recursos Humanos
        $menuDeAntes = DB::table('modulos as m')->join('menus as me', 'me.id', '=', 'm.menu_id')->where('m.clave', 'colaboradores')->where('me.clave', 'recursos_humanos')->exists();
        if ($direccion === null || $colaboradores === null || ((int) $colaboradores === (int) $direccion && ! $rhConPersonal && ! $menuDeAntes)) {
            return; // instalación nueva (o ya ordenada): el catálogo ya los crea en Dirección
        }
        $modulos = DB::table('modulos')->pluck('id', 'clave');
        $nota = ['modulos' => [], 'menu' => [], 'modulo_producto' => [], 'textos' => [], 'creados' => [], 'excepciones' => [], 'empresa_modulos' => [], 'foto' => []];
        $comercial = Schema::hasTable('productos') && Schema::hasTable('suscripciones');

        DB::transaction(function () use ($direccion, $modulos, &$nota, $comercial) {
            // 0. Foto de los módulos de cada cliente con suscripción (down() la regresa tal cual) y
            //    excepciones por lo que se cambió a mano (antes de mover nada: se compara con el cálculo de antes)
            if ($comercial) {
                foreach (DB::table('suscripciones')->pluck('empresa_id') as $empresaId) {
                    $nota['foto'][$empresaId] = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->pluck('activo', 'modulo_id')
                        ->map(fn ($a) => (int) $a)->all();
                }
                $nota['excepciones'] = $this->excepcionesPorCambiosManuales();
            }

            // 1. Áreas (C-01)
            foreach (array_keys(self::ORGANIZACION) as $clave) {
                $fila = DB::table('modulos')->where('clave', $clave)->first(['id', 'area_id']);
                if ($fila === null) {
                    continue;
                }
                $nota['modulos'][$clave] = (array) $fila;
                DB::table('modulos')->where('id', $fila->id)->update(['area_id' => $direccion, 'updated_at' => now()]);
            }
            // Menú: Estructura y Recursos Humanos quedan como en una instalación nueva (MenuSeeder::menus())
            foreach (['estructura', 'recursos_humanos'] as $menuClave) {
                $menuId = DB::table('menus')->where('clave', $menuClave)->value('id');
                if ($menuId === null) {
                    continue;
                }
                $posicion = 0;
                foreach (MenuSeeder::menus()[$menuClave][3] as $seccion => $deSeccion) {
                    foreach (array_keys($deSeccion) as $clave) {
                        $posicion++;
                        $fila = DB::table('modulos')->where('clave', $clave)->first(['id', 'menu_id', 'seccion_menu', 'orden_menu']);
                        if ($fila === null || ((int) $fila->menu_id !== (int) $menuId && ! in_array($clave, self::CON_MENU, true))) {
                            continue;
                        }
                        $nota['menu'][$fila->id] ??= ['menu_id' => $fila->menu_id, 'seccion_menu' => $fila->seccion_menu, 'orden_menu' => $fila->orden_menu];
                        DB::table('modulos')->where('id', $fila->id)->update(['menu_id' => $menuId, 'seccion_menu' => $seccion, 'orden_menu' => $posicion]);
                    }
                }
            }

            // Toda empresa tiene los módulos de Dirección: se encienden donde faltan
            $ids = collect(self::ORGANIZACION)->keys()->map(fn ($c) => $modulos[$c] ?? null)->filter()->values();
            foreach (DB::table('empresas')->pluck('id') as $empresaId) {
                foreach ($ids as $moduloId) {
                    $fila = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $moduloId)->first();
                    if ($fila === null) {
                        DB::table('empresa_modulos')->insert(['empresa_id' => $empresaId, 'modulo_id' => $moduloId, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
                        $nota['empresa_modulos'][] = [$empresaId, $moduloId, null];
                    } elseif (! $fila->activo) {
                        DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $moduloId)->update(['activo' => true, 'updated_at' => now()]);
                        $nota['empresa_modulos'][] = [$empresaId, $moduloId, 0];
                    }
                }
            }

            if (! $comercial || ! DB::table('productos')->exists()) {
                return;
            }

            // 2. Productos (C-01 y C-05)
            $productos = DB::table('productos')->pluck('id', 'clave');
            foreach (self::PRODUCTOS as $producto => [$salen, $entran]) {
                if (! isset($productos[$producto])) {
                    continue;
                }
                $pid = $productos[$producto];
                $nota['modulo_producto'][$producto] = DB::table('modulo_producto')->where('producto_id', $pid)->pluck('modulo_id')->all();
                DB::table('modulo_producto')->where('producto_id', $pid)->whereIn('modulo_id', collect($salen)->map(fn ($c) => $modulos[$c] ?? 0))->delete();
                foreach ($entran as $clave) {
                    if (isset($modulos[$clave]) && ! DB::table('modulo_producto')->where('producto_id', $pid)->where('modulo_id', $modulos[$clave])->exists()) {
                        DB::table('modulo_producto')->insert(['producto_id' => $pid, 'modulo_id' => $modulos[$clave]]);
                    }
                }
            }
            foreach (self::TEXTOS as $donde => [$antes, $despues]) {
                [$tabla, $clave] = explode('.', $donde);
                $columna = $tabla === 'planes' ? 'slug' : 'clave';
                if (DB::table($tabla)->where($columna, $clave)->where('descripcion', $antes)->update(['descripcion' => $despues, 'updated_at' => now()]) > 0) {
                    $nota['textos'][$donde] = $antes;
                }
            }

            // Complemento «Reservas y eventos» (DD-03)
            if (! isset($productos['reservas']) && isset($modulos['reservas'])) {
                $pid = DB::table('productos')->insertGetId(['clave' => 'reservas', 'nombre' => 'Reservas y eventos',
                    'descripcion' => 'Reservas de salones, salas y áreas comunes, con aprobación, órdenes de servicio y montaje por departamento.',
                    'icono' => 'bi-calendar-check', 'activo' => true, 'orden' => (int) DB::table('productos')->max('orden') + 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('modulo_producto')->insert(['producto_id' => $pid, 'modulo_id' => $modulos['reservas']]);
                $nota['creados']['producto'] = $pid;
                if (! DB::table('paquetes')->where('slug', 'reservas-y-eventos')->exists()) {
                    $paq = DB::table('paquetes')->insertGetId(['nombre' => 'Reservas y eventos', 'slug' => 'reservas-y-eventos', 'modalidad' => 'suscripcion',
                        'descripcion' => 'Reservas de salones, salas y áreas comunes, con aprobación, órdenes de servicio y montaje.',
                        'precio' => 290, 'por_sede' => true, 'activo' => true, 'publicado' => true,
                        'orden' => (int) DB::table('paquetes')->max('orden') + 1, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('paquete_producto')->insert(['paquete_id' => $paq, 'producto_id' => $pid]);
                    foreach (DB::table('planes')->whereIn('slug', ['esencial', 'profesional', 'corporativo'])->pluck('id') as $planId) {
                        DB::table('paquete_plan')->insert(['paquete_id' => $paq, 'plan_id' => $planId]);
                    }
                    $nota['creados']['paquete'] = $paq;
                }
            }
        });

        ConfiguracionPlataforma::create(['clave' => self::CLAVE, 'valor' => $nota]);
        Autorizador::olvidarEsquema();

        // 3. Suscripciones: recalcular (lo que un plan ya no trae queda en solo lectura, con aviso)
        if ($comercial) {
            $this->sincronizarTodas(avisar: true);
        }
    }

    public function down(): void
    {
        $registro = ConfiguracionPlataforma::where('clave', self::CLAVE)->first();
        if ($registro === null) {
            return;
        }
        $nota = $registro->valor;

        DB::transaction(function () use ($nota) {
            foreach ($nota['modulos'] ?? [] as $clave => $antes) {
                DB::table('modulos')->where('id', $antes['id'])->update(['area_id' => $antes['area_id'], 'updated_at' => now()]);
            }
            foreach ($nota['menu'] ?? [] as $id => $antes) {
                DB::table('modulos')->where('id', $id)->update($antes);
            }
            foreach ($nota['empresa_modulos'] ?? [] as [$empresaId, $moduloId, $antes]) {
                $consulta = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $moduloId);
                $antes === null ? $consulta->delete() : $consulta->update(['activo' => false]);
            }
            if (Schema::hasTable('productos')) {
                $productos = DB::table('productos')->pluck('id', 'clave');
                foreach ($nota['modulo_producto'] ?? [] as $producto => $ids) {
                    if (! isset($productos[$producto])) {
                        continue;
                    }
                    DB::table('modulo_producto')->where('producto_id', $productos[$producto])->delete();
                    DB::table('modulo_producto')->insert(array_map(fn ($m) => ['producto_id' => $productos[$producto], 'modulo_id' => $m], $ids));
                }
                foreach ($nota['textos'] ?? [] as $donde => $antes) {
                    [$tabla, $clave] = explode('.', $donde);
                    DB::table($tabla)->where($tabla === 'planes' ? 'slug' : 'clave', $clave)->update(['descripcion' => $antes]);
                }
                // El complemento creado se quita solo si ningún cliente lo tiene
                if (($paq = $nota['creados']['paquete'] ?? null) !== null && ! DB::table('suscripcion_paquete')->where('paquete_id', $paq)->exists()) {
                    DB::table('paquetes')->where('id', $paq)->delete();
                }
                if (($pid = $nota['creados']['producto'] ?? null) !== null && ! DB::table('paquete_producto')->where('producto_id', $pid)->exists()
                    && ! DB::table('plan_producto')->where('producto_id', $pid)->exists()) {
                    DB::table('productos')->where('id', $pid)->delete();
                }
            }
            if (Schema::hasTable('suscripcion_excepciones')) {
                DB::table('suscripcion_excepciones')->whereIn('id', $nota['excepciones'] ?? [])->delete();
            }
            // Módulos de cada cliente con suscripción, como estaban
            $conLectura = Schema::hasColumn('empresa_modulos', 'solo_lectura');
            foreach ($nota['foto'] ?? [] as $empresaId => $filas) {
                DB::table('empresa_modulos')->where('empresa_id', $empresaId)->whereNotIn('modulo_id', array_keys($filas))->delete();
                foreach ($filas as $moduloId => $activo) {
                    DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $moduloId)
                        ->update(['activo' => (bool) $activo] + ($conLectura ? ['solo_lectura' => false] : []));
                }
            }
        });
        $registro->delete();
        Autorizador::olvidarEsquema();
        app(Autorizador::class)->olvidar();
    }

    /**
     * Módulos que el Super Administrador cambió a mano en Empresas → Módulos
     * a un cliente con suscripción: se vuelven excepciones para que el
     * recálculo no los pierda (A-09).
     *
     * @return list<int> ids de las excepciones creadas
     */
    private function excepcionesPorCambiosManuales(): array
    {
        $creadas = [];
        $calculo = app(ModulosEfectivos::class);
        $calculo->conDependencias = false;
        $direccion = (int) DB::table('areas')->where('clave', 'direccion')->value('id');
        $catalogo = DB::table('modulos')->where('activo', true)->where('tipo', '!=', 'plataforma')->get(['id', 'area_id'])->keyBy('id');
        foreach (Suscripcion::query()->get() as $s) {
            $esperado = $calculo->calcular($s);
            $activos = DB::table('empresa_modulos')->where('empresa_id', $s->empresa_id)->where('activo', true)->pluck('modulo_id')->map(fn ($id) => (int) $id)->all();
            $cambios = [];
            foreach (array_diff($activos, $esperado) as $id) {
                if (isset($catalogo[$id])) {
                    $cambios[] = ['modulo_agregado', $id];
                }
            }
            foreach (array_diff($esperado, $activos) as $id) {
                if (isset($catalogo[$id]) && (int) $catalogo[$id]->area_id !== $direccion) {
                    $cambios[] = ['modulo_quitado', $id];
                }
            }
            foreach ($cambios as [$tipo, $id]) {
                $creadas[] = SuscripcionExcepcion::create(['suscripcion_id' => $s->id, 'empresa_id' => $s->empresa_id, 'tipo' => $tipo, 'modulo_id' => $id,
                    'cantidad' => null, 'motivo' => self::MOTIVO_EXCEPCION])->id;
            }
        }

        return $creadas;
    }

    private function sincronizarTodas(bool $avisar): void
    {
        $calculo = app(ModulosEfectivos::class);
        foreach (Suscripcion::query()->get() as $s) {
            try {
                $calculo->sincronizar($s, $avisar);
            } catch (Throwable $e) {
                // El aviso (correo o campana) no detiene la actualización: los módulos ya quedaron escritos
                Log::warning('Etapa 1: no se pudo avisar o sincronizar la suscripción '.$s->id.': '.$e->getMessage());
            }
        }
    }
};
