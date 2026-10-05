<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resguardo (lote) de equipos de seguridad a cargo de un colaborador, con su
 * firma de conformidad (SEGCAT: responsivas_equipos, donde el "lote" era el
 * mismo colaborador con la misma fecha exacta). Folio "CENRES-000001".
 *
 * Estados: en_campo → devuelta ("Recibir Lote Completo (OK)").
 */
class Responsiva extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'responsivas';

    public const EN_CAMPO = 'en_campo';

    public const DEVUELTA = 'devuelta';

    protected $attributes = ['estado' => self::EN_CAMPO];

    protected $fillable = ['empresa_id', 'sede_id', 'colaborador_id'];

    /** La ruta de la firma nunca sale en JSON ni en la auditoría. */
    protected $hidden = ['firma_ruta'];

    protected function casts(): array
    {
        return ['entregado_en' => 'datetime', 'devuelto_en' => 'datetime', 'numero' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(EquipoResponsiva::class)->orderBy('id');
    }

    public function entrego(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    public function recibio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    public function enCampo(): bool
    {
        return $this->estado === self::EN_CAMPO;
    }

    /**
     * Folio como en SEGCAT (generarFolioResguardo): 3 letras de la sede +
     * "RES-" + 6 dígitos. Las letras salen del código de la sede (CEN, PLA…)
     * o, si no tiene, de su nombre sin artículos.
     */
    public static function folioPara(Sede $sede, int $numero): string
    {
        $base = $sede->codigo ?: (string) preg_replace('/\b(DE|DEL|EL|LA|LOS|LAS|HOTEL)\b/u', '', mb_strtoupper((string) $sede->nombre));
        $sinAcentos = strtr(mb_strtoupper($base), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $letras = substr((string) preg_replace('/[^A-Z0-9]/', '', $sinAcentos), 0, 3);

        return ($letras ?: 'SED').'RES-'.str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
