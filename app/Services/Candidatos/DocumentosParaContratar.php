<?php

namespace App\Services\Candidatos;

use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentoParaContratar;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Candidatos, fase 3 (ADR-0011): documentos para contratar y firma del contrato.
 *
 *  - La lista de documentos es por empresa (documentos_para_contratar); sin
 *    renglones se usa la estándar (DEFECTO). Cada documento subido lleva la
 *    clave del renglón en candidato_documentos.tipo; cv, otro y contrato son
 *    tipos fijos.
 *  - «Contrato firmado» (desde Elegido) guarda fecha, quién lo recibió y, si
 *    se adjunta, el archivo (disco privado, tipo contrato).
 *  - «Contratar» avisa qué obligatorios faltan y si no hay contrato; se puede
 *    forzar con motivo (queda en auditoría y en el historial).
 *  - Al contratar, los documentos pasan al expediente del colaborador
 *    (colaborador_id): se ven desde Colaboradores con colaboradores.datos_personales.
 */
class DocumentosParaContratar
{
    /** Lista estándar: clave => [nombre, obligatorio]. */
    public const DEFECTO = [
        'ine' => ['Identificación oficial (INE)', true],
        'curp' => ['CURP', true],
        'nss' => ['Número de seguro social (NSS)', true],
        'rfc' => ['RFC (constancia de situación fiscal)', true],
        'acta_nacimiento' => ['Acta de nacimiento', true],
        'comprobante' => ['Comprobante de domicilio', true],
        'certificado_estudios' => ['Certificado de estudios', true],
        'cartas_recomendacion' => ['Cartas de recomendación', true],
    ];

    public const MAX_DOCUMENTOS = 15;

    /** @var array<int, array<string, string>> nombres por empresa (cv, otro, contrato y la lista, activa o no) */
    private array $nombres = [];

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    // ----------------------------------------------------------------- Lista

    /**
     * Renglones activos de la empresa (o la lista estándar si no configuró ninguna).
     *
     * @return Collection<int, DocumentoParaContratar>
     */
    public function lista(int $empresaId): Collection
    {
        $filas = DocumentoParaContratar::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('activo', true)->orderBy('orden')->orderBy('id')->get();
        if ($filas->isNotEmpty()) {
            return $filas;
        }
        $i = 0;

        return collect(self::DEFECTO)->map(fn ($d, $clave) => new DocumentoParaContratar(['clave' => $clave, 'nombre' => $d[0], 'obligatorio' => $d[1], 'orden' => $i++, 'activo' => true]))->values();
    }

    /** ¿La empresa ya guardó su propia lista? */
    public function configurada(int $empresaId): bool
    {
        return DocumentoParaContratar::withoutGlobalScopes()->where('empresa_id', $empresaId)->exists();
    }

    /**
     * Opciones del selector «Agregar documento»: CV, la lista de la empresa y «Otro».
     *
     * @return array<string, string>
     */
    public function opciones(int $empresaId): array
    {
        $r = ['cv' => CandidatoDocumento::FIJOS['cv']];
        foreach ($this->lista($empresaId) as $d) {
            $r[$d->clave] = $d->nombre;
        }
        $r['otro'] = CandidatoDocumento::FIJOS['otro'];

        return $r;
    }

    /**
     * Nombre de cada tipo, incluidos los renglones ya inactivos (un documento
     * subido antes conserva su nombre) y los tipos de siempre.
     *
     * @return array<string, string>
     */
    public function nombres(int $empresaId): array
    {
        if (! isset($this->nombres[$empresaId])) {
            $r = CandidatoDocumento::TIPOS + CandidatoDocumento::FIJOS;
            foreach (self::DEFECTO as $clave => $d) {
                $r[$clave] = $d[0];
            }
            foreach (DocumentoParaContratar::withoutGlobalScopes()->where('empresa_id', $empresaId)->get(['clave', 'nombre']) as $d) {
                $r[$d->clave] = $d->nombre;
            }
            $this->nombres[$empresaId] = $r;
        }

        return $this->nombres[$empresaId];
    }

    public function nombre(int $empresaId, string $tipo): string
    {
        return $this->nombres($empresaId)[$tipo] ?? Str::headline(str_replace('_', ' ', $tipo));
    }

    /** Claves que acepta «Agregar documento» (nunca el contrato). */
    public function tipoValido(int $empresaId, string $tipo): bool
    {
        return array_key_exists($tipo, $this->opciones($empresaId));
    }

    /**
     * Guarda la lista de la empresa (pantalla «Documentos para contratar»).
     * Renglones vacíos se omiten; los que ya no vienen quedan inactivos.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $filas = [];
        $claves = [];
        foreach (is_array($entrada['documentos'] ?? null) ? $entrada['documentos'] : [] as $i => $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $nombre = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($fila['nombre'] ?? null)));
            if ($nombre === '') {
                continue;
            }
            if (mb_strlen($nombre) > 80) {
                throw ValidationException::withMessages(['documentos' => 'Cada documento admite máximo 80 caracteres.']);
            }
            $clave = Entrada::texto($fila['clave'] ?? null);
            $clave = preg_match('/^[a-z0-9_]{1,40}$/', $clave) ? $clave : (Str::slug($nombre, '_') ?: 'documento_'.substr(md5($nombre), 0, 8));
            $clave = mb_substr($clave, 0, 40);
            if (array_key_exists($clave, CandidatoDocumento::FIJOS)) {
                throw ValidationException::withMessages(['documentos' => "«{$nombre}» ya es un tipo de la plataforma (CV, Otro o Contrato): no hace falta en la lista."]);
            }
            if (in_array($clave, $claves, true)) {
                throw ValidationException::withMessages(['documentos' => "El documento «{$nombre}» está repetido."]);
            }
            $claves[] = $clave;
            $filas[] = ['clave' => $clave, 'nombre' => $nombre, 'obligatorio' => filter_var($fila['obligatorio'] ?? false, FILTER_VALIDATE_BOOL), 'orden' => count($filas)];
        }
        if ($filas === []) {
            throw ValidationException::withMessages(['documentos' => 'Escribe al menos un documento para contratar.']);
        }
        if (count($filas) > self::MAX_DOCUMENTOS) {
            throw ValidationException::withMessages(['documentos' => 'Máximo '.self::MAX_DOCUMENTOS.' documentos.']);
        }

        $antes = $this->lista($empresa->id)->map(fn ($d) => $d->nombre.($d->obligatorio ? ' (obligatorio)' : ' (opcional)'))->values()->all();
        DB::transaction(function () use ($empresa, $filas, $claves) {
            $existentes = DocumentoParaContratar::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get()->keyBy('clave');
            foreach ($filas as $f) {
                $d = $existentes[$f['clave']] ?? new DocumentoParaContratar(['clave' => $f['clave']]);
                $d->fill(['nombre' => $f['nombre'], 'obligatorio' => $f['obligatorio'], 'orden' => $f['orden'], 'activo' => true]);
                $d->forceFill(['empresa_id' => $empresa->id])->save();
            }
            DocumentoParaContratar::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereNotIn('clave', $claves)->update(['activo' => false, 'updated_at' => now()]);
        });
        unset($this->nombres[$empresa->id]);
        $despues = $this->lista($empresa->id)->map(fn ($d) => $d->nombre.($d->obligatorio ? ' (obligatorio)' : ' (opcional)'))->values()->all();
        $this->auditoria->auditar($actor, 'candidatos.documentos_configurados', $empresa, ['documentos' => $antes], ['documentos' => $despues]);
    }

    // ---------------------------------------------------------------- Estado

    /**
     * Qué tiene y qué le falta a la ficha para contratar.
     *
     * @return array{obligatorios: int, tiene: int, faltan: list<string>, opcionales_faltan: list<string>, contrato: bool, completo: bool, por_tipo: array<string, int>}
     */
    public function estado(Candidato $c, ?Postulacion $p = null): array
    {
        $porTipo = $c->documentos->countBy('tipo')->all();
        $faltan = [];
        $opcionales = [];
        $obligatorios = 0;
        $tiene = 0;
        foreach ($this->lista((int) $c->empresa_id) as $d) {
            $hay = ($porTipo[$d->clave] ?? 0) > 0;
            if ($d->obligatorio) {
                $obligatorios++;
                if ($hay) {
                    $tiene++;
                } else {
                    $faltan[] = $d->nombre;
                }
            } elseif (! $hay) {
                $opcionales[] = $d->nombre;
            }
        }
        $contrato = $p !== null && $p->contratoFirmado();

        return ['obligatorios' => $obligatorios, 'tiene' => $tiene, 'faltan' => $faltan, 'opcionales_faltan' => $opcionales, 'contrato' => $contrato,
            'completo' => $faltan === [] && $contrato, 'por_tipo' => $porTipo];
    }

    /**
     * Texto corto para la ficha y la lista: «Documentos: 5 de 8 obligatorios».
     *
     * @param  array{obligatorios: int, tiene: int}  $estado
     */
    public function resumen(array $estado): string
    {
        return 'Documentos: '.$estado['tiene'].' de '.$estado['obligatorios'].' obligatorios';
    }

    // -------------------------------------------------------------- Contrato

    /**
     * «Contrato firmado»: desde Elegido. Guarda cuándo se firmó, quién lo
     * recibió (quien lo registra) y, si viene, el archivo del contrato.
     *
     * @param  array<string, mixed>  $entrada  fecha (local, no futura), archivo
     */
    public function contratoFirmado(User $actor, Candidato $c, array $entrada, ?UploadedFile $archivo): Postulacion
    {
        $p = app(Postulaciones::class)->asegurar($c);
        if ($p->etapa !== 'elegido') {
            throw new CambioNoPermitido('El contrato se registra cuando el candidato está «Elegido».');
        }
        $d = Validator::make($entrada, [
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
        ], ['fecha.*' => 'Revisa la fecha de la firma: no puede ser futura.'])->validate();

        $documento = null;
        if ($archivo !== null) {
            $documento = app(DocumentosCandidato::class)->subir($c, $archivo, CandidatoDocumento::TIPO_CONTRATO, 'rh', 'archivo');
        }
        $fecha = isset($d['fecha']) ? Carbon::parse($d['fecha'], app(HoraLocal::class)->zona())->setTime(12, 0)->utc() : now();
        $cambios = ['contrato_firmado_en' => $fecha, 'contrato_recibido_por' => $actor->id];
        if ($documento !== null) {
            $cambios['contrato_documento_id'] = $documento->id;
        }
        if (! app(Postulaciones::class)->cambiar($p, 'elegido', $cambios, $actor)) {
            throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
        }
        app(AdministradorCandidatos::class)->evento($c, 'contrato_firmado', null, null, 'Contrato firmado'.($documento ? ' (archivo adjunto)' : '').'. Lo recibió '.$actor->name.'.', $actor);
        $this->auditoria->auditar($actor, 'candidatos.contrato_firmado', $c, null, ['postulacion_id' => $p->id, 'con_archivo' => $documento !== null]);

        return $p->refresh();
    }

    /**
     * «Recibido en papel»: el candidato entregó el documento físico y no se
     * escanea. Queda un renglón sin archivo con el tipo, la fecha de entrega
     * y quién lo recibió (quien lo registra). Solo tipos de la lista de la
     * empresa (el CV, «Otro» y el contrato siempre llevan archivo).
     *
     * @param  array<string, mixed>  $entrada  tipo, fecha (local, no futura)
     */
    public function recibidoEnPapel(User $actor, Candidato $c, array $entrada): CandidatoDocumento
    {
        $lista = $this->lista((int) $c->empresa_id)->keyBy('clave');
        $d = Validator::make($entrada, [
            'tipo' => ['required', 'string', Rule::in($lista->keys()->all())],
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
        ], ['tipo.*' => 'Elige qué documento recibiste en papel.', 'fecha.*' => 'Revisa la fecha de entrega: no puede ser futura.'])->validate();
        $tipo = $d['tipo'];
        if ($c->documentos->contains(fn ($doc) => $doc->tipo === $tipo && $doc->enPapel())) {
            throw ValidationException::withMessages(['tipo' => 'Ese documento ya está marcado como recibido en papel.']);
        }
        $fecha = isset($d['fecha']) ? Carbon::parse($d['fecha'], app(HoraLocal::class)->zona()) : now()->setTimezone(app(HoraLocal::class)->zona());
        $documento = new CandidatoDocumento(['candidato_id' => $c->id, 'tipo' => $tipo, 'nombre_original' => 'Recibido en papel', 'ruta' => null,
            'mime' => 'papel', 'bytes' => 0, 'origen' => CandidatoDocumento::ORIGEN_PAPEL, 'recibido_en' => $fecha->toDateString()]);
        $documento->forceFill(['empresa_id' => $c->empresa_id])->save();
        $c->unsetRelation('documentos');
        app(AdministradorCandidatos::class)->evento($c, 'documento_papel', null, null, $lista[$tipo]->nombre.' recibido en papel el '.$fecha->format('d/m/Y').'. Lo recibió '.$actor->name.'.', $actor);
        $this->auditoria->auditar($actor, 'candidatos.documento_papel', $c, null, ['tipo' => $tipo, 'recibido_en' => $fecha->toDateString()]);

        return $documento;
    }

    /**
     * Lo que «Contratar» debe advertir. Devuelve la lista de faltantes (vacía
     * si está todo).
     *
     * @return list<string>
     */
    public function faltantesParaContratar(Candidato $c, Postulacion $p): array
    {
        $estado = $this->estado($c->loadMissing('documentos'), $p);
        $faltan = array_map(fn ($n) => 'Falta: '.$n, $estado['faltan']);
        if (! $estado['contrato']) {
            $faltan[] = 'El contrato no está firmado';
        }

        return $faltan;
    }

    /**
     * Al contratar, los documentos de la ficha pasan al expediente del
     * colaborador (se conservan en la ficha; la ficha de un contratado no caduca).
     */
    public function pasarAlExpediente(Candidato $c, Colaborador $colaborador): int
    {
        return CandidatoDocumento::where('candidato_id', $c->id)->whereNull('colaborador_id')->update(['colaborador_id' => $colaborador->id, 'updated_at' => now()]);
    }
}
