<?php

namespace Tests\Feature\Inventarios;

use App\Services\Firmas\Firmas;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class FirmasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private function firmaJpeg(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_guarda_en_privado_por_empresa_y_solo_la_muestra_a_su_empresa(): void
    {
        Storage::fake('local');
        $this->sembrarCatalogo();
        $uno = $this->crearEmpresa();
        $dos = $this->crearEmpresa('Hotel Dos');
        $firmas = app(Firmas::class);

        $ruta = app(Tenant::class)->conEmpresa($uno->id, fn () => $firmas->guardar($this->firmaJpeg(), 'Responsivas'));
        $this->assertStringStartsWith("firmas/{$uno->id}/responsivas/", $ruta);
        Storage::disk('local')->assertExists($ruta);

        $respuesta = app(Tenant::class)->conEmpresa($uno->id, fn () => $firmas->respuesta($ruta));
        $this->assertSame(200, $respuesta->getStatusCode());

        $this->expectException(NotFoundHttpException::class);
        app(Tenant::class)->conEmpresa($dos->id, fn () => $firmas->respuesta($ruta));
    }

    public function test_rechaza_vacia_falsa_o_enorme(): void
    {
        Storage::fake('local');
        $firmas = app(Firmas::class);
        $this->assertFalse($firmas->viene(''));
        $this->assertFalse($firmas->viene('data:image/svg+xml;base64,'.base64_encode(str_repeat('<svg/>', 50))));

        foreach ([null, 'data:image/jpeg;base64,'.base64_encode(str_repeat('no es imagen', 50))] as $mala) {
            try {
                $firmas->guardar($mala, 'x', 'firma_guardia', 'la firma del guardia');
                $this->fail('Debió rechazarse');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('firma_guardia', $e->errors());
            }
        }
    }
}
