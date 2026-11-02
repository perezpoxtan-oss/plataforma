<?php

namespace App\Services\Privacidad;

use App\Models\Acceso;
use App\Models\AccidenteColaborador;
use App\Models\AccidenteDictamen;
use App\Models\AccidenteFirma;
use App\Models\AccidenteGuardavidas;
use App\Models\AccidenteHuesped;
use App\Models\AccidenteIncapacidad;
use App\Models\AcompananteAcceso;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\EquipoResponsiva;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundReportePerdida;
use App\Models\MovimientoTransporte;
use App\Models\MovimientoTransportePasajero;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\NovedadReporteGeneral;
use App\Models\NovedadTestigo;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Reserva;
use App\Models\ReservaEvento;
use App\Models\Responsiva;
use App\Models\RoboDetalle;
use App\Models\VoucherReposicion;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Firmas\Firmas;
use App\Services\Traslado\ExportacionEmpresa;
use App\Services\Traslado\ZipPorPartes;
use App\Support\Csv;
use App\Support\Receptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que la plataforma tiene de UNA persona dentro de la empresa activa
 * (el tenant ya está puesto): buscarlo, exportarlo en un ZIP y quitar sus
 * datos personales. Lo usan las solicitudes ARCO y la conservación de datos
 * (docs/tecnico/privacidad.md). Las tablas que alcanza cada tipo están en
 * InventarioDatosPersonales (una prueba falla si una tabla nueva con datos
 * personales no está ahí).
 *
 * Tipos que se buscan:
 *  - persona:     padrón de personas (visitas, proveedores, contratistas).
 *  - acceso:      registros de la Bitácora de accesos de personas externas.
 *  - candidato:   fichas de candidatos (con su CV y documentos).
 *  - colaborador: solo para consultar y rectificar en su ficha (la relación
 *                 laboral obliga a conservarlos; no se cancelan desde aquí).
 *  - lost_found_reporte / lost_found_entrega: quien reportó una pérdida o
 *                 recibió un objeto (con su firma).
 *  - novedad:     tickets que mencionan a la persona (involucrados,
 *                 descripción, testigos, sospechoso de robo, notas).
 *  - accidente:   accidentes de huésped con sus datos médicos (dictamen,
 *                 lesiones y firma del afectado). Los de colaborador solo se
 *                 consultan. Los formularios de Accidentes no cambian: esto
 *                 los trata desde fuera.
 *  - reserva:     reservas a nombre de un cliente.
 *  - transporte:  movimientos donde la persona fue el conductor del taxi (su
 *                 firma) o la mencionan las observaciones.
 *  - prestamo_llave / responsiva / voucher (Etapa 2): préstamos, responsivas
 *                 y vouchers donde es el receptor o el responsable del cobro
 *                 como persona del Padrón (receptor_tipo = persona) o su
 *                 empresa externa (proveedor). Se conservan como comprobante;
 *                 «Eliminar» quita la garantía, su firma y su nombre del texto.
 *
 * C-37 (X-01): antes solo alcanzaba los cuatro primeros.
 */
class DatosDePersona
{
    public const TIPOS = [
        'persona' => 'Padrón de personas',
        'acceso' => 'Bitácora de accesos',
        'candidato' => 'Candidatos',
        'colaborador' => 'Colaboradores',
        'lost_found_reporte' => 'Lost & Found: reportes de pérdida',
        'lost_found_entrega' => 'Lost & Found: entregas',
        'novedad' => 'Novedades que la mencionan',
        'accidente' => 'Accidentes (con datos médicos)',
        'reserva' => 'Reservas',
        'transporte' => 'Bitácora de transporte',
        // Etapa 2 (C-16): lo que se le entregó o se le cobró como persona externa (o a su empresa)
        'prestamo_llave' => 'Préstamos de llaves a su nombre',
        'responsiva' => 'Responsivas a su nombre',
        'voucher' => 'Vouchers a su cargo',
    ];

    /** Tipos que no se eliminan por ARCO: solo se consultan y rectifican en su ficha. */
    public const SOLO_CONSULTA = ['colaborador'];

    /** Tipos de acceso de personas externas (no colaboradores ni unidades de emergencia). */
    public const ACCESOS_EXTERNOS = ['visitante', 'huesped', 'proveedor', 'contratista'];

    public const MAX_POR_TIPO = 100;

    public function __construct(
        private readonly DocumentosCandidato $documentos,
        private readonly Firmas $firmas,
    ) {}

    // -------------------------------------------------------------- Buscar

    /**
     * @param  array{nombre?: ?string, correo?: ?string, telefono?: ?string, folio?: ?string}  $criterios
     * @return array<string, list<array{id: int, titulo: string, detalle: string, url: ?string}>>
     */
    public function buscar(array $criterios): array
    {
        $nombre = trim((string) ($criterios['nombre'] ?? ''));
        $correo = mb_strtolower(trim((string) ($criterios['correo'] ?? '')));
        $telefono = preg_replace('/\D/', '', (string) ($criterios['telefono'] ?? '')) ?? '';
        $folio = strtoupper(preg_replace('/[\s-]/', '', (string) ($criterios['folio'] ?? '')) ?? '');
        $porNombre = mb_strlen($nombre) >= 5;
        $resultado = array_fill_keys(array_keys(self::TIPOS), []);
        if (! $porNombre && $correo === '' && strlen($telefono) < 7 && strlen($folio) < 4) {
            return $resultado;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nombre).'%';

        $personas = Persona::query()->where(function (Builder $q) use ($porNombre, $like, $telefono, $folio) {
            $q->whereRaw('1 = 0');
            if ($porNombre) {
                $q->orWhere('nombre_completo', 'like', $like);
            }
            if (strlen($telefono) >= 7) {
                $q->orWhere('telefono', $telefono);
            }
            if (strlen($folio) >= 4) {
                $q->orWhere('folio_identificacion', $folio);
            }
        })->orderBy('id')->limit(self::MAX_POR_TIPO)->get();
        foreach ($personas as $p) {
            $resultado['persona'][] = ['id' => $p->id, 'titulo' => $p->nombre_completo,
                'detalle' => trim(($p->tipo ?? '').' · '.($p->empresa_procedencia ?? '').($p->folio_identificacion ? ' · identificación '.self::enmascarar($p->folio_identificacion) : ''), ' ·'),
                'url' => route('personas.index', ['buscar' => $p->nombre_completo])];
        }

        $idsPersonas = $personas->pluck('id');
        $accesos = Acceso::query()->whereIn('tipo', self::ACCESOS_EXTERNOS)->whereNull('datos_eliminados_en')
            ->where(function (Builder $q) use ($porNombre, $like, $idsPersonas) {
                $q->whereRaw('1 = 0');
                if ($porNombre) {
                    $q->orWhere('nombre', 'like', $like);
                }
                if ($idsPersonas->isNotEmpty()) {
                    $q->orWhereIn('persona_id', $idsPersonas);
                }
            })->with('sede:id,nombre')->orderByDesc('entrada_at')->limit(self::MAX_POR_TIPO)->get();
        foreach ($accesos as $a) {
            $resultado['acceso'][] = ['id' => $a->id, 'titulo' => $a->nombre,
                'detalle' => ($a->sede?->nombre ?? '').' · '.$a->etiquetaTipo().' · '.$a->entrada_at?->format('d/m/Y H:i').($a->foto_persona || $a->foto_identificacion ? ' · con fotos' : ''),
                'url' => null];
        }

        $candidatos = Candidato::query()->where(function (Builder $q) use ($porNombre, $like, $correo, $telefono) {
            $q->whereRaw('1 = 0');
            if ($porNombre) {
                $q->orWhere('nombre_completo', 'like', $like);
            }
            if ($correo !== '') {
                $q->orWhere('correo', $correo);
            }
            if (strlen($telefono) >= 7) {
                $q->orWhere('telefono', $telefono);
            }
        })->with('sede:id,nombre')->orderBy('id')->limit(self::MAX_POR_TIPO)->get();
        foreach ($candidatos as $c) {
            $resultado['candidato'][] = ['id' => $c->id, 'titulo' => $c->nombre_completo,
                'detalle' => ($c->sede?->nombre ?? '').' · '.($c->correo ?: 'sin correo').($c->contratado() ? ' · contratado (no se elimina: ya es colaborador)' : ''),
                'url' => route('candidatos.show', $c->id)];
        }

        $idsColaboradores = collect();
        if ($porNombre) {
            // Cada palabra del nombre debe aparecer en el nombre o en algún apellido (sin concatenar en SQL: igual en MariaDB y SQLite)
            $colaboradores = Colaborador::query()->where(function (Builder $q) use ($nombre) {
                foreach (array_slice(preg_split('/\s+/u', $nombre) ?: [], 0, 5) as $palabra) {
                    $p = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $palabra).'%';
                    $q->where(fn (Builder $w) => $w->where('nombre', 'like', $p)->orWhere('apellido_paterno', 'like', $p)->orWhere('apellido_materno', 'like', $p));
                }
            })->orderBy('id')->limit(self::MAX_POR_TIPO)->get();
            foreach ($colaboradores as $c) {
                // C-10: el residente se rectifica en Padrones → Residentes
                $resultado['colaborador'][] = $c->esResidente()
                    ? ['id' => $c->id, 'titulo' => $c->nombreCompleto(), 'detalle' => 'Residente '.($c->activo ? 'activo' : 'dado de baja').' · se rectifica en Residentes',
                        'url' => route('residentes.index').'#residente-'.$c->id]
                    : ['id' => $c->id, 'titulo' => $c->nombreCompleto(), 'detalle' => 'Colaborador '.($c->activo ? 'activo' : 'dado de baja').' · se rectifica en su ficha',
                        'url' => route('colaboradores.index', ['buscar' => $c->nombreCompleto()])];
            }
            $idsColaboradores = $colaboradores->pluck('id');
        }

        // C-37 (X-01): Lost & Found, Novedades, Accidentes, Reservas y Transporte
        $c = ['porNombre' => $porNombre, 'like' => $like, 'correo' => $correo, 'telefono' => $telefono];
        $resultado['lost_found_reporte'] = $this->buscarReportesPerdida($c);
        $resultado['lost_found_entrega'] = $this->buscarEntregas($c, $idsPersonas);
        $resultado['novedad'] = $porNombre ? $this->buscarNovedades($like) : [];
        $resultado['accidente'] = $this->buscarAccidentes($porNombre ? $like : null, $idsColaboradores);
        $resultado['reserva'] = $this->buscarReservas($c + ['residentes' => $idsColaboradores]);
        $resultado['transporte'] = $this->buscarTransporte($porNombre ? $like : null, $idsPersonas);
        // Etapa 2 (revisión): receptor o responsable externo por id del Padrón o de su empresa
        $idsEmpresas = $porNombre ? Proveedor::query()->where('nombre', 'like', $like)->orderBy('id')->limit(self::MAX_POR_TIPO)->pluck('id') : collect();
        [$resultado['prestamo_llave'], $resultado['responsiva'], $resultado['voucher']] = $this->buscarActivos($idsPersonas, $idsEmpresas);

        return $resultado;
    }

    /** @param  array{porNombre: bool, like: string, correo: string, telefono: string}  $c */
    private function buscarReportesPerdida(array $c): array
    {
        $filas = LostFoundReportePerdida::query()->whereNull('datos_eliminados_en')->where(function (Builder $q) use ($c) {
            $q->whereRaw('1 = 0');
            if ($c['porNombre']) {
                $q->orWhere('nombre_huesped', 'like', $c['like']);
            }
            if ($c['correo'] !== '') {
                $q->orWhereRaw('LOWER(correo) = ?', [$c['correo']]);
            }
            if (strlen($c['telefono']) >= 7) {
                self::orTelefono($q, 'telefono', $c['telefono']);
            }
        })->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get();

        return $filas->map(fn (LostFoundReportePerdida $r) => ['id' => $r->id, 'titulo' => (string) $r->nombre_huesped,
            'detalle' => 'Reporte de pérdida '.$r->folio.' · '.$r->objeto.' · '.$r->etiquetaEstatus().' · '.$r->created_at?->format('d/m/Y'),
            'url' => route('novedades.mostrar', $r->novedad_id)])->all();
    }

    /** @param  array{porNombre: bool, like: string, correo: string, telefono: string}  $c */
    private function buscarEntregas(array $c, Collection $idsPersonas): array
    {
        $filas = LostFoundEntrega::query()->whereNull('datos_eliminados_en')->where(function (Builder $q) use ($c, $idsPersonas) {
            $q->whereRaw('1 = 0');
            if ($c['porNombre']) {
                $q->orWhere('nombre_recibe', 'like', $c['like']);
            }
            if ($c['correo'] !== '') {
                $q->orWhereRaw('LOWER(correo_recibe) = ?', [$c['correo']]);
            }
            if ($idsPersonas->isNotEmpty()) {
                $q->orWhereIn('persona_id', $idsPersonas);
            }
        })->with('articulo:id,folio,objeto')->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get();

        return $filas->map(fn (LostFoundEntrega $e) => ['id' => $e->id, 'titulo' => (string) $e->nombre_recibe,
            'detalle' => 'Entrega de '.($e->articulo?->folio ?? 'objeto').' · '.$e->etiquetaCierre().' · '.$e->created_at?->format('d/m/Y').($e->firma_ruta ? ' · con firma' : ''),
            'url' => route('lost_found.articulos.show', $e->articulo_id)])->all();
    }

    /** Tickets cuyo texto menciona a la persona (involucrados, quién reporta, descripción, testigos, sospechoso, notas). */
    private function buscarNovedades(string $like): array
    {
        $ids = Novedad::query()->where(fn (Builder $q) => $q->where('involucrados', 'like', $like)->orWhere('reportado_por', 'like', $like)
            ->orWhere('descripcion', 'like', $like)->orWhere('como_sucedio', 'like', $like)->orWhere('resolucion', 'like', $like))->pluck('id')
            ->merge(NovedadTestigo::where('nombre', 'like', $like)->pluck('novedad_id'))
            ->merge(RoboDetalle::where('descripcion_sospechoso', 'like', $like)->pluck('novedad_id'))
            ->merge(NovedadNota::where('texto', 'like', $like)->pluck('novedad_id'))
            ->merge(NovedadReporteGeneral::where('observados', 'like', $like)->pluck('novedad_id'))
            ->unique()->sortDesc()->take(self::MAX_POR_TIPO);
        if ($ids->isEmpty()) {
            return [];
        }

        return Novedad::query()->whereIn('id', $ids)->with('sede:id,nombre')->orderByDesc('id')->get()
            ->map(fn (Novedad $n) => ['id' => $n->id, 'titulo' => 'Ticket '.$n->folio(),
                'detalle' => ($n->sede?->nombre ?? '').' · '.$n->etiquetaCategoria().' · '.$n->ocurrio_en?->format('d/m/Y').' · la menciona el texto o sus testigos',
                'url' => route('novedades.mostrar', $n->id)])->all();
    }

    /**
     * Accidentes: de huésped por su nombre (con sus datos médicos) y de los
     * colaboradores encontrados (solo consulta). El id es el del ticket.
     */
    private function buscarAccidentes(?string $like, Collection $idsColaboradores): array
    {
        $huespedes = $like === null ? collect() : AccidenteHuesped::where('nombre', 'like', $like)->pluck('novedad_id');
        $colaboradores = $idsColaboradores->isEmpty() ? collect() : AccidenteColaborador::whereIn('colaborador_id', $idsColaboradores)->pluck('novedad_id');
        $ids = $huespedes->merge($colaboradores)->unique();
        if ($ids->isEmpty()) {
            return [];
        }
        $conDictamen = AccidenteDictamen::whereIn('novedad_id', $ids)->pluck('novedad_id')->flip();

        return Novedad::query()->whereIn('id', $ids)->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get()
            ->map(fn (Novedad $n) => ['id' => $n->id, 'titulo' => 'Accidente '.$n->folio(),
                'detalle' => ($colaboradores->contains($n->id) && ! $huespedes->contains($n->id)
                    ? 'De colaborador · se conserva (relación laboral); se rectifica en el ticket'
                    : 'De huésped · '.$n->ocurrio_en?->format('d/m/Y').($conDictamen->has($n->id) ? ' · con datos médicos' : '')),
                'url' => route('novedades.mostrar', $n->id)])->all();
    }

    /** @param  array{porNombre: bool, like: string, correo: string, telefono: string}  $c */
    private function buscarReservas(array $c): array
    {
        $filas = Reserva::query()->whereNull('datos_eliminados_en')->where(function (Builder $q) use ($c) {
            $q->whereRaw('1 = 0');
            if ($c['porNombre']) {
                $q->orWhere('cliente_nombre', 'like', $c['like'])->orWhere('titulo', 'like', $c['like']);
            }
            if ($c['correo'] !== '') {
                $q->orWhereRaw('LOWER(cliente_correo) = ?', [$c['correo']]);
            }
            if (strlen($c['telefono']) >= 7) {
                self::orTelefono($q, 'cliente_telefono', $c['telefono']);
            }
            // C-34: las reservas que hizo como residente
            if (($c['residentes'] ?? collect())->isNotEmpty()) {
                $q->orWhereIn('residente_id', $c['residentes']);
            }
        })->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get();

        return $filas->map(fn (Reserva $r) => ['id' => $r->id, 'titulo' => (string) ($r->cliente_nombre ?: $r->titulo),
            'detalle' => 'Reserva '.$r->folioTexto().' · '.$r->titulo.' · '.$r->inicio?->format('d/m/Y').' · '.(Reserva::ESTADOS[$r->estado] ?? $r->estado),
            'url' => route('reservas.show', $r->id)])->all();
    }

    /** Movimientos donde la persona del padrón manejó el taxi (su firma) o la mencionan las observaciones o la justificación. */
    private function buscarTransporte(?string $like, Collection $idsPersonas): array
    {
        if ($like === null && $idsPersonas->isEmpty()) {
            return [];
        }
        $filas = MovimientoTransporte::query()->whereNull('datos_eliminados_en')->where(function (Builder $q) use ($like, $idsPersonas) {
            $q->whereRaw('1 = 0');
            if ($idsPersonas->isNotEmpty()) {
                $q->orWhereIn('chofer_id', $idsPersonas);
            }
            if ($like !== null) {
                $q->orWhere('observaciones', 'like', $like)->orWhere('justificacion', 'like', $like);
            }
        })->with('sede:id,nombre')->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get();

        return $filas->map(fn (MovimientoTransporte $m) => ['id' => $m->id, 'titulo' => 'Transporte '.$m->folio(),
            'detalle' => ($m->sede?->nombre ?? '').' · '.$m->fecha?->format('d/m/Y').' · '.$m->etiquetaTipo().' · '
                .($idsPersonas->contains($m->chofer_id) ? 'conductor del taxi'.($m->firma_taxista ? ' (con firma)' : '') : 'la mencionan las observaciones'),
            'url' => route('transporte.show', $m->id)])->all();
    }

    /**
     * Préstamos de llaves, responsivas y vouchers a nombre de la persona del
     * Padrón o de su empresa externa (receptor_tipo/_id, responsable_tipo/_id).
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>}
     */
    private function buscarActivos(Collection $idsPersonas, Collection $idsEmpresas): array
    {
        if ($idsPersonas->isEmpty() && $idsEmpresas->isEmpty()) {
            return [[], [], []];
        }
        $de = function (Builder $q, string $prefijo) use ($idsPersonas, $idsEmpresas): void {
            $q->where(function (Builder $w) use ($prefijo, $idsPersonas, $idsEmpresas) {
                $w->whereRaw('1 = 0');
                if ($idsPersonas->isNotEmpty()) {
                    $w->orWhere(fn (Builder $o) => $o->where($prefijo.'_tipo', Receptor::PERSONA)->whereIn($prefijo.'_id', $idsPersonas));
                }
                if ($idsEmpresas->isNotEmpty()) {
                    $w->orWhere(fn (Builder $o) => $o->where($prefijo.'_tipo', Receptor::PROVEEDOR)->whereIn($prefijo.'_id', $idsEmpresas));
                }
            });
        };
        $quien = fn ($m, string $prefijo) => ($m->getAttribute($prefijo.'_tipo') === Receptor::PERSONA ? 'persona' : 'su empresa').' ('.Receptor::nombreDe(Receptor::cargar($m->getAttribute($prefijo.'_tipo'), (int) $m->getAttribute($prefijo.'_id'))).')';

        $prestamos = PrestamoLlave::query()->where(fn (Builder $q) => $de($q, 'receptor'))->with(['llave:id,nomenclatura', 'sede:id,nombre'])
            ->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get()
            ->map(fn (PrestamoLlave $p) => ['id' => $p->id, 'titulo' => 'Llave '.($p->llave?->nomenclatura ?? '—'),
                'detalle' => ($p->sede?->nombre ?? '').' · '.$p->prestado_en?->format('d/m/Y').' · a nombre de '.$quien($p, 'receptor').($p->folio_garantia ? ' · con garantía' : ''),
                'url' => route('prestamo_llaves.index')])->all();
        $responsivas = Responsiva::query()->where(fn (Builder $q) => $de($q, 'receptor'))->with('sede:id,nombre')
            ->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get()
            ->map(fn (Responsiva $r) => ['id' => $r->id, 'titulo' => 'Responsiva '.$r->folio,
                'detalle' => ($r->sede?->nombre ?? '').' · '.$r->entregado_en?->format('d/m/Y').' · a nombre de '.$quien($r, 'receptor').' (con su firma)',
                'url' => route('responsivas.index').'#responsiva-'.$r->id])->all();
        $vouchers = VoucherReposicion::query()->where(fn (Builder $q) => $de($q, 'responsable'))
            ->orderByDesc('id')->limit(self::MAX_POR_TIPO)->get()
            ->map(fn (VoucherReposicion $v) => ['id' => $v->id, 'titulo' => 'Voucher '.$v->folio,
                'detalle' => $v->origen_descripcion.' · '.$v->created_at?->format('d/m/Y').' · responsable: '.$quien($v, 'responsable'),
                'url' => route('vouchers.index', ['q' => $v->folio])])->all();

        return [$prestamos, $responsivas, $vouchers];
    }

    /** Teléfono escrito con o sin espacios, guiones o lada: se comparan los últimos dígitos. */
    private static function orTelefono(Builder $q, string $columna, string $telefono): void
    {
        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$columna}, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?", ['%'.substr($telefono, -10)]);
    }

    // ------------------------------------------------------------ Exportar

    /**
     * ZIP con lo seleccionado: un JSON y un CSV por tipo, más fotos y
     * documentos de esos registros. Reutiliza el escritor de ZIP y la
     * protección contra fórmulas de la exportación de empresa.
     *
     * @param  array<string, list<int>>  $seleccion
     * @return array{ruta: string, registros: int, archivos: int}
     */
    public function exportar(array $seleccion, string $ruta, string $folio): array
    {
        Storage::disk('local')->makeDirectory(dirname($ruta));
        $zip = new ZipPorPartes(Storage::disk('local')->path($ruta));
        $registros = 0;
        $archivos = 0;
        foreach ($this->registros($seleccion) as $tipo => $filas) {
            if ($filas === []) {
                continue;
            }
            $registros += count($filas);
            $zip->agregarTexto("datos/{$tipo}.json", (string) json_encode($filas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $columnas = array_keys($filas[0]);
            $memoria = fopen('php://temp', 'w+b');
            fwrite($memoria, "\xEF\xBB\xBF");
            Csv::fila($memoria, $columnas);
            foreach ($filas as $fila) {
                Csv::fila($memoria, array_map(fn ($c) => is_scalar($fila[$c] ?? null) || ($fila[$c] ?? null) === null ? ($fila[$c] ?? null) : json_encode($fila[$c]), $columnas));
            }
            rewind($memoria);
            $zip->agregarTexto("datos/{$tipo}.csv", (string) stream_get_contents($memoria));
            fclose($memoria);
            foreach ($filas as $fila) {
                // Fotos, documentos y firmas que menciona el registro (disco privado)
                foreach ($fila as $valor) {
                    if (is_string($valor) && preg_match('#^(accesos|candidatos|firmas)/[\w\-./]+\.[A-Za-z0-9]{2,5}$#', $valor)
                        && ZipPorPartes::nombreSeguro('archivos/'.$valor) && Storage::disk('local')->exists($valor)) {
                        $zip->agregarArchivo('archivos/'.$valor, Storage::disk('local')->path($valor));
                        $archivos++;
                    }
                }
            }
        }
        $zip->agregarTexto('LEEME.txt', "Datos personales entregados por la solicitud {$folio}.\n"
            .'Generado el '.now()->format('d/m/Y H:i')." (hora universal).\n\n"
            ."datos/*.json y datos/*.csv: los registros que la plataforma tiene de la persona.\n"
            ."archivos/: fotos y documentos ligados a esos registros.\n");
        $zip->cerrar();

        return ['ruta' => $ruta, 'registros' => $registros, 'archivos' => $archivos];
    }

    /**
     * @param  array<string, list<int>>  $seleccion
     * @return array<string, list<array<string, mixed>>>
     */
    private function registros(array $seleccion): array
    {
        $quitar = fn (array $fila) => array_filter($fila, fn ($k) => ! preg_match(ExportacionEmpresa::PATRON_COLUMNA_SENSIBLE, (string) $k), ARRAY_FILTER_USE_KEY);
        $r = [];
        if ($ids = $seleccion['persona'] ?? []) {
            $r['padron_personas'] = Persona::whereIn('id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        }
        if ($ids = $seleccion['acceso'] ?? []) {
            $accesos = Acceso::whereIn('id', $ids)->whereIn('tipo', self::ACCESOS_EXTERNOS)->get();
            $r['bitacora_accesos'] = $accesos->map(fn ($m) => $quitar($m->getAttributes()))->all();
            $r['acompanantes'] = AcompananteAcceso::whereIn('acceso_id', $accesos->pluck('id'))->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        }
        if ($ids = $seleccion['candidato'] ?? []) {
            $r['candidatos'] = Candidato::whereIn('id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
            $r['candidatos_postulaciones'] = Postulacion::whereIn('candidato_id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
            $r['candidatos_documentos'] = CandidatoDocumento::whereIn('candidato_id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        }
        if ($ids = $seleccion['colaborador'] ?? []) {
            $r['colaboradores'] = Colaborador::whereIn('id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
            $r['transporte_viajes_colaborador'] = MovimientoTransportePasajero::whereIn('colaborador_id', $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        }
        // C-37 (X-01): los módulos que antes no alcanzaba
        $filas = fn (string $modelo, string $columna, array $ids) => $modelo::whereIn($columna, $ids)->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        if ($ids = $seleccion['lost_found_reporte'] ?? []) {
            $r['lost_found_reportes_perdida'] = $filas(LostFoundReportePerdida::class, 'id', $ids);
        }
        if ($ids = $seleccion['lost_found_entrega'] ?? []) {
            $r['lost_found_entregas'] = $filas(LostFoundEntrega::class, 'id', $ids);
        }
        if ($ids = $seleccion['novedad'] ?? []) {
            $r['novedades'] = $filas(Novedad::class, 'id', $ids);
            $r['novedades_testigos'] = $filas(NovedadTestigo::class, 'novedad_id', $ids);
            $r['novedades_robo'] = $filas(RoboDetalle::class, 'novedad_id', $ids);
            $r['novedades_reporte_general'] = $filas(NovedadReporteGeneral::class, 'novedad_id', $ids);
        }
        if ($ids = $seleccion['accidente'] ?? []) {
            $r['accidentes'] = $filas(Novedad::class, 'id', $ids);
            $r['accidentes_huesped'] = $filas(AccidenteHuesped::class, 'novedad_id', $ids);
            $r['accidentes_colaborador'] = $filas(AccidenteColaborador::class, 'novedad_id', $ids);
            $r['accidentes_datos_medicos'] = $filas(AccidenteDictamen::class, 'novedad_id', $ids);
            $r['accidentes_guardavidas'] = $filas(AccidenteGuardavidas::class, 'novedad_id', $ids);
            $r['accidentes_incapacidad'] = $filas(AccidenteIncapacidad::class, 'novedad_id', $ids);
            $r['accidentes_firmas'] = AccidenteFirma::whereIn('novedad_id', $ids)->where('rol', 'afectado')->get()->map(fn ($m) => $quitar($m->getAttributes()))->all();
        }
        if ($ids = $seleccion['reserva'] ?? []) {
            $r['reservas'] = $filas(Reserva::class, 'id', $ids);
        }
        if ($ids = $seleccion['transporte'] ?? []) {
            $r['transporte'] = MovimientoTransporte::whereIn('id', $ids)->get()
                ->map(fn ($m) => $quitar(array_diff_key($m->getAttributes(), ['firma_guardia' => 1])))->all(); // la del guardia es de otra persona
        }
        // Etapa 2: préstamos, responsivas y vouchers a su nombre (sin firmas de Seguridad)
        if ($ids = $seleccion['prestamo_llave'] ?? []) {
            $r['prestamos_llaves'] = $filas(PrestamoLlave::class, 'id', $ids);
        }
        if ($ids = $seleccion['responsiva'] ?? []) {
            $r['responsivas'] = $filas(Responsiva::class, 'id', $ids);
            $r['responsivas_equipos'] = $filas(EquipoResponsiva::class, 'responsiva_id', $ids);
        }
        if ($ids = $seleccion['voucher'] ?? []) {
            $r['vouchers'] = VoucherReposicion::whereIn('id', $ids)->get()
                ->map(fn ($m) => $quitar(array_diff_key($m->getAttributes(), ['firma_seguridad' => 1])))->all();
        }

        return array_filter($r, fn ($lista) => $lista !== []);
    }

    // ------------------------------------------------------------- Quitar

    /**
     * Cancelación: quita los datos personales de lo seleccionado. Los
     * registros de las bitácoras se conservan con el texto indicado; la ficha
     * del padrón queda anónima y de baja; la de candidato se borra con sus
     * documentos (salvo contratados). Colaboradores y accidentes de
     * colaborador no se tocan (relación laboral). En los textos libres
     * (novedades, notas, observaciones) se quita el nombre de la persona.
     *
     * @param  array<string, list<int>>  $seleccion
     * @return array<string, int> registros tratados por tipo, más «omitidos»
     */
    public function eliminar(array $seleccion, string $texto, ?string $nombre = null): array
    {
        $hecho = array_fill_keys(array_keys(self::TIPOS), 0) + ['omitidos' => 0];
        unset($hecho['colaborador']);
        $nombre = trim((string) $nombre);
        DB::transaction(function () use ($seleccion, $texto, $nombre, &$hecho) {
            foreach (Acceso::whereIn('id', $seleccion['acceso'] ?? [])->whereIn('tipo', self::ACCESOS_EXTERNOS)->get() as $a) {
                $this->anonimizarAcceso($a, $texto);
                $hecho['acceso']++;
            }
            foreach (Persona::whereIn('id', $seleccion['persona'] ?? [])->get() as $p) {
                foreach (Acceso::where('persona_id', $p->id)->whereNull('datos_eliminados_en')->get() as $a) {
                    $this->anonimizarAcceso($a, $texto);
                    $hecho['acceso']++;
                }
                $p->forceFill(['nombre_completo' => mb_substr($texto, 0, 150), 'folio_identificacion' => null, 'tipo_identificacion' => null,
                    'telefono' => null, 'motivo_visita' => null, 'activo' => false])->save();
                $hecho['persona']++;
            }
            foreach (Candidato::whereIn('id', $seleccion['candidato'] ?? [])->get() as $c) {
                if ($c->contratado()) {
                    $hecho['omitidos']++;

                    continue;
                }
                foreach ($c->documentos as $doc) {
                    $this->documentos->borrar($doc->ruta);
                }
                $this->firmas->borrar($c->firma_ruta);
                $c->delete();
                $hecho['candidato']++;
            }
            // C-37 (X-01): Lost & Found, Novedades, Accidentes, Reservas y Transporte
            foreach (LostFoundReportePerdida::whereIn('id', $seleccion['lost_found_reporte'] ?? [])->whereNull('datos_eliminados_en')->get() as $r) {
                $this->anonimizarReportePerdida($r, $texto, $nombre);
                $hecho['lost_found_reporte']++;
            }
            foreach (LostFoundEntrega::whereIn('id', $seleccion['lost_found_entrega'] ?? [])->whereNull('datos_eliminados_en')->get() as $e) {
                $this->anonimizarEntrega($e, $texto, $nombre);
                $hecho['lost_found_entrega']++;
            }
            foreach (Novedad::whereIn('id', $seleccion['novedad'] ?? [])->get() as $n) {
                $this->quitarNombreDeNovedad($n, $texto, $nombre);
                $hecho['novedad']++;
            }
            foreach (Novedad::whereIn('id', $seleccion['accidente'] ?? [])->get() as $n) {
                if (! AccidenteHuesped::where('novedad_id', $n->id)->exists()) {
                    $hecho['omitidos']++; // accidente de colaborador: relación laboral

                    continue;
                }
                if ($nombre !== '') {
                    $this->quitarNombreDeNovedad($n, $texto, $nombre);
                }
                $this->anonimizarAccidente($n, $texto);
                $hecho['accidente']++;
            }
            foreach (Reserva::whereIn('id', $seleccion['reserva'] ?? [])->whereNull('datos_eliminados_en')->get() as $r) {
                $this->anonimizarReserva($r, $texto, $nombre);
                $hecho['reserva']++;
            }
            // Etapa 2: el préstamo, la responsiva y el voucher se conservan como comprobante; se quitan la garantía, la firma y su nombre del texto
            foreach (PrestamoLlave::whereIn('id', $seleccion['prestamo_llave'] ?? [])->whereIn('receptor_tipo', [Receptor::PERSONA, Receptor::PROVEEDOR])->get() as $p) {
                PrestamoLlave::whereKey($p->id)->update(['folio_garantia' => null, 'updated_at' => $p->updated_at]);
                $hecho['prestamo_llave']++;
            }
            foreach (Responsiva::whereIn('id', $seleccion['responsiva'] ?? [])->whereIn('receptor_tipo', [Receptor::PERSONA, Receptor::PROVEEDOR])->get() as $r) {
                $this->firmas->borrar($r->firma_ruta);
                Responsiva::whereKey($r->id)->update(['firma_ruta' => '', 'updated_at' => $r->updated_at]);
                $hecho['responsiva']++;
            }
            foreach (VoucherReposicion::whereIn('id', $seleccion['voucher'] ?? [])->whereIn('responsable_tipo', [Receptor::PERSONA, Receptor::PROVEEDOR])->get() as $v) {
                $this->firmas->borrar($v->firma_responsable);
                VoucherReposicion::whereKey($v->id)->update(['firma_responsable' => null, 'descripcion' => self::sinNombre($v->descripcion, $nombre, $texto), 'updated_at' => $v->updated_at]);
                $hecho['voucher']++;
            }
            $choferes = Persona::whereIn('id', $seleccion['persona'] ?? [])->pluck('id');
            foreach (MovimientoTransporte::whereIn('id', $seleccion['transporte'] ?? [])->whereNull('datos_eliminados_en')->get() as $m) {
                $this->anonimizarTransporte($m, $texto, $nombre, $choferes->contains($m->chofer_id) || $nombre === '' || $this->esChofer($m, $nombre));
                $hecho['transporte']++;
            }
        });

        return $hecho;
    }

    // ------------------------------------------------- Tratamiento por módulo

    /** Quien reportó la pérdida: nombre, teléfono y correo (el reporte y su objeto se conservan). */
    public function anonimizarReportePerdida(LostFoundReportePerdida $r, string $texto, string $nombre = ''): void
    {
        LostFoundReportePerdida::whereKey($r->id)->update(['nombre_huesped' => mb_substr($texto, 0, 150), 'telefono' => null, 'correo' => null,
            'descripcion' => self::sinNombre($r->descripcion, $nombre, $texto), 'datos_eliminados_en' => now(), 'updated_at' => $r->updated_at]);
    }

    /** Quien recibió el objeto: nombre, identificación, correo y firma (la entrega se conserva). */
    public function anonimizarEntrega(LostFoundEntrega $e, string $texto, string $nombre = ''): void
    {
        $this->firmas->borrar($e->firma_ruta);
        LostFoundEntrega::whereKey($e->id)->update(['nombre_recibe' => mb_substr($texto, 0, 150), 'tipo_identificacion' => null, 'correo_recibe' => null,
            'firma_ruta' => null, 'persona_id' => null, 'observaciones' => self::sinNombre($e->observaciones, $nombre, $texto),
            'datos_eliminados_en' => now(), 'updated_at' => $e->updated_at]);
    }

    /** Quita el nombre de la persona de los textos del ticket, de sus testigos, del sospechoso y de las notas. */
    public function quitarNombreDeNovedad(Novedad $n, string $texto, string $nombre): void
    {
        if ($nombre === '') {
            return;
        }
        Novedad::whereKey($n->id)->update([
            'reportado_por' => mb_substr((string) self::sinNombre($n->reportado_por, $nombre, $texto), 0, 150),
            'involucrados' => self::sinNombre($n->involucrados, $nombre, $texto),
            'descripcion' => (string) self::sinNombre($n->descripcion, $nombre, $texto),
            'como_sucedio' => self::sinNombre($n->como_sucedio, $nombre, $texto),
            'resolucion' => self::sinNombre($n->resolucion, $nombre, $texto),
            'updated_at' => $n->updated_at,
        ]);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nombre).'%';
        foreach (NovedadTestigo::where('novedad_id', $n->id)->where('nombre', 'like', $like)->get() as $t) {
            NovedadTestigo::whereKey($t->id)->update(['nombre' => mb_substr($texto, 0, 150), 'declaracion' => null, 'updated_at' => $t->updated_at]);
        }
        foreach (RoboDetalle::where('novedad_id', $n->id)->get() as $r) {
            RoboDetalle::whereKey($r->id)->update(['descripcion_sospechoso' => self::sinNombre($r->descripcion_sospechoso, $nombre, $texto), 'updated_at' => $r->updated_at]);
        }
        foreach (NovedadReporteGeneral::where('novedad_id', $n->id)->whereNotNull('observados')->get() as $g) {
            NovedadReporteGeneral::whereKey($g->id)->update(['observados' => mb_substr((string) self::sinNombre($g->observados, $nombre, $texto), 0, 255), 'updated_at' => $g->updated_at]);
        }
        foreach (NovedadNota::where('novedad_id', $n->id)->where('texto', 'like', $like)->get() as $nota) {
            NovedadNota::whereKey($nota->id)->update(['texto' => (string) self::sinNombre($nota->texto, $nombre, $texto), 'updated_at' => $nota->updated_at]);
        }
    }

    /**
     * Accidente de huésped: sus datos (nombre, habitación, agencia, país,
     * sexo, edad y su relato), los datos médicos del dictamen, sus lesiones
     * en el anexo del guardavidas y su firma. Quedan la fecha, el lugar y las
     * casillas (para las estadísticas). El formulario no cambia.
     */
    public function anonimizarAccidente(Novedad $n, string $texto): void
    {
        AccidenteHuesped::where('novedad_id', $n->id)->update(['nombre' => mb_substr($texto, 0, 150), 'num_habitacion' => null, 'agencia' => null,
            'fecha_check_in' => null, 'fecha_check_out' => null, 'pais' => null, 'sexo' => null, 'edad' => null, 'explicacion' => null,
            'motivo_asistencia' => null, 'detalles_testigos' => null]);
        AccidenteDictamen::where('novedad_id', $n->id)->update(['tipos_herida' => null, 'zonas_cuerpo' => null, 'cuales_auxilios' => null,
            'cuales_atencion' => null, 'diagnostico' => null, 'nombre_hospital' => null, 'trasladado_en' => null, 'nombre_medico' => null, 'observaciones' => null]);
        AccidenteGuardavidas::where('novedad_id', $n->id)->update(['tipo_calzado' => null, 'tipo_herida' => null, 'parte_afectada' => null,
            'especifique_riesgo' => null, 'descripcion' => null, 'material_curacion' => null]);
        foreach (AccidenteFirma::where('novedad_id', $n->id)->where('rol', 'afectado')->get() as $f) {
            $this->firmas->borrar($f->ruta);
            $f->delete();
        }
        Novedad::whereKey($n->id)->update(['involucrados' => null, 'datos_eliminados_en' => now(), 'updated_at' => $n->updated_at]);
    }

    /**
     * Conservación (C-37): ticket cerrado que pasó su plazo. Sin buscar a una
     * persona en particular: quita los involucrados, los nombres y
     * declaraciones de los testigos, la descripción del sospechoso y, si es
     * un accidente de huésped, sus datos y datos médicos. La descripción del
     * hecho se conserva. No se llama para accidentes de colaborador.
     */
    public function anonimizarIncidente(Novedad $n, string $texto): void
    {
        if (AccidenteHuesped::where('novedad_id', $n->id)->exists()) {
            $this->anonimizarAccidente($n, $texto);
        }
        foreach (NovedadTestigo::where('novedad_id', $n->id)->get() as $t) {
            NovedadTestigo::whereKey($t->id)->update(['nombre' => mb_substr($texto, 0, 150), 'declaracion' => null, 'updated_at' => $t->updated_at]);
        }
        RoboDetalle::where('novedad_id', $n->id)->whereNotNull('descripcion_sospechoso')->update(['descripcion_sospechoso' => null]);
        NovedadReporteGeneral::where('novedad_id', $n->id)->whereNotNull('observados')->update(['observados' => null]);
        Novedad::whereKey($n->id)->update(['involucrados' => null, 'datos_eliminados_en' => now(), 'updated_at' => $n->updated_at]);
    }

    /** Cliente de la reserva: nombre, empresa, correo y teléfono; su nombre sale del título y las notas. */
    public function anonimizarReserva(Reserva $r, string $texto, string $nombre = ''): void
    {
        Reserva::whereKey($r->id)->update(['cliente_nombre' => mb_substr($texto, 0, 150), 'cliente_empresa' => null, 'cliente_correo' => null, 'cliente_telefono' => null,
            'titulo' => mb_substr((string) self::sinNombre($r->titulo, $nombre, $texto), 0, 150), 'notas' => self::sinNombre($r->notas, $nombre, $texto),
            'indicaciones_montaje' => self::sinNombre($r->indicaciones_montaje, $nombre, $texto), 'datos_eliminados_en' => now(), 'updated_at' => $r->updated_at]);
        if ($nombre !== '') {
            foreach (ReservaEvento::where('reserva_id', $r->id)->get() as $ev) {
                $nuevo = self::sinNombre($ev->detalle, $nombre, $texto);
                if ($nuevo !== $ev->detalle) {
                    ReservaEvento::whereKey($ev->id)->update(['detalle' => $nuevo]);
                }
            }
        }
    }

    /** Movimiento de transporte: la firma del taxista si era la persona, y su nombre en observaciones y justificación. */
    public function anonimizarTransporte(MovimientoTransporte $m, string $texto, string $nombre, bool $esChofer): void
    {
        $cambios = ['observaciones' => self::sinNombre($m->observaciones, $nombre, $texto), 'justificacion' => self::sinNombre($m->justificacion, $nombre, $texto),
            'datos_eliminados_en' => now(), 'updated_at' => $m->updated_at];
        if ($esChofer) {
            $this->firmas->borrar($m->firma_taxista);
            $cambios['firma_taxista'] = null;
        }
        MovimientoTransporte::whereKey($m->id)->update($cambios);
    }

    private function esChofer(MovimientoTransporte $m, string $nombre): bool
    {
        $chofer = $m->chofer_id ? Persona::whereKey($m->chofer_id)->value('nombre_completo') : null;

        return $chofer !== null && mb_stripos($chofer, $nombre) !== false;
    }

    /** El texto sin el nombre de la persona (sin distinguir mayúsculas). Null se queda null. */
    public static function sinNombre(?string $valor, string $nombre, string $texto): ?string
    {
        if ($valor === null || trim($nombre) === '') {
            return $valor;
        }

        return preg_replace('/'.preg_quote(trim($nombre), '/').'/iu', '['.$texto.']', $valor) ?? $valor;
    }

    /** Quita del registro de la bitácora lo que identifica a la persona; el registro se conserva. */
    public function anonimizarAcceso(Acceso $a, string $texto): void
    {
        $this->documentos->borrar($a->foto_persona);
        $this->documentos->borrar($a->foto_identificacion);
        Acceso::whereKey($a->id)->update([
            'nombre' => mb_substr($texto, 0, 150), 'persona_id' => null, 'persona_visita' => null, 'identificacion' => null,
            'placas' => null, 'vehiculo_id' => null, 'conductor' => null, 'numero_reserva' => null, 'habitacion' => null,
            'observaciones' => null, 'foto_persona' => null, 'foto_identificacion' => null,
            'datos_eliminados_en' => now(), 'updated_at' => $a->updated_at, // no es un movimiento de la bitácora
        ]);
        AcompananteAcceso::where('acceso_id', $a->id)->update(['nombre' => mb_substr($texto, 0, 150), 'identificacion' => null]);
    }

    /** Quita una foto (de la persona o de su identificación) de un registro de la bitácora. */
    public function quitarFoto(Acceso $a, string $campo): void
    {
        $this->documentos->borrar($a->{$campo});
        Acceso::whereKey($a->id)->update([$campo => null, 'updated_at' => $a->updated_at]);
    }

    public static function enmascarar(string $valor): string
    {
        return mb_strlen($valor) <= 4 ? '****' : str_repeat('*', mb_strlen($valor) - 4).mb_substr($valor, -4);
    }
}
