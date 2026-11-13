<?php

namespace App\Support;

use App\Http\Controllers\Administracion\AuditoriaController;
use App\Models\Empresa;
use App\Models\LostFoundUmbral;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\RecorridosPc\AjustesEquiposPc;
use App\Services\Relojes\AjustesRelojes;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Str;

/**
 * Configuración en mosaico (pedido del dueño, 12 nov 2026; docs/tecnico/configuracion.md):
 * al entrar se ve un buscador y una tarjeta por tema; cada tema abre solo sus
 * secciones en /configuracion/{tema}. Aquí viven los temas, a qué tema
 * pertenece cada ancla vieja (`#cfg-…`, `#config-<grupo>`, `#sec-…`) y la
 * lista de ajustes que encuentra el buscador.
 *
 * Una sección (las de antes: `sec-general`, `caseta`, …) pertenece a un solo
 * tema; ningún ajuste cambió de sección, solo se agruparon.
 */
final class TemasConfiguracion
{
    /** slug => [nombre, ícono, qué contiene (una línea), secciones en orden]. */
    public const TEMAS = [
        'general' => ['General', 'bi-sliders', 'Configura tu empresa, datos de la empresa, reloj y suplente y nombres del menú.', ['sec-general']],
        'caseta' => ['Caseta y accesos', 'bi-door-open', 'Quién espera autorización, motivo de la visita, permanencia, noche sin supervisor y equipos de acceso.', ['caseta']],
        'estructura' => ['Estructura', 'bi-diagram-3', 'Credenciales del personal y avisos de colaboradores.', ['sec-estructura']],
        'personal' => ['Recepción y RR. HH.', 'bi-people', 'Kiosco y aviso de candidatos, plazos, entrevistas, asistencia y bolsa de trabajo.', ['sec-rh']],
        'novedades' => ['Novedades', 'bi-headset', 'Nombre de la bitácora, tickets, procedimientos, Protección Civil y Lost & Found.', ['sec-novedades']],
        'activos' => ['Activos, transporte y pases', 'bi-key', 'Llaves, responsivas, vouchers, vales de taxi y pases de salida.', ['sec-activos', 'sec-transporte', 'sec-pases']],
        'reservas' => ['Reservas de áreas', 'bi-calendar-check', 'Responsable general de reservas, áreas reservables y sus avisos.', ['sec-reservas']],
        'avisos' => ['Avisos por correo', 'bi-envelope', 'Todos los avisos de un vistazo, los que no son de un módulo y restablecerlos.', ['sec-avisos']],
        'seguridad' => ['Seguridad y privacidad', 'bi-shield-lock', 'Verificación en dos pasos, cierre de sesión, bitácora de auditoría y datos personales.', ['sec-cuentas', 'sec-privacidad']],
        'plataforma' => ['Plataforma', 'bi-hdd-network', 'Super Administradores, correo de la plataforma, respaldos y versión de PHP.', ['sec-plataforma']],
    ];

    /** Sección => [nombre, ícono] (los títulos dentro de cada tema). */
    public const SECCIONES = [
        'sec-general' => ['General', 'bi-sliders'],
        'caseta' => ['Caseta y accesos', 'bi-door-open'],
        'sec-estructura' => ['Estructura', 'bi-diagram-3'],
        'sec-rh' => ['Recepción y RR. HH.', 'bi-people'],
        'sec-novedades' => ['Novedades', 'bi-headset'],
        'sec-activos' => ['Activos', 'bi-key'],
        'sec-transporte' => ['Transporte', 'bi-taxi-front'],
        'sec-pases' => ['Pases de salida', 'bi-box-arrow-up-right'],
        'sec-reservas' => ['Reservas de áreas', 'bi-calendar-check'],
        'sec-avisos' => ['Avisos por correo', 'bi-envelope'],
        'sec-cuentas' => ['Cuentas y seguridad', 'bi-shield-lock'],
        'sec-privacidad' => ['Privacidad', 'bi-person-lock'],
        'sec-plataforma' => ['Plataforma', 'bi-hdd-network'],
    ];

    /**
     * Grupo de avisos => sección donde se muestra su tarjeta (C-38; los que no
     * estén aquí van en «Avisos por correo»).
     */
    public const SECCION_DE_GRUPO = ['cuentas' => 'sec-cuentas', 'colaboradores' => 'sec-estructura', 'padrones' => 'caseta', 'procedimientos' => 'sec-novedades',
        'autorizaciones' => 'caseta', 'novedades' => 'sec-novedades', 'pases_salida' => 'sec-pases', 'transporte' => 'sec-transporte', 'vouchers' => 'sec-activos',
        'candidatos' => 'sec-rh', 'reservas' => 'sec-reservas'];

    /** Anclas de tarjetas y ajustes => su sección (las de las secciones mismas y `config-<grupo>` se resuelven aparte). */
    public const ANCLAS = [
        'configura-tu-empresa' => 'sec-general', 'cfg-empresa' => 'sec-general', 'cfg-vobo' => 'sec-general', 'cfg-menu' => 'sec-general',
        'cfg-esperas' => 'caseta', 'cfg-impresion' => 'caseta', 'cfg-equipos-acceso' => 'caseta', 'cfg-motivos' => 'caseta', 'cfg-permanencia' => 'caseta', 'cfg-noche' => 'caseta',
        'cfg-credenciales' => 'sec-estructura',
        'recepcion' => 'sec-rh', 'recepcion-aviso' => 'sec-rh', 'cfg-plazos' => 'sec-rh', 'cfg-criterios' => 'sec-rh', 'cfg-asistencia' => 'sec-rh', 'bolsa-trabajo' => 'sec-rh',
        'novedades' => 'sec-novedades', 'novedades-procedimientos' => 'sec-novedades', 'cfg-procedimientos' => 'sec-novedades', 'cfg-equipos-pc' => 'sec-novedades', 'lost-found' => 'sec-novedades',
        'cfg-llaves' => 'sec-activos', 'cfg-responsivas' => 'sec-activos', 'cfg-transporte' => 'sec-transporte', 't-pases-salida' => 'sec-pases',
        'cfg-reservas-areas' => 'sec-reservas',
        'cfg-dos-pasos' => 'sec-cuentas', 'cfg-sesion' => 'sec-cuentas', 'config-auditoria' => 'sec-cuentas',
        'cfg-conservacion' => 'sec-privacidad', 'cfg-aviso' => 'sec-privacidad', 'cfg-arco' => 'sec-privacidad',
        'cfg-superadmins' => 'sec-plataforma', 'cfg-php' => 'sec-plataforma', 'cfg-correo' => 'sec-plataforma', 't-correo' => 'sec-plataforma',
        'cfg-respaldos' => 'sec-plataforma', 't-respaldos' => 'sec-plataforma',
    ];

    /**
     * Lo que encuentra el buscador «¿Qué quieres ajustar?»: [nombre, palabras clave, ancla].
     * El nombre es el de la tarjeta tal como se ve; las palabras, cómo lo diría alguien.
     */
    public const AJUSTES = [
        ['Configura tu empresa', 'primeros pasos inicio mostrar ocultar arranque', 'configura-tu-empresa'],
        ['Datos de la empresa', 'nombre razón social rfc logo logotipo zona horaria hora', 'cfg-empresa'],
        ['Reloj y suplente', 'visto bueno horas amarillo rojo ámbar recordatorio escalar suplente espera', 'cfg-vobo'],
        ['Nombres en el menú', 'renombrar cambiar nombre módulo menú', 'cfg-menu'],
        ['Quién espera autorización del departamento', 'esperas autorizar visitas proveedores contratistas minutos supervisor', 'cfg-esperas'],
        ['Imprimir etiquetas desde los padrones', 'etiquetas qr impresión plantillas padrones', 'cfg-impresion'],
        ['Equipos de acceso', 'lectores torniquetes checadores zkteco hikvision biométrico huella', 'cfg-equipos-acceso'],
        ['Motivo de la visita', 'motivo marcado visita a huésped caseta', 'cfg-motivos'],
        ['Tiempo de permanencia en sitio', 'permanencia horas visita proveedor contratista daypass salida temporal', 'cfg-permanencia'],
        ['Noche sin supervisor', 'aprobar por excepción agente noche foto', 'cfg-noche'],
        ['Autorizaciones de visitas: avisos por correo', 'correo responsable departamento delegado', 'config-autorizaciones'],
        ['Altas por verificar: avisos por correo', 'padrones registro rápido verificar correo', 'config-padrones'],
        ['Credenciales del personal', 'credencial gafete vigencia vencimiento tarjeta proximidad mifare reverso teléfono', 'cfg-credenciales'],
        ['Colaboradores: avisos por correo', 'alta provisional colaborador correo', 'config-colaboradores'],
        ['Aviso de privacidad y kiosco de candidatos', 'kiosco candidatos enlace qr horas usos solicitud de empleo', 'recepcion'],
        ['Candidatos: plazos y documentos para contratar', 'plazos documentos contratar candidatos días', 'cfg-plazos'],
        ['Criterios para calificar las entrevistas', 'entrevista calificar criterios candidatos', 'cfg-criterios'],
        ['Asistencia: tolerancia y aviso de faltas', 'asistencia retardo tolerancia faltas checar', 'cfg-asistencia'],
        ['Bolsa de trabajo en internet', 'vacantes bolsa trabajo página pública postulación', 'bolsa-trabajo'],
        ['Recepción y candidatos: avisos por correo', 'candidato llegada entrevista evaluación correo', 'config-candidatos'],
        ['Nombre de la bitácora', 'novedades incidentes nombre módulo bitácora hallazgo recorrido categoría reloj tickets departamento accidentes reporte general tipos', 'novedades'],
        ['¿Qué hacer? procedimiento de cada categoría', 'qué hacer procedimiento categoría equipo ticket avanzado', 'novedades'],
        ['Procedimientos: acuse «Leí y entendí» y recordatorio', 'procedimientos acuse leí y entendí firma recordatorio días', 'cfg-procedimientos'],
        ['Equipos de Protección Civil: frecuencia de revisión', 'protección civil extintores hidrantes revisión frecuencia días recorridos', 'cfg-equipos-pc'],
        ['Lost & Found: días de resguardo', 'lost found objetos olvidados resguardo días semáforo perdidos', 'lost-found'],
        ['Préstamo de llaves: llaves fuera mucho tiempo', 'llaves semáforo horas garantía préstamo', 'cfg-llaves'],
        ['Responsivas: equipos de «Turno»', 'responsivas equipos turno regreso firma hoja impresa', 'cfg-responsivas'],
        ['Vouchers de reposición: avisos y copias', 'vouchers cobro copia seguridad recepción administración correo', 'config-vouchers'],
        ['Firmas del vale de taxi', 'transporte taxi vale firmas guardia taxista', 'cfg-transporte'],
        ['Bitácora de transporte: avisos por correo', 'vale de taxi correos que lo reciben autorizar', 'config-transporte'],
        ['Circuito de aprobación de pases de salida', 'pases de salida circuito aprobación firmas configurar', 't-pases-salida'],
        ['Pases de salida: avisos por correo', 'pases vencidos firmas aprobación resultado correo', 'config-pases_salida'],
        ['Responsable general de reservas', 'reservas responsable suplente recordar escalar montaje iva', 'sec-reservas'],
        ['Áreas reservables y catálogos', 'salones salas aforo montajes tarifas tipos de uso servicios', 'cfg-reservas-areas'],
        ['Reservas de áreas: avisos por correo', 'reserva aprobación resultado orden de servicio correo', 'config-reservas'],
        ['Avisos por correo: resumen y restablecer', 'correo avisos encendidos apagados restablecer todos', 'sec-avisos'],
        ['Verificación en dos pasos', 'dos pasos código teléfono autenticador exigir', 'cfg-dos-pasos'],
        ['Cierre de sesión por inactividad', 'sesión minutos inactividad cerrar', 'cfg-sesion'],
        ['Bitácora de auditoría: qué ve «Su sede»', 'auditoría su sede movimientos bitácora', 'config-auditoria'],
        ['Cuentas y seguridad: avisos', 'cuenta bloqueada movimientos sensibles campana', 'config-cuentas'],
        ['Conservación de datos personales', 'privacidad borrar fotos identificación días conservar', 'cfg-conservacion'],
        ['Aviso de privacidad', 'aviso privacidad versión publicar texto abogado', 'cfg-aviso'],
        ['Solicitudes ARCO', 'arco derechos acceso rectificación cancelación oposición', 'cfg-arco'],
        ['Super Administradores', 'super administrador dos como mínimo restablecer ayuda', 'cfg-superadmins'],
        ['Versión de PHP del servidor', 'php servidor versión opcache memoria', 'cfg-php'],
        ['Correo de la plataforma', 'smtp servidor puerto contraseña remitente correo de prueba', 'cfg-correo'],
        ['Respaldos de la base de datos', 'respaldo descargar traslado copia de seguridad', 'cfg-respaldos'],
        ['Identidad, catálogo comercial y módulos de cada empresa', 'identidad logotipo colores catálogo comercial suscripciones módulos contratados', 'sec-plataforma'],
    ];

    /** Sección a la que pertenece un ancla (null si no es de Configuración). */
    public static function seccionDe(string $ancla): ?string
    {
        if (isset(self::SECCIONES[$ancla])) {
            return $ancla;
        }
        if (str_starts_with($ancla, 't-sec-')) {
            $seccion = substr($ancla, 2);

            return isset(self::SECCIONES[$seccion]) ? $seccion : ($seccion === 'sec-caseta' ? 'caseta' : null);
        }
        if (isset(self::ANCLAS[$ancla])) {
            return self::ANCLAS[$ancla];
        }
        if (preg_match('/^(?:t-)?config-([a-z_]+)$/', $ancla, $m) && isset(GruposAvisos::agrupados()[$m[1]])) {
            return self::SECCION_DE_GRUPO[$m[1]] ?? 'sec-avisos';
        }

        return null;
    }

    /** Tema de una sección. */
    public static function temaDeSeccion(string $seccion): ?string
    {
        foreach (self::TEMAS as $slug => [, , , $secciones]) {
            if (in_array($seccion, $secciones, true)) {
                return $slug;
            }
        }

        return null;
    }

    /** Tema al que lleva un ancla vieja de la página única (null si no es de Configuración). */
    public static function temaDe(string $ancla): ?string
    {
        $seccion = self::seccionDe(ltrim($ancla, '#'));

        return $seccion === null ? null : self::temaDeSeccion($seccion);
    }

    /**
     * Dirección de un ajuste: /configuracion/{tema}#ancla. Sin ancla o con un
     * ancla desconocida, el mosaico (/configuracion).
     */
    public static function url(?string $ancla = null): string
    {
        $ancla = $ancla === null ? null : ltrim($ancla, '#');
        $tema = $ancla === null || $ancla === '' ? null : self::temaDe($ancla);

        return $tema === null
            ? route('configuracion.index').($ancla ? '#'.$ancla : '')
            : route('configuracion.tema', $tema).'#'.$ancla;
    }

    /**
     * Todas las anclas conocidas => tema (para que la pantalla lleve los
     * enlaces viejos `/configuracion#…` al tema correcto).
     *
     * @return array<string, string>
     */
    public static function mapaAnclas(): array
    {
        $mapa = [];
        $anclas = [...array_keys(self::SECCIONES), ...array_map(fn ($s) => 't-'.$s, array_keys(self::SECCIONES)), 't-sec-caseta', ...array_keys(self::ANCLAS)];
        foreach (array_keys(GruposAvisos::agrupados()) as $grupo) {
            $anclas[] = 'config-'.$grupo;
            $anclas[] = 't-config-'.$grupo;
        }
        foreach ($anclas as $ancla) {
            if (($tema = self::temaDe($ancla)) !== null) {
                $mapa[$ancla] = $tema;
            }
        }

        return $mapa;
    }

    /** Texto sin acentos y en minúsculas para comparar en el buscador. */
    public static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texto))));
    }

    /** ¿Todas las palabras de la búsqueda están en el texto? */
    public static function coincide(string $busqueda, string $texto): bool
    {
        $texto = self::normalizar($texto);
        foreach (explode(' ', self::normalizar($busqueda)) as $palabra) {
            if ($palabra !== '' && ! str_contains($texto, $palabra)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Temas con algún ajuste distinto del de fábrica (insignia «Cambiado»).
     * Solo se revisa lo que se guarda en la empresa; lo que vive en otro
     * módulo trae su propio «Valor original» en su tarjeta.
     *
     * @return array<string, bool>
     */
    public static function cambiados(Empresa $empresa): array
    {
        $p = is_array($empresa->preferencias) ? $empresa->preferencias : [];
        $caseta = is_array($p[AjustesCaseta::CLAVE] ?? null) ? $p[AjustesCaseta::CLAVE] : [];
        $grupo = fn (string $g) => array_intersect_key($caseta, array_flip(AjustesCaseta::GRUPOS[$g])) !== [];
        $modulos = is_array($p['avisos_modulos'] ?? null) ? $p['avisos_modulos'] : [];
        $apagado = fn (string ...$grupos) => collect($grupos)->contains(fn ($g) => ($modulos[$g] ?? true) === false);
        $avisos = is_array($p['avisos'] ?? null) ? $p['avisos'] : [];
        $avisoDistinto = fn (string ...$grupos) => collect(GruposAvisos::agrupados())->only($grupos)
            ->contains(fn ($def) => collect($def['avisos'])->contains(fn ($d, $clave) => array_key_exists($clave, $avisos) && (bool) $avisos[$clave] !== (bool) $d[1]));
        $deGrupos = fn (string ...$grupos) => $apagado(...$grupos) || $avisoDistinto(...$grupos);
        $menuPropio = collect(NombresMenu::de($empresa))->flatten(1)->contains(fn ($m) => ($m['propio'] ?? null) !== null);
        $lostFound = app(Tenant::class)->conEmpresa((int) $empresa->id, fn () => LostFoundUmbral::vigentes() != LostFoundUmbral::POR_OMISION);

        return [
            'general' => $grupo('vobo') || ! empty($p[AjustesRelojes::CLAVE]) || $menuPropio,
            'caseta' => $grupo('motivos') || $grupo('permanencia') || $grupo('noche') || $deGrupos('padrones', 'autorizaciones'),
            'estructura' => ! empty($p[AjustesCredenciales::CLAVE]) || $deGrupos('colaboradores'),
            'personal' => $deGrupos('candidatos'),
            'novedades' => $grupo('procedimientos') || ! empty($p['novedades']) || ! empty($p[AjustesEquiposPc::CLAVE]) || $lostFound || $deGrupos('novedades', 'procedimientos'),
            'activos' => $grupo('llaves') || $grupo('responsivas') || $grupo('transporte') || $deGrupos('vouchers', 'transporte', 'pases_salida'),
            'reservas' => $deGrupos('reservas'),
            'avisos' => $deGrupos(GruposAvisos::OTROS),
            'seguridad' => app(VerificacionDosPasos::class)->politica($empresa) !== VerificacionDosPasos::POLITICA_ORIGINAL
                || (isset($p['auditoria_sede']) && $p['auditoria_sede'] !== AuditoriaController::SEDE_POR_OMISION) || $deGrupos('cuentas'),
            'plataforma' => false,
        ];
    }
}
