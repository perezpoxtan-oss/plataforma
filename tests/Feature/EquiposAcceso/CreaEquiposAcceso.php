<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\EquipoAcceso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Credenciales\AdministradorCredenciales;
use App\Services\EquiposAcceso\AdministradorEquipos;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;

/**
 * Datos y llamadas de equipo para las pruebas de equipos de acceso
 * (decisión 10). Las llamadas usan los fixtures de tests/fixtures/equipos-acceso:
 * peticiones con el formato real de cada protocolo.
 */
trait CreaEquiposAcceso
{
    use CreaDatosNucleo;

    protected Empresa $empresa;

    protected Sede $centro;

    protected Sede $playa;

    protected User $admin;

    /** @var array<int, string> id del equipo => token */
    protected array $tokens = [];

    protected function prepararEmpresa(): void
    {
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Centro');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    protected function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    protected function colaborador(string $num = '1001', ?Sede $sede = null, ?Empresa $empresa = null, string $nombre = 'Juan'): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create([
            'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'López', 'sede_id' => ($sede ?? $this->centro)->id,
        ]), $empresa);
    }

    /**
     * Equipo dado de alta como en la pantalla (con su token), con fecha de
     * alta en el pasado para que los marcajes de los fixtures cuenten.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function equipo(string $marca = 'zkteco', array $datos = [], ?Empresa $empresa = null, ?User $actor = null, ?Sede $sede = null): EquipoAcceso
    {
        $empresa ??= $this->empresa;
        $actor ??= $empresa->is($this->empresa) ? $this->admin : $this->crearUsuario($empresa, 'Administrador');
        $sede ??= $empresa->is($this->empresa) ? $this->centro : Sede::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        [$equipo, $token] = $this->enEmpresa(fn () => app(AdministradorEquipos::class)->crear($actor, $datos + [
            'sede_id' => $sede->id, 'nombre' => $marca === 'zkteco' ? 'Checador personal' : 'Torniquete personal', 'marca' => $marca,
            'numero_serie' => $marca === 'zkteco' ? 'CQZ7232460321' : 'DS-K1T341-0001', 'puerta' => 'Acceso de personal', 'sentido' => 'ambos',
        ]), $empresa);
        $equipo->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        $this->tokens[$equipo->id] = $token;

        return $equipo->refresh();
    }

    /** El equipo llama sin sesión: nadie «registró» lo que haga. */
    protected function sinSesion(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function fixture(string $archivo): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/equipos-acceso/'.$archivo));
    }

    /** Llamada del equipo ZKTeco con el cuerpo de texto del protocolo. */
    protected function zk(string $metodo, string $ruta, array $query, string $cuerpo = '', array $servidor = []): TestResponse
    {
        $this->sinSesion();

        return $this->call($metodo, $ruta.'?'.http_build_query($query), [], [], [], $servidor + ['CONTENT_TYPE' => 'text/plain'], $cuerpo);
    }

    protected function attlog(EquipoAcceso $equipo, string $cuerpo, array $servidor = []): TestResponse
    {
        return $this->zk('POST', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'table' => 'ATTLOG', 'Stamp' => '9999'], $cuerpo, $servidor);
    }

    /** Evento JSON de Hikvision con el token en la ruta. */
    protected function hik(EquipoAcceso $equipo, string $json, ?string $token = null): TestResponse
    {
        $this->sinSesion();

        return $this->call('POST', '/equipos-acceso/hikvision/'.($token ?? $this->tokens[$equipo->id]), [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
    }

    /** Entrega la credencial con tarjeta de 125 kHz (como RR. HH. en la ficha). */
    protected function darCredencial(Colaborador $c, ?string $tarjeta = '0012345678'): Credencial
    {
        $this->empresa->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['credenciales' => ['tarjetas' => ['proximidad_125']]])])->save();
        $this->actingAs($this->admin);

        return $this->enEmpresa(fn () => app(AdministradorCredenciales::class)->darSalida($this->admin, $c->refresh(), $tarjeta, $tarjeta ? 'proximidad_125' : null));
    }

    /** @return Collection<int, Acceso> */
    protected function accesosDe(Colaborador $c)
    {
        return $this->enEmpresa(fn () => Acceso::where('colaborador_id', $c->id)->orderBy('id')->get());
    }
}
