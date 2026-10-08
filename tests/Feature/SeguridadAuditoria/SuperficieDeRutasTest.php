<?php

namespace Tests\Feature\SeguridadAuditoria;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rutas;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Auditoría de autorización: qué queda expuesto sin sesión.
 */
class SuperficieDeRutasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    /**
     * Toda ruta nueva exige sesión salvo el acceso, el aviso de sesión
     * expirada y la revisión de salud del servidor.
     */
    public function test_solo_el_acceso_queda_fuera_de_la_sesion(): void
    {
        $abiertas = collect(Rutas::getRoutes()->getRoutes())
            ->reject(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => implode('|', array_diff($r->methods(), ['HEAD'])).' /'.ltrim($r->uri(), '/'))
            ->sort()->values()->all();

        // Kiosco de candidatos (ADR-0007): enlace temporal de un solo candidato, con límite de peticiones
        // Bolsa de trabajo (lección 36): solo empresas que la encienden, vacantes publicadas, límite de peticiones y campo trampa
        $this->assertSame(['GET /empleos/{empresa}', 'GET /empleos/{empresa}/gracias', 'GET /empleos/{empresa}/{vacante}', 'GET /empleos/{empresa}/{vacante}/postular',
            'GET /k', 'GET /k/{token}', 'GET /login', 'GET /sesion/expirada', 'GET /up', 'POST /empleos/{empresa}/{vacante}/postular', 'POST /k', 'POST /k/{token}',
            'POST /login', 'POST /sesion/expirada'], $abiertas);
    }

    /**
     * AZ-05: el disco privado (firmas) no se publica en /storage/{ruta}
     * (Laravel lo sirve con URL firmada si "serve" está activo).
     */
    public function test_az05_el_disco_privado_no_tiene_ruta_publica(): void
    {
        $this->assertFalse(Rutas::has('storage.local'));
        $this->assertFalse(Rutas::has('storage.local.upload'));

        $this->sembrarCatalogo();
        $admin = $this->crearUsuario($this->crearEmpresa(), 'Administrador');
        Storage::fake('local');
        Storage::disk('local')->put('firmas/1/responsivas/2026/10/firma.png', 'x');

        $this->get('/storage/firmas/1/responsivas/2026/10/firma.png')->assertNotFound();
        $this->actingAs($admin)->get('/storage/firmas/1/responsivas/2026/10/firma.png')->assertNotFound();
        $this->put('/storage/firmas/1/x.png', [])->assertNotFound();
    }
}
