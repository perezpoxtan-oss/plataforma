<?php

namespace App\Support;

use App\Models\Acceso;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Support\Espacios\Etiquetas;
use App\Support\Tenancy\Tenant;

/**
 * Palabras de la plataforma según el giro (rubro) de la empresa: el único
 * lugar que decide cómo se nombra a quien visita, a quien vive o trabaja en
 * la sede, al «área específica» del árbol y a los tipos de acceso.
 *
 *  - Hotel (o empresa sin giro): todo queda exactamente como siempre.
 *  - Corporativo / industria: «Visitante», «Oficina»; sin Huésped.
 *  - Condominio y fraccionamiento: «Residente» (el colaborador), «Visita»,
 *    «Servicio», «Departamento» o «Casa»; sin Huésped, reservación ni tour.
 *
 * Las claves internas de los tipos de acceso no cambian (`colaborador`,
 * `huesped`, `visitante`…): solo cambia qué tipos se ofrecen y su nombre. Un
 * registro de antes con un tipo que el giro ya no ofrece se sigue mostrando
 * con su nombre de siempre.
 *
 * Además de las etiquetas, texto() y html() cambian las palabras de hotel
 * que quedaron escritas en pantallas, avisos, correos y en el manual
 * (Huésped, Habitación, Colaborador en condominios…). Ver docs/tecnico/giros.md.
 */
final class Terminos
{
    public const HOTEL = 'hotel';

    public const CORPORATIVO = 'corporativo';

    public const CONDOMINIO = 'condominio';

    public const FRACCIONAMIENTO = 'fraccionamiento';

    /** Giros donde la gente vive en la sede. */
    public const RESIDENCIALES = [self::CONDOMINIO, self::FRACCIONAMIENTO];

    /** Género de las palabras que se sustituyen (para cuidar el artículo: «la habitación» → «el departamento»). */
    private const FEMENINAS = ['visita', 'oficina', 'casa', 'habitación', 'habitacion', 'suite', 'residencia', 'villa', 'unidad', 'vivienda'];

    /** Palabras que no cambian en plural («el personal» / «el personal»). */
    private const SIN_PLURAL = ['personal'];

    /** Marcas que apagan la sustitución en un pedazo de HTML (p. ej. el formato de Accidente, que no se toca). */
    public const SIN_SUSTITUIR = '<!--terminos:no-->';

    public const SUSTITUIR = '<!--terminos:si-->';

    /** Atributos visibles que también se adaptan. */
    private const ATRIBUTOS = ['placeholder', 'title', 'aria-label', 'alt', 'data-confirmar', 'data-titulo', 'label'];

    /** @var list<array{0: string, 1: callable|string}>|null */
    private ?array $reglas = null;

    /**
     * @param  array<string, string>  $terminologia
     */
    private function __construct(public readonly string $giro, private readonly array $terminologia) {}

    // ------------------------------------------------------------- Creación

    public static function para(?Empresa $empresa): self
    {
        if ($empresa === null) {
            return self::hotel();
        }
        $rubro = $empresa->relationLoaded('rubro') ? $empresa->rubro : $empresa->rubro()->first();

        return self::deRubro($rubro?->clave, $rubro?->terminologia ?? []);
    }

    /** Términos de una empresa por su id (una consulta por empresa y petición). */
    public static function deEmpresaId(?int $empresaId): self
    {
        if ($empresaId === null) {
            return self::hotel();
        }

        return app(RegistroTerminos::class)->recordar($empresaId, function () use ($empresaId) {
            $empresa = Empresa::query()->withoutGlobalScopes()->with('rubro')->find($empresaId);

            return self::para($empresa);
        });
    }

    /** Términos de la empresa activa (la del usuario o la elegida por el Super Administrador). */
    public static function actual(): self
    {
        return self::deEmpresaId(app(Tenant::class)->empresaId());
    }

    /**
     * @param  array<string, string>  $terminologia
     */
    public static function deRubro(?string $clave, array $terminologia = []): self
    {
        $giro = in_array($clave, [self::CORPORATIVO, self::CONDOMINIO, self::FRACCIONAMIENTO], true) ? $clave : self::HOTEL;

        return new self($giro, $giro === self::HOTEL ? [] : array_filter($terminologia, 'is_string'));
    }

    public static function hotel(): self
    {
        return new self(self::HOTEL, []);
    }

    // ---------------------------------------------------------------- Giro

    public function esHotel(): bool
    {
        return $this->giro === self::HOTEL;
    }

    public function esCorporativo(): bool
    {
        return $this->giro === self::CORPORATIVO;
    }

    public function esResidencial(): bool
    {
        return in_array($this->giro, self::RESIDENCIALES, true);
    }

    // ------------------------------------------------------------ Palabras

    /** Quien visita la sede: Huésped (hotel), Visitante (corporativo), Visita (condominio). */
    public function visitante(): string
    {
        return $this->esHotel() ? 'Huésped' : ($this->terminologia['visitante'] ?? ($this->esResidencial() ? 'Visita' : 'Visitante'));
    }

    public function visitantes(): string
    {
        return $this->esHotel() ? 'Huéspedes' : Etiquetas::plural($this->visitante());
    }

    /**
     * Quien trabaja en la sede: Colaborador, o «Personal» en condominio y
     * fraccionamiento (C-10: el residente ya no es el colaborador; es un tipo
     * de persona con vivienda, ver residente()).
     */
    public function colaborador(): string
    {
        $palabra = $this->esHotel() ? 'Colaborador' : ($this->terminologia['colaborador'] ?? ($this->esResidencial() ? 'Personal' : 'Colaborador'));

        // Datos de antes de C-10: «Residente» ya no nombra al personal
        return $this->esResidencial() && mb_strtolower($palabra) === 'residente' ? 'Personal' : $palabra;
    }

    public function colaboradores(): string
    {
        return self::plural($this->colaborador());
    }

    /** C-10: quien vive en la sede (solo giros residenciales). */
    public function residente(): string
    {
        return 'Residente';
    }

    public function residentes(): string
    {
        return 'Residentes';
    }

    /** Plural que respeta las palabras sin plural («Personal»). */
    public static function plural(string $palabra): string
    {
        return in_array(mb_strtolower($palabra), self::SIN_PLURAL, true) ? $palabra : Etiquetas::plural($palabra);
    }

    /** Nivel «área específica» del árbol: Habitación, Oficina, Departamento, Casa. */
    public function espacio(): string
    {
        return $this->esHotel() ? 'Habitación' : ($this->terminologia['area_especifica'] ?? 'Área específica');
    }

    /**
     * Revisión Etapa 5: cómo se llama UN lugar de ese nivel. En condominio y
     * fraccionamiento, el área común (alberca, salón) no es «Vivienda».
     *
     * @param  Espacio|array<string, mixed>|null  $lugar  el espacio o la fila de Lugares (con «area_comun»)
     */
    public function nombreLugar(Espacio|array|null $lugar): string
    {
        $comun = is_array($lugar) ? (bool) ($lugar['area_comun'] ?? false) : (bool) $lugar?->esAreaComun();

        return $comun && $this->esResidencial() ? 'Área común' : $this->espacio();
    }

    public function espacios(): string
    {
        return Etiquetas::plural($this->espacio());
    }

    /** Pastilla junto al nombre en la Bitácora de accesos: «Hab. 204», «Depto. 101», «Casa 12». */
    public function abreviaturaEspacio(?string $tipoAcceso = null): string
    {
        if ($this->esHotel() || $tipoAcceso === 'huesped') {
            return 'Hab.';
        }

        return match (mb_strtolower($this->espacio())) {
            'departamento' => 'Depto.',
            'vivienda' => 'Viv.',
            'habitación', 'habitacion' => 'Hab.',
            default => $this->espacio(),
        };
    }

    // ------------------------------------------------------ Tipos de acceso

    /**
     * Tipos que se ofrecen en este giro: clave => nombre corto (tarjetas, filtros, informes).
     *
     * @return array<string, string>
     */
    public function tiposAcceso(): array
    {
        if ($this->esHotel()) {
            return Acceso::TIPOS;
        }

        return [
            // C-10: en residenciales entran residentes y personal con el mismo tipo
            'colaborador' => $this->esResidencial() ? 'Residente o personal' : $this->colaborador(),
            'visitante' => $this->visitante(),
            'proveedor' => $this->esResidencial() ? 'Servicio' : 'Proveedor',
            'contratista' => 'Contratista',
            'emergencia' => 'Servicio de Emergencia',
        ];
    }

    /**
     * Opciones del formulario de Nuevo Ingreso (solo los tipos del giro).
     *
     * @return array<string, string>
     */
    public function opcionesTipo(): array
    {
        if ($this->esHotel()) {
            return Acceso::OPCIONES_TIPO;
        }

        return [
            'colaborador' => $this->esResidencial() ? 'Residente o personal' : $this->colaborador(),
            'visitante' => $this->visitante(),
            'proveedor' => $this->esResidencial() ? 'Servicio / Proveedor' : 'Proveedor',
            'contratista' => 'Contratista (Obra)',
            'emergencia' => 'Servicio de Emergencia',
        ];
    }

    public function ofreceTipo(?string $tipo): bool
    {
        return $tipo !== null && array_key_exists($tipo, $this->tiposAcceso());
    }

    /** Nombre de un tipo: el del giro si lo ofrece; si no (registro de antes), su nombre de siempre. */
    public function etiquetaTipo(?string $tipo): string
    {
        return $this->tiposAcceso()[$tipo] ?? Acceso::TIPOS[$tipo] ?? (string) $tipo;
    }

    /**
     * Todos los tipos (también los que el giro ya no ofrece) con el nombre que
     * se muestra: para informes y conteos de registros de cualquier fecha.
     *
     * @return array<string, string>
     */
    public function etiquetasTipo(): array
    {
        return array_merge(Acceso::TIPOS, $this->tiposAcceso());
    }

    /**
     * Motivos de la visita que se ofrecen: clave => texto del botón.
     *
     * @return array<string, string>
     */
    public function motivos(): array
    {
        if ($this->esResidencial()) {
            return ['colaborador' => 'Visita a residente', 'departamento' => 'Visita a Administración'];
        }

        return Acceso::MOTIVOS;
    }

    /**
     * C-09 (DD-12): texto del botón que junta «Visita a colaborador» y «Visita a
     * departamento» en un solo motivo con «¿A quién viene?» (persona o área).
     */
    public function motivoAQuien(): string
    {
        return $this->esResidencial() ? 'Visita' : 'Visita a persona o área';
    }

    public function motivoPorDefecto(): string
    {
        return array_key_first($this->motivos());
    }

    public function etiquetaMotivo(?string $motivo): string
    {
        return $this->motivos()[$motivo] ?? Acceso::MOTIVOS[$motivo] ?? (string) $motivo;
    }

    /** En condominios y fraccionamientos la visita dice a qué departamento o casa va. */
    public function visitaLlevaEspacio(): bool
    {
        return $this->esResidencial();
    }

    // ------------------------------------------------------- Sustitución

    /**
     * Cambia en un texto las palabras de hotel por las del giro (en hotel lo
     * deja igual). Cuida mayúsculas, plurales y el artículo de enfrente.
     */
    public function texto(?string $texto): string
    {
        $texto = (string) $texto;
        // Atajo: la mayoría de los textos no tiene ninguna palabra que cambiar
        if ($this->esHotel() || $texto === '' || ! preg_match('/hu[eé]sped|habitaci|colaborador|lost|personal externo/iu', $texto)) {
            return $texto;
        }
        foreach ($this->reglas() as [$patron, $reemplazo]) {
            $texto = is_string($reemplazo)
                ? (string) preg_replace($patron, $reemplazo, $texto)
                : (string) preg_replace_callback($patron, $reemplazo, $texto);
        }

        return $texto;
    }

    /**
     * Lo mismo que texto() sobre una página HTML: solo en el texto visible y en
     * los atributos que se leen (placeholder, title, aria-label…). No toca
     * <script>, <style>, <textarea>, valores de campos ni lo que esté entre
     * self::SIN_SUSTITUIR y self::SUSTITUIR.
     */
    public function html(string $html): string
    {
        if ($this->esHotel() || $html === '') {
            return $html;
        }

        $partes = preg_split('/(<!--terminos:(?:no|si)-->|<script\b.*?<\/script>|<style\b.*?<\/style>|<textarea\b.*?<\/textarea>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($partes === false) {
            return $html;
        }
        $apagado = false;
        foreach ($partes as $i => $parte) {
            if ($parte === self::SIN_SUSTITUIR) {
                $apagado = true;

                continue;
            }
            if ($parte === self::SUSTITUIR) {
                $apagado = false;

                continue;
            }
            if ($apagado || $parte === '') {
                continue;
            }
            if ($parte[0] !== '<') {
                $partes[$i] = $this->texto($parte);
            } elseif (preg_match('/^<[a-z]/i', $parte) && ! preg_match('/^<(script|style|textarea)\b/i', $parte)) {
                $partes[$i] = (string) preg_replace_callback(
                    '/(\s(?:'.implode('|', array_map(fn ($a) => preg_quote($a, '/'), self::ATRIBUTOS)).')=")([^"]*)(")/i',
                    fn (array $m) => $m[1].$this->texto($m[2]).$m[3],
                    $parte,
                );
            }
        }

        return implode('', $partes);
    }

    /**
     * Reglas de sustitución en orden: primero frases completas, después palabras.
     *
     * @return list<array{0: string, 1: callable|string}>
     */
    private function reglas(): array
    {
        if ($this->reglas !== null) {
            return $this->reglas;
        }
        $reglas = [];
        $visitante = $this->visitante();

        // Frases de Objetos olvidados (Lost & Found) que en otros giros se dicen sin «huésped»
        foreach ([
            'Devuelto al Huésped' => 'Devuelto a su dueño',
            'lo que un huésped dice que le falta' => 'lo que una persona dice que le falta',
            'Nombre del huésped' => 'Nombre de quien lo perdió',
            'Nombre del Huésped' => 'Nombre de quien lo perdió',
            'Huésped o persona externa' => 'Persona externa',
            // C-32 (D-17): una sola etiqueta, sin «Objetos olvidados (Objetos Perdidos)»
            'Lost &amp; Found (Objetos Perdidos)' => 'Objetos olvidados',
            'Lost & Found (Objetos Perdidos)' => 'Objetos olvidados',
            'Lost &amp; Found' => 'Objetos olvidados',
            'Lost & Found' => 'Objetos olvidados',
            'Lost and Found' => 'Objetos olvidados',
            'Transporte de Huéspedes' => 'Transporte de '.$this->visitantes(),
            'Transporte Huéspedes' => 'Transporte '.$this->visitantes(),
            'Transporte de huéspedes' => 'Transporte de '.mb_strtolower($this->visitantes()),
            'Personal Externo / Visita' => $visitante,
        ] as $frase => $cambio) {
            $reglas[] = ['/'.preg_quote($frase, '/').'/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $cambio)];
        }

        $reglas[] = $this->reglaPalabra(['huésped', 'huéspedes', 'huesped', 'huespedes'], $visitante, false);
        $reglas[] = $this->reglaPalabra(['personal externo'], $visitante, false);
        $reglas[] = $this->reglaPalabra(['habitación', 'habitaciones', 'habitacion'], $this->espacio(), true);
        if ($this->colaborador() !== 'Colaborador') {
            // Frases con artículo que no se dicen con «Personal» (C-10)
            if (in_array(mb_strtolower($this->colaborador()), self::SIN_PLURAL, true)) {
                foreach (['un colaborador' => 'alguien del personal', 'Un colaborador' => 'Alguien del personal', 'una colaboradora' => 'alguien del personal',
                    'al colaborador' => 'al personal', 'del colaborador' => 'del personal', 'el colaborador' => 'el personal', 'El colaborador' => 'El personal',
                    'Nuevo Colaborador' => 'Nuevo personal', 'Nuevo colaborador' => 'Nuevo personal', 'Colaborador que Ingresa' => 'Persona que ingresa'] as $frase => $cambio) {
                    $reglas[] = ['/(?<![\p{L}\p{N}])'.preg_quote($frase, '/').'(?![\p{L}\p{N}])/u', $cambio];
                }
            }
            $reglas[] = $this->reglaPalabra(['colaborador', 'colaboradores', 'colaboradora', 'colaboradoras'], $this->colaborador(), false);
        }

        return $this->reglas = $reglas;
    }

    /**
     * Regla para una palabra (singular y plural) con el artículo o
     * determinante de enfrente, que se ajusta al género de la palabra nueva.
     *
     * @param  list<string>  $formas  la primera es el singular
     * @return array{0: string, 1: callable}
     */
    private function reglaPalabra(array $formas, string $nueva, bool $origenFemenino): array
    {
        $destinoFemenino = in_array(mb_strtolower(explode(' ', $nueva)[0]), self::FEMENINAS, true);
        $articulos = ['el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'del', 'al', 'de la', 'a la', 'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'esos', 'esas', 'nuevo', 'nueva', 'nuevos', 'nuevas'];
        $alternativas = implode('|', array_map(fn ($f) => preg_quote($f, '/'), $formas));
        $patron = '/(?<![\p{L}\p{N}])(?:('.implode('|', array_map(fn ($a) => preg_quote($a, '/'), $articulos)).')(\s+))?('.$alternativas.')(?![\p{L}\p{N}])/iu';

        return [$patron, function (array $m) use ($nueva, $destinoFemenino) {
            $palabra = $m[3];
            $base = mb_strtolower($palabra);
            $plural = in_array($base, ['huéspedes', 'huespedes', 'habitaciones', 'colaboradores', 'colaboradoras'], true);
            $texto = $plural ? self::plural($nueva) : $nueva;
            $texto = $this->comoOriginal($palabra, $texto);

            $articulo = $m[1] ?? '';
            if ($articulo === '') {
                return $texto;
            }
            // «los colaboradores» → «el personal» (palabra sin plural: el artículo va en singular)
            if ($plural && in_array(mb_strtolower($nueva), self::SIN_PLURAL, true)) {
                $singular = ['los' => 'el', 'las' => 'la', 'unos' => 'el', 'unas' => 'el', 'estos' => 'este', 'estas' => 'este', 'esos' => 'ese', 'esas' => 'ese',
                    'nuevos' => 'nuevo', 'nuevas' => 'nuevo', 'del' => 'del', 'al' => 'al'];
                $bajo = mb_strtolower($articulo);
                if (isset($singular[$bajo])) {
                    $nuevo = $singular[$bajo];
                    $articulo = mb_strtoupper($articulo) === $articulo && mb_strlen($articulo) > 1 ? mb_strtoupper($nuevo)
                        : (mb_strtoupper(mb_substr($articulo, 0, 1)) === mb_substr($articulo, 0, 1) ? mb_strtoupper(mb_substr($nuevo, 0, 1)).mb_substr($nuevo, 1) : $nuevo);

                    return $articulo.$m[2].$texto;
                }
            }

            return $this->ajustarArticulo($articulo, $destinoFemenino).$m[2].$texto;
        }];
    }

    /** Mismo uso de mayúsculas que la palabra original (TODO, Inicial o minúsculas). */
    private function comoOriginal(string $original, string $nueva): string
    {
        if (mb_strtoupper($original) === $original && mb_strtolower($original) !== $original) {
            return mb_strtoupper($nueva);
        }
        $primera = mb_substr($original, 0, 1);
        if (mb_strtoupper($primera) === $primera) {
            return mb_strtoupper(mb_substr($nueva, 0, 1)).mb_substr($nueva, 1);
        }

        // En medio de una frase: minúscula (salvo siglas)
        return mb_strtolower(mb_substr($nueva, 0, 1)).mb_substr($nueva, 1);
    }

    /** «el/la», «un/una», «del/de la»… según el género de la palabra nueva. */
    private function ajustarArticulo(string $articulo, bool $femenino): string
    {
        $parejas = [
            'el' => 'la', 'los' => 'las', 'un' => 'una', 'unos' => 'unas', 'del' => 'de la', 'al' => 'a la',
            'este' => 'esta', 'estos' => 'estas', 'ese' => 'esa', 'esos' => 'esas', 'nuevo' => 'nueva', 'nuevos' => 'nuevas',
        ];
        $bajo = mb_strtolower(preg_replace('/\s+/u', ' ', $articulo) ?? $articulo);
        $nuevo = $femenino
            ? ($parejas[$bajo] ?? $bajo)
            : (array_search($bajo, $parejas, true) ?: $bajo);

        $primera = mb_substr($articulo, 0, 1);
        if (mb_strtoupper($articulo) === $articulo && mb_strlen($articulo) > 1) {
            return mb_strtoupper($nuevo);
        }

        return mb_strtoupper($primera) === $primera ? mb_strtoupper(mb_substr($nuevo, 0, 1)).mb_substr($nuevo, 1) : $nuevo;
    }
}
