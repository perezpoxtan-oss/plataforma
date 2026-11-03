<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Models\User;
use App\Services\BaseDatos\LimpiezaQa;
use App\Services\Respaldos\Respaldos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deja la base de pruebas como recién instalada: solo los Super
 * Administradores y lo de nivel plataforma (ver LimpiezaQa). Nunca corre en
 * Producción. desplegar.sh la corre una sola vez por cada solicitud del dueño
 * (SOLICITUD), con la marca $APP/.limpieza_qa_completada.
 */
class LimpiarQa extends Command
{
    /**
     * Identificador de la solicitud de limpieza vigente. desplegar.sh la corre
     * una vez por identificador: para pedir otra limpieza en QA basta con
     * cambiarlo (p. ej. a la fecha de la nueva solicitud) en una versión nueva.
     */
    public const SOLICITUD = '2026-11-02';

    public const MOTIVO_RESPALDO = 'antes-de-limpiar-qa';

    protected $signature = 'plataforma:limpiar-qa
        {--confirmar : Confirma que se borren todas las empresas, sus usuarios y sus datos}
        {--simular : Solo lista qué se borraría y cuántos renglones por tabla; no cambia nada}
        {--solicitud : Solo escribe el identificador de la solicitud vigente (lo usa desplegar.sh)}';

    protected $description = 'Limpia la base de pruebas: conserva solo a los Super Administradores y los datos de la plataforma (solo QA y desarrollo)';

    public function handle(LimpiezaQa $limpieza): int
    {
        if ($this->option('solicitud')) {
            $this->line(self::SOLICITUD);

            return self::SUCCESS;
        }

        // Mismo criterio que plataforma:demo: lista de ambientes permitidos (no
        // "todo menos production"); un APP_ENV mal escrito en Producción no borra nada
        if (app()->environment('production')) {
            $this->error('Bloqueado en Producción: la limpieza de la base solo se hace en el ambiente de pruebas.');

            return self::FAILURE;
        }
        if (! app()->environment(CrearDatosDemo::AMBIENTES)) {
            $this->error('La limpieza solo se hace en '.implode(', ', CrearDatosDemo::AMBIENTES).' (este ambiente: '.app()->environment().').');

            return self::FAILURE;
        }

        $plan = $limpieza->plan();
        $archivos = $limpieza->archivos();

        if ($this->option('simular')) {
            $this->mostrarPlan($plan, $archivos);
            $this->info('Simulación: no se cambió nada. Para limpiar, corre el comando con --confirmar.');

            return self::SUCCESS;
        }

        if (! $this->option('confirmar')) {
            $this->error('Falta --confirmar: este comando borra todas las empresas, sus usuarios y sus datos. Usa --simular para ver qué se borraría.');

            return self::FAILURE;
        }

        // Respaldo primero: si no se puede respaldar, no se borra nada
        $antes = $this->respaldoMasReciente();
        $rc = $this->call('plataforma:respaldar', ['--motivo' => self::MOTIVO_RESPALDO]);
        $respaldo = $this->respaldoMasReciente();
        if ($rc !== self::SUCCESS || $respaldo === null || $respaldo === $antes) {
            $this->error('No se limpió la base: no se pudo hacer el respaldo previo.');

            return self::FAILURE;
        }

        try {
            $borrados = $limpieza->ejecutar();
        } catch (Throwable $e) {
            $this->error('No se limpió la base (no se borró nada): '.$e->getMessage());

            return self::FAILURE;
        }
        $nArchivos = $limpieza->borrarArchivos();

        try {
            Cache::flush();
        } catch (Throwable) {
            // La caché se vuelve a llenar sola
        }

        // Catálogo de la plataforma al día (seeders repetibles)
        $this->callSilently('plataforma:instalar');

        $superadmins = User::where('es_superadmin', true)->count();
        $renglones = array_sum($borrados);
        Auditoria::create([
            'empresa_id' => null, 'sede_id' => null, 'user_id' => null, 'evento' => 'plataforma.base_limpiada',
            'auditable_type' => null, 'auditable_id' => null, 'antes' => null,
            'despues' => ['solicitud' => self::SOLICITUD, 'respaldo' => $respaldo, 'renglones' => $renglones, 'tablas' => $borrados,
                'archivos' => $nArchivos, 'super_administradores' => $superadmins],
            'ip' => null,
        ]);

        $this->mostrarPlan($plan, $archivos);
        $this->info(sprintf('Base de QA limpiada: %d renglones de %d tablas y %d archivos; se conservaron %d Super Administrador(es). Respaldo: %s',
            $renglones, count($borrados), $nArchivos, $superadmins, $respaldo));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{modo: string, renglones: int}>  $plan
     * @param  array<string, int>  $archivos
     */
    private function mostrarPlan(array $plan, array $archivos): void
    {
        $nombres = ['vaciar' => 'Se vacía', 'solo_empresas' => 'Solo renglones de empresas', 'super_administradores' => 'Todos menos los Super Administradores',
            'dependiente' => 'Lo ligado a roles o cuentas que se borran'];
        $filas = collect($plan)->filter(fn ($p) => $p['renglones'] > 0)
            ->map(fn ($p, $t) => [$t, $nombres[$p['modo']] ?? $p['modo'], $p['renglones']])->values()->all();
        $this->table(['Tabla', 'Qué se borra', 'Renglones'], $filas);
        $this->line('Total: '.array_sum(array_column($plan, 'renglones')).' renglones en '.count($filas).' tablas.');
        $this->line('Se conservan completas: '.implode(', ', collect($plan)->filter(fn ($p) => $p['modo'] === 'conservar')->keys()->all()).'.');
        if ($archivos !== []) {
            $this->table(['Carpeta de archivos', 'Archivos'], collect($archivos)->map(fn ($n, $c) => [$c, $n])->values()->all());
        }
        $this->line('Archivos que nunca se borran: respaldos, traslados e identidad de la plataforma.');
    }

    private function respaldoMasReciente(): ?string
    {
        return app(Respaldos::class)->listar()
            ->firstWhere('motivo', self::MOTIVO_RESPALDO)['archivo'] ?? null;
    }
}
