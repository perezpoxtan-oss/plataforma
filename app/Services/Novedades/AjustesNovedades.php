<?php

namespace App\Services\Novedades;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Ajustes de la Bitácora de novedades por empresa (Configuración → Bitácora
 * de novedades, permiso «configuracion.editar»; ADR-0013). Sin tocarlos, todo
 * se ve y funciona como siempre:
 *
 *  - Nombre en el menú: se guarda en empresa_modulos.nombre_visible del
 *    módulo «novedades» (el mismo dato que ya usa el menú). Vacío = el de
 *    siempre («Novedades»). Cambia el menú, el botón de abajo en el celular y
 *    el título de la pantalla; las direcciones y los permisos no cambian.
 *  - Categoría del ticket que abre un hallazgo de un Recorrido de Protección
 *    Civil: «Siniestro Protección Civil» (omisión) o «Mantenimiento de equipo
 *    PC». Se guarda en empresas.preferencias['novedades'].
 */
class AjustesNovedades
{
    public const NOMBRE_OMISION = 'Novedades';

    /** Nombres que se sugieren (se puede escribir otro). */
    public const NOMBRES_SUGERIDOS = ['Novedades', 'Incidentes'];

    public const CATEGORIAS_HALLAZGO = [
        'proteccion_civil' => 'Siniestro Protección Civil (como siempre)',
        'mantenimiento_pc' => 'Mantenimiento de equipo PC',
    ];

    public const CATEGORIA_HALLAZGO_OMISION = 'proteccion_civil';

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /** Nombre del módulo en el menú de la empresa (vacío = el de siempre). */
    public function nombreMenu(?int $empresaId): string
    {
        if ($empresaId === null) {
            return self::NOMBRE_OMISION;
        }
        $nombre = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('m.clave', 'novedades')->value('em.nombre_visible');

        return is_string($nombre) && trim($nombre) !== '' ? $nombre : self::NOMBRE_OMISION;
    }

    /** ¿La empresa cambió el nombre del menú? */
    public function nombrePropio(?int $empresaId): bool
    {
        return $this->nombreMenu($empresaId) !== self::NOMBRE_OMISION;
    }

    public function categoriaHallazgoPc(?Empresa $empresa): string
    {
        $valor = $empresa?->preferencias['novedades']['categoria_hallazgo_pc'] ?? null;

        return isset(self::CATEGORIAS_HALLAZGO[$valor]) ? $valor : self::CATEGORIA_HALLAZGO_OMISION;
    }

    /**
     * @param  array<string, mixed>  $entrada  nombre_menu, categoria_hallazgo_pc
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $datos = Validator::make($entrada, [
            'nombre_menu' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN .&\-]+$/u'],
            'categoria_hallazgo_pc' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIAS_HALLAZGO))],
        ], [
            'nombre_menu.max' => 'El nombre del menú admite máximo 40 letras.',
            'nombre_menu.regex' => 'El nombre del menú solo puede llevar letras, números, espacios, punto, guion y «&».',
            'categoria_hallazgo_pc.required' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
            'categoria_hallazgo_pc.in' => 'Elige la categoría del ticket que abre un hallazgo de recorrido.',
        ])->validate();

        $antes = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa)];
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['nombre_menu'] ?? '')));
        $nombre = $nombre === '' || $nombre === self::NOMBRE_OMISION ? null : $nombre;
        $categoria = $datos['categoria_hallazgo_pc'];

        DB::transaction(function () use ($empresa, $nombre, $categoria) {
            $modulo = Modulo::where('clave', 'novedades')->value('id');
            if ($modulo !== null) {
                // Solo en la empresa que tiene el módulo contratado (sin contrato no hay menú que nombrar)
                DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->where('modulo_id', $modulo)
                    ->update(['nombre_visible' => $nombre, 'updated_at' => now()]);
            }
            $fresca = Empresa::query()->lockForUpdate()->find($empresa->id);
            $preferencias = is_array($fresca?->preferencias) ? $fresca->preferencias : [];
            if ($categoria === self::CATEGORIA_HALLAZGO_OMISION) {
                unset($preferencias['novedades']['categoria_hallazgo_pc']);
                if (($preferencias['novedades'] ?? null) === []) {
                    unset($preferencias['novedades']);
                }
            } else {
                $preferencias['novedades']['categoria_hallazgo_pc'] = $categoria;
            }
            $fresca?->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        });
        $empresa->refresh();

        $despues = ['nombre_menu' => $this->nombreMenu($empresa->id), 'categoria_hallazgo_pc' => $this->categoriaHallazgoPc($empresa)];
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'novedades.configurado', $empresa, $antes, $despues);
        }
    }
}
