<?php

namespace App\Services\Comercial;

use App\Models\ConfiguracionPlataforma;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes comerciales de la plataforma (Catálogo comercial → Ajustes):
 * moneda, IVA, leyenda «+ IVA», si la página pública está publicada, textos
 * de la portada, preguntas frecuentes, correos que reciben las solicitudes,
 * texto legal y aviso de privacidad. Viven en configuracion_plataforma
 * (clave «comercial»), como la identidad. Globales: no son de ninguna empresa.
 */
class AjustesComerciales
{
    public const CLAVE = 'comercial';

    public const MONEDAS = ['MXN' => 'Pesos mexicanos (MXN)', 'USD' => 'Dólares (USD)'];

    public const POR_OMISION = [
        'moneda' => 'MXN',
        'iva_porcentaje' => 16.0,
        'leyenda_iva' => '+ IVA',
        'landing_publicada' => false,
        'portada_titulo' => 'Control inteligente de accesos y seguridad para cada sede',
        'portada_subtitulo' => 'La caseta registra, el responsable autoriza desde su celular y la dirección ve todo en un tablero. Paga por sede al mes, sin instalar nada.',
        'beneficios' => [
            'Bitácora de accesos con autorización del departamento en vivo',
            'Incidentes, objetos olvidados y rondas con evidencia',
            'Recursos Humanos: candidatos, vacantes y expediente',
            'Informes por sede listos para imprimir',
        ],
        'preguntas' => [
            ['¿Cómo se cobra?', 'Por sede al mes. Si tu empresa tiene tres hoteles o tres plantas, pagas tres sedes. Los paquetes de pago único se cobran una sola vez.'],
            ['¿Necesito instalar algo?', 'No. Se usa desde el navegador de la computadora, la tableta o el celular. Solo necesitas internet.'],
            ['¿Puedo empezar con un plan y cambiar después?', 'Sí. Puedes sumar sedes, paquetes o cambiar de plan cuando lo necesites; el cambio se refleja en el siguiente mes.'],
            ['¿Los precios incluyen IVA?', 'No. Los precios se muestran antes de IVA; la calculadora te muestra el IVA y el total.'],
            ['¿Cómo se paga?', 'Por ahora te contactamos para confirmar tu plan y te enviamos los datos de pago. Pronto podrás pagar en línea.'],
        ],
        'correos_solicitudes' => [],
        'texto_legal' => 'Precios en la moneda indicada, antes de impuestos. Sujetos a cambio sin previo aviso. La contratación se formaliza con la cotización y el contrato de servicio.',
        'aviso_privacidad' => '',
        'sembrado_en' => null,
    ];

    /** @var array<string, mixed>|null */
    private ?array $valores = null;

    /**
     * @return array<string, mixed>
     */
    public function todos(): array
    {
        if ($this->valores !== null) {
            return $this->valores;
        }
        $guardados = Schema::hasTable('configuracion_plataforma')
            ? (ConfiguracionPlataforma::where('clave', self::CLAVE)->value('valor') ?? [])
            : [];
        $valores = array_merge(self::POR_OMISION, array_intersect_key((array) $guardados, self::POR_OMISION));

        $valores['moneda'] = isset(self::MONEDAS[$valores['moneda']]) ? $valores['moneda'] : 'MXN';
        $valores['iva_porcentaje'] = max(0.0, min(100.0, round((float) $valores['iva_porcentaje'], 2)));
        $valores['landing_publicada'] = (bool) $valores['landing_publicada'];
        $valores['beneficios'] = array_values(array_filter((array) $valores['beneficios'], fn ($b) => is_string($b) && trim($b) !== ''));
        $valores['preguntas'] = array_values(array_filter((array) $valores['preguntas'],
            fn ($p) => is_array($p) && count($p) === 2 && is_string($p[0]) && is_string($p[1])));
        $valores['correos_solicitudes'] = array_values(array_filter((array) $valores['correos_solicitudes'],
            fn ($c) => is_string($c) && filter_var($c, FILTER_VALIDATE_EMAIL) !== false));

        return $this->valores = $valores;
    }

    public function get(string $clave): mixed
    {
        return $this->todos()[$clave] ?? null;
    }

    public function landingPublicada(): bool
    {
        return $this->todos()['landing_publicada'];
    }

    /**
     * IVA en centésimas de punto (16 % = 1600), para calcular con enteros.
     */
    public function ivaCentesimas(): int
    {
        return (int) round($this->todos()['iva_porcentaje'] * 100);
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    public function guardar(array $valores): void
    {
        $permitidos = array_intersect_key($valores, self::POR_OMISION);
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => self::CLAVE]);
        $registro->valor = array_merge((array) ($registro->valor ?? []), $permitidos);
        $registro->save();
        $this->valores = null;
    }

    /**
     * «Pregunta» en la primera línea y la respuesta debajo; una línea en blanco separa cada pregunta.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function preguntasDesdeTexto(string $texto): array
    {
        $bloques = preg_split('/\R\s*\R/u', trim(str_replace("\r", '', $texto))) ?: [];
        $preguntas = [];
        foreach ($bloques as $bloque) {
            $lineas = array_values(array_filter(array_map('trim', explode("\n", $bloque)), fn ($l) => $l !== ''));
            if (count($lineas) >= 2) {
                $preguntas[] = [mb_substr($lineas[0], 0, 200), mb_substr(implode(' ', array_slice($lineas, 1)), 0, 1000)];
            }
        }

        return array_slice($preguntas, 0, 12);
    }

    /** @param  list<array{0: string, 1: string}>  $preguntas */
    public static function preguntasATexto(array $preguntas): string
    {
        return implode("\n\n", array_map(fn ($p) => $p[0]."\n".$p[1], $preguntas));
    }
}
