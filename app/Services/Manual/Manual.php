<?php

namespace App\Services\Manual;

use App\Models\User;
use App\Support\Terminos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Manual de usuario dentro de la plataforma (ver docs/tecnico/manual.md).
 *
 * Las páginas son los archivos docs/usuario/<slug>.md con un encabezado
 * (front matter) que dice su título, sección, orden, resumen y los módulos
 * del catálogo a los que pertenece. Un usuario ve una página si puede «ver»
 * alguno de esos módulos (o si la página es general: `modulos: []`). Una
 * entrada también puede ser la acción exacta (`reservas.configurar`) cuando
 * la pantalla que explica la página no se abre solo con «ver».
 *
 * El Markdown se convierte a HTML en el servidor con league/commonmark sin
 * HTML crudo (todo se escapa). Solo se conservan los enlaces entre páginas
 * del manual (`otra-pagina.md`, `#apartado`) y las imágenes de
 * docs/usuario/img/<carpeta>/<archivo>; lo demás queda como texto. Lo
 * convertido se guarda en caché según la fecha del archivo; los enlaces a
 * otras páginas se resuelven en cada visita según lo que el usuario puede ver.
 */
class Manual
{
    /** Secciones del índice, en este orden. */
    public const SECCIONES = ['Primeros pasos', 'Operación', 'Padrones', 'Recursos Humanos', 'Informes', 'Estructura'];

    /** Íconos de cada sección en el índice. */
    public const ICONOS = [
        'Primeros pasos' => 'bi-signpost-2', 'Operación' => 'bi-shield-shaded', 'Padrones' => 'bi-folder2-open',
        'Recursos Humanos' => 'bi-people-fill', 'Informes' => 'bi-graph-up', 'Estructura' => 'bi-building-gear',
    ];

    /** Archivos de docs/usuario que no son páginas del manual (la guía de redacción). */
    public const EXCLUIDOS = ['GUIA'];

    /** Cambia si cambia la forma de convertir (invalida la caché). */
    private const VERSION = 2;

    /** Giros: renglones que marcan una parte que no se sustituye (`<!-- sin cambiar -->` … `<!-- fin sin cambiar -->`). */
    private const SIN_CAMBIAR = '§TERMINOS-NO§';

    private const FIN_SIN_CAMBIAR = '§TERMINOS-SI§';

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const IMAGEN = '#^img/([a-z0-9]+(?:-[a-z0-9]+)*)/([A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|gif|webp))$#';

    /** Tipos de imagen que se sirven (por extensión). */
    public const TIPOS_IMAGEN = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** @var array<string, PaginaManual>|null */
    private ?array $paginas = null;

    private readonly string $carpeta;

    public function __construct(?string $carpeta = null)
    {
        $this->carpeta = rtrim($carpeta ?? base_path('docs/usuario'), '/');
    }

    public function carpeta(): string
    {
        return $this->carpeta;
    }

    /**
     * Archivos de página: slug => ruta (sin la guía de redacción).
     *
     * @return array<string, string>
     */
    public function archivos(): array
    {
        $archivos = [];
        foreach (glob($this->carpeta.'/*.md') ?: [] as $ruta) {
            $slug = basename($ruta, '.md');
            if (in_array($slug, self::EXCLUIDOS, true) || ! preg_match(self::SLUG, $slug) || ! is_file($ruta)) {
                continue;
            }
            $archivos[$slug] = $ruta;
        }
        ksort($archivos);

        return $archivos;
    }

    /**
     * Todas las páginas con encabezado válido, ordenadas por sección y orden.
     *
     * @return array<string, PaginaManual>
     */
    public function paginas(): array
    {
        if ($this->paginas !== null) {
            return $this->paginas;
        }

        $archivos = $this->archivos();
        $huella = md5(self::VERSION.'|'.$this->carpeta.'|'.implode('|', array_map(
            fn (string $slug, string $ruta) => $slug.':'.@filemtime($ruta).':'.@filesize($ruta), array_keys($archivos), $archivos,
        )));

        $datos = Cache::rememberForever('manual.indice.'.$huella, function () use ($archivos) {
            $datos = [];
            foreach ($archivos as $slug => $ruta) {
                [$encabezado, $errores] = $this->leerEncabezado((string) file_get_contents($ruta));
                if ($errores === []) {
                    $datos[$slug] = $encabezado;
                }
            }

            return $datos;
        });

        $paginas = [];
        foreach ($datos as $slug => $d) {
            $paginas[$slug] = new PaginaManual($slug, $d['titulo'], $d['modulos'], $d['seccion'], $d['orden'], $d['resumen']);
        }
        uasort($paginas, fn (PaginaManual $a, PaginaManual $b) => [array_search($a->seccion, self::SECCIONES, true), $a->orden, $a->titulo]
            <=> [array_search($b->seccion, self::SECCIONES, true), $b->orden, $b->titulo]);

        return $this->paginas = $paginas;
    }

    public function pagina(string $slug): ?PaginaManual
    {
        return $this->paginas()[$slug] ?? null;
    }

    public function puedeVer(User $usuario, PaginaManual $pagina): bool
    {
        if ($pagina->esGeneral()) {
            return true;
        }
        foreach ($pagina->modulos as $modulo) {
            if ($usuario->can(str_contains($modulo, '.') ? $modulo : $modulo.'.ver')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Páginas que el usuario puede ver, agrupadas por sección (en orden).
     *
     * @return array<string, list<PaginaManual>>
     */
    public function indice(User $usuario): array
    {
        $grupos = [];
        foreach ($this->paginas() as $pagina) {
            if ($this->puedeVer($usuario, $pagina)) {
                $grupos[$pagina->seccion][] = $pagina;
            }
        }

        return $grupos;
    }

    /**
     * Página de ayuda de un módulo (para el botón «?» de su pantalla): la que
     * lo nombra primero en su lista de módulos y, si hay varias, la de menor orden.
     */
    public function paginaDeModulo(string $clave, User $usuario): ?PaginaManual
    {
        $candidatas = array_filter($this->paginas(), fn (PaginaManual $p) => in_array($clave, $p->clavesDeModulo(), true));
        usort($candidatas, fn (PaginaManual $a, PaginaManual $b) => [array_search($clave, $a->clavesDeModulo(), true), $a->orden, $a->slug]
            <=> [array_search($clave, $b->clavesDeModulo(), true), $b->orden, $b->slug]);

        foreach ($candidatas as $pagina) {
            if ($this->puedeVer($usuario, $pagina)) {
                return $pagina;
            }
        }

        return null;
    }

    /**
     * HTML de la página para este usuario y su índice de apartados (## …).
     *
     * @return array{html: string, apartados: list<array{id: string, texto: string}>}
     */
    public function contenido(PaginaManual $pagina, User $usuario): array
    {
        $ruta = $this->archivos()[$pagina->slug];
        $convertida = $this->convertida($ruta);

        // Enlaces a otras páginas: solo si existen y el usuario puede verlas; si no, texto simple
        $html = preg_replace_callback('#<a href="manual:([a-z0-9-]+)(\#[^"]*)?"([^>]*)>(.*?)</a>#s', function (array $m) use ($usuario) {
            $destino = $this->pagina($m[1]);
            if ($destino === null || ! $this->puedeVer($usuario, $destino)) {
                return '<span class="manual-enlace-sin-acceso">'.$m[4].'</span>';
            }

            return '<a href="'.e(route('manual.ver', $destino->slug)).($m[2] ?? '').'"'.$m[3].'>'.$m[4].'</a>';
        }, $convertida['html']);

        $html = preg_replace_callback('#src="manual-img:([a-z0-9-]+)/([A-Za-z0-9._-]+)"#', fn (array $m) => 'src="'.e(route('manual.imagen', [$m[1], $m[2]])).'"', (string) $html);

        // Giros: el manual dice las palabras de la empresa (las anclas se quedan como están)
        return ['html' => $this->terminos()->html((string) $html), 'apartados' => $this->apartadosConTerminos($convertida['apartados'])];
    }

    // Mejoras estructura (ADR-0016): buscar en el contenido y ayuda directa al apartado

    /**
     * Apartados (## …) de una página: [id, texto], en orden.
     *
     * @return list<array{id: string, texto: string}>
     */
    public function apartados(PaginaManual $pagina): array
    {
        $ruta = $this->archivos()[$pagina->slug] ?? null;
        if ($ruta === null) {
            return [];
        }

        return $this->convertida($ruta)['apartados'];
    }

    /**
     * Dirección de un apartado de una página («Cómo dar de alta una cuenta»)
     * si el usuario puede ver la página; sin el apartado, la página completa.
     */
    public function enlaceApartado(User $usuario, string $slug, string $apartado): ?string
    {
        $pagina = $this->pagina($slug);
        if ($pagina === null || ! $this->puedeVer($usuario, $pagina)) {
            return null;
        }
        $buscado = Str::lower(Str::ascii($apartado));
        foreach ($this->apartados($pagina) as $a) {
            if (Str::lower(Str::ascii($a['texto'])) === $buscado) {
                return route('manual.ver', $pagina->slug).'#'.$a['id'];
            }
        }

        return route('manual.ver', $pagina->slug);
    }

    /**
     * Texto simple de cada apartado de una página (para buscar en el contenido).
     *
     * @return list<array{id: ?string, titulo: ?string, texto: string}>
     */
    public function textoPorApartado(PaginaManual $pagina): array
    {
        $ruta = $this->archivos()[$pagina->slug] ?? null;
        if ($ruta === null) {
            return [];
        }
        $terminos = $this->terminos();
        $clave = 'manual.texto.'.md5(self::VERSION.'|'.$terminos->giro.'|'.$ruta.'|'.@filemtime($ruta).'|'.@filesize($ruta));

        return Cache::rememberForever($clave, function () use ($ruta, $pagina, $terminos) {
            $texto = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n/s', '', self::segunGiro((string) file_get_contents($ruta), $terminos->giro)) ?? '';
            $apartados = $this->apartados($pagina);
            $partes = [['id' => null, 'titulo' => null, 'texto' => '']];
            $n = 0;
            $enCodigo = false;
            foreach (preg_split('/\r?\n/', $texto) ?: [] as $linea) {
                if (in_array(trim($linea), [self::SIN_CAMBIAR, self::FIN_SIN_CAMBIAR], true)) {
                    continue;
                }
                if (str_starts_with(ltrim($linea), '```')) {
                    $enCodigo = ! $enCodigo;

                    continue;
                }
                if (! $enCodigo && preg_match('/^##\s+(.+)$/', $linea, $m)) {
                    $a = $apartados[$n++] ?? null;
                    $partes[] = ['id' => $a['id'] ?? null, 'titulo' => $a['texto'] ?? trim($m[1]), 'texto' => ''];

                    continue;
                }
                $partes[count($partes) - 1]['texto'] .= ' '.$this->sinMarcas($linea);
            }

            return array_values(array_map(fn ($p) => ['id' => $p['id'], 'titulo' => $p['titulo'] === null ? null : $terminos->texto($p['titulo']),
                'texto' => $terminos->texto(trim(preg_replace('/\s+/', ' ', $p['texto']) ?? ''))], $partes));
        });
    }

    /**
     * Palabras de la página (sin acentos ni repetidas) para el filtro en vivo del índice.
     */
    public function palabras(PaginaManual $pagina): string
    {
        $texto = Str::lower(Str::ascii(implode(' ', array_map(fn ($p) => ($p['titulo'] ?? '').' '.$p['texto'], $this->textoPorApartado($pagina)))));
        preg_match_all('/[a-z0-9]{4,}/', $texto, $m);

        return implode(' ', array_keys(array_flip($m[0])));
    }

    /**
     * Busca en el título, el resumen y el contenido de las páginas que el
     * usuario puede ver. Cada resultado lleva al apartado donde aparece.
     *
     * @return list<array{pagina: PaginaManual, apartado: ?string, titulo_apartado: ?string, fragmento: string}>
     */
    public function buscar(User $usuario, string $consulta): array
    {
        $palabras = array_values(array_filter(preg_split('/\s+/', Str::lower(Str::ascii($consulta))) ?: []));
        if ($palabras === []) {
            return [];
        }
        $contiene = fn (string $t) => collect($palabras)->every(fn ($w) => str_contains($t, $w));

        $resultados = [];
        foreach ($this->paginas() as $pagina) {
            if (! $this->puedeVer($usuario, $pagina)) {
                continue;
            }
            $partes = $this->textoPorApartado($pagina);
            $cabeza = $this->terminos()->texto($pagina->titulo.' '.$pagina->resumen); // Giros: se busca con las palabras de la empresa
            $enTitulo = $contiene(Str::lower(Str::ascii($cabeza)));
            $todo = Str::lower(Str::ascii($cabeza.' '.implode(' ', array_map(fn ($p) => ($p['titulo'] ?? '').' '.$p['texto'], $partes))));
            if (! $enTitulo && ! $contiene($todo)) {
                continue;
            }
            // El primer apartado donde están todas las palabras (si no, el primero con alguna)
            $elegido = null;
            foreach ($partes as $p) {
                if ($p['id'] !== null && $contiene(Str::lower(Str::ascii(($p['titulo'] ?? '').' '.$p['texto'])))) {
                    $elegido = $p;
                    break;
                }
            }
            $elegido ??= collect($partes)->first(fn ($p) => $p['id'] !== null && str_contains(Str::lower(Str::ascii(($p['titulo'] ?? '').' '.$p['texto'])), $palabras[0]));
            $resultados[] = [
                'pagina' => $pagina,
                'apartado' => $enTitulo && $elegido === null ? null : $elegido['id'] ?? null,
                'titulo_apartado' => $elegido['titulo'] ?? null,
                'fragmento' => $elegido === null ? $this->terminos()->texto($pagina->resumen) : $this->fragmento($elegido['texto'], $palabras[0]),
                'peso' => $enTitulo ? 0 : 1,
            ];
        }
        usort($resultados, fn ($a, $b) => $a['peso'] <=> $b['peso']);

        return array_map(fn ($r) => array_diff_key($r, ['peso' => true]), $resultados);
    }

    private function fragmento(string $texto, string $palabra): string
    {
        $plano = Str::lower(Str::ascii($texto));
        $pos = strpos($plano, $palabra);
        if ($pos === false || mb_strlen($texto) <= 160) {
            return Str::limit($texto, 160);
        }
        // Str::ascii puede cambiar la longitud: se recorta sobre el texto original con un margen
        $inicio = max(0, $pos - 60);

        return ($inicio > 0 ? '…' : '').Str::limit(mb_substr($texto, $inicio, 200), 160);
    }

    private function sinMarcas(string $linea): string
    {
        $linea = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $linea) ?? $linea;       // imágenes
        $linea = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $linea) ?? $linea;    // enlaces
        $linea = preg_replace('/^\s*(#{1,6}|[-*]|\d+\.|>)\s*/', '', $linea) ?? $linea; // títulos, listas, citas
        $linea = str_replace(['**', '__', '`', '|'], ['', '', '', ' '], $linea);

        return preg_match('/^\s*:?-{3,}/', $linea) ? '' : $linea;
    }
    // Fin Mejoras estructura

    // Giros (docs/tecnico/giros.md)

    /** Palabras de la empresa activa (Huésped / Visitante / Visita…). */
    private function terminos(): Terminos
    {
        return Terminos::actual();
    }

    /**
     * HTML convertido de una página para el giro de la empresa activa (en caché
     * por archivo y giro). Los ids de los apartados salen del texto de la
     * página y son los mismos en todos los giros.
     *
     * @return array{html: string, apartados: list<array{id: string, texto: string}>}
     */
    private function convertida(string $ruta): array
    {
        $giro = $this->terminos()->giro;
        $clave = 'manual.pagina.'.md5(self::VERSION.'|'.$giro.'|'.$ruta.'|'.@filemtime($ruta).'|'.@filesize($ruta));

        return Cache::rememberForever($clave, fn () => $this->convertir(self::segunGiro((string) file_get_contents($ruta), $giro)));
    }

    /**
     * @param  list<array{id: string, texto: string}>  $apartados
     * @return list<array{id: string, texto: string}>
     */
    private function apartadosConTerminos(array $apartados): array
    {
        $terminos = $this->terminos();

        return array_map(fn (array $a) => ['id' => $a['id'], 'texto' => $terminos->texto($a['texto'])], $apartados);
    }

    /**
     * Partes de una página que solo aplican a algunos giros:
     *
     *     <!-- solo: hotel -->
     *     …texto que solo ven los hoteles…
     *     <!-- fin -->
     *
     * (también `<!-- solo: corporativo, condominio, fraccionamiento -->`). Las
     * marcas se quitan siempre; el contenido se queda solo si el giro está en la lista.
     */
    public static function segunGiro(string $texto, string $giro): string
    {
        $texto = str_replace("\r\n", "\n", $texto);
        // Partes que dicen las palabras de todos los giros (p. ej. una tabla que compara giros): no se sustituyen
        $texto = (string) preg_replace(['/^<!--\s*sin cambiar\s*-->[ \t]*$/m', '/^<!--\s*fin sin cambiar\s*-->[ \t]*$/m'], ["\n".self::SIN_CAMBIAR."\n\n", "\n".self::FIN_SIN_CAMBIAR."\n\n"], $texto);

        return (string) preg_replace_callback('/^<!--\s*solo:\s*([a-z,\s]+?)\s*-->[ \t]*\n(.*?)^<!--\s*fin\s*-->[ \t]*(?:\n|\z)/ms', function (array $m) use ($giro) {
            $giros = array_map('trim', explode(',', $m[1]));

            return in_array($giro, $giros, true) ? $m[2] : '';
        }, $texto);
    }
    // Fin Giros

    /**
     * Ruta real de una imagen del manual, solo si está dentro de docs/usuario/img
     * y es de un tipo de imagen permitido.
     */
    public function rutaImagen(string $carpeta, string $archivo): ?string
    {
        if (! preg_match(self::IMAGEN, 'img/'.$carpeta.'/'.$archivo)) {
            return null;
        }
        $base = realpath($this->carpeta.'/img');
        $ruta = realpath($this->carpeta.'/img/'.$carpeta.'/'.$archivo);
        if ($base === false || $ruta === false || ! str_starts_with($ruta, $base.DIRECTORY_SEPARATOR) || ! is_file($ruta)) {
            return null;
        }

        return $ruta;
    }

    /**
     * Lee y valida el encabezado (front matter) de una página.
     *
     * @return array{0: array{titulo: string, modulos: list<string>, seccion: string, orden: int, resumen: string}|null, 1: list<string>}
     */
    public function leerEncabezado(string $texto): array
    {
        $texto = str_replace("\r\n", "\n", $texto);
        if (! preg_match('/\A---\n(.*?)\n---\n/s', $texto, $m)) {
            return [null, ['La página no empieza con el encabezado (---).']];
        }

        $valores = [];
        $errores = [];
        foreach (explode("\n", $m[1]) as $n => $linea) {
            if (trim($linea) === '' || str_starts_with(ltrim($linea), '#')) {
                continue;
            }
            if (! preg_match('/^([a-z_]+):\s*(.*)$/', $linea, $par)) {
                $errores[] = 'Renglón '.($n + 2).' del encabezado no tiene la forma «clave: valor».';

                continue;
            }
            $valores[$par[1]] = trim($par[2]);
        }

        foreach (['titulo', 'modulos', 'seccion', 'orden', 'resumen'] as $clave) {
            if (! isset($valores[$clave]) || $valores[$clave] === '') {
                $errores[] = "Falta «{$clave}» en el encabezado.";
            }
        }
        $sobran = array_diff(array_keys($valores), ['titulo', 'modulos', 'seccion', 'orden', 'resumen']);
        if ($sobran !== []) {
            $errores[] = 'El encabezado tiene claves desconocidas: '.implode(', ', $sobran).'.';
        }
        if ($errores !== []) {
            return [null, $errores];
        }

        $modulos = [];
        if (! preg_match('/^\[([a-z0-9_.,\s]*)\]\s*(#.*)?$/', $valores['modulos'], $lista)) {
            $errores[] = '«modulos» debe ser una lista: [clave, otra_clave.accion] o [].';
        } else {
            $modulos = array_values(array_filter(array_map('trim', explode(',', $lista[1])), fn ($v) => $v !== ''));
        }
        if (! in_array($valores['seccion'], self::SECCIONES, true)) {
            $errores[] = '«seccion» debe ser una de: '.implode(', ', self::SECCIONES).'.';
        }
        if (! preg_match('/^\d{1,4}$/', $valores['orden'])) {
            $errores[] = '«orden» debe ser un número.';
        }

        return $errores !== [] ? [null, $errores] : [[
            'titulo' => $valores['titulo'],
            'modulos' => $modulos,
            'seccion' => $valores['seccion'],
            'orden' => (int) $valores['orden'],
            'resumen' => $valores['resumen'],
        ], []];
    }

    /**
     * Markdown → HTML seguro (sin el encabezado ni el primer «# Título», que la pantalla ya muestra).
     *
     * @return array{html: string, apartados: list<array{id: string, texto: string}>}
     */
    private function convertir(string $texto): array
    {
        $texto = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n/s', '', $texto) ?? $texto;

        $entorno = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $entorno->addExtension(new CommonMarkCoreExtension);
        $entorno->addExtension(new TableExtension);
        $entorno->addExtension(new StrikethroughExtension);

        $documento = (new MarkdownParser($entorno))->parse($texto);

        $nodos = [];
        foreach ($documento->iterator() as $nodo) {
            $nodos[] = $nodo;
        }

        $primero = $documento->firstChild();
        if ($primero instanceof Heading && $primero->getLevel() === 1) {
            $primero->detach();
        }

        $apartados = [];
        $usados = [];
        foreach ($nodos as $nodo) {
            if ($nodo instanceof Heading && $nodo->parent() !== null && in_array($nodo->getLevel(), [2, 3], true)) {
                $titulo = $this->textoDe($nodo);
                $base = Str::slug($titulo) ?: 'apartado';
                $id = $base;
                for ($i = 2; isset($usados[$id]); $i++) {
                    $id = $base.'-'.$i;
                }
                $usados[$id] = true;
                $nodo->data->set('attributes/id', $id);
                if ($nodo->getLevel() === 2) {
                    $apartados[] = ['id' => $id, 'texto' => $titulo];
                }
            } elseif ($nodo instanceof Image) {
                if (preg_match(self::IMAGEN, $nodo->getUrl(), $img) && $this->rutaImagen($img[1], $img[2]) !== null) {
                    $nodo->setUrl('manual-img:'.$img[1].'/'.$img[2]);
                    $nodo->data->set('attributes/loading', 'lazy');
                } else {
                    $nodo->detach();
                }
            } elseif ($nodo instanceof Link) {
                $url = $nodo->getUrl();
                if (preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)\.md(#[A-Za-z0-9_-]*)?$/', $url, $enlace)) {
                    $nodo->setUrl('manual:'.$enlace[1].($enlace[2] ?? ''));
                } elseif (! preg_match('/^#[A-Za-z0-9_-]+$/', $url)) {
                    // Cualquier otro destino (internet, rutas del servidor…) queda como texto
                    foreach ($nodo->children() as $hijo) {
                        $nodo->insertBefore($hijo);
                    }
                    $nodo->detach();
                }
            } elseif ($nodo instanceof Table) {
                $nodo->data->set('attributes/class', 'manual-tabla');
            }
        }

        $html = (string) (new HtmlRenderer($entorno))->renderDocument($documento);
        $html = str_replace(['<p>'.self::SIN_CAMBIAR.'</p>', '<p>'.self::FIN_SIN_CAMBIAR.'</p>'], [Terminos::SIN_SUSTITUIR, Terminos::SUSTITUIR], $html);

        return ['html' => $html, 'apartados' => $apartados];
    }

    private function textoDe(Node $nodo): string
    {
        $texto = '';
        foreach ($nodo->iterator() as $hijo) {
            if ($hijo instanceof Text || $hijo instanceof Code) {
                $texto .= $hijo->getLiteral();
            }
        }

        return trim($texto);
    }
}
