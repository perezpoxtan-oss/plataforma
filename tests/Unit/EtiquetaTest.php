<?php

namespace Tests\Unit;

use App\Support\Lector\Etiqueta;
use PHPUnit\Framework\TestCase;

class EtiquetaTest extends TestCase
{
    public function test_normaliza_numero_de_serie_de_web_nfc(): void
    {
        $this->assertSame('04A23B1C5D8000', Etiqueta::normalizar(' 04:a2:3b:1c:5d:80:00 '));
        $this->assertSame('', Etiqueta::normalizar(null));
    }

    public function test_el_mismo_chip_en_decimal_y_en_hexadecimal_coincide(): void
    {
        // Tarjeta de 125 kHz impresa "0012345678" = 0x00BC614E; algunos lectores invierten los bytes
        $desdeDecimal = Etiqueta::candidatos('0012345678');
        $this->assertContains('00BC614E', $desdeDecimal);
        $this->assertContains('4E61BC00', $desdeDecimal);

        $desdeHex = Etiqueta::candidatos('00:bc:61:4e');
        $this->assertContains('0012345678', $desdeHex);
        $this->assertContains('12345678', $desdeHex);
        $this->assertContains('4E61BC00', $desdeHex);
    }

    public function test_extrae_el_codigo_de_una_direccion_de_la_plataforma(): void
    {
        $this->assertSame('abcd1234efgh5678ijkl9012', Etiqueta::codigoDeUrl('https://qa.vdcp.com.mx/e/abcd1234efgh5678ijkl9012'));
        $this->assertSame('abcd1234efgh5678', Etiqueta::codigoDeUrl('https://qa.vdcp.com.mx/vehiculos/qr/ABCD1234EFGH5678'));
        $this->assertNull(Etiqueta::codigoDeUrl('HDM-CEN-VIS-001'));
    }
}
