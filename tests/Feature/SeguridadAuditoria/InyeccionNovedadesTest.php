<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Models\LostFoundDetalle;
use App\Models\NovedadNota;
use Tests\Feature\Seguridad\PruebaNovedades;

/**
 * Auditoría de seguridad 2026-10-06 (inyección y XSS) sobre la Bitácora de
 * Novedades: textos libres del expediente y el enlace de Lost & Found.
 */
class InyeccionNovedadesTest extends PruebaNovedades
{
    private const XSS = '<img src=x onerror=alert(1)>"><svg/onload=alert(2)>{{7*7}}';

    public function test_iny08_el_enlace_externo_de_lost_found_solo_se_pinta_si_es_http(): void
    {
        $n = $this->novedad(['categoria' => 'lost_found']);
        // Dato heredado (p. ej. migrado de SEGCAT) o regresado con errores: no pasó por la validación url:http,https
        $this->enEmpresa(fn () => LostFoundDetalle::create(['novedad_id' => $n->id, 'enlace_externo' => 'javascript:alert(document.cookie)']));

        $pagina = $this->actingAs($this->admin)->get("/novedades?abrir={$n->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('href="javascript:', $pagina);
        $this->assertStringNotContainsString('Abrir en la otra plataforma', $pagina);

        // Al regresar con errores, lo escrito tampoco se convierte en enlace
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['lf_enlace_externo' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('lf_enlace_externo');
        $this->assertStringNotContainsString('href="javascript:', $this->actingAs($this->admin)->get("/novedades?abrir={$n->id}")->getContent());

        $this->enEmpresa(fn () => LostFoundDetalle::where('novedad_id', $n->id)->update(['enlace_externo' => 'https://lostandfound.ejemplo.com/a/1']));
        $this->actingAs($this->admin)->get("/novedades?abrir={$n->id}")->assertSee('href="https://lostandfound.ejemplo.com/a/1"', false);
    }

    public function test_xss_almacenado_en_el_expediente_se_escapa_en_lista_detalle_e_impresion(): void
    {
        $n = $this->novedad(['categoria' => 'incidente_general', 'descripcion' => "Línea 1\n".self::XSS, 'reportado_por' => self::XSS, 'ubicacion' => self::XSS]);
        $this->enEmpresa(fn () => NovedadNota::create(['novedad_id' => $n->id, 'tipo' => 'nota', 'autor_nombre' => self::XSS, 'texto' => self::XSS]));

        foreach (['/novedades', "/novedades?abrir={$n->id}", "/novedades/{$n->id}/imprimir"] as $url) {
            $pagina = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x', $pagina, $url);
            $this->assertStringNotContainsString('<svg/onload', $pagina, $url);
            $this->assertStringNotContainsString('>49<', $pagina, $url);
        }
        $this->actingAs($this->admin)->get("/novedades/{$n->id}/imprimir")->assertSee(e('Línea 1').'<br />', false);
    }
}
