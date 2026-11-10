<?php

namespace App\Support;

use App\Models\Empresa;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * C-44 (Etapa 8): ajustes de credencialización por empresa, en
 * empresas.preferencias['credenciales']. Sin configurar, la credencial no
 * vence, solo lleva QR y el reverso dice de quién es (propuesta aprobada el
 * 12 oct; lo que el dueño aún no decide queda aquí con un valor prudente).
 *
 *  - vigencia: sin (no vence) | meses (vigencia_meses desde que se emite) |
 *    anual (vence cada año en vigencia_fecha, MM-DD). «Hasta fin de
 *    contrato» queda pendiente: el colaborador no guarda esa fecha.
 *  - aviso_dias: cuántos días antes de vencer aparece en Mis pendientes.
 *  - tarjetas: además del QR (siempre), qué tarjetas usa la empresa en el
 *    plástico (decisión del dueño, 3 nov): proximidad_125 (125 kHz) y/o
 *    mifare_1356 (MIFARE 13.56 MHz). De fábrica ninguna: solo QR y al dar
 *    salida no se pide número de tarjeta. Las dos guardan el número en la
 *    etiqueta NFC/RFID del colaborador y el lector universal las lee igual;
 *    cambia el lector que hace falta (TARJETAS[...]['lector']).
 *  - reverso_texto / telefono: el reverso de la credencial.
 */
final class AjustesCredenciales
{
    public const CLAVE = 'credenciales';

    public const VIGENCIAS = [
        'sin' => 'Sin vencimiento (de fábrica)',
        'meses' => 'Vence a los meses que elijas desde que se emite',
        'anual' => 'Vence cada año en la misma fecha',
    ];

    /** El QR va siempre; se lee con la cámara de cualquier celular o con un lector de QR. */
    public const LECTOR_QR = 'La cámara de cualquier celular o un lector de códigos QR';

    /** Tarjetas que cada empresa puede habilitar además del QR (pueden convivir). */
    public const TARJETAS = [
        'proximidad_125' => [
            'nombre' => 'Proximidad 125 kHz',
            'lector' => 'Lector USB de proximidad 125 kHz (escribe el número como un teclado)',
        ],
        'mifare_1356' => [
            'nombre' => 'MIFARE 13.56 MHz',
            'lector' => 'NFC del celular Android o lector USB de 13.56 MHz (MIFARE)',
        ],
    ];

    public const OMISION = [
        'vigencia' => 'sin',
        'vigencia_meses' => 12,
        'vigencia_fecha' => '12-31',
        'aviso_dias' => 30,
        'tarjetas' => [],
        'reverso_texto' => '',
        'telefono' => '',
    ];

    /** @return array{vigencia: string, vigencia_meses: int, vigencia_fecha: string, aviso_dias: int, tarjetas: list<string>, reverso_texto: string, telefono: string} */
    public static function de(Empresa|int|null $empresa): array
    {
        if (is_int($empresa)) {
            $empresa = Empresa::find($empresa);
        }
        $g = $empresa?->preferencias[self::CLAVE] ?? [];
        $g = is_array($g) ? $g : [];

        return [
            'vigencia' => isset(self::VIGENCIAS[$g['vigencia'] ?? '']) ? $g['vigencia'] : 'sin',
            'vigencia_meses' => is_numeric($g['vigencia_meses'] ?? null) ? max(1, min(120, (int) $g['vigencia_meses'])) : 12,
            'vigencia_fecha' => is_string($g['vigencia_fecha'] ?? null) && self::fechaValida($g['vigencia_fecha']) ? $g['vigencia_fecha'] : '12-31',
            'aviso_dias' => is_numeric($g['aviso_dias'] ?? null) ? max(0, min(180, (int) $g['aviso_dias'])) : 30,
            'tarjetas' => self::tarjetasGuardadas($g),
            'reverso_texto' => is_string($g['reverso_texto'] ?? null) ? mb_substr($g['reverso_texto'], 0, 300) : '',
            'telefono' => is_string($g['telefono'] ?? null) ? mb_substr($g['telefono'], 0, 30) : '',
        ];
    }

    /**
     * Valida lo que llega del formulario y devuelve solo lo distinto de la omisión.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, int|string>
     */
    public static function validar(array $entrada): array
    {
        $errores = [];
        $v = [];
        $v['vigencia'] = (string) ($entrada['vigencia'] ?? 'sin');
        if (! isset(self::VIGENCIAS[$v['vigencia']])) {
            $errores['vigencia'] = 'Elige una de las opciones de la lista.';
        }
        $meses = $entrada['vigencia_meses'] ?? 12;
        if (! is_numeric($meses) || (int) $meses != $meses || (int) $meses < 1 || (int) $meses > 120) {
            $errores['vigencia_meses'] = 'Escribe un número entero de meses, de 1 a 120.';
        }
        $v['vigencia_meses'] = (int) $meses;
        $v['vigencia_fecha'] = (string) ($entrada['vigencia_fecha'] ?? '12-31');
        if (! self::fechaValida($v['vigencia_fecha'])) {
            $errores['vigencia_fecha'] = 'Escribe el día y el mes como MM-DD, por ejemplo 12-31.';
        }
        $dias = $entrada['aviso_dias'] ?? 30;
        if (! is_numeric($dias) || (int) $dias != $dias || (int) $dias < 0 || (int) $dias > 180) {
            $errores['aviso_dias'] = 'Escribe un número entero de días, de 0 a 180 (0 = sin aviso).';
        }
        $v['aviso_dias'] = (int) $dias;
        // Casillas: llega la lista de las marcadas (un valor vacío = ninguna marcada)
        $tarjetas = $entrada['tarjetas'] ?? [];
        $tarjetas = array_values(array_filter(is_array($tarjetas) ? $tarjetas : [$tarjetas], fn ($t) => $t !== '' && $t !== null));
        if (array_diff($tarjetas, array_keys(self::TARJETAS)) !== []) {
            $errores['tarjetas'] = 'Elige las tarjetas de la lista: proximidad 125 kHz, MIFARE 13.56 MHz o las dos.';
        }
        $v['tarjetas'] = self::ordenar($tarjetas);
        $v['reverso_texto'] = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($entrada['reverso_texto'] ?? '')));
        if (mb_strlen($v['reverso_texto']) > 300) {
            $errores['reverso_texto'] = 'El texto del reverso es muy largo (máximo 300 caracteres).';
        }
        $v['telefono'] = trim(Entrada::texto($entrada['telefono'] ?? ''));
        if (mb_strlen($v['telefono']) > 30) {
            $errores['telefono'] = 'El teléfono es muy largo (máximo 30 caracteres).';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return array_filter($v, fn ($valor, $k) => $valor !== self::OMISION[$k], ARRAY_FILTER_USE_BOTH);
    }

    /** Fecha de vencimiento de una credencial emitida hoy (null = no vence). */
    public static function venceEn(Empresa|int|null $empresa, ?Carbon $hoy = null): ?Carbon
    {
        $a = self::de($empresa);
        $hoy = ($hoy ?? Carbon::now(app(HoraLocal::class)->zona()))->copy()->startOfDay();

        return match ($a['vigencia']) {
            'meses' => $hoy->copy()->addMonthsNoOverflow($a['vigencia_meses']),
            'anual' => (function () use ($a, $hoy) {
                [$mes, $dia] = array_map('intval', explode('-', $a['vigencia_fecha']));
                $fecha = $hoy->copy()->setDate($hoy->year, $mes, min($dia, Carbon::create($hoy->year, $mes, 1)->daysInMonth));

                return $fecha->lt($hoy) ? $fecha->addYearNoOverflow() : $fecha;
            })(),
            default => null,
        };
    }

    /** Nombre de la tarjeta (o null si no es una de la lista). */
    public static function nombreTarjeta(?string $tipo): ?string
    {
        return $tipo === null ? null : (self::TARJETAS[$tipo]['nombre'] ?? null);
    }

    /** Qué lector necesita la tarjeta (o null si no es una de la lista). */
    public static function lectorTarjeta(?string $tipo): ?string
    {
        return $tipo === null ? null : (self::TARJETAS[$tipo]['lector'] ?? null);
    }

    /**
     * Tarjetas habilitadas en lo guardado. Un ajuste anterior a la decisión
     * del 3 nov («tecnologia = qr_tarjeta», sin decir cuál) cuenta como las
     * dos: el lector universal acepta cualquiera.
     *
     * @param  array<string, mixed>  $g
     * @return list<string>
     */
    private static function tarjetasGuardadas(array $g): array
    {
        if (! array_key_exists('tarjetas', $g)) {
            return ($g['tecnologia'] ?? null) === 'qr_tarjeta' ? array_keys(self::TARJETAS) : [];
        }

        return self::ordenar(is_array($g['tarjetas']) ? $g['tarjetas'] : []);
    }

    /**
     * Solo las de la lista, sin repetir y en el orden de TARJETAS.
     *
     * @param  array<mixed>  $tarjetas
     * @return list<string>
     */
    private static function ordenar(array $tarjetas): array
    {
        return array_values(array_filter(array_keys(self::TARJETAS), fn (string $t) => in_array($t, $tarjetas, true)));
    }

    private static function fechaValida(string $mmdd): bool
    {
        return (bool) preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $mmdd)
            && checkdate((int) substr($mmdd, 0, 2), (int) substr($mmdd, 3, 2), 2024);
    }
}
