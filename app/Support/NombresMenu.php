<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Configuración → General → «Avanzado: nombres en el menú». Cada empresa
 * puede llamar distinto a un módulo en su menú (empresa_modulos.nombre_visible,
 * el dato que ya leen el menú y «Lo que verá» de la Matriz). Hasta ahora solo
 * se cambiaba en la base de datos; vacío = el nombre de siempre.
 *
 * La Bitácora de novedades no está aquí: su nombre tiene su propio ajuste
 * (Configuración → Novedades, AjustesNovedades), que además cambia títulos,
 * avisos e informes. Una sola fuente de verdad por dato.
 */
final class NombresMenu
{
    /** Módulos cuyo nombre se cambia en otro ajuste. */
    public const EXCLUIDOS = ['novedades'];

    public const MAXIMO = 40;

    /**
     * Módulos contratados por la empresa que tienen renglón en el menú,
     * agrupados por menú: [menú => [[id, clave, original, propio]]].
     *
     * @return array<string, list<array{id: int, clave: string, original: string, propio: ?string}>>
     */
    public static function de(Empresa $empresa): array
    {
        $contratados = DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->where('activo', true)
            ->pluck('nombre_visible', 'modulo_id')->all();
        $terminologia = $empresa->loadMissing('rubro')->rubro?->terminologia ?? [];
        $modulos = Modulo::query()->whereNotNull('menu_id')->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)
            ->whereNotIn('clave', self::EXCLUIDOS)->orderBy('orden_menu')
            ->get(['id', 'menu_id', 'clave', 'nombre', 'nombre_menu', 'ruta'])->groupBy('menu_id');

        $resultado = [];
        foreach (Menu::where('activo', true)->orderBy('orden')->get(['id', 'nombre']) as $menu) {
            foreach ($modulos->get($menu->id, collect()) as $m) {
                if (! array_key_exists($m->id, $contratados) || $m->ruta === null || ! Route::has($m->ruta)) {
                    continue;
                }
                $original = ($m->clave === 'sedes' ? ($terminologia['sedes'] ?? null) : null) ?? $m->nombre_menu ?? $m->nombre;
                $propio = is_string($contratados[$m->id]) && trim($contratados[$m->id]) !== '' ? $contratados[$m->id] : null;
                $resultado[$menu->nombre][] = ['id' => (int) $m->id, 'clave' => $m->clave, 'original' => $original, 'propio' => $propio];
            }
        }

        return $resultado;
    }

    /**
     * Guarda los nombres que llegan (modulo_id => texto). Vacío o igual al
     * original = el de siempre. Devuelve [antes, después] para la bitácora.
     *
     * @param  array<int|string, mixed>  $entrada
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function guardar(Empresa $empresa, array $entrada): array
    {
        $actuales = collect(self::de($empresa))->flatten(1)->keyBy('id');
        $errores = [];
        $cambios = [];
        foreach ($entrada as $id => $texto) {
            $modulo = $actuales->get((int) $id);
            if ($modulo === null) {
                continue; // módulo no contratado o con su propio ajuste: se ignora
            }
            $nombre = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($texto)));
            if ($nombre !== '' && (mb_strlen($nombre) > self::MAXIMO || ! preg_match('/^[\pL\pN .&\-]+$/u', $nombre))) {
                $errores['nombres.'.$id] = "«{$modulo['original']}»: máximo ".self::MAXIMO.' letras; solo letras, números, espacios, punto, guion y «&».';

                continue;
            }
            $cambios[(int) $id] = $nombre === '' || $nombre === $modulo['original'] ? null : $nombre;
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $resumen = fn ($lista) => collect($lista)->filter(fn ($m) => $m['propio'] !== null)->mapWithKeys(fn ($m) => [$m['clave'] => $m['propio']])->all();
        $antes = $resumen($actuales);
        DB::transaction(function () use ($empresa, $cambios) {
            foreach ($cambios as $id => $nombre) {
                DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->where('modulo_id', $id)
                    ->update(['nombre_visible' => $nombre, 'updated_at' => now()]);
            }
        });

        return [$antes, $resumen(collect(self::de($empresa))->flatten(1))];
    }

    /** «Restablecer valor original»: todos los módulos de la lista vuelven a su nombre de siempre. */
    public static function restablecer(Empresa $empresa): void
    {
        $ids = collect(self::de($empresa))->flatten(1)->pluck('id')->all();
        DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->whereIn('modulo_id', $ids)
            ->update(['nombre_visible' => null, 'updated_at' => now()]);
    }
}
