<?php

namespace Tests\Feature\Nucleo;

use App\Models\Autorizacion;
use App\Models\PaseSalida;
use App\Models\Vacante;
use Tests\TestCase;

/**
 * Etapa 7 · C-40: inventario automático de los botones de enviar de todas
 * las vistas Blade contra los diez verbos (docs/criterios-ux.md, regla 4).
 * Falla si aparece un botón nuevo cuyo texto no empieza con uno de los diez
 * verbos, con un verbo ya aceptado o con una acción de la lista de exclusión.
 *
 * La lista de exclusión (EXCLUIDOS) es de acciones que NO son de negocio:
 * moverse, buscar o filtrar, entrar o salir de la sesión, contestar una
 * pregunta del diálogo («Sí, es la misma»), herramientas de la plataforma y
 * de configuración, y formularios que llena alguien de fuera (kiosco, bolsa
 * de trabajo, solicitud ARCO). Agregar algo aquí requiere la aprobación del
 * dueño (docs/criterios-ux.md, «Cómo se aplica»).
 *
 * Accidentes queda fuera: su formato no se toca (DD-16).
 */
class DiezVerbosTest extends TestCase
{
    public const DIEZ_VERBOS = ['Guardar', 'Dar de baja', 'Reactivar', 'Imprimir', 'Dar salida', 'Recibir', 'Aprobar', 'Rechazar', 'Atender', 'Canalizar'];

    /** Verbos ya aceptados fuera de los diez (CHANGELOG y DD-20). */
    public const ACEPTADOS = ['No regresó', 'Restringir acceso', 'Quitar restricción', 'Liberar lugar', 'Canalizar al supervisor', 'Delegar',
        'Aprobar por excepción', 'Aprobar · es el mismo'];

    /** Texto exacto (o inicio, si termina en «…») => por qué no es un verbo de acción de negocio. */
    public const EXCLUIDOS = [
        // Moverse, buscar y filtrar
        'Buscar' => 'filtro', 'Filtrar' => 'filtro', 'Aplicar' => 'filtro', 'Aplicar Filtros' => 'filtro', 'Ver' => 'navegación', 'Ver día' => 'navegación',
        'Abrir punto' => 'navegación (abre el punto del recorrido)', 'Cancelar' => 'cierra un diálogo', 'Cambiar' => 'cambia la empresa de trabajo',
        'Continuar' => 'kiosco: sigue al formulario', 'Marcar todas como leídas' => 'campana',
        // Exportar
        'Con datos personales' => 'exportar', 'Exportar datos' => 'exportar (traslado)',
        // Sesión y cuenta
        'Entrar' => 'sesión', 'Cerrar Sesión' => 'sesión', 'Volver a entrar con otra cuenta' => 'sesión', 'Enviarme el enlace' => 'recuperar la cuenta',
        'Pedir a mi administrador' => 'recuperar la cuenta', 'Perdí mi teléfono: pedir ayuda' => 'recuperar la cuenta',
        'Restablecer códigos de respaldo' => 'dos pasos',
        // Respuestas a una pregunta del diálogo
        'Sí, es la misma' => 'respuesta', 'No, es otra' => 'respuesta', 'No, es alguien distinto' => 'respuesta', 'Enviar de todos modos' => 'confirma un aviso (C-21)',
        // Formularios que llena alguien de fuera
        'Enviar mi solicitud' => 'kiosco', 'Enviar mi postulación' => 'bolsa de trabajo', 'Enviar solicitud' => 'ARCO pública', 'Confirmar mi solicitud' => 'ARCO pública',
        // Herramientas de la plataforma y de configuración
        'Enviar prueba' => 'correo de prueba', 'Respaldar ahora' => 'respaldo', 'Generar paquete de traslado' => 'traslado', 'Verificar' => 'traslado',
        'Verificar paquete' => 'traslado', 'Revisar archivo' => 'importar: vista previa', 'Cargar servicios sugeridos de hotel' => 'asistente de Reservas',
        'Copiar Pisos' => 'asistente de Zonas', 'Generar QR' => 'kiosco', 'QR para que llene su solicitud' => 'kiosco', 'Enviar enlace por correo' => 'kiosco',
        'Mostrar en Inicio' => 'preferencia', 'Ocultar de Inicio' => 'preferencia', 'Ya terminé' => 'asistente de arranque',
        'Agregar a mi sede' => 'lista de la sede (Empresas externas)', 'Quitar de mi sede' => 'lista de la sede (Empresas externas)',
        'Restablecer…' => 'regresa al valor original', 'Omitir paso' => 'circuito de pases (administrador)',
        // Borrado definitivo (distinto de «Dar de baja»; ver docs/usuario/eliminar-definitivamente.md)
        'Eliminar…' => 'borrado definitivo', 'Borrar mi firma guardada' => 'borrado de un archivo propio',
        // Credencialización (C-44, propuesta aprobada por el dueño el 12 oct; docs/tecnico/credenciales.md): emitir crea la credencial
        // que RR. HH. imprime; reponer da de baja la vigente y emite otra en un paso (la anterior deja de valer al instante)
        'Emitir credencial' => 'credencialización (propuesta aprobada)', 'Reponer credencial' => 'credencialización (propuesta aprobada)',
        // Estado del candidato (como «No regresó»)
        'No se presentó' => 'estado del candidato (ADR-0009)',
    ];

    /** @return list<array{0: string, 1: string}> [texto, vista] */
    public static function inventario(): array
    {
        $botones = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $archivo) {
            $ruta = $archivo->getPathname();
            if (! str_ends_with($ruta, '.blade.php') || str_contains($ruta, 'formatos/accidente') || str_contains($ruta, '_fila-testigo-accidente')) {
                continue;
            }
            $vista = str_replace(resource_path('views').'/', '', $ruta);
            $s = (string) file_get_contents($ruta);
            $s = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $s);
            // «->» y «=>» de PHP no cierran la etiqueta
            $s = str_replace(['->', '=>', ' > ', ' >= '], ['→', '⇒', ' ＞ ', ' ≥ '], $s);
            preg_match_all('/<button\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/button>/s', $s, $m, PREG_SET_ORDER);
            foreach ($m as [, $atributos, $cuerpo]) {
                if (preg_match('/\btype="(button|reset)"/', $atributos)) {
                    continue;
                }
                foreach (self::textos($cuerpo) as $texto) {
                    $botones[] = [$texto, $vista];
                }
            }
        }

        return $botones;
    }

    /** Textos posibles de un botón: lo escrito antes de la primera expresión o, si empieza con una, sus textos entre comillas. */
    private static function textos(string $cuerpo): array
    {
        $cuerpo = (string) preg_replace(['/<[^>]+>/', '/@(if|elseif|else|endif|isset|endisset|unless|endunless|can|endcan)\b(\s*\((?:[^()]|\([^()]*\))*\))?/'], ' ', $cuerpo);
        $cuerpo = trim((string) preg_replace('/\s+/', ' ', $cuerpo));
        if ($cuerpo === '') {
            return [];
        }
        $antes = trim((string) strstr($cuerpo, '{{', true) ?: (str_contains($cuerpo, '{{') ? '' : $cuerpo));
        if ($antes !== '') {
            return [$antes];
        }
        preg_match('/\{\{(.*?)\}\}/s', $cuerpo, $exp);
        preg_match_all("/'([^']*\\pL[^']*)'/u", $exp[1] ?? '', $literales);

        // Fuera las claves de código ('huesped', 'publicada') y las clases
        return array_values(array_filter(array_map('trim', $literales[1] ?? []), fn ($t) => $t !== '' && ! preg_match('/^[a-z_]+$/', $t) && ! str_starts_with($t, 'bi-') && ! str_starts_with($t, 'btn')));
    }

    public static function aceptado(string $texto): bool
    {
        $texto = trim($texto);
        foreach ([...self::DIEZ_VERBOS, ...self::ACEPTADOS] as $verbo) {
            if (mb_strtolower(mb_substr($texto, 0, mb_strlen($verbo))) === mb_strtolower($verbo)) {
                return true;
            }
        }
        foreach (array_keys(self::EXCLUIDOS) as $excluido) {
            if (str_ends_with($excluido, '…') ? str_starts_with($texto, mb_substr($excluido, 0, -1)) : $texto === $excluido) {
                return true;
            }
        }

        return false;
    }

    public function test_los_botones_de_enviar_usan_los_diez_verbos(): void
    {
        $inventario = self::inventario();
        $this->assertGreaterThan(150, count($inventario), 'El inventario debe leer los botones de todas las vistas');
        $fuera = collect($inventario)->reject(fn ($b) => self::aceptado($b[0]))
            ->map(fn ($b) => "«{$b[0]}» en {$b[1]}")->unique()->values()->all();
        $this->assertSame([], $fuera, 'Botón con un verbo fuera de los diez (docs/criterios-ux.md, regla 4). Usa uno de los diez o pide al dueño que lo apruebe.');
    }

    public function test_los_botones_que_salen_de_constantes_usan_los_diez_verbos(): void
    {
        foreach ([...Autorizacion::BOTONES, ...Vacante::BOTONES, ...array_column(PaseSalida::PASOS_FISICOS, 1)] as $texto) {
            $this->assertTrue(self::aceptado($texto), "«{$texto}» no usa los diez verbos");
        }
    }

    public function test_la_lista_de_exclusion_no_deja_pasar_verbos_de_negocio_viejos(): void
    {
        foreach (['Registrar Salida', 'Confirmar Autorización', 'Autorizar por excepción', 'Que pase', 'Despachar Ticket', 'Registrar Cierre', 'Generar Lote',
            'Cancelar pase', 'Cerrar vacante', 'Finalizar Recorrido', 'Reembolso entregado', 'Listo', 'Ya lo revisé', 'Unir', 'Registrar y Capturar Siguiente'] as $viejo) {
            $this->assertFalse(self::aceptado($viejo), "«{$viejo}» ya no se acepta");
        }
        $this->assertTrue(self::aceptado('Aprobar · que pase'));
        $this->assertTrue(self::aceptado('Rechazar · que espere'));
        $this->assertTrue(self::aceptado('Guardar y terminar recorrido'));
    }
}
