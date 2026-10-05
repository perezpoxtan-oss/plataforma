<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Modulo;
use App\Models\Novedad;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Datos y ayudas comunes de las pruebas de la Bitácora de Novedades.
 */
abstract class PruebaNovedades extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected Empresa $empresa;

    protected Sede $centro;

    protected Sede $playa;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->admin->forceFill(['name' => 'Ana Administradora'])->save();
    }

    protected function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    /** @return array<string, mixed> */
    protected function datos(array $extra = []): array
    {
        return array_merge([
            '_dialogo' => 'crear',
            'sede_id' => $this->centro->id,
            'categoria' => 'sin_clasificar',
            'reportado_por' => ' camarista  rosa ',
            'ubicacion' => 'piso 2, cerca del elevador',
            'descripcion' => 'Huésped reporta olor a gas en el pasillo.',
            'ocurrio_en' => '2026-10-01T08:30',
        ], $extra);
    }

    protected function novedad(array $extra = [], ?Empresa $empresa = null, ?User $autor = null): Novedad
    {
        $empresa ??= $this->empresa;

        return $this->enEmpresa(function () use ($extra, $empresa, $autor) {
            $sede = $empresa->is($this->empresa) ? $this->centro : Sede::firstOrCreate(['codigo' => 'OT1'], ['nombre' => 'Sede OT1']);
            $n = new Novedad(array_merge(['sede_id' => $sede->id, 'reportado_por' => 'GUARDIA', 'ubicacion' => 'LOBBY', 'descripcion' => 'Prueba'], array_diff_key($extra, ['estatus' => 1])));
            $n->numero = (int) Novedad::max('numero') + 1;
            $n->estatus = $extra['estatus'] ?? Novedad::ABIERTO;
            $n->creado_por = $autor?->id;
            $n->save();

            return $n;
        }, $empresa);
    }

    /** Datos mínimos del expediente (Guardar Expediente). */
    protected function expediente(Novedad $n, array $extra = []): array
    {
        return array_merge(['_dialogo' => 'expediente-'.$n->id, 'categoria' => $n->categoria, 'estatus' => $n->estatus], $extra);
    }

    protected function espacio(Sede $sede, string $nivel, string $nombre, ?Espacio $padre = null): Espacio
    {
        return $this->enEmpresa(fn () => app(AdministradorEspacios::class)->crear($this->admin, $sede, $padre, $nivel, ['nombre' => $nombre], false));
    }

    protected function buscar(int $id): Novedad
    {
        return $this->enEmpresa(fn () => Novedad::with(['notas'])->findOrFail($id));
    }

    protected function rolSoloLostFound(?Sede $sede = null): User
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Ama de Llaves', 'nivel_jerarquia' => 70]);
        $modulo = Modulo::where('clave', 'lost_found')->firstOrFail();
        foreach ($modulo->moduloAcciones()->with('accion')->get() as $ma) {
            if (in_array($ma->accion->clave, ['ver', 'crear', 'editar', 'imprimir'], true)) {
                RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
            }
        }

        return $this->crearUsuario($this->empresa, 'Ama de Llaves', $sede);
    }

    public static function firmaJpeg(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }
}
