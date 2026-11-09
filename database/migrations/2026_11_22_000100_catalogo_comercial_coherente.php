<?php

use App\Models\ConfiguracionPlataforma;
use App\Models\Suscripcion;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\ModulosEfectivos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo comercial coherente con lo construido (credenciales con QR,
 * 125 kHz o MIFARE, lector QR/NFC, equipos ZKTeco o Hikvision y Asistencia).
 * Lleva una base ya sembrada a la forma de CatalogoComercialSeeder:
 *
 *  1. Corporativo incluye el producto «Asistencia» (el módulo sigue apagado
 *     en la empresa hasta que lo enciende en «Tu suscripción», decisión 13).
 *  2. Paquetes de suscripción: «Asistencia» se suma solo a Esencial y
 *     Profesional; «Reservas y eventos» a los tres; «Recursos Humanos y
 *     candidatos» a Esencial y Profesional. Solo se agregan los enlaces que
 *     faltan y se quita Corporativo de Asistencia.
 *  3. Textos: frases y descripciones de los planes, de los productos
 *     Estructura y Caseta y del Kit de lector. Solo si siguen como venían de
 *     fábrica (lo que el Super Administrador escribió se respeta).
 *  4. Pagos únicos nuevos, SIN publicar y con PRECIO DE EJEMPLO:
 *     «Integración de equipo de acceso» y «Credenciales CR80 (paquete de 100)».
 *  5. No cambia ningún precio ni borra nada.
 *  6. Vuelve a escribir los módulos de las suscripciones afectadas
 *     (Corporativo o con el complemento Asistencia) con ModulosEfectivos. Un
 *     cliente Corporativo que ya usaba Asistencia la conserva encendida.
 *
 * Todo lo que cambia se anota en configuracion_plataforma
 * («catalogo_comercial_coherente») para que down() lo regrese tal cual.
 * Idempotente; en una instalación nueva (catálogo vacío) no hace nada: el
 * seeder ya crea el catálogo así.
 */
return new class extends Migration
{
    private const CLAVE = 'catalogo_comercial_coherente';

    /** Planes a los que se suma cada paquete de suscripción (se agregan los que falten). */
    private const PAQUETE_PLANES = [
        'recursos-humanos' => ['esencial', 'profesional'],
        'reservas-y-eventos' => ['esencial', 'profesional', 'corporativo'],
        'asistencia' => ['esencial', 'profesional'],
    ];

    /** Planes de los que se quita un paquete (porque el plan ya lo incluye). */
    private const PAQUETE_SIN_PLANES = ['asistencia' => ['corporativo']];

    /** [tabla, columna llave, llave, campo, textos de fábrica anteriores, texto nuevo] */
    private const TEXTOS = [
        ['productos', 'clave', 'estructura', 'descripcion',
            ['Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores, departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.'],
            'Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores con su credencial de empleado (QR o tarjeta 125 kHz o MIFARE), departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.'],
        ['productos', 'clave', 'caseta', 'descripcion',
            ['Bitácora de accesos con autorización del departamento, llaves, vouchers, pases de salida y transporte.'],
            'Bitácora de accesos con autorización del departamento, lector de QR y tarjetas (NFC) y equipos de acceso ZKTeco o Hikvision, llaves, vouchers, pases de salida y transporte.'],
        ['planes', 'slug', 'esencial', 'frase', ['La caseta en orden desde el primer día.'],
            'La caseta en orden desde el primer día, con credenciales QR y lector.'],
        ['planes', 'slug', 'esencial', 'descripcion', ['Bitácora de accesos, llaves, pases de salida y padrones para una operación de seguridad ordenada.'],
            'Bitácora de accesos, llaves, pases de salida y padrones, con credenciales de empleado con QR o tarjeta 125 kHz o MIFARE, lector QR/NFC y equipos de acceso ZKTeco o Hikvision incluidos.'],
        ['planes', 'slug', 'profesional', 'descripcion', ['Todo lo de Esencial, más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.'],
            'Todo lo de Esencial (credenciales, lector y equipos de acceso incluidos), más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.'],
        ['planes', 'slug', 'corporativo', 'frase', ['Para grupos con varias sedes y Recursos Humanos.'],
            'Para grupos con varias sedes, Recursos Humanos y asistencia.'],
        ['planes', 'slug', 'corporativo', 'descripcion', [
            'Seguridad, incidentes, informes y Recursos Humanos, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.',
            'Toda la plataforma, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.'],
            'Todo lo de Profesional, más Recursos Humanos y Asistencia del personal (la enciendes cuando la quieras usar), usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.'],
        ['paquetes', 'slug', 'kit-lector', 'descripcion', ['Lector de gafetes y códigos QR listo para conectarse a la computadora de la caseta.'],
            'Lector para la computadora de la caseta que lee códigos QR y tarjetas 125 kHz o MIFARE: credenciales de empleado, gafetes y pases.'],
    ];

    /** Pagos únicos nuevos (PRECIO DE EJEMPLO; nacen sin publicar): slug => [nombre, descripción, precio]. */
    private const NUEVOS = [
        'integracion-equipo-acceso' => ['Integración de equipo de acceso',
            'Configuración de un equipo ZKTeco o Hikvision (torniquete, puerta o checador) con la plataforma: alta del equipo, prueba de entradas y salidas y envío de colaboradores. Precio por equipo; si son varios, indícalo en tus comentarios.', 1500],
        'credenciales-cr80' => ['Credenciales CR80 (paquete de 100)',
            'Cien tarjetas CR80 para imprimir credenciales de empleado. Pueden ser con chip 125 kHz o MIFARE para leerse en el lector de la caseta y en los equipos de acceso.', 1200],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('paquetes') || ! Schema::hasTable('suscripciones') || ConfiguracionPlataforma::where('clave', self::CLAVE)->exists()
            || (! DB::table('planes')->exists() && ! DB::table('paquetes')->exists())) {
            return; // instalación nueva (el seeder crea el catálogo así) o ya aplicada
        }
        $nota = ['plan_producto' => [], 'productos' => [], 'paquete_plan_agregados' => [], 'paquete_plan_quitados' => [], 'textos' => [],
            'paquetes' => [], 'encendidos' => [], 'foto' => []];
        $ahora = now();
        $planes = DB::table('planes')->pluck('id', 'slug');
        $moduloAsistencia = DB::table('modulos')->where('clave', 'asistencia')->value('id');

        DB::transaction(function () use (&$nota, $ahora, $planes, $moduloAsistencia) {
            // Suscripciones afectadas: Corporativo o con el complemento Asistencia. Foto de sus módulos para down().
            $paqAsistencia = DB::table('paquetes')->where('slug', 'asistencia')->value('id');
            $afectadas = DB::table('suscripciones')->where(fn ($q) => $q->where('plan_id', $planes['corporativo'] ?? 0)
                ->orWhereIn('id', DB::table('suscripcion_paquete')->where('paquete_id', $paqAsistencia ?? 0)->select('suscripcion_id')))
                ->get(['id', 'empresa_id', 'plan_id']);
            foreach ($afectadas as $s) {
                $nota['foto'][$s->empresa_id] = DB::table('empresa_modulos')->where('empresa_id', $s->empresa_id)->get()
                    ->mapWithKeys(fn ($f) => [$f->modulo_id => [(int) $f->activo, (int) ($f->solo_lectura ?? 0)]])->all();
            }
            $nota['suscripciones'] = $afectadas->pluck('id')->all();

            // 1. Producto «Asistencia» (lo crea la migración del módulo; si faltara, se crea) dentro de Corporativo
            $producto = DB::table('productos')->where('clave', 'asistencia')->value('id');
            if ($producto === null && $moduloAsistencia !== null) {
                $producto = DB::table('productos')->insertGetId(['clave' => 'asistencia', 'nombre' => 'Asistencia',
                    'descripcion' => 'Asistencia del día y reporte por periodo para nómina: entradas, salidas, retardos, faltas y horas trabajadas de los colaboradores según su turno.',
                    'icono' => 'bi-calendar2-check', 'activo' => true, 'orden' => (int) DB::table('productos')->max('orden') + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
                DB::table('modulo_producto')->insert(['producto_id' => $producto, 'modulo_id' => $moduloAsistencia]);
                $nota['productos'][] = $producto;
            }
            if ($producto !== null && isset($planes['corporativo'])
                && ! DB::table('plan_producto')->where('plan_id', $planes['corporativo'])->where('producto_id', $producto)->exists()) {
                DB::table('plan_producto')->insert(['plan_id' => $planes['corporativo'], 'producto_id' => $producto]);
                $nota['plan_producto'][] = [$planes['corporativo'], $producto];
            }

            // 2. A qué planes se suma cada paquete de suscripción
            foreach (self::PAQUETE_PLANES as $slug => $conPlanes) {
                $paquete = DB::table('paquetes')->where('slug', $slug)->value('id');
                if ($paquete === null) {
                    continue;
                }
                foreach ($conPlanes as $plan) {
                    if (isset($planes[$plan]) && ! DB::table('paquete_plan')->where('paquete_id', $paquete)->where('plan_id', $planes[$plan])->exists()) {
                        DB::table('paquete_plan')->insert(['paquete_id' => $paquete, 'plan_id' => $planes[$plan]]);
                        $nota['paquete_plan_agregados'][] = [$paquete, $planes[$plan]];
                    }
                }
                foreach (self::PAQUETE_SIN_PLANES[$slug] ?? [] as $plan) {
                    if (isset($planes[$plan]) && DB::table('paquete_plan')->where('paquete_id', $paquete)->where('plan_id', $planes[$plan])->delete() > 0) {
                        $nota['paquete_plan_quitados'][] = [$paquete, $planes[$plan]];
                    }
                }
            }

            // 3. Textos de fábrica (lo que escribió el Super Administrador no se toca)
            foreach (self::TEXTOS as $i => [$tabla, $llave, $valor, $campo, $antes, $despues]) {
                $actual = DB::table($tabla)->where($llave, $valor)->value($campo);
                if ($actual !== null && in_array($actual, $antes, true)) {
                    DB::table($tabla)->where($llave, $valor)->update([$campo => $despues, 'updated_at' => $ahora]);
                    $nota['textos'][$i] = $actual;
                }
            }

            // 4. Pagos únicos nuevos: sin publicar, PRECIO DE EJEMPLO
            foreach (self::NUEVOS as $slug => [$nombre, $descripcion, $precio]) {
                if (DB::table('paquetes')->where('slug', $slug)->exists()) {
                    continue;
                }
                $nota['paquetes'][] = DB::table('paquetes')->insertGetId(['nombre' => $nombre, 'slug' => $slug, 'modalidad' => 'unico',
                    'descripcion' => $descripcion, 'precio' => $precio, 'por_sede' => false, 'activo' => true, 'publicado' => false,
                    'orden' => (int) DB::table('paquetes')->max('orden') + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }

            // 5. Un cliente Corporativo que ya usaba Asistencia la conserva encendida
            foreach ($afectadas as $s) {
                if ((int) $s->plan_id !== (int) ($planes['corporativo'] ?? 0) || $moduloAsistencia === null
                    || ! DB::table('empresa_modulos')->where('empresa_id', $s->empresa_id)->where('modulo_id', $moduloAsistencia)->where('activo', true)->exists()) {
                    continue;
                }
                $preferencias = json_decode((string) DB::table('empresas')->where('id', $s->empresa_id)->value('preferencias'), true) ?: [];
                $lista = (array) ($preferencias[ModulosEfectivos::PREFERENCIA] ?? []);
                if (! in_array('asistencia', $lista, true)) {
                    $preferencias[ModulosEfectivos::PREFERENCIA] = array_values(array_merge($lista, ['asistencia']));
                    DB::table('empresas')->where('id', $s->empresa_id)->update(['preferencias' => json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
                    $nota['encendidos'][] = (int) $s->empresa_id;
                }
            }
        });

        ConfiguracionPlataforma::create(['clave' => self::CLAVE, 'valor' => $nota]);
        $this->sincronizar($nota['suscripciones'] ?? []);
    }

    public function down(): void
    {
        $registro = ConfiguracionPlataforma::where('clave', self::CLAVE)->first();
        if ($registro === null) {
            return;
        }
        $nota = $registro->valor;

        DB::transaction(function () use ($nota) {
            foreach ($nota['plan_producto'] ?? [] as [$plan, $producto]) {
                DB::table('plan_producto')->where('plan_id', $plan)->where('producto_id', $producto)->delete();
            }
            foreach ($nota['paquete_plan_agregados'] ?? [] as [$paquete, $plan]) {
                DB::table('paquete_plan')->where('paquete_id', $paquete)->where('plan_id', $plan)->delete();
            }
            foreach ($nota['paquete_plan_quitados'] ?? [] as [$paquete, $plan]) {
                if (DB::table('paquetes')->where('id', $paquete)->exists() && DB::table('planes')->where('id', $plan)->exists()) {
                    DB::table('paquete_plan')->insertOrIgnore(['paquete_id' => $paquete, 'plan_id' => $plan]);
                }
            }
            // Los textos regresan solo si nadie los cambió después
            foreach ($nota['textos'] ?? [] as $i => $antes) {
                [$tabla, $llave, $valor, $campo, , $despues] = self::TEXTOS[$i];
                DB::table($tabla)->where($llave, $valor)->where($campo, $despues)->update([$campo => $antes]);
            }
            // Lo creado se quita solo si ningún cliente lo tiene
            foreach ($nota['paquetes'] ?? [] as $paquete) {
                if (! DB::table('suscripcion_paquete')->where('paquete_id', $paquete)->exists()) {
                    DB::table('paquete_plan')->where('paquete_id', $paquete)->delete();
                    DB::table('paquete_producto')->where('paquete_id', $paquete)->delete();
                    DB::table('paquetes')->where('id', $paquete)->delete();
                }
            }
            foreach ($nota['productos'] ?? [] as $producto) {
                if (! DB::table('paquete_producto')->where('producto_id', $producto)->exists() && ! DB::table('plan_producto')->where('producto_id', $producto)->exists()) {
                    DB::table('modulo_producto')->where('producto_id', $producto)->delete();
                    DB::table('productos')->where('id', $producto)->delete();
                }
            }
            foreach ($nota['encendidos'] ?? [] as $empresaId) {
                $preferencias = json_decode((string) DB::table('empresas')->where('id', $empresaId)->value('preferencias'), true) ?: [];
                $preferencias[ModulosEfectivos::PREFERENCIA] = array_values(array_diff((array) ($preferencias[ModulosEfectivos::PREFERENCIA] ?? []), ['asistencia']));
                if ($preferencias[ModulosEfectivos::PREFERENCIA] === []) {
                    unset($preferencias[ModulosEfectivos::PREFERENCIA]);
                }
                DB::table('empresas')->where('id', $empresaId)->update(['preferencias' => $preferencias === [] ? null : json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
            }
            // Módulos de los clientes afectados, como estaban
            $conLectura = Schema::hasColumn('empresa_modulos', 'solo_lectura');
            foreach ($nota['foto'] ?? [] as $empresaId => $filas) {
                DB::table('empresa_modulos')->where('empresa_id', $empresaId)->whereNotIn('modulo_id', array_keys($filas))->delete();
                foreach ($filas as $moduloId => [$activo, $lectura]) {
                    DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $moduloId)
                        ->update(['activo' => (bool) $activo] + ($conLectura ? ['solo_lectura' => (bool) $lectura] : []));
                }
            }
        });
        $registro->delete();
        Autorizador::olvidarEsquema();
        app(Autorizador::class)->olvidar();
    }

    /** @param  list<int>  $ids */
    private function sincronizar(array $ids): void
    {
        Autorizador::olvidarEsquema();
        $calculo = app(ModulosEfectivos::class);
        foreach (Suscripcion::query()->whereIn('id', $ids)->get() as $s) {
            try {
                $calculo->sincronizar($s, avisar: false);
            } catch (Throwable $e) {
                Log::warning('Catálogo comercial: no se pudo sincronizar la suscripción '.$s->id.': '.$e->getMessage());
            }
        }
    }
};
