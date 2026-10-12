<?php

namespace App\Services\Notificaciones;

use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\Auditoria\MovimientosSensibles;
use App\Support\GruposAvisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preferencias de avisos de cada persona (ADR-0016): qué tipos de aviso de
 * la campana y qué avisos por correo silenció en «Mi cuenta». Se guardan en
 * users.preferencias_avisos = {"campana": [tipos], "correo": [claves]};
 * nulo = recibe todo (como siempre).
 *
 * Silenciar no quita nada de «Mis pendientes»: lo que espera la respuesta de
 * la persona sigue ahí con su número (criterio 1).
 */
class PreferenciasAvisos
{
    /** Avisos de la propia cuenta que no se pueden silenciar (seguridad). */
    public const NO_SILENCIABLES = ['cuenta_contrasena'];

    /** Tipo de la campana => [módulo para agrupar, qué avisa, permisos de quien lo recibe]. */
    public const CAMPANA = [
        'autorizacion_visita' => ['Autorizaciones', 'Una visita a tu departamento espera tu autorización', ['autorizaciones.responder']],
        'autorizacion_candidato' => ['Autorizaciones', 'Un candidato espera tu autorización', ['autorizaciones.responder']],
        'autorizacion_escalada' => ['Autorizaciones', 'Una autorización pasó al suplente o al jefe por tardanza', ['autorizaciones.responder', 'accesos.ver']],
        'delegacion' => ['Autorizaciones', 'Te delegaron las autorizaciones de un departamento', ['autorizaciones.responder']],
        'autorizacion_respuesta' => ['Bitácora de accesos', 'Respondieron una autorización que pidió la caseta', ['accesos.ver']],
        'autorizacion_recepcion' => ['Bitácora de accesos', 'Recursos Humanos dice «Que pase» a quien espera en caseta', ['accesos.ver']],
        'candidato_llegada' => ['Recepción y candidatos', 'Llega un candidato a Recepción', ['candidatos.editar']],
        'candidato_autocaptura' => ['Recepción y candidatos', 'Un candidato llenó su solicitud en el kiosco', ['candidatos.editar']],
        'evaluacion_departamento' => ['Recepción y candidatos', 'El departamento evaluó o eligió a un candidato', ['candidatos.editar']],
        'plazo_candidato' => ['Recepción y candidatos', 'Algo de candidatos pasó de su plazo', ['candidatos.editar']],
        'caducidad_candidato' => ['Recepción y candidatos', 'Una ficha de candidato se borrará por caducidad', ['candidatos.editar']],
        'entrevista_asignada' => ['Mis entrevistas', 'Te canalizaron una entrevista', ['candidatos.evaluar']],
        'entrevista_cambio' => ['Mis entrevistas', 'Cambió o se canceló una entrevista', ['candidatos.evaluar']],
        'entrevista_llegada' => ['Mis entrevistas', 'Llegó el candidato a su entrevista', ['candidatos.evaluar']],
        'pase_por_firmar' => ['Pases de salida', 'Un pase de salida espera tu firma', ['pases_salida.aprobar', 'pases_salida.firmar']],
        'pase_aprobado' => ['Pases de salida', 'Tu pase de salida se aprobó', ['pases_salida.crear']],
        'pase_rechazado' => ['Pases de salida', 'Tu pase de salida se rechazó', ['pases_salida.crear']],
        'pase_vencido' => ['Pases de salida', 'Un pase de salida venció y no ha regresado', ['pases_salida.ver']],
        'pase_llega' => ['Pases de salida', 'Un pase de salida viene a tu sede', ['pases_salida.ver']],
        'cuenta_bloqueada' => ['Cuentas y seguridad', 'Una cuenta se bloqueó por intentos fallidos', ['usuarios.editar', 'usuarios.desbloquear']],
        'cuenta_desactivada' => ['Cuentas y seguridad', 'Una cuenta se desactivó por la baja del colaborador', ['usuarios.editar']],
        MovimientosSensibles::TIPO => ['Cuentas y seguridad', 'Alguien hizo un movimiento sensible (roles, permisos, eliminaciones, exportaciones)', ['auditoria.ver']],
    ];

    /**
     * @return array{campana: list<string>, correo: list<string>}
     */
    public static function silenciados(User $usuario): array
    {
        $datos = $usuario->getAttributes()['preferencias_avisos'] ?? null;
        $datos = is_string($datos) ? json_decode($datos, true) : $datos;

        return [
            'campana' => array_values(array_filter((array) ($datos['campana'] ?? []), 'is_string')),
            'correo' => array_values(array_filter((array) ($datos['correo'] ?? []), 'is_string')),
        ];
    }

    /**
     * Quita a quienes silenciaron este tipo de aviso de la campana.
     *
     * @param  iterable<int>  $usuarios
     * @return list<int>
     */
    public static function quienesLoReciben(iterable $usuarios, string $tipo): array
    {
        $ids = collect($usuarios)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || in_array($tipo, self::NO_SILENCIABLES, true)) {
            return $ids->all();
        }

        $silenciaron = DB::table('users')->whereIn('id', $ids)->whereNotNull('preferencias_avisos')->get(['id', 'preferencias_avisos'])
            ->filter(fn ($u) => in_array($tipo, (array) (json_decode((string) $u->preferencias_avisos, true)['campana'] ?? []), true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $ids->reject(fn ($id) => in_array($id, $silenciaron, true))->values()->all();
    }

    /**
     * Quita de una lista de correos a las personas de la empresa que silenciaron ese aviso por correo.
     *
     * @param  list<string>  $correos
     * @return list<string>
     */
    public static function correosQueLoReciben(int $empresaId, string $clave, array $correos): array
    {
        if ($correos === []) {
            return $correos;
        }

        $silenciaron = DB::table('users')->where('empresa_id', $empresaId)->whereIn('email', $correos)->whereNotNull('preferencias_avisos')
            ->get(['email', 'preferencias_avisos'])
            ->filter(fn ($u) => in_array($clave, (array) (json_decode((string) $u->preferencias_avisos, true)['correo'] ?? []), true))
            ->pluck('email')->map(fn ($c) => mb_strtolower((string) $c))->all();

        return array_values(array_filter($correos, fn ($c) => ! in_array(mb_strtolower($c), $silenciaron, true)));
    }

    /**
     * Lo que se le muestra a la persona en «Mi cuenta → Mis avisos»: los tipos
     * de campana que le pueden llegar (por permiso o porque ya le llegaron) y
     * los avisos por correo de los módulos que ve.
     *
     * @return array{campana: array<string, array<string, string>>, correo: array<string, array<string, string>>}
     */
    public function opciones(User $usuario): array
    {
        $recibidos = Notificacion::query()->withoutGlobalScopes()->where('user_id', $usuario->id)->distinct()->pluck('tipo')->all();

        $campana = [];
        foreach (self::CAMPANA as $tipo => [$grupo, $etiqueta, $permisos]) {
            if (in_array($tipo, $recibidos, true) || collect($permisos)->contains(fn ($p) => $usuario->can($p))) {
                $campana[$grupo][$tipo] = $etiqueta;
            }
        }
        // Tipos nuevos de otros módulos que ya le llegaron
        foreach (array_diff($recibidos, array_keys(self::CAMPANA), self::NO_SILENCIABLES) as $tipo) {
            $campana['Otros avisos'][$tipo] = Str::ucfirst(str_replace('_', ' ', (string) $tipo));
        }

        $correo = [];
        foreach (GruposAvisos::agrupados() as $grupo => $def) {
            if (! $this->veGrupo($usuario, $grupo, $def['modulo'])) {
                continue;
            }
            foreach ($def['avisos'] as $clave => [$etiqueta]) {
                if ($clave !== MovimientosSensibles::AVISO) { // ese llega solo a la campana
                    $correo[$def['nombre']][$clave] = $etiqueta;
                }
            }
        }

        return ['campana' => $campana, 'correo' => array_filter($correo)];
    }

    /**
     * @param  array<string, mixed>  $marcados  ['campana' => [tipo => 1], 'correo' => [clave => 1]] (marcado = recibir)
     */
    public function guardar(User $usuario, array $marcados): array
    {
        $opciones = $this->opciones($usuario);
        $antes = self::silenciados($usuario);
        $nuevos = [];
        foreach (['campana', 'correo'] as $canal) {
            $mostrados = collect($opciones[$canal])->flatMap(fn ($g) => array_keys($g))->all();
            $recibir = array_keys(array_filter((array) ($marcados[$canal] ?? [])));
            $nuevos[$canal] = array_values(array_unique([
                ...array_diff($antes[$canal], $mostrados),
                ...array_diff($mostrados, $recibir, self::NO_SILENCIABLES),
            ]));
        }
        $usuario->forceFill(['preferencias_avisos' => $nuevos['campana'] === [] && $nuevos['correo'] === [] ? null : json_encode($nuevos)])->save();

        return $nuevos;
    }

    private function veGrupo(User $usuario, string $grupo, ?string $modulo): bool
    {
        return match ($grupo) {
            'cuentas' => $usuario->can('usuarios.editar') || $usuario->can('auditoria.ver'),
            'padrones' => $usuario->can('vehiculos.editar') || $usuario->can('proveedores.editar') || $usuario->can('visitantes.editar'),
            'candidatos' => $usuario->can('candidatos.editar') || $usuario->can('candidatos.evaluar') || $usuario->can('autorizaciones.responder'),
            'pases_salida' => $usuario->can('pases_salida.ver'),
            default => $modulo !== null ? $usuario->can($modulo.'.ver') : true,
        };
    }

    /**
     * ¿La empresa tiene encendido el aviso? (para avisar en «Mis avisos» que está apagado para todos)
     */
    public static function apagadoEnEmpresa(?Empresa $empresa, string $clave): bool
    {
        return $empresa !== null && ! $empresa->aviso($clave);
    }
}
