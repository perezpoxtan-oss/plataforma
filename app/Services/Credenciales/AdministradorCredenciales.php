<?php

namespace App\Services\Credenciales;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Activos\CampanaActivos;
use App\Services\Activos\CustodiaActivos;
use App\Services\Activos\HistorialActivos;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Inventarios\Vouchers;
use App\Services\Lector\Identificacion;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Support\AjustesCredenciales;
use App\Support\EstadoActivo;
use App\Support\Identidad;
use App\Support\ImagenSegura;
use App\Support\Receptor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * C-44 (Etapa 8): credencialización. La credencial es un activo más (ver
 * App\Models\Credencial): se le da salida al colaborador, se recibe (OK,
 * dañada o «No regresó», la recepción común de C-45), se da de baja con
 * voucher de origen «credencial» y se da de baja sola cuando se da de baja
 * al colaborador («Antes de dar de baja», C-20).
 *
 * Permiso: «Colaboradores → Credencial» (colaboradores.credencial), con el
 * alcance de sedes del colaborador. Debe correr dentro de la empresa de trabajo.
 */
class AdministradorCredenciales
{
    public const PERMISO = 'colaboradores.credencial';

    private const DISCO = 'local';

    /** Filtros de la lista «Credenciales». */
    public const FILTROS = [
        'todas' => 'Todas', 'vigentes' => 'Vigentes', 'por_vencer' => 'Por vencer', 'vencidas' => 'Vencidas',
        'no_regreso' => 'No regresó', 'baja' => 'De baja', 'sin' => 'Sin credencial',
    ];

    /** Máximo de renglones de la lista (se acota con la búsqueda). */
    public const LISTA = 500;

    public function __construct(
        private readonly AdministradorColaboradores $colaboradores,
        private readonly AdministradorRoles $auditoria,
        private readonly Vouchers $vouchers,
        private readonly HistorialActivos $historial,
    ) {}

    /** El colaborador en el alcance del permiso (si no, 404). */
    public function colaborador(User $actor, int $id): Colaborador
    {
        abort_unless($actor->can(self::PERMISO), 403);
        $c = $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->whereKey($id)->first();
        abort_if($c === null, 404);

        return $c;
    }

    /** La credencial en el alcance del permiso (por su colaborador; si no, 404). */
    public function credencial(User $actor, int $id): Credencial
    {
        abort_unless($actor->can(self::PERMISO), 403);
        $cred = Credencial::query()->whereKey($id)
            ->whereIn('colaborador_id', $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->select('colaboradores.id'))->first();
        abort_if($cred === null, 404);

        return $cred;
    }

    /** La que no está de baja (a lo más una). */
    public function vigenteDe(Colaborador $c): ?Credencial
    {
        return Credencial::where('colaborador_id', $c->id)->vigentes()->latest('id')->first();
    }

    /**
     * «Dar salida»: entrega la credencial. Si Recursos Humanos guarda la que
     * ya tenía (DISPONIBLE), se le vuelve a dar esa; si no, nace una nueva con
     * su folio. Opcional: la tarjeta NFC/RFID del plástico (la misma regla
     * única de «Código e identificación»).
     */
    public function darSalida(User $actor, Colaborador $c, ?string $etiqueta = null): Credencial
    {
        $this->exigirEmitible($c);
        if ($etiqueta !== null && trim($etiqueta) !== '') {
            app(Identificacion::class)->asignarEtiqueta($actor, 'colaborador', $c, $etiqueta);
            $c->refresh();
        }

        $vigente = $this->vigenteDe($c);
        if ($vigente !== null && $vigente->estado !== EstadoActivo::DISPONIBLE) {
            throw ValidationException::withMessages(['credencial' => "Ya tiene la credencial {$vigente->folio} ({$vigente->textoEstado()}): recíbela, repónla o dala de baja antes de entregar otra."]);
        }
        // Sin credencial vigente se emite en el mismo paso (como siempre: un solo botón)
        $credencial = $vigente ?? $this->emitir($actor, $c);
        DB::transaction(function () use ($actor, $c, $credencial) {
            $credencial->forceFill([
                'sede_id' => $c->sede_id, 'estado' => EstadoActivo::ASIGNADO, 'etiqueta_nfc' => $c->fresh()?->etiqueta_nfc,
                'entregada_en' => now(), 'entregada_por' => $actor->id, 'recibida_en' => null, 'recibida_por' => null,
            ])->save();
        });

        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'salida', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $credencial->folio]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_entregada', $c, null, ['credencial' => $credencial->folio]);

        return $credencial;
    }

    /**
     * «Emitir credencial» (fase 1 de la propuesta): nace DISPONIBLE (la guarda
     * Recursos Humanos para imprimirla y entregarla), con su folio, su
     * vencimiento según la regla de la empresa y el código que llevará
     * impreso. Una sola vigente por colaborador. Si el colaborador ya tuvo
     * una con su código actual, recibe otro: la anterior deja de ser vigente
     * al instante (verificación pública y lector).
     */
    public function emitir(User $actor, Colaborador $c): Credencial
    {
        $this->exigirEmitible($c);
        $credencial = DB::transaction(function () use ($c) {
            $c = Colaborador::whereKey($c->id)->lockForUpdate()->first();
            if ($this->vigenteDe($c) !== null) {
                throw ValidationException::withMessages(['credencial' => 'Ya tiene una credencial vigente: repónla o dala de baja antes de emitir otra.']);
            }
            if (Credencial::where('colaborador_id', $c->id)->where('codigo_qr', $c->codigo_qr)->exists()) {
                $c->forceFill(['codigo_qr' => Str::lower(Str::random(24))])->save();
            }
            $consecutivo = (int) Credencial::lockForUpdate()->max('consecutivo') + 1;
            $credencial = new Credencial(['colaborador_id' => $c->id, 'sede_id' => $c->sede_id]);
            $credencial->forceFill(['consecutivo' => $consecutivo, 'folio' => 'CRED-'.str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT),
                'estado' => EstadoActivo::DISPONIBLE, 'codigo_qr' => $c->codigo_qr, 'vence_en' => AjustesCredenciales::venceEn((int) $c->empresa_id)?->toDateString()])->save();

            return $credencial;
        });
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'emision', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $credencial->folio]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_emitida', $c, null, ['credencial' => $credencial->folio, 'vence_en' => $credencial->vence_en?->toDateString()]);

        return $credencial;
    }

    /**
     * «Reponer credencial»: la vigente se da de baja y se emite otra en el
     * mismo paso; la anterior deja de valer al instante (su código cambia).
     * Extraviada, dañada o robada generan su voucher (con cobro opcional);
     * vencida o cambio de datos o foto, no.
     *
     * @param  array<string, mixed>  $entrada  motivo, descripcion, aplica_cobro, monto
     * @return array{0: Credencial, 1: ?VoucherReposicion}
     */
    public function reponer(User $actor, Credencial $credencial, array $entrada): array
    {
        $c = Colaborador::find($credencial->colaborador_id);
        $this->exigirEmitible($c);
        $motivo = (string) ($entrada['motivo'] ?? '');
        if (! isset(Credencial::MOTIVOS_REPONER[$motivo])) {
            throw ValidationException::withMessages(['motivo' => 'Elige por qué se repone la credencial.']);
        }
        if ($credencial->estado === EstadoActivo::BAJA) {
            throw ValidationException::withMessages(['motivo' => "La credencial {$credencial->folio} ya está dada de baja: emite otra."]);
        }
        $voucher = null;
        if (isset(VoucherReposicion::MOTIVOS[$motivo])) {
            $voucher = $this->darDeBaja($actor, $credencial, $entrada);
        } else {
            $this->bajaSinVoucher($actor, $credencial, $motivo);
        }
        $nueva = $this->emitir($actor, $c->fresh());

        return [$nueva, $voucher];
    }

    /** Vigente = emitida o entregada, sin vencer y de un colaborador activo. */
    public static function esVigente(?Credencial $cr, ?Colaborador $c = null): bool
    {
        if ($cr === null || ! in_array($cr->estado, [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO], true) || $cr->vencida()) {
            return false;
        }
        $c ??= Colaborador::withoutGlobalScopes()->find($cr->colaborador_id);

        return $c !== null && $c->activo && $c->fusionado_en_id === null;
    }

    /**
     * Foto de la credencial: de la cámara (data URL) o de un archivo. Se
     * recodifica (sin metadatos), se recorta al centro en 3:4 y se guarda a
     * 600 × 800 en el disco privado. La anterior se borra.
     */
    public function guardarFoto(User $actor, Colaborador $c, mixed $archivo, ?string $dataUrl): void
    {
        $binario = null;
        if ($archivo instanceof UploadedFile) {
            if (! $archivo->isValid() || $archivo->getSize() > 8 * 1024 * 1024) {
                throw ValidationException::withMessages(['foto' => 'No se pudo recibir la foto (máximo 8 MB). Tómala de nuevo.']);
            }
            $binario = (string) file_get_contents($archivo->getRealPath());
        } elseif (is_string($dataUrl) && preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) && strlen($dataUrl) < 12 * 1024 * 1024) {
            $binario = (string) base64_decode($m[2], true);
        }
        if ($binario === null || $binario === '') {
            throw ValidationException::withMessages(['foto' => 'Toma la foto con la cámara o elige un archivo JPG o PNG.']);
        }
        [$limpio] = ImagenSegura::recodificar($binario, 'foto', null, 'La foto no es válida: tómala con la cámara o sube un JPG o PNG.');
        $imagen = @imagecreatefromstring($limpio);
        if ($imagen === false) {
            throw ValidationException::withMessages(['foto' => 'La foto no es válida: tómala con la cámara o sube un JPG o PNG.']);
        }
        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];
        $recorteAncho = (int) min($ancho, round($alto * 3 / 4));
        $recorteAlto = (int) min($alto, round($recorteAncho * 4 / 3));
        $final = imagecreatetruecolor(600, 800);
        imagecopyresampled($final, $imagen, 0, 0, (int) (($ancho - $recorteAncho) / 2), (int) (($alto - $recorteAlto) / 2), 600, 800, $recorteAncho, $recorteAlto);
        ob_start();
        imagejpeg($final, null, 85);
        $jpg = (string) ob_get_clean();

        $ruta = 'credenciales/'.$c->empresa_id.'/fotos/'.Str::uuid().'.jpg';
        Storage::disk(self::DISCO)->put($ruta, $jpg);
        $anterior = $c->foto_credencial_ruta;
        $c->forceFill(['foto_credencial_ruta' => $ruta])->save();
        if (is_string($anterior) && str_starts_with($anterior, 'credenciales/')) {
            Storage::disk(self::DISCO)->delete($anterior);
        }
        $this->auditoria->auditar($actor, 'colaboradores.foto_credencial', $c, ['foto' => $anterior !== null], ['foto' => true]);
    }

    /** La foto como data URI (para la vista previa, la impresión y la verificación pública), o null. */
    public static function fotoDataUri(?Colaborador $c): ?string
    {
        $ruta = $c?->foto_credencial_ruta;
        if (! is_string($ruta) || ! str_starts_with($ruta, 'credenciales/') || ! Storage::disk(self::DISCO)->exists($ruta)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) Storage::disk(self::DISCO)->get($ruta));
    }

    /**
     * Renglones de la lista: un colaborador (personal activo, del alcance)
     * con su última credencial y en qué grupo cae.
     *
     * @return Collection<int, array{colaborador: Colaborador, credencial: ?Credencial, grupo: string}>
     */
    public function lista(User $actor, string $filtro, string $texto, int $avisoDias): Collection
    {
        $consulta = $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->personal()
            ->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
            ->with(['puesto:id,nombre', 'sede:id,nombre']);
        if ($texto !== '') {
            // Nombre normalizado (sin acentos ni mayúsculas, funciona igual en MySQL y SQLite), apellido materno o número
            $comodin = '%'.addcslashes(Colaborador::normalizarNombre($texto, ''), '%_\\').'%';
            $crudo = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
            $consulta->where(fn ($q) => $q->where('colaboradores.nombre_normalizado', 'like', $comodin)
                ->orWhereRaw('LOWER(colaboradores.apellido_materno) LIKE ?', [$crudo])
                ->orWhere('colaboradores.num_empleado', 'like', $crudo));
        }
        $colaboradores = $consulta->orderBy('colaboradores.nombre')->orderBy('colaboradores.apellido_paterno')->limit(self::LISTA)->get();
        $ultimas = Credencial::whereIn('colaborador_id', $colaboradores->pluck('id'))->orderBy('id')->get()->keyBy('colaborador_id');

        return $colaboradores->map(fn (Colaborador $c) => ['colaborador' => $c, 'credencial' => $cr = $ultimas[$c->id] ?? null, 'grupo' => $this->grupo($cr, $avisoDias)])
            ->filter(fn (array $f) => $filtro === 'todas' || $f['grupo'] === $filtro || ($filtro === 'vigentes' && $f['grupo'] === 'por_vencer'))->values();
    }

    /** @return array<string, int> grupo => cuántos (para las pastillas de la lista) */
    public function conteos(User $actor, int $avisoDias): array
    {
        $grupos = $this->lista($actor, 'todas', '', $avisoDias)->countBy('grupo')->all();
        $grupos['vigentes'] = ($grupos['vigentes'] ?? 0) + ($grupos['por_vencer'] ?? 0);

        return $grupos;
    }

    public function grupo(?Credencial $cr, int $avisoDias): string
    {
        return match (true) {
            $cr === null => 'sin',
            $cr->estado === EstadoActivo::BAJA => 'baja',
            $cr->estado === EstadoActivo::NO_REGRESO => 'no_regreso',
            $cr->vencida() => 'vencidas',
            $cr->porVencer($avisoDias) => 'por_vencer',
            default => 'vigentes',
        };
    }

    /**
     * Credenciales vigentes que se pueden imprimir (en el alcance), con su colaborador.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Credencial>
     */
    public function paraImprimir(User $actor, array $ids): Collection
    {
        return Credencial::query()->whereKey($ids)
            ->whereIn('colaborador_id', $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->select('colaboradores.id'))
            ->with(['colaborador.puesto:id,nombre', 'colaborador.departamento:id,nombre'])->get()
            ->filter(fn (Credencial $cr) => self::esVigente($cr, $cr->colaborador))
            ->sortBy(fn (Credencial $cr) => array_search($cr->id, $ids, true))->values();
    }

    public function registrarImpresion(User $actor, Credencial $cr): void
    {
        $this->auditoria->auditar($actor, 'colaboradores.credencial_impresa', $cr->colaborador ?? $cr, null, ['credencial' => $cr->folio]);
    }

    /** QR (SVG, sin la declaración XML) con la dirección pública de verificación. */
    public static function qrSvg(Credencial $cr): string
    {
        $escritor = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $escritor->writeString(route('credenciales.verificar', (string) $cr->codigo_qr)));
    }

    /** Logo de la empresa (como en Etiquetas QR) o null. */
    public function logo(?Empresa $empresa): ?string
    {
        foreach ([$empresa?->logo_ruta, app(Identidad::class)->get('simbolo')] as $ruta) {
            if (is_string($ruta) && $ruta !== '' && ! str_contains($ruta, '..') && is_file(public_path($ruta))) {
                return asset($ruta);
            }
        }

        return null;
    }

    private function exigirEmitible(?Colaborador $c): void
    {
        if ($c === null || ! $c->activo || $c->fusionado_en_id !== null) {
            throw ValidationException::withMessages(['credencial' => 'Solo se emite credencial a un colaborador activo.']);
        }
        if ($c->provisional) {
            throw ValidationException::withMessages(['credencial' => 'Este colaborador es un alta provisional: Recursos Humanos debe validarlo antes de emitir su credencial.']);
        }
        if ($c->esResidente()) {
            throw ValidationException::withMessages(['credencial' => 'Las credenciales son del personal; los residentes se identifican con su vivienda.']);
        }
    }

    private function bajaSinVoucher(User $actor, Credencial $credencial, string $motivo): void
    {
        $c = Colaborador::find($credencial->colaborador_id);
        $antes = $credencial->estado;
        $credencial->forceFill(['estado' => EstadoActivo::BAJA, 'baja_motivo' => $motivo, 'baja_en' => now()])->save();
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => Credencial::MOTIVOS_BAJA[$motivo] ?? $motivo]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_baja', $c ?? $credencial, ['estado' => $antes], ['estado' => EstadoActivo::BAJA, 'credencial' => $credencial->folio, 'motivo' => $motivo]);
        $this->resolverAviso($credencial);
    }

    /**
     * Recepción común (C-45): OK → DISPONIBLE (la guarda RR. HH.); Dañado →
     * DE BAJA sin cobro (se imprime otra); No regresó → NO REGRESÓ, con aviso
     * en la campana y en Mis pendientes a quien la da de baja.
     */
    public function recibir(User $actor, Credencial $credencial, string $resultado): string
    {
        $c = Colaborador::find($credencial->colaborador_id);
        $permitido = match ($resultado) {
            'ok' => [EstadoActivo::ASIGNADO, EstadoActivo::NO_REGRESO],
            'danado' => [EstadoActivo::ASIGNADO, EstadoActivo::NO_REGRESO],
            'no_regreso' => [EstadoActivo::ASIGNADO],
            default => [],
        };
        if (! in_array($credencial->estado, $permitido, true)) {
            throw ValidationException::withMessages(['credencial' => "La credencial {$credencial->folio} está {$credencial->textoEstado()}: no se puede marcar así."]);
        }
        $antes = $credencial->estado;
        $base = ['sede_id' => $credencial->sede_id, 'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $credencial->folio];

        if ($resultado === 'no_regreso') {
            $credencial->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
            $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'no_regreso', $base);
            $this->auditoria->auditar($actor, 'colaboradores.credencial_no_regreso', $c ?? $credencial, ['estado' => $antes], ['estado' => EstadoActivo::NO_REGRESO, 'credencial' => $credencial->folio]);
            $this->avisarNoRegreso($credencial, $c, $actor);

            return "La credencial {$credencial->folio} quedó «No regresó». Si aparece, recíbela; si no, dala de baja con su voucher.";
        }

        DB::transaction(function () use ($actor, $credencial, $resultado) {
            $credencial->forceFill(['recibida_en' => now(), 'recibida_por' => $actor->id] + ($resultado === 'danado'
                ? ['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'reposicion', 'baja_en' => now()]
                : ['estado' => EstadoActivo::DISPONIBLE]))->save();
        });
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'recepcion', $base + ['resultado' => $resultado]);
        if ($resultado === 'danado') {
            $this->apagarIdentificacion($actor, $c, 'Credencial dañada');
            $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', $base);
        }
        $this->auditoria->auditar($actor, 'colaboradores.credencial_recibida', $c ?? $credencial, ['estado' => $antes], ['estado' => $credencial->estado, 'credencial' => $credencial->folio, 'resultado' => $resultado]);
        $this->resolverAviso($credencial);

        return $resultado === 'danado'
            ? "Credencial {$credencial->folio} recibida dañada: quedó DE BAJA y su código ya no lo identifica. Imprime y dale salida a una nueva."
            : "Credencial {$credencial->folio} recibida: queda DISPONIBLE con Recursos Humanos.";
    }

    /**
     * «Dar de baja» con voucher (extraviada, dañada o robada), con cobro
     * opcional al colaborador. Su tarjeta NFC se quita y su QR cambia: el
     * plástico perdido ya no lo identifica.
     *
     * @param  array<string, mixed>  $entrada  motivo, descripcion, aplica_cobro, monto, firma_modo…
     */
    public function darDeBaja(User $actor, Credencial $credencial, array $entrada): VoucherReposicion
    {
        if ($credencial->estado === EstadoActivo::BAJA) {
            throw ValidationException::withMessages(['motivo' => "La credencial {$credencial->folio} ya está dada de baja."]);
        }
        $c = Colaborador::find($credencial->colaborador_id);
        // El responsable del cobro es el colaborador (no se elige)
        $entrada = ['responsable_tipo' => Receptor::COLABORADOR, 'responsable_id' => $credencial->colaborador_id, 'colaborador_id' => $credencial->colaborador_id]
            + array_intersect_key($entrada, array_flip(['motivo', 'descripcion', 'aplica_cobro', 'monto', 'firma_modo', 'firma_seguridad', 'firma_responsable']));
        $entrada['firma_modo'] ??= 'fisica';
        $datos = $this->vouchers->validar($entrada);
        $antes = $credencial->estado;

        $voucher = $this->vouchers->darDeBaja($actor, $credencial, 'credencial', $credencial->folio.' · '.($c?->nombreCompleto() ?? ''), $datos, function () use ($credencial) {
            $credencial->forceFill(['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'reposicion', 'baja_en' => now()])->save();
        }, 'credencial');
        $credencial->forceFill(['voucher_id' => $voucher->id])->save();

        $this->apagarIdentificacion($actor, $c, 'Baja de su credencial');
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $voucher->folio.' · '.(VoucherReposicion::MOTIVOS[$voucher->motivo] ?? $voucher->motivo)]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_baja', $c ?? $credencial, ['estado' => $antes], ['estado' => EstadoActivo::BAJA, 'credencial' => $credencial->folio, 'voucher' => $voucher->folio]);
        $this->resolverAviso($credencial);

        return $voucher;
    }

    /**
     * «Antes de dar de baja» (C-20): al dar de baja al colaborador su
     * credencial vigente se da de baja sola (su etiqueta NFC ya se apaga ahí).
     */
    public function bajaPorColaborador(User $actor, Colaborador $c): ?Credencial
    {
        $credencial = $this->vigenteDe($c);
        if ($credencial === null) {
            return null;
        }
        $antes = $credencial->estado;
        $credencial->forceFill(['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'baja_colaborador', 'baja_en' => now()])->save();
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => 'Baja del colaborador']);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_baja', $c, ['estado' => $antes], ['estado' => EstadoActivo::BAJA, 'credencial' => $credencial->folio, 'motivo' => 'Baja del colaborador']);
        $this->resolverAviso($credencial);

        return $credencial;
    }

    /** Quita la tarjeta NFC y cambia el QR del colaborador (el plástico ya no lo identifica). */
    private function apagarIdentificacion(User $actor, ?Colaborador $c, string $motivo): void
    {
        if ($c === null) {
            return;
        }
        $antes = ['etiqueta_nfc' => $c->etiqueta_nfc];
        $c->forceFill(['etiqueta_nfc' => null, 'codigo_qr' => Str::lower(Str::random(24))])->save();
        $this->auditoria->auditar($actor, 'colaboradores.etiqueta_quitada', $c, $antes, ['etiqueta_nfc' => null, 'codigo_qr' => 'nuevo', 'motivo' => $motivo]);
    }

    private function avisarNoRegreso(Credencial $credencial, ?Colaborador $c, User $actor): void
    {
        $usuarios = app(CampanaActivos::class)->conPermiso((int) $credencial->empresa_id, self::PERMISO, $credencial->sede_id)
            ->reject(fn (int $id) => $id === (int) $actor->id);
        app(CentroNotificaciones::class)->avisar((int) $credencial->empresa_id, $usuarios, CustodiaActivos::TIPO_AVISO, [
            'titulo' => "No regresó: credencial {$credencial->folio}",
            'texto' => 'Es de '.($c?->nombreCompleto() ?? 'un colaborador').'; la marcó '.$actor->name.'. Si aparece, recíbela; si no, dala de baja con su voucher.',
            'url' => route('colaboradores.expediente', $credencial->colaborador_id).'#codigo',
            'referencia_tipo' => CustodiaActivos::TIPO_AVISO.'_credencial',
            'referencia_id' => (int) $credencial->id,
            'acciones' => [['etiqueta' => 'Dar de baja', 'abrir' => true]],
        ]);
    }

    private function resolverAviso(Credencial $credencial): void
    {
        if (Notificacion::where('referencia_tipo', CustodiaActivos::TIPO_AVISO.'_credencial')->where('referencia_id', $credencial->id)->exists()) {
            app(CentroNotificaciones::class)->resolver(CustodiaActivos::TIPO_AVISO.'_credencial', (int) $credencial->id);
        }
    }
}
