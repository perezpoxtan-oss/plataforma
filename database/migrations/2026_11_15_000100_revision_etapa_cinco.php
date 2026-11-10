<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión de la Etapa 5 (PR #92).
 *
 *  - espacios.uso: en condominio y fraccionamiento, el nivel «área
 *    específica» guarda viviendas y áreas comunes (alberca, salón, asador).
 *    «area_comun» las distingue; vacío = vivienda (o habitación, oficina…
 *    en los demás giros, donde no se usa). Datos de antes: en las empresas
 *    residenciales es área común lo que ya es reservable y lo que no tiene
 *    número en el nombre ni residentes. El administrador lo cambia en Zonas
 *    y edificios.
 *  - Accidente de personal → RR. HH. ya no se decide por el nombre del
 *    departamento: se guarda en empresas.preferencias['novedades']
 *    ['departamento_accidentes'] el que hoy coincide por nombre (valor de
 *    fábrica). Renombrarlo después no cambia nada.
 *
 * down(): quita la columna y el ajuste guardado (vuelve la regla por nombre).
 */
return new class extends Migration
{
    private const RESIDENCIALES = ['condominio', 'fraccionamiento'];

    /** El mismo patrón que usaba CanalizarDepartamento (valor de fábrica). */
    private const PATRON_RRHH = '/recursos\s+humanos|rr\.?\s*hh|capital\s+humano|personal y n[oó]mina/iu';

    public function up(): void
    {
        if (! Schema::hasColumn('espacios', 'uso')) {
            Schema::table('espacios', fn (Blueprint $table) => $table->string('uso', 12)->nullable()->after('nivel'));
        }
        $residenciales = DB::table('empresas')->join('rubros', 'rubros.id', '=', 'empresas.rubro_id')
            ->whereIn('rubros.clave', self::RESIDENCIALES)->pluck('empresas.id');
        foreach ($residenciales as $empresaId) {
            $reservables = DB::table('reserva_areas')->where('empresa_id', $empresaId)->pluck('espacio_id')->map(fn ($id) => (int) $id)->all();
            $conResidentes = DB::table('colaboradores')->where('empresa_id', $empresaId)->whereNotNull('espacio_id')->pluck('espacio_id')->map(fn ($id) => (int) $id)->all();
            foreach (DB::table('espacios')->where('empresa_id', $empresaId)->where('nivel', 'area_especifica')->whereNull('uso')->get(['id', 'nombre']) as $e) {
                $sinNumero = preg_match('/\d/u', (string) $e->nombre) !== 1 && ! in_array((int) $e->id, $conResidentes, true);
                if (in_array((int) $e->id, $reservables, true) || $sinNumero) {
                    DB::table('espacios')->where('id', $e->id)->update(['uso' => 'area_comun']);
                }
            }
        }

        foreach (DB::table('empresas')->get(['id', 'preferencias']) as $empresa) {
            $preferencias = json_decode((string) $empresa->preferencias, true) ?: [];
            if (isset($preferencias['novedades']['departamento_accidentes'])) {
                continue;
            }
            $rrhh = DB::table('departamentos')->where('empresa_id', $empresa->id)->where('activo', true)->orderBy('id')->get(['id', 'nombre'])
                ->first(fn ($d) => preg_match(self::PATRON_RRHH, (string) $d->nombre) === 1);
            if ($rrhh === null) {
                continue;
            }
            $preferencias['novedades']['departamento_accidentes'] = (int) $rrhh->id;
            DB::table('empresas')->where('id', $empresa->id)->update(['preferencias' => json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('empresas')->whereNotNull('preferencias')->get(['id', 'preferencias']) as $empresa) {
            $preferencias = json_decode((string) $empresa->preferencias, true) ?: [];
            if (! isset($preferencias['novedades']['departamento_accidentes'])) {
                continue;
            }
            unset($preferencias['novedades']['departamento_accidentes']);
            if (($preferencias['novedades'] ?? null) === []) {
                unset($preferencias['novedades']);
            }
            DB::table('empresas')->where('id', $empresa->id)->update(['preferencias' => $preferencias === [] ? null : json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
        }
        if (Schema::hasColumn('espacios', 'uso')) {
            Schema::table('espacios', fn (Blueprint $table) => $table->dropColumn('uso'));
        }
    }
};
