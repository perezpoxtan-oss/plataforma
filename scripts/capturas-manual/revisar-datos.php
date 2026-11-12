<?php

/*
 * Busca en la base local textos que no deben verse en las capturas del manual
 * (usuarios de prueba, dominios, «demo»…). Solo lee. Uso:
 *   php scripts/capturas-manual/revisar-datos.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$patron = '/(\.demo|demo1234|vdcp|qa\.|neubox|cpanel|phpmyadmin|github|perezpoxtan|marcelino|segcat|pr #|migraci|demo)/i';
$omitir = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'auditorias', 'notifications', 'password_reset_tokens'];
$hallazgos = [];
foreach (DB::select("select name from sqlite_master where type='table'") as $t) {
    if (in_array($t->name, $omitir, true)) {
        continue;
    }
    $columnas = Schema::getColumnListing($t->name);
    foreach (DB::table($t->name)->cursor() as $fila) {
        foreach ($columnas as $c) {
            $v = $fila->$c ?? null;
            if (is_string($v) && preg_match_all($patron, $v, $m)) {
                foreach (array_unique(array_map('mb_strtolower', $m[0])) as $w) {
                    $clave = "{$t->name}.{$c} «{$w}»";
                    $hallazgos[$clave] ??= ['n' => 0, 'ej' => mb_substr($v, 0, 90)];
                    $hallazgos[$clave]['n']++;
                }
            }
        }
    }
}
ksort($hallazgos);
foreach ($hallazgos as $k => $h) {
    echo "{$k} ×{$h['n']}  ej: {$h['ej']}\n";
}
echo count($hallazgos) === 0 ? "Sin textos prohibidos.\n" : '';
