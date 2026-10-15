<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionPlataforma;
use App\Services\Traslado\Paquetes;
use App\Services\Traslado\RestauradorTraslado;
use App\Services\Traslado\VerificadorPaquete;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Restaura un paquete de traslado en el servidor nuevo (docs/tecnico/traslado.md).
 * Corre por consola (terminal del panel del hospedaje) o desde un script de
 * despliegue, en proceso y sin exec:
 *
 *   php artisan plataforma:restaurar-traslado paquete.zip                  revisa, copia archivos y explica cómo importar la base
 *   php artisan plataforma:restaurar-traslado paquete.zip --importar-base  además importa la base (solo si está vacía; pide confirmación)
 *   php artisan plataforma:restaurar-traslado paquete.zip --solo-verificar
 */
class RestaurarTraslado extends Command
{
    protected $signature = 'plataforma:restaurar-traslado
        {paquete : Ruta del paquete .zip}
        {--solo-verificar : Solo revisa el paquete (huellas SHA-256); no copia nada}
        {--importar-base : Importa también la base de datos (solo sobre una base vacía)}
        {--forzar : No pregunta antes de importar la base (para scripts; la base debe estar vacía de todos modos)}
        {--sobrescribir : Reemplaza archivos que ya existen y son distintos}
        {--conexion= : Conexión de base de datos de destino (por omisión, la principal)}';

    protected $description = 'Restaura un paquete de traslado: verifica, copia los archivos y (opcional) importa la base';

    public function handle(VerificadorPaquete $verificador, RestauradorTraslado $restaurador, Paquetes $paquetes): int
    {
        $ruta = (string) $this->argument('paquete');
        if (! is_file($ruta)) {
            $this->error("No existe el archivo {$ruta}.");

            return self::FAILURE;
        }

        // 1) Integridad
        $this->info('1/4 Revisando el paquete (huellas SHA-256)…');
        $r = $verificador->verificar($ruta);
        $m = $r['manifest'] ?? [];
        if ($m !== []) {
            $this->line(sprintf('   Paquete de %s, generado el %s por %s; versión %s; %d archivos (%s).',
                $m['tipo'] === 'empresa' ? 'una empresa' : 'traslado', $m['generado_en'], $m['generado_por'] ?? '—', $m['version'] ?? '—',
                $m['archivos'], Paquetes::legible((int) $m['bytes'])));
        }
        foreach ($r['avisos'] as $aviso) {
            $this->warn('   '.$aviso);
        }
        if (! $r['valido']) {
            foreach ($r['problemas'] as $p) {
                $this->error('   '.$p);
            }
            $this->error('El paquete NO pasó la revisión: no se restauró nada.');
            $this->auditar($restaurador, 'configuracion.traslado_verificado', ['paquete' => basename($ruta), 'valido' => false, 'problemas' => array_slice($r['problemas'], 0, 10), 'origen' => 'consola']);

            return self::FAILURE;
        }
        $this->info("   Correcto: {$r['revisados']} archivos revisados, todas las huellas coinciden.");
        if (($m['tipo'] ?? '') !== 'traslado') {
            $this->error('Este paquete es la exportación de una empresa; todavía no se puede importar en otra instalación. Para mover toda la plataforma usa un paquete de traslado.');

            return self::FAILURE;
        }
        $this->avisarVersion($m);

        if ($this->option('solo-verificar')) {
            $this->auditar($restaurador, 'configuracion.traslado_verificado', ['paquete' => basename($ruta), 'valido' => true, 'revisados' => $r['revisados'], 'origen' => 'consola']);

            return self::SUCCESS;
        }

        [$zip, $manifest] = $verificador->abrir($ruta);
        try {
            // 2) Archivos
            $this->info('2/4 Copiando los archivos a su lugar…');
            $a = $restaurador->restaurarArchivos($zip, $manifest, (bool) $this->option('sobrescribir'));
            $this->line("   Nuevos: {$a['nuevos']} · ya estaban iguales: {$a['iguales']} · reemplazados: {$a['reemplazados']}.");
            if ($a['conflictos'] !== []) {
                $this->warn('   '.count($a['conflictos']).' archivo(s) ya existían con otro contenido y NO se tocaron (usa --sobrescribir para reemplazarlos). Por ejemplo: '.implode(', ', array_slice($a['conflictos'], 0, 3)));
            }

            // 3) Base de datos
            $this->info('3/4 Respaldo de la base de datos…');
            $base = $restaurador->extraerBase($zip, $paquetes);
        } finally {
            $zip->close();
        }
        $this->line("   Quedó en: {$base}");

        $conexion = $restaurador->conexion($this->option('conexion'));
        $motor = (string) ($manifest['base']['motor'] ?? $manifest['plataforma']['motor'] ?? '');
        $importada = false;
        $diferencias = null;

        if ($this->option('importar-base')) {
            if (! $restaurador->motorCompatible($motor, $conexion)) {
                $this->error("   El respaldo es de {$motor} y la base de destino es {$conexion->getDriverName()}: impórtalo con la herramienta de bases de datos de tu hospedaje.");

                return self::FAILURE;
            }
            if (! $restaurador->baseVacia($conexion)) {
                $this->error('   La base de destino NO está vacía ('.count($restaurador->tablas($conexion)).' tablas). Por seguridad no se importa encima: usa una base nueva y vacía.');
                $this->instrucciones($base);

                return self::FAILURE;
            }
            if (! $this->option('forzar') && ! $this->confirm("Se importará la base en «{$conexion->getDatabaseName()}» (vacía). ¿Continuar?", false)) {
                $this->warn('   No se importó la base.');
                $this->instrucciones($base);

                return self::SUCCESS;
            }
            try {
                $n = $restaurador->importarBase($base, $conexion, fn (int $s) => $this->output->write("\r   Sentencias: {$s}"));
                $this->newLine();
                $this->info("   Base importada ({$n} sentencias).");
                $importada = true;
            } catch (Throwable $e) {
                $this->newLine();
                $this->error('   No se pudo importar la base: '.$e->getMessage());
                $this->line('   La base quedó a medias: bórrala y créala vacía antes de volver a intentar, o impórtala con la herramienta de tu hospedaje.');

                return self::FAILURE;
            }

            // 4) Comprobación
            $this->info('4/4 Comparando registros por tabla con el manifest…');
            $diferencias = $restaurador->diferencias($conexion, $manifest['base']['tablas'] ?? []);
            if ($diferencias === []) {
                $this->info('   Todas las tablas tienen los mismos registros que el servidor anterior.');
            } else {
                $this->table(['Tabla', 'Esperado', 'Encontrado'], array_map(fn ($d) => [$d['tabla'], $d['esperado'], $d['encontrado'] ?? 'no existe'], $diferencias));
            }
        } else {
            $this->instrucciones($base);
        }

        $this->auditar($restaurador, 'configuracion.traslado_restaurado', [
            'paquete' => basename($ruta), 'archivos_nuevos' => $a['nuevos'], 'archivos_iguales' => $a['iguales'], 'archivos_reemplazados' => $a['reemplazados'],
            'conflictos' => count($a['conflictos']), 'base_importada' => $importada, 'diferencias' => $diferencias === null ? null : count($diferencias),
        ], $conexion);

        $this->newLine();
        $this->info('Listo. Entra con tu cuenta de Super Administrador y revisa empresas, sedes, usuarios y fotos.');
        if ($importada) {
            $this->line('Si esta instalación es de una versión más nueva que el paquete, ejecuta ahora: php artisan migrate --force');
            File::delete($base);
        }

        return $diferencias === null || $diferencias === [] ? self::SUCCESS : self::FAILURE;
    }

    private function instrucciones(string $base): void
    {
        $this->line('   Para importar la base tienes dos caminos:');
        $this->line('   a) Con la herramienta de bases de datos de tu hospedaje: importa el archivo de arriba (.sql.gz) en una base vacía.');
        $this->line('   b) Con esta misma orden sobre una base vacía: agrega --importar-base.');
        $this->line('   Después, borra el archivo .sql.gz del servidor: contiene datos personales.');
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function avisarVersion(array $m): void
    {
        $ultima = $m['ultima_migracion'] ?? null;
        $conocidas = collect(File::glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));
        if ($ultima !== null && ! $conocidas->contains($ultima)) {
            $this->warn("   El paquete viene de una versión más nueva de la plataforma ({$m['version']}). Instala primero esa versión (o una posterior).");
        }
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(RestauradorTraslado $restaurador, string $evento, array $despues, ?Connection $conexion = null): void
    {
        try {
            $conexion ??= $restaurador->conexion($this->option('conexion'));
            if (! in_array('auditoria', $restaurador->tablas($conexion), true)) {
                return;
            }
            $conexion->table('auditoria')->insert([
                'empresa_id' => null, 'user_id' => null, 'evento' => $evento,
                'auditable_type' => ConfiguracionPlataforma::class, 'auditable_id' => null,
                'antes' => null, 'despues' => json_encode($despues, JSON_UNESCAPED_UNICODE), 'ip' => null, 'creado_en' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // la bitácora no debe impedir la restauración
        }
    }
}
