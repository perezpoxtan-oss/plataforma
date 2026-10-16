<?php

namespace App\Services\Privacidad;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Firmas\Firmas;
use App\Services\Traslado\ExportacionEmpresa;
use App\Services\Traslado\ZipPorPartes;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que la plataforma tiene de UNA persona dentro de la empresa activa
 * (el tenant ya está puesto): buscarlo, exportarlo en un ZIP y quitar sus
 * datos personales. Lo usan las solicitudes ARCO y la conservación de datos
 * (docs/tecnico/privacidad.md).
 *
 * Tipos que se buscan:
 *  - persona:     padrón de personas (visitas, proveedores, contratistas).
 *  - acceso:      registros de la Bitácora de accesos de personas externas.
 *  - candidato:   fichas de candidatos (con su CV y documentos).
 *  - colaborador: solo para consultar y rectificar en su ficha (la relación
 *                 laboral obliga a conservarlos; no se cancelan desde aquí).
 */
class DatosDePersona
{
    public const TIPOS = ['persona' => 'Padrón de personas', 'acceso' => 'Bitácora de accesos', 'candidato' => 'Candidatos', 'colaborador' => 'Colaboradores'];

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

        if ($porNombre) {
            // Cada palabra del nombre debe aparecer en el nombre o en algún apellido (sin concatenar en SQL: igual en MariaDB y SQLite)
            $colaboradores = Colaborador::query()->where(function (Builder $q) use ($nombre) {
                foreach (array_slice(preg_split('/\s+/u', $nombre) ?: [], 0, 5) as $palabra) {
                    $p = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $palabra).'%';
                    $q->where(fn (Builder $w) => $w->where('nombre', 'like', $p)->orWhere('apellido_paterno', 'like', $p)->orWhere('apellido_materno', 'like', $p));
                }
            })->orderBy('id')->limit(self::MAX_POR_TIPO)->get();
            foreach ($colaboradores as $c) {
                $resultado['colaborador'][] = ['id' => $c->id, 'titulo' => $c->nombreCompleto(), 'detalle' => 'Colaborador '.($c->activo ? 'activo' : 'dado de baja').' · se rectifica en su ficha',
                    'url' => route('colaboradores.index', ['buscar' => $c->nombreCompleto()])];
            }
        }

        return $resultado;
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
        }

        return $r;
    }

    // ------------------------------------------------------------- Quitar

    /**
     * Cancelación: quita los datos personales de lo seleccionado. Los
     * registros de la bitácora se conservan con el texto indicado; la ficha
     * del padrón queda anónima y de baja; la de candidato se borra con sus
     * documentos (salvo contratados). Colaboradores no se tocan.
     *
     * @param  array<string, list<int>>  $seleccion
     * @return array{persona: int, acceso: int, candidato: int, omitidos: int}
     */
    public function eliminar(array $seleccion, string $texto): array
    {
        $hecho = ['persona' => 0, 'acceso' => 0, 'candidato' => 0, 'omitidos' => 0];
        DB::transaction(function () use ($seleccion, $texto, &$hecho) {
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
        });

        return $hecho;
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
