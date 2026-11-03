<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migración 2026_11_21_000200_equipos_de_acceso: con datos, migrar →
 * revertir → migrar deja el esquema igual y las entradas que registraron
 * los equipos se conservan (sin su equipo).
 */
class MigracionEquiposAccesoTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_21_000200_equipos_de_acceso.php');
    }

    /** @return array<string, mixed> */
    private function esquema(): array
    {
        $r = ['accesos' => Schema::getColumnListing('accesos')];
        foreach (['equipos_acceso', 'equipos_acceso_comandos', 'equipos_acceso_marcajes'] as $tabla) {
            $r[$tabla] = Schema::hasTable($tabla) ? Schema::getColumnListing($tabla) : null;
        }

        return $r;
    }

    public function test_migrar_revertir_migrar_con_datos(): void
    {
        $this->prepararEmpresa();
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertOk();
        $original = $this->esquema();

        $this->migracion()->down();
        $this->assertFalse(Schema::hasTable('equipos_acceso'));
        $this->assertFalse(Schema::hasColumn('accesos', 'equipo_entrada_id'));
        $this->assertSame(1, $this->enEmpresa(fn () => Acceso::where('colaborador_id', $juan->id)->count()), 'La entrada y salida se conservan');

        $this->migracion()->up();
        $this->assertSame($original, $this->esquema());
        $this->assertNull($this->enEmpresa(fn () => Acceso::where('colaborador_id', $juan->id)->value('equipo_entrada_id')));
    }
}
