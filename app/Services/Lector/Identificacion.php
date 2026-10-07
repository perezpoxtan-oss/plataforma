<?php

namespace App\Services\Lector;

use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Etiqueta;
use App\Support\Lector\Identificable;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ronda 5 (LL-05 / VE-04): diálogo "Código e identificación" común a todas
 * las fichas con QR (vehículos, llaves, gafetes, equipos, equipos de
 * Protección Civil, colaboradores, Lost & Found): el QR se dibuja en el
 * servidor (sin servicios externos) y la etiqueta NFC/RFID se asigna con el
 * lector universal en modo "capturar".
 *
 * Funciona con cualquier tipo registrado en config/lector.php. Debe correr
 * con la empresa de trabajo ya fijada en el Tenant.
 */
class Identificacion
{
    public function __construct(
        private readonly Lector $lector,
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * @return class-string<Model&Identificable>
     */
    public function clase(string $tipo): string
    {
        $clase = $this->lector->tipos()[$tipo] ?? null;
        abort_if($clase === null, 404);

        return $clase;
    }

    /** Módulo de permisos del tipo ("llaves", "vehiculos"…). */
    public function modulo(string $tipo): string
    {
        return Str::before($this->clase($tipo)::permisoLector(), '.');
    }

    /**
     * Registro visible con el permiso ($accion = ver | editar) del módulo:
     * de la empresa de trabajo, en las sedes del permiso y, con alcance
     * "propios", solo los que dio de alta. Si no, 404.
     */
    public function buscar(User $actor, string $tipo, int $id, string $accion): Model&Identificable
    {
        $clase = $this->clase($tipo);
        $permiso = $this->modulo($tipo).'.'.$accion;
        abort_unless($actor->can($permiso), 403);

        /** @var (Model&Identificable)|null $registro */
        $registro = $clase::query()->find($id);
        abort_if($registro === null, 404);

        if (! $actor->es_superadmin) {
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
            $sede = $registro->resumenLector()['sede_id'] ?? null;
            abort_if($sedes !== null && $sede !== null && ! in_array((int) $sede, $sedes, true), 404);

            $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
            $autor = $registro->getAttribute('creado_por');
            abort_if($efectivo?->alcance === Alcance::Propios && $autor !== null && (int) $autor !== $actor->id, 404);
        }

        return $registro;
    }

    /** QR (SVG) con la dirección /e/{código}: no lleva datos del registro. */
    public function qrSvg(Model&Identificable $registro, int $tamano = 220): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd)))
            ->writeString(route('lector.ir', $registro->getAttribute('codigo_qr')));
    }

    /**
     * Asigna (o quita, con vacío) la etiqueta NFC/RFID. No puede estar en
     * otro registro de la empresa, de ningún tipo: el lector no sabría cuál
     * abrir.
     */
    public function asignarEtiqueta(User $actor, string $tipo, Model&Identificable $registro, ?string $valor): ?string
    {
        if ($valor !== null && mb_strlen($valor) > Etiqueta::MAXIMO) {
            throw ValidationException::withMessages(['etiqueta_nfc' => 'Lo leído es demasiado largo para ser una etiqueta NFC/RFID.']);
        }
        $limpia = Etiqueta::normalizar($valor);
        $nueva = $limpia === '' ? null : $limpia;

        if ($nueva !== null) {
            foreach ($this->lector->tipos() as $otroTipo => $clase) {
                $ocupada = $clase::etiquetaOcupada($nueva, $clase === $registro::class ? (int) $registro->getKey() : null);
                if ($ocupada !== null) {
                    $quien = $ocupada->resumenLector()['titulo'] ?? '';
                    throw ValidationException::withMessages(['etiqueta_nfc' => 'Esa tarjeta o etiqueta NFC/RFID ya está asignada a '
                        .(self::NOMBRES[$otroTipo] ?? 'el registro')." «{$quien}». Quítala de ahí primero o usa otra."]);
                }
            }
        }

        $antes = $registro->getAttribute('etiqueta_nfc');
        if ($antes === $nueva) {
            return $nueva;
        }
        $registro->forceFill(['etiqueta_nfc' => $nueva])->save();
        $this->auditoria->auditar($actor, $this->modulo($tipo).($nueva === null ? '.etiqueta_quitada' : '.etiqueta_asignada'), $registro,
            ['etiqueta_nfc' => $antes], ['etiqueta_nfc' => $nueva]);

        return $nueva;
    }

    /** Para los mensajes: "la llave «HDC-101»", "el vehículo «ABC123»"… */
    public const NOMBRES = [
        'colaborador' => 'el colaborador',
        'vehiculo' => 'el vehículo',
        'llave' => 'la llave',
        'gafete' => 'el gafete',
        'equipo' => 'el equipo',
        'lost_found' => 'el artículo de Lost & Found',
        'equipo_pc' => 'el equipo de Protección Civil',
    ];
}
