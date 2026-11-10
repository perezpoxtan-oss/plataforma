<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Leí y entendí este procedimiento": firma de un usuario sobre una versión
 * publicada. Una por usuario y versión; una versión nueva pide firmar otra vez.
 */
class ProcedimientoAcuse extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'procedimiento_acuses';

    protected $fillable = ['empresa_id', 'version_id', 'user_id', 'colaborador_id', 'nombre', 'firma_ruta', 'ip', 'leido_en'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcedimientoVersion::class, 'version_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
