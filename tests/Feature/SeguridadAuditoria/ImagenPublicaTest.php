<?php

namespace Tests\Feature\SeguridadAuditoria;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Respaldo de /storage para logos e identidad cuando el hospedaje no sigue el enlace public/storage.
 */
class ImagenPublicaTest extends TestCase
{
    private const NOMBRE = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCd';

    public function test_entrega_el_logo_de_una_empresa_sin_sesion_y_con_encabezados_seguros(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('empresas/logos/'.self::NOMBRE.'.jpg', 'jpg');
        Storage::disk('public')->put('identidad/'.self::NOMBRE.'.png', 'png');

        $r = $this->get('/storage/empresas/logos/'.self::NOMBRE.'.jpg')->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
        $this->assertEmpty($r->headers->getCookies());

        $this->get('/storage/identidad/'.self::NOMBRE.'.png')->assertOk();
    }

    public function test_no_entrega_otras_carpetas_ni_nombres_ni_archivos_inexistentes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reservas/areas/'.self::NOMBRE.'.jpg', 'x');
        Storage::disk('public')->put('empresas/logos/logo.jpg', 'x');
        Storage::disk('public')->put('empresas/logos/'.self::NOMBRE.'.php', 'x');

        $this->get('/storage/reservas/areas/'.self::NOMBRE.'.jpg')->assertNotFound();
        $this->get('/storage/empresas/logos/logo.jpg')->assertNotFound();
        $this->get('/storage/empresas/logos/'.self::NOMBRE.'.php')->assertNotFound();
        $this->get('/storage/empresas/logos/../../.env')->assertNotFound();
        $this->get('/storage/empresas/logos/'.substr(self::NOMBRE, 0, 39).'Z.jpg')->assertNotFound();
    }
}
