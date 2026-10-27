<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-10 (DD-14, opción B): el residente es un
 * tipo de persona del directorio, con su vivienda.
 *
 *  - colaboradores.tipo: «personal» (de fábrica) o «residente».
 *  - colaboradores.espacio_id: la vivienda (nivel «área específica» del árbol).
 *  - Datos: en las empresas de giro condominio o fraccionamiento, quien NO
 *    tiene una cuenta de usuario vinculada pasa a «residente» (las cuentas
 *    son del personal: guardias, administración, mantenimiento). Su vivienda
 *    se deduce del número (#R-101 → 101, #R-B101 → B-101) cuando coincide con
 *    una vivienda de su sede; si no coincide se queda sin vivienda y el
 *    administrador la completa en Padrones → Residentes.
 *  - Terminología: en condominio el nivel del árbol se llama «Vivienda» (ya no
 *    «Departamento», que chocaba con el módulo Departamentos) y, en los dos
 *    giros residenciales, «Colaborador» se dice «Personal» (antes «Residente»).
 *    Solo se cambia el valor de fábrica: lo que la empresa haya personalizado
 *    se respeta.
 *
 * down(): regresa la terminología y quita las dos columnas (el tipo y la
 * vivienda se pierden; las personas siguen en el directorio como antes).
 */
return new class extends Migration
{
    private const RESIDENCIALES = ['condominio', 'fraccionamiento'];

    public function up(): void
    {
        // Quién tiene cuenta (antes de tocar la tabla)
        $conCuenta = DB::table('users')->whereNotNull('colaborador_id')->pluck('colaborador_id')->map(fn ($id) => (int) $id)->all();

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('tipo', 12)->default('personal')->after('empresa_id');
            $table->unsignedBigInteger('espacio_id')->nullable()->after('sede_id');
            $table->index(['empresa_id', 'tipo']);
            $table->index('espacio_id');
        });
        Restricciones::agregarForanea('colaboradores', 'espacio_id', 'espacios', 'set null');

        $empresas = DB::table('empresas')->join('rubros', 'rubros.id', '=', 'empresas.rubro_id')
            ->whereIn('rubros.clave', self::RESIDENCIALES)->pluck('empresas.id');
        foreach ($empresas as $empresaId) {
            DB::table('colaboradores')->where('empresa_id', $empresaId)
                ->when($conCuenta !== [], fn ($q) => $q->whereNotIn('id', $conCuenta))
                ->update(['tipo' => 'residente']);
            $this->deducirViviendas((int) $empresaId);
        }

        $this->terminologia(['area_especifica' => ['condominio' => ['Departamento', 'Vivienda']], 'colaborador' => [
            'condominio' => ['Residente', 'Personal'], 'fraccionamiento' => ['Residente', 'Personal'],
        ]]);
    }

    public function down(): void
    {
        $this->terminologia(['area_especifica' => ['condominio' => ['Vivienda', 'Departamento']], 'colaborador' => [
            'condominio' => ['Personal', 'Residente'], 'fraccionamiento' => ['Personal', 'Residente'],
        ]]);

        Restricciones::quitarForanea('colaboradores', 'espacio_id');
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'tipo']);
            $table->dropIndex(['espacio_id']);
        });
        Schema::table('colaboradores', fn (Blueprint $table) => $table->dropColumn(['espacio_id', 'tipo']));
    }

    /** «R-B101» → «B101»: el número sin prefijo de residente, sin guiones ni espacios, en minúsculas. */
    private function clave(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto));
        $t = (string) preg_replace('/^(r|res|residente)[\s\-#\.]*/u', '', $t);

        return (string) preg_replace('/[\s\-#\.]+/u', '', $t);
    }

    private function deducirViviendas(int $empresaId): void
    {
        $viviendas = DB::table('espacios')->where('empresa_id', $empresaId)->where('nivel', 'area_especifica')->where('activo', true)
            ->get(['id', 'sede_id', 'nombre', 'codigo']);
        $porSede = [];
        foreach ($viviendas as $v) {
            foreach (array_filter([$this->clave($v->nombre), $this->clave($v->codigo)]) as $clave) {
                $porSede[(int) $v->sede_id][$clave][] = (int) $v->id;
            }
        }
        $residentes = DB::table('colaboradores')->where('empresa_id', $empresaId)->where('tipo', 'residente')->whereNull('espacio_id')
            ->whereNotNull('sede_id')->get(['id', 'sede_id', 'num_empleado']);
        foreach ($residentes as $r) {
            $coinciden = array_values(array_unique($porSede[(int) $r->sede_id][$this->clave($r->num_empleado)] ?? []));
            // Solo si hay UNA vivienda con ese número en su sede (dos iguales: lo decide el administrador)
            if (count($coinciden) === 1) {
                DB::table('colaboradores')->where('id', $r->id)->update(['espacio_id' => $coinciden[0]]);
            }
        }
    }

    /**
     * @param  array<string, array<string, array{0: string, 1: string}>>  $cambios  palabra => giro => [de, a]
     */
    private function terminologia(array $cambios): void
    {
        foreach (DB::table('rubros')->whereIn('clave', self::RESIDENCIALES)->get(['id', 'clave', 'terminologia']) as $rubro) {
            $t = json_decode((string) $rubro->terminologia, true) ?: [];
            $cambio = false;
            foreach ($cambios as $palabra => $porGiro) {
                [$de, $a] = $porGiro[$rubro->clave] ?? [null, null];
                if ($de !== null && ($t[$palabra] ?? null) === $de) {
                    $t[$palabra] = $a;
                    $cambio = true;
                }
            }
            if ($cambio) {
                DB::table('rubros')->where('id', $rubro->id)->update(['terminologia' => json_encode($t, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
