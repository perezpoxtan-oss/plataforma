<?php

namespace Tests\Unit;

use App\Services\Permisos\Alcance;
use PHPUnit\Framework\TestCase;

class AlcanceTest extends TestCase
{
    public function test_orden_de_alcances(): void
    {
        $this->assertTrue(Alcance::Empresa->cubre(Alcance::Sede));
        $this->assertTrue(Alcance::Sede->cubre(Alcance::Propios));
        $this->assertFalse(Alcance::Propios->cubre(Alcance::Sede));
        $this->assertSame(Alcance::Empresa, Alcance::mayor(Alcance::Sede, Alcance::Empresa));
    }
}
