<?php

namespace Tests;

use App\Support\TemasConfiguracion;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configuración en mosaico: el mosaico más cada tema que el usuario puede
     * abrir, en una sola respuesta. Sirve para revisar que algo aparece (o no
     * aparece) en ningún lugar de la Configuración; para un ajuste concreto,
     * mejor abrir su tema (/configuracion/{tema}).
     */
    protected function configuracionCompleta(): TestResponse
    {
        $html = $this->get('/configuracion')->assertOk()->getContent();
        foreach (array_keys(TemasConfiguracion::TEMAS) as $tema) {
            $respuesta = $this->get('/configuracion/'.$tema);
            if ($respuesta->isOk()) {
                $html .= $respuesta->getContent();
            }
        }

        return TestResponse::fromBaseResponse(new Response($html));
    }
}
