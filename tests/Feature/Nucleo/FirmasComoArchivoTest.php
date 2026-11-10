<?php

namespace Tests\Feature\Nucleo;

use App\Http\Middleware\FirmasComoArchivo;
use App\Support\RegresoTrasEnvio;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\Feature\EnviaFirmasComoArchivo;
use Tests\TestCase;

/**
 * Firmas enviadas como archivo (App\Http\Middleware\FirmasComoArchivo):
 * vuelven a su campo original como `data:image/...;base64,...` antes de los
 * controladores; lo que no es imagen PNG/JPEG de hasta 1 MB no se inyecta.
 * Y el aviso «envío incompleto» de App\Support\RegresoTrasEnvio.
 */
class FirmasComoArchivoTest extends TestCase
{
    use EnviaFirmasComoArchivo;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post('/prueba-firmas-como-archivo', fn (Request $r) => response()->json([
            'entrada' => $r->all(), 'archivos' => array_keys($r->allFiles()), 'firma' => $r->input('firma'), 'anidada' => $r->input('firmas.f_afectado'),
        ]));
    }

    private static function imagen(string $tipo = 'png'): string
    {
        $img = imagecreatetruecolor(60, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        $tipo === 'png' ? imagepng($img) : imagejpeg($img);

        return (string) ob_get_clean();
    }

    public function test_reinyecta_cada_firma_en_su_campo_y_quita_la_entrada_interna(): void
    {
        $png = self::imagen('png');
        $jpg = self::imagen('jpeg');
        $r = $this->post('/prueba-firmas-como-archivo', [
            'nombre' => 'Ana', 'firma' => '', 'firmas' => ['f_afectado' => ''],
            '__firmas' => [UploadedFile::fake()->createWithContent('a.png', $png), UploadedFile::fake()->createWithContent('b.jpg', $jpg)],
            '__firmas_campos' => ['firma', 'firmas[f_afectado]'],
        ])->assertOk();

        $this->assertSame('data:image/png;base64,'.base64_encode($png), $r->json('firma'));
        $this->assertSame('data:image/jpeg;base64,'.base64_encode($jpg), $r->json('anidada'));
        $this->assertSame('Ana', $r->json('entrada.nombre'));
        $this->assertSame([], $r->json('archivos'));
        $this->assertArrayNotHasKey('__firmas', $r->json('entrada'));
        $this->assertArrayNotHasKey('__firmas_campos', $r->json('entrada'));
    }

    public function test_lo_que_no_es_imagen_valida_no_se_inyecta(): void
    {
        $casos = [
            'texto' => UploadedFile::fake()->createWithContent('firma.png', '<?php system($_GET[1]);'),
            'gif' => UploadedFile::fake()->createWithContent('firma.png', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            'grande' => UploadedFile::fake()->createWithContent('firma.png', self::imagen().str_repeat("\0", FirmasComoArchivo::MAXIMO_BYTES)),
        ];
        foreach ($casos as $caso => $archivo) {
            $r = $this->post('/prueba-firmas-como-archivo', ['firma' => '', '__firmas' => [$archivo], '__firmas_campos' => ['firma']])->assertOk();
            $this->assertNull($r->json('firma'), "Caso {$caso}");
            $this->assertSame([], $r->json('archivos'), "Caso {$caso}");
        }

        // Nombres de campo raros o internos se ignoran
        foreach (['_token', '_method', 'a[]', '../firma', 'a b', ''] as $campo) {
            $r = $this->post('/prueba-firmas-como-archivo', ['__firmas' => [UploadedFile::fake()->createWithContent('f.png', self::imagen())], '__firmas_campos' => [$campo]])->assertOk();
            $this->assertSame([], array_filter((array) $r->json('entrada'), fn ($v) => is_string($v) && str_starts_with($v, 'data:')), "Campo {$campo}");
        }
    }

    public function test_el_envio_de_siempre_con_el_texto_de_la_firma_sigue_igual(): void
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode(self::imagen());
        $this->post('/prueba-firmas-como-archivo', ['firma' => $dataUrl])->assertOk()->assertJsonPath('firma', $dataUrl);

        // El ayudante de las pruebas arma lo mismo que el navegador
        $datos = $this->firmasComoArchivo(['firma' => $dataUrl, 'firmas' => ['f_afectado' => $dataUrl]]);
        $this->assertSame(['firma', 'firmas[f_afectado]'], $datos['__firmas_campos']);
        $this->post('/prueba-firmas-como-archivo', $datos)->assertOk()->assertJsonPath('firma', $dataUrl)->assertJsonPath('anidada', $dataUrl);
    }

    public function test_aviso_de_envio_incompleto_respeta_el_ancla(): void
    {
        $this->assertSame('https://x.test/a/1/leer?envio=incompleto#acuse', RegresoTrasEnvio::conAviso('https://x.test/a/1/leer#acuse'));
        $this->assertSame('https://x.test/a?abrir=2&envio=incompleto', RegresoTrasEnvio::conAviso('https://x.test/a?abrir=2'));
        $this->assertSame('https://x.test/a?envio=incompleto', RegresoTrasEnvio::conAviso('https://x.test/a'));
    }
}
