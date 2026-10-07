<?php

namespace App\Services\Lector;

use App\Models\EtiquetaPlantilla;
use App\Models\ImpresionEtiquetas;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ronda 7 (parte B): plantillas de etiqueta del gestor de impresión QR.
 *
 *  - Las 4 medidas de la Ronda 6 (llavero, 50 × 25, gafete y calcomanía) son
 *    las plantillas de siempre de cada empresa (clave no nula); se pueden
 *    ajustar o desactivar, pero no borrar.
 *  - De toda la empresa (sede_id nulo) o de una sede. Las de toda la empresa
 *    solo las cambia quien configura con alcance de empresa (Administrador,
 *    Director); el Jefe de seguridad crea y cambia las de su sede.
 *  - Para imprimir se ofrecen las activas de la empresa y de las sedes que
 *    el usuario tiene a cargo. La que se preelige es la última que usó.
 *
 * Debe correr con la empresa de trabajo ya fijada en el Tenant.
 */
class PlantillasEtiquetas
{
    public const CONFIGURAR = 'etiquetas_qr.configurar';

    /** Relleno interior de cada etiqueta (mm): el QR y el texto no tocan la orilla. */
    public const RELLENO = 1.5;

    /** El QR más chico que una cámara de celular lee bien (mm). */
    public const QR_MINIMO = 8;

    /**
     * Las 4 medidas de la Ronda 6 (EtiquetasMasivas::TAMANOS), como columnas
     * de etiquetas_plantillas. También las usa la migración.
     */
    public const PREDETERMINADAS = [
        'llavero' => [
            'nombre' => 'Llavero pequeño', 'formato' => 'rollo', 'papel' => null, 'ancho_mm' => 40, 'alto_mm' => 25,
            'margen_superior_mm' => 0, 'margen_izquierdo_mm' => 0, 'separacion_horizontal_mm' => 0, 'separacion_vertical_mm' => 0,
            'columnas' => 1, 'filas' => 1, 'orientacion' => 'horizontal', 'qr_mm' => 20,
            'mostrar_titulo' => true, 'mostrar_codigo' => true, 'mostrar_fecha' => false, 'mostrar_ubicacion' => false, 'mostrar_tipo' => true, 'mostrar_logo' => true, 'activo' => true,
        ],
        'etiqueta' => [
            'nombre' => 'Etiqueta 50 × 25 mm', 'formato' => 'rollo', 'papel' => null, 'ancho_mm' => 50, 'alto_mm' => 25,
            'margen_superior_mm' => 0, 'margen_izquierdo_mm' => 0, 'separacion_horizontal_mm' => 0, 'separacion_vertical_mm' => 0,
            'columnas' => 1, 'filas' => 1, 'orientacion' => 'horizontal', 'qr_mm' => 21,
            'mostrar_titulo' => true, 'mostrar_codigo' => true, 'mostrar_fecha' => false, 'mostrar_ubicacion' => false, 'mostrar_tipo' => true, 'mostrar_logo' => true, 'activo' => true,
        ],
        'gafete' => [
            'nombre' => 'Gafete', 'formato' => 'hoja', 'papel' => 'carta', 'ancho_mm' => 86, 'alto_mm' => 54,
            'margen_superior_mm' => 20, 'margen_izquierdo_mm' => 18, 'separacion_horizontal_mm' => 4, 'separacion_vertical_mm' => 6,
            'columnas' => 2, 'filas' => 4, 'orientacion' => 'horizontal', 'qr_mm' => 40,
            'mostrar_titulo' => true, 'mostrar_codigo' => true, 'mostrar_fecha' => false, 'mostrar_ubicacion' => true, 'mostrar_tipo' => true, 'mostrar_logo' => true, 'activo' => true,
        ],
        'calcomania' => [
            'nombre' => 'Calcomanía vehicular', 'formato' => 'hoja', 'papel' => 'carta', 'ancho_mm' => 100, 'alto_mm' => 70,
            'margen_superior_mm' => 15, 'margen_izquierdo_mm' => 6, 'separacion_horizontal_mm' => 4, 'separacion_vertical_mm' => 6,
            'columnas' => 2, 'filas' => 3, 'orientacion' => 'horizontal', 'qr_mm' => 55,
            'mostrar_titulo' => true, 'mostrar_codigo' => true, 'mostrar_fecha' => false, 'mostrar_ubicacion' => false, 'mostrar_tipo' => true, 'mostrar_logo' => true, 'activo' => true,
        ],
    ];

    /** Plantilla de siempre que se preelige según el tipo filtrado (como en la Ronda 6). */
    public const POR_TIPO = ['llave' => 'llavero', 'gafete' => 'gafete', 'vehiculo' => 'calcomania'];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /** Crea las 4 plantillas de siempre si la empresa (del Tenant) aún no las tiene (empresas nuevas). */
    public function asegurar(): void
    {
        if (EtiquetaPlantilla::query()->whereNotNull('clave')->exists()) {
            return;
        }
        foreach (self::PREDETERMINADAS as $clave => $datos) {
            $nombre = $datos['nombre'];
            // Si alguien ya usó ese nombre en una plantilla propia, no se duplica
            if (EtiquetaPlantilla::query()->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
                $nombre .= ' (predeterminada)';
            }
            $plantilla = new EtiquetaPlantilla(['nombre' => $nombre] + $datos);
            $plantilla->clave = $clave;
            $plantilla->save();
        }
    }

    // ------------------------------------------------------------ Consultas

    /**
     * Plantillas activas con las que el usuario puede imprimir: las de toda la
     * empresa y las de sus sedes (según «etiquetas_qr.ver»).
     *
     * @return Collection<int, EtiquetaPlantilla>
     */
    public function disponibles(User $actor): Collection
    {
        $this->asegurar();

        return $this->alcance(EtiquetaPlantilla::query()->where('activo', true), $this->autorizador->sedesPermitidas($actor, 'etiquetas_qr.ver'))
            ->with('sede:id,nombre')->orderByRaw('CASE WHEN clave IS NULL THEN 1 ELSE 0 END')->orderBy('nombre')->get();
    }

    /**
     * Todas las plantillas que ve quien configura (activas e inactivas).
     *
     * @return Collection<int, EtiquetaPlantilla>
     */
    public function paraConfigurar(User $actor): Collection
    {
        $this->asegurar();

        return $this->alcance(EtiquetaPlantilla::query(), $this->autorizador->sedesPermitidas($actor, self::CONFIGURAR))
            ->with('sede:id,nombre')
            ->leftJoin('users as uc', 'uc.id', '=', 'etiquetas_plantillas.creado_por')
            ->leftJoin('users as ua', 'ua.id', '=', 'etiquetas_plantillas.actualizado_por')
            ->select('etiquetas_plantillas.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
            ->orderByDesc('etiquetas_plantillas.activo')->orderByRaw('CASE WHEN etiquetas_plantillas.clave IS NULL THEN 1 ELSE 0 END')
            ->orderBy('etiquetas_plantillas.nombre')->get();
    }

    /** ¿Puede cambiarla? Las de toda la empresa piden alcance de empresa; las de sede, esa sede. */
    public function puedeEditar(User $actor, EtiquetaPlantilla $plantilla): bool
    {
        if (! $actor->can(self::CONFIGURAR)) {
            return false;
        }
        if ($plantilla->sede_id === null) {
            return $this->autorizador->alcanceDeEmpresa($actor, self::CONFIGURAR);
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, self::CONFIGURAR);

        return $sedes === null || in_array((int) $plantilla->sede_id, array_map('intval', $sedes), true);
    }

    /** La plantilla a su alcance para configurar (otra sede u otra empresa: 404; de toda la empresa sin alcance: 403). */
    public function paraEditar(User $actor, int $id): EtiquetaPlantilla
    {
        $plantilla = $this->alcance(EtiquetaPlantilla::query(), $this->autorizador->sedesPermitidas($actor, self::CONFIGURAR))->whereKey($id)->first();
        abort_if($plantilla === null, 404);
        abort_unless($this->puedeEditar($actor, $plantilla), 403, 'Esta plantilla es de toda la empresa: solo la cambia quien administra toda la empresa.');

        return $plantilla;
    }

    /**
     * La que se preelige al imprimir: la última que usó este usuario; si no,
     * la de siempre del tipo filtrado (llavero para llaves…); si no, la de 50 × 25.
     *
     * @param  Collection<int, EtiquetaPlantilla>  $disponibles
     */
    public function preelegida(User $actor, Collection $disponibles, ?string $tipo): ?EtiquetaPlantilla
    {
        $ultima = ImpresionEtiquetas::query()->where('creado_por', $actor->id)->whereIn('plantilla_id', $disponibles->pluck('id'))
            ->latest('id')->value('plantilla_id');
        if ($ultima !== null && $tipo === null) {
            return $disponibles->firstWhere('id', $ultima);
        }
        $clave = self::POR_TIPO[$tipo] ?? null;

        return ($clave ? $disponibles->firstWhere('clave', $clave) : null)
            ?? ($ultima !== null ? $disponibles->firstWhere('id', $ultima) : null)
            ?? $disponibles->firstWhere('clave', 'etiqueta')
            ?? $disponibles->first();
    }

    /**
     * Sedes que puede elegir al guardar una plantilla (null = también «Toda la empresa»).
     *
     * @return array{0: bool, 1: Collection<int, Sede>}
     */
    public function sedesParaElegir(User $actor): array
    {
        $deEmpresa = $this->autorizador->alcanceDeEmpresa($actor, self::CONFIGURAR);
        $sedes = $this->autorizador->sedesPermitidas($actor, self::CONFIGURAR);

        return [$deEmpresa, Sede::where('activo', true)->when($sedes !== null, fn ($q) => $q->whereIn('id', $sedes))->orderBy('nombre')->get(['id', 'nombre'])];
    }

    // ------------------------------------------------------------ Guardar

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, array $entrada, ?EtiquetaPlantilla $actual = null): EtiquetaPlantilla
    {
        $datos = $this->validar($actor, $entrada, $actual);
        $antes = $actual?->only(array_keys($datos));
        $plantilla = $actual ?? new EtiquetaPlantilla;
        $plantilla->fill($datos)->save();
        $this->auditoria->auditar($actor, $actual ? 'etiquetas_qr.plantilla_actualizada' : 'etiquetas_qr.plantilla_creada', $plantilla, $antes, $plantilla->only(array_keys($datos)));

        return $plantilla;
    }

    public function cambiarEstado(User $actor, EtiquetaPlantilla $plantilla, bool $activo): void
    {
        if ($plantilla->activo === $activo) {
            return;
        }
        if (! $activo && $plantilla->sede_id === null
            && ! EtiquetaPlantilla::query()->whereNull('sede_id')->where('activo', true)->whereKeyNot($plantilla->id)->exists()) {
            throw ValidationException::withMessages(['activo' => 'Es la única plantilla activa de toda la empresa: activa otra antes de desactivar esta (sin plantillas no se podría imprimir).']);
        }
        $plantilla->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'etiquetas_qr.plantilla_reactivada' : 'etiquetas_qr.plantilla_desactivada', $plantilla, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Valida y acomoda lo capturado. Revisa que el QR quepa en la etiqueta y
     * que la planilla quepa en la hoja, con mensajes que dicen qué cambiar.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validar(User $actor, array $entrada, ?EtiquetaPlantilla $actual): array
    {
        $entrada['nombre'] = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($entrada['nombre'] ?? null)));
        foreach (['ancho_mm', 'alto_mm', 'margen_superior_mm', 'margen_izquierdo_mm', 'separacion_horizontal_mm', 'separacion_vertical_mm', 'qr_mm'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = str_replace(',', '.', trim($entrada[$campo]));
            }
        }
        [$deEmpresa, $sedes] = $this->sedesParaElegir($actor);
        $hoja = ($entrada['formato'] ?? null) === 'hoja';

        $datos = Validator::make($entrada, [
            'nombre' => ['required', 'string', 'max:80', function (string $a, mixed $valor, \Closure $falla) use ($actual) {
                $repetida = EtiquetaPlantilla::query()->whereRaw('LOWER(nombre) = ?', [mb_strtolower((string) $valor)])
                    ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->exists();
                if ($repetida) {
                    $falla('Ya existe una plantilla con ese nombre en esta empresa.');
                }
            }],
            'sede_id' => [$deEmpresa ? 'nullable' : 'required', 'integer', Rule::in($sedes->pluck('id')->all())],
            'formato' => ['required', Rule::in(array_keys(EtiquetaPlantilla::FORMATOS))],
            'papel' => [$hoja ? 'required' : 'nullable', Rule::in(array_keys(EtiquetaPlantilla::PAPELES))],
            'ancho_mm' => ['required', 'numeric', 'min:15', 'max:216'],
            'alto_mm' => ['required', 'numeric', 'min:10', 'max:297'],
            'margen_superior_mm' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'margen_izquierdo_mm' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'separacion_horizontal_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'separacion_vertical_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'columnas' => [$hoja ? 'required' : 'nullable', 'integer', 'min:1', 'max:10'],
            'filas' => [$hoja ? 'required' : 'nullable', 'integer', 'min:1', 'max:30'],
            'orientacion' => ['required', Rule::in(array_keys(EtiquetaPlantilla::ORIENTACIONES))],
            'qr_mm' => ['required', 'numeric', 'min:'.self::QR_MINIMO, 'max:200'],
        ], [
            'nombre.required' => 'Escribe un nombre para la plantilla (por ejemplo «Zebra 2 × 1 pulgadas»).',
            'sede_id.required' => 'Elige la sede de la plantilla.',
            'sede_id.in' => 'Elige una de tus sedes.',
            'papel.required' => 'Elige el tamaño de la hoja (carta o A4).',
            'columnas.required' => 'Escribe cuántas etiquetas caben a lo ancho de la hoja (columnas).',
            'filas.required' => 'Escribe cuántas etiquetas caben a lo largo de la hoja (filas).',
            'qr_mm.min' => 'El QR debe medir al menos '.self::QR_MINIMO.' mm para que la cámara lo lea.',
            'ancho_mm.min' => 'La etiqueta debe medir al menos 15 mm de ancho.',
            'alto_mm.min' => 'La etiqueta debe medir al menos 10 mm de alto.',
        ], [
            'ancho_mm' => 'ancho', 'alto_mm' => 'alto', 'margen_superior_mm' => 'margen superior', 'margen_izquierdo_mm' => 'margen izquierdo',
            'separacion_horizontal_mm' => 'separación entre columnas', 'separacion_vertical_mm' => 'separación entre filas',
            'qr_mm' => 'tamaño del QR', 'columnas' => 'columnas', 'filas' => 'filas', 'papel' => 'hoja', 'formato' => 'formato', 'orientacion' => 'orientación',
        ])->validate();

        $num = fn (string $c) => round((float) ($datos[$c] ?? 0), 1);
        $limpio = [
            'nombre' => $datos['nombre'],
            'sede_id' => isset($datos['sede_id']) ? (int) $datos['sede_id'] : null,
            'formato' => $datos['formato'],
            'papel' => $hoja ? $datos['papel'] : null,
            'ancho_mm' => $num('ancho_mm'),
            'alto_mm' => $num('alto_mm'),
            'margen_superior_mm' => $hoja ? $num('margen_superior_mm') : 0,
            'margen_izquierdo_mm' => $hoja ? $num('margen_izquierdo_mm') : 0,
            'separacion_horizontal_mm' => $hoja ? $num('separacion_horizontal_mm') : 0,
            'separacion_vertical_mm' => $num('separacion_vertical_mm'),
            'columnas' => $hoja ? (int) $datos['columnas'] : 1,
            'filas' => $hoja ? (int) $datos['filas'] : 1,
            'orientacion' => $datos['orientacion'],
            'qr_mm' => $num('qr_mm'),
        ];
        foreach (array_keys(EtiquetaPlantilla::DATOS) as $dato) {
            $limpio[$dato] = filter_var($entrada[$dato] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $errores = $this->revisarMedidas($limpio);
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $limpio;
    }

    /**
     * ¿Cabe el QR (y algo de texto) en la etiqueta y la planilla en la hoja?
     *
     * @param  array<string, mixed>  $d
     * @return array<string, string>
     */
    public function revisarMedidas(array $d): array
    {
        $errores = [];
        $mm = fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $maximoQr = min($d['ancho_mm'], $d['alto_mm']) - 2 * self::RELLENO;
        if ($d['qr_mm'] > $maximoQr) {
            $errores['qr_mm'] = "El QR de {$mm($d['qr_mm'])} mm no cabe en una etiqueta de {$mm($d['ancho_mm'])} × {$mm($d['alto_mm'])} mm: puede medir hasta {$mm(max(0, $maximoQr))} mm.";
        } elseif ($d['orientacion'] === 'vertical' && $d['alto_mm'] - $d['qr_mm'] - 2 * self::RELLENO < 5) {
            $errores['qr_mm'] = 'Con el QR arriba no queda espacio para el texto: haz el QR más chico, la etiqueta más alta o usa la orientación horizontal.';
        } elseif ($d['orientacion'] === 'horizontal' && $d['ancho_mm'] - $d['qr_mm'] - 2 * self::RELLENO < 12) {
            $errores['qr_mm'] = 'Con el QR a la izquierda no queda espacio para el texto: haz el QR más chico, la etiqueta más ancha o usa la orientación vertical.';
        }
        if (! $d['mostrar_titulo'] && ! $d['mostrar_codigo']) {
            $errores['mostrar_titulo'] = 'Marca al menos el nombre o el código legible: una etiqueta solo con el QR no se reconoce a simple vista.';
        }
        if ($d['formato'] === 'hoja') {
            [$papel, $anchoHoja, $altoHoja] = EtiquetaPlantilla::PAPELES[$d['papel']];
            $nombreHoja = $d['papel'] === 'a4' ? 'A4' : 'carta';
            $ancho = $d['margen_izquierdo_mm'] + $d['columnas'] * $d['ancho_mm'] + ($d['columnas'] - 1) * $d['separacion_horizontal_mm'];
            $alto = $d['margen_superior_mm'] + $d['filas'] * $d['alto_mm'] + ($d['filas'] - 1) * $d['separacion_vertical_mm'];
            if ($ancho > $anchoHoja) {
                $errores['columnas'] = "No caben {$d['columnas']} columnas en la hoja {$nombreHoja}: ocupan {$mm($ancho)} mm (con el margen izquierdo y la separación) y la hoja mide {$mm($anchoHoja)} mm de ancho. Quita una columna o reduce las medidas.";
            }
            if ($alto > $altoHoja) {
                $errores['filas'] = "No caben {$d['filas']} filas en la hoja {$nombreHoja}: ocupan {$mm($alto)} mm (con el margen superior y la separación) y la hoja mide {$mm($altoHoja)} mm de alto. Quita una fila o reduce las medidas.";
            }
        }

        return $errores;
    }

    // ------------------------------------------------------------ Impresión

    /**
     * Tamaños de letra (mm) según el espacio que deja el QR, para la hoja de impresión.
     *
     * @return array{titulo: float, chico: float, logo: float, relleno: float}
     */
    public function medidasTexto(EtiquetaPlantilla $p): array
    {
        $alto = $p->orientacion === 'vertical' ? $p->alto_mm - $p->qr_mm - 2 * self::RELLENO - 1 : $p->alto_mm - 2 * self::RELLENO;
        $lineas = ($p->mostrar_titulo ? 2.2 : 0) + ($p->mostrar_codigo ? 1 : 0) + ($p->mostrar_tipo ? 1 : 0)
            + ($p->mostrar_ubicacion ? 1 : 0) + ($p->mostrar_fecha ? 1 : 0) + ($p->mostrar_logo ? 1.3 : 0);
        $unidad = $alto / max(3, $lineas + 0.6);
        // También cuenta el ancho que queda para el texto (un nombre de ~8 letras debe caber en una línea)
        $ancho = $p->orientacion === 'vertical' ? $p->ancho_mm - 2 * self::RELLENO : $p->ancho_mm - $p->qr_mm - 3 * self::RELLENO;
        $limitar = fn (float $v, float $min, float $max) => round(max($min, min($max, $v)), 2);

        return [
            'titulo' => $limitar(min($unidad * 1.7, $ancho / 5), 1.6, 9),
            'chico' => $limitar(min($unidad * 0.85, $ancho / 7.5), 1.2, 3.6),
            'logo' => $limitar(min($unidad * 1.3, $ancho / 3), 2, 9),
            'relleno' => self::RELLENO,
        ];
    }

    /**
     * Plantillas de toda la empresa o de las sedes indicadas (null = todas).
     *
     * @param  Builder<EtiquetaPlantilla>  $consulta
     * @param  list<int>|null  $sedes
     * @return Builder<EtiquetaPlantilla>
     */
    private function alcance(Builder $consulta, ?array $sedes): Builder
    {
        return $sedes === null ? $consulta : $consulta->where(fn ($q) => $q->whereNull('etiquetas_plantillas.sede_id')->orWhereIn('etiquetas_plantillas.sede_id', $sedes));
    }
}
