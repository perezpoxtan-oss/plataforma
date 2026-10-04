<?php

namespace Tests\Feature\Nucleo;

use App\Models\Sede;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    public function test_cada_empresa_solo_ve_sus_sedes(): void
    {
        $a = $this->crearEmpresa('Empresa A');
        $b = $this->crearEmpresa('Empresa B');
        $this->crearSede($a, 'A1');
        $this->crearSede($a, 'A2');
        $this->crearSede($b, 'B1');

        $tenant = app(Tenant::class);

        $this->assertSame(['A1', 'A2'], $tenant->conEmpresa($a->id, fn () => Sede::orderBy('codigo')->pluck('codigo')->all()));
        $this->assertSame(['B1'], $tenant->conEmpresa($b->id, fn () => Sede::pluck('codigo')->all()));
    }

    public function test_al_crear_se_asigna_la_empresa_activa(): void
    {
        $a = $this->crearEmpresa();
        $sede = $this->crearSede($a, 'X1');

        $this->assertSame($a->id, (int) $sede->empresa_id);
    }

    public function test_no_se_puede_guardar_un_registro_de_otra_empresa(): void
    {
        $a = $this->crearEmpresa('Empresa A');
        $b = $this->crearEmpresa('Empresa B');

        $this->expectException(\DomainException::class);

        app(Tenant::class)->conEmpresa($a->id, fn () => Sede::create([
            'empresa_id' => $b->id, 'codigo' => 'Z1', 'nombre' => 'Intrusa',
        ]));
    }

    public function test_sin_empresa_activa_no_se_filtra(): void
    {
        $this->crearSede($this->crearEmpresa('Empresa A'), 'A1');
        $this->crearSede($this->crearEmpresa('Empresa B'), 'B1');

        $this->assertSame(2, Sede::count());
    }
}
