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
        // Olvidé mi contraseña (criterio 6): respuesta uniforme, límite por equipo y por cuenta, enlace firmado de un solo uso
        // Página pública de planes (Catálogo comercial): 404 mientras no esté publicada, límite de peticiones, campo trampa;
        // la ruta de avisos de pago está reservada y apagada (404)
        // Plataforma instalable (app-movil.md): manifest, service worker, «Sin conexión» y App Links (assetlinks.json), sin sesión ni datos de nadie
        // Verificación en dos pasos (dos-pasos.md): el código después de la contraseña, solo con una cuenta pendiente en la sesión, límite por equipo y por cuenta
        // Privacidad (privacidad.md): aviso de cada empresa por su código, formulario ARCO con campo trampa y límites, confirmación con enlace de un solo uso
        $this->assertSame(['GET /.well-known/assetlinks.json', 'GET /aviso-de-privacidad', 'GET /contratar', 'GET /contratar/gracias', 'GET /empleos/{empresa}', 'GET /empleos/{empresa}/gracias', 'GET /empleos/{empresa}/{vacante}', 'GET /empleos/{empresa}/{vacante}/postular',
            'GET /k', 'GET /k/{token}', 'GET /login', 'GET /login/verificacion', 'GET /manifest.webmanifest', 'GET /olvide-contrasena', 'GET /planes',
            'GET /privacidad/solicitud', 'GET /privacidad/verificar/{token}', 'GET /privacidad/{codigo}', 'GET /privacidad/{codigo}/solicitud',
            'GET /restablecer-contrasena/{token}', 'GET /sesion/expirada', 'GET /sin-conexion', 'GET /sw.js', 'GET /up',
            'POST /contratar', 'POST /empleos/{empresa}/{vacante}/postular', 'POST /k', 'POST /k/{token}', 'POST /login', 'POST /login/verificacion', 'POST /login/verificacion/cancelar', 'POST /olvide-contrasena', 'POST /pagos/webhook/{pasarela}',
            'POST /privacidad/solicitud', 'POST /privacidad/verificar/{token}', 'POST /privacidad/{codigo}/solicitud', 'POST /restablecer-contrasena',
            'POST /sesion/expirada'], $abiertas);
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
