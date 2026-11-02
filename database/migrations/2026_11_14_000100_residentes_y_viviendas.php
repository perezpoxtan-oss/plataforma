<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-10 (DD-14, opción B): el residente es un
 * tipo de persona del directorio, con su vivienda.
 *
 *  - colaboradores.tipo: «personal» (de fábrica) o «residente».
 *  - colaboradores.espacio_id: la vivienda (nivel «área específica» del árbol).
 *  - colaboradores.tipo_por_confirmar: la conversión no supo si es residente
 *    (revisión Etapa 5, A-2). Se queda como Personal y el administrador lo
 *    confirma en Padrones → Residentes («Por confirmar»).
 *  - Datos: en las empresas de giro condominio o fraccionamiento, entre
 *    quienes NO tienen una cuenta de usuario vinculada (las cuentas son del
 *    personal), solo pasa a «residente» quien tiene un vínculo con una
 *    vivienda o el puesto lo dice:
 *      · su número coincide con UNA vivienda de su sede (#R-101 → 101,
 *        #R-B101 → B-101): residente con esa vivienda;
 *      · su puesto es de residente (Residente, Condómino, Propietario,
 *        Inquilino, Colono): residente; la vivienda la completa el
 *        administrador.
 *    No tener cuenta NO basta. Lo dudoso (número de residente «R-…» sin
 *    vivienda que coincida, o sin puesto ni departamento) se queda como
 *    Personal con «tipo_por_confirmar». Los demás siguen siendo Personal.
 *  - Terminología: en condominio el nivel del árbol se llama «Vivienda» (ya no
 *    «Departamento», que chocaba con el módulo Departamentos) y, en los dos
 *    giros residenciales, «Colaborador» se dice «Personal» (antes «Residente»).
 *    Solo se cambia el valor de fábrica: lo que la empresa haya personalizado
 *    se respeta.
 *
 * down(): regresa la terminología y quita las columnas. Antes guarda en la
 * tabla «respaldo_residentes» el tipo, la vivienda y la marca de cada persona
 * de las empresas residenciales; un up() posterior los restaura (gana sobre la
 * deducción) y borra la tabla. Así un residente dado de alta con la versión
 * nueva (sin número) no pierde su vivienda al revertir y volver a migrar. Si
 * solo se revierte y nunca se vuelve a migrar, la tabla se queda (no la usa
 * nadie) y puede borrarse a mano.
 */
return new class extends Migration
{
    private const RESIDENCIALES = ['condominio', 'fraccionamiento'];

    private const RESPALDO = 'respaldo_residentes';

    /** Puesto que dice que la persona vive ahí. */
    private const PUESTO_RESIDENTE = '/\\b(residente|cond[oó]mino|propietari[oa]|inquilin[oa]|colon[oa])s?\\b/iu';

    /** Número con prefijo de residente: «R-101», «RES 12», «Residente-3». */
    private const NUMERO_RESIDENTE = '/^(r|res|residente)[\\s\\-#\\.]*[a-z]?\\d/iu';

    public function up(): void
    {
        // Quién tiene cuenta (antes de tocar la tabla)
        $conCuenta = DB::table('users')->whereNotNull('colaborador_id')->pluck('colaborador_id')->map(fn ($id) => (int) $id)->all();

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('tipo', 12)->default('personal')->after('empresa_id');
            $table->unsignedBigInteger('espacio_id')->nullable()->after('sede_id');
            $table->boolean('tipo_por_confirmar')->default(false)->after('tipo');
            $table->index(['empresa_id', 'tipo']);
            $table->index('espacio_id');
        });
        Restricciones::agregarForanea('colaboradores', 'espacio_id', 'espacios', 'set null');

        foreach ($this->empresasResidenciales() as $empresaId) {
            $this->clasificar((int) $empresaId, $conCuenta);
        }
        $this->restaurarRespaldo();

        $this->terminologia(['area_especifica' => ['condominio' => ['Departamento', 'Vivienda']], 'colaborador' => [
            'condominio' => ['Residente', 'Personal'], 'fraccionamiento' => ['Residente', 'Personal'],
        ]]);
    }

    public function down(): void
    {
        $this->guardarRespaldo();

        $this->terminologia(['area_especifica' => ['condominio' => ['Vivienda', 'Departamento']], 'colaborador' => [
            'condominio' => ['Personal', 'Residente'], 'fraccionamiento' => ['Personal', 'Residente'],
        ]]);

        Restricciones::quitarForanea('colaboradores', 'espacio_id');
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'tipo']);
            $table->dropIndex(['espacio_id']);
        });
        Schema::table('colaboradores', fn (Blueprint $table) => $table->dropColumn(['espacio_id', 'tipo_por_confirmar', 'tipo']));
    }

    /** @return Collection<int, int> */
    private function empresasResidenciales()
    {
        return DB::table('empresas')->join('rubros', 'rubros.id', '=', 'empresas.rubro_id')
            ->whereIn('rubros.clave', self::RESIDENCIALES)->pluck('empresas.id');
    }

    /** «R-B101» → «B101»: el número sin prefijo de residente, sin guiones ni espacios, en minúsculas. */
    private function clave(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto));
        $t = (string) preg_replace('/^(r|res|residente)[\s\-#\.]*/u', '', $t);

        return (string) preg_replace('/[\s\-#\.]+/u', '', $t);
    }

    /**
     * Revisión Etapa 5 (A-2): residente solo con vínculo a una vivienda o
     * puesto de residente; lo dudoso se queda como Personal por confirmar.
     *
     * @param  list<int>  $conCuenta
     */
    private function clasificar(int $empresaId, array $conCuenta): void
    {
        $viviendas = DB::table('espacios')->where('empresa_id', $empresaId)->where('nivel', 'area_especifica')->where('activo', true)
            ->get(['id', 'sede_id', 'nombre', 'codigo']);
        $porSede = [];
        foreach ($viviendas as $v) {
            foreach (array_filter([$this->clave($v->nombre), $this->clave($v->codigo)]) as $clave) {
                $porSede[(int) $v->sede_id][$clave][] = (int) $v->id;
            }
        }
        $puestosResidente = DB::table('puestos')->where('empresa_id', $empresaId)->get(['id', 'nombre'])
            ->filter(fn ($p) => preg_match(self::PUESTO_RESIDENTE, (string) $p->nombre) === 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $personas = DB::table('colaboradores')->where('empresa_id', $empresaId)
            ->when($conCuenta !== [], fn ($q) => $q->whereNotIn('id', $conCuenta))
            ->get(['id', 'sede_id', 'num_empleado', 'puesto_id', 'departamento_id']);
        foreach ($personas as $p) {
            // Solo si hay UNA vivienda con ese número en su sede (dos iguales: lo decide el administrador)
            $coinciden = $p->sede_id === null || trim((string) $p->num_empleado) === '' ? []
                : array_values(array_unique($porSede[(int) $p->sede_id][$this->clave($p->num_empleado)] ?? []));
            if (count($coinciden) === 1) {
                DB::table('colaboradores')->where('id', $p->id)->update(['tipo' => 'residente', 'espacio_id' => $coinciden[0]]);
            } elseif ($p->puesto_id !== null && in_array((int) $p->puesto_id, $puestosResidente, true)) {
                DB::table('colaboradores')->where('id', $p->id)->update(['tipo' => 'residente']);
            } elseif (preg_match(self::NUMERO_RESIDENTE, trim((string) $p->num_empleado)) === 1 || ($p->puesto_id === null && $p->departamento_id === null)) {
                DB::table('colaboradores')->where('id', $p->id)->update(['tipo_por_confirmar' => true]);
            }
        }
    }

    /** down(): tipo, vivienda y marca de las personas de las empresas residenciales. */
    private function guardarRespaldo(): void
    {
        if (! Schema::hasColumn('colaboradores', 'tipo')) {
            return;
        }
        if (! Schema::hasTable(self::RESPALDO)) {
            Schema::create(self::RESPALDO, function (Blueprint $table) {
                $table->unsignedBigInteger('colaborador_id')->primary();
                $table->unsignedBigInteger('empresa_id')->index();
                $table->string('tipo', 12);
                $table->unsignedBigInteger('espacio_id')->nullable();
                $table->boolean('tipo_por_confirmar')->default(false);
                $table->timestamp('guardado_en')->nullable();
            });
        }
        $empresas = $this->empresasResidenciales()->all();
        if ($empresas === []) {
            return;
        }
        $ahora = now();
        DB::table('colaboradores')->whereIn('empresa_id', $empresas)->orderBy('id')
            ->select(['id', 'empresa_id', 'tipo', 'espacio_id', 'tipo_por_confirmar'])
            ->chunk(500, function ($filas) use ($ahora) {
                DB::table(self::RESPALDO)->upsert($filas->map(fn ($c) => [
                    'colaborador_id' => (int) $c->id, 'empresa_id' => (int) $c->empresa_id, 'tipo' => (string) $c->tipo,
                    'espacio_id' => $c->espacio_id === null ? null : (int) $c->espacio_id, 'tipo_por_confirmar' => (bool) $c->tipo_por_confirmar, 'guardado_en' => $ahora,
                ])->all(), ['colaborador_id'], ['empresa_id', 'tipo', 'espacio_id', 'tipo_por_confirmar', 'guardado_en']);
            });
    }

    /** up() después de un down(): lo guardado gana sobre la deducción; la vivienda solo si sigue existiendo. */
    private function restaurarRespaldo(): void
    {
        if (! Schema::hasTable(self::RESPALDO)) {
            return;
        }
        DB::table(self::RESPALDO)->orderBy('colaborador_id')->chunk(500, function ($filas) {
            $existen = DB::table('espacios')->whereIn('id', $filas->pluck('espacio_id')->filter()->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($filas as $r) {
                DB::table('colaboradores')->where('id', $r->colaborador_id)->where('empresa_id', $r->empresa_id)->update([
                    'tipo' => in_array($r->tipo, ['personal', 'residente'], true) ? $r->tipo : 'personal',
                    'espacio_id' => $r->espacio_id !== null && in_array((int) $r->espacio_id, $existen, true) ? (int) $r->espacio_id : null,
                    'tipo_por_confirmar' => (bool) $r->tipo_por_confirmar,
                ]);
            }
        });
        Schema::drop(self::RESPALDO);
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
