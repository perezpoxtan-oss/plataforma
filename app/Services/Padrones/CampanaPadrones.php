<?php

namespace App\Services\Padrones;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Altas por verificar en la campana (criterio 1 de docs/criterios-ux.md;
 * ADR-0015), además del correo que ya existía:
 *
 *  - «Alta por verificar: <título>» a quienes pueden verificar ese padrón
 *    en la sede donde se registró (permiso «editar» del padrón), con el botón
 *    «Verificar» que abre la lista del padrón en sus pendientes. Se resuelve
 *    (pierde el botón y queda leída para todos) cuando alguien la acepta, la
 *    une o la rechaza.
 *  - El resultado a quien la registró en la caseta: «Tu alta … fue
 *    verificada», «… se unió con …» o «… fue rechazada (motivo)». Así el
 *    agente no se entera hasta que intenta usarla de nuevo.
 *
 * Referencia (para resolver): alta_<padron> + id del registro.
 */
class CampanaPadrones
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly Autorizador $autorizador,
    ) {}

    /** El alta nació pendiente: avisar a quienes pueden verificarla. */
    public function porVerificar(string $padron, Model $registro, User $quien): void
    {
        $altas = app(AltasPorVerificar::class);
        $d = $altas->definicion($padron);
        $sedeId = $registro->getAttribute('sede_alta_id') === null ? null : (int) $registro->getAttribute('sede_alta_id');
        $usuarios = $this->conPermiso((int) $registro->getAttribute('empresa_id'), $d['permiso'].'.editar', $sedeId)
            ->reject(fn (int $id) => $id === (int) $quien->id);
        $origen = $registro->getAttribute('origen_alta') !== null ? (AltasPorVerificar::ORIGENES[$registro->getAttribute('origen_alta')]['nombre'] ?? null) : null;

        $this->centro->avisar((int) $registro->getAttribute('empresa_id'), $usuarios, 'alta_por_verificar', [
            'titulo' => 'Alta por verificar: '.$altas->titulo($padron, $registro),
            'texto' => ucfirst($d['singular']).' registrad'.($d['genero'] === 'f' ? 'a' : 'o').' por '.$quien->name
                .($origen ? ' desde '.$origen : '').'. Acéptala, únela con la correcta o recházala.',
            'url' => route($d['ruta'], ['verificacion' => 'pendiente']).'#'.$d['ancla'].$registro->getKey(),
            'referencia_tipo' => 'alta_'.$padron,
            'referencia_id' => (int) $registro->getKey(),
            'acciones' => [['etiqueta' => 'Verificar', 'abrir' => true]],
        ]);
    }

    /**
     * Ya se verificó (aceptada, unida o rechazada): el aviso pierde su botón
     * y quien la registró se entera del resultado.
     *
     * @param  string  $resultado  verificada | unida | rechazada
     */
    public function resultado(string $padron, Model $registro, string $resultado, User $quien, ?Model $destino = null): void
    {
        $this->centro->resolver('alta_'.$padron, (int) $registro->getKey());

        $creador = (int) $registro->getAttribute('creado_por');
        if ($creador === 0 || $creador === (int) $quien->id
            || ! User::whereKey($creador)->where('activo', true)->where('empresa_id', $registro->getAttribute('empresa_id'))->exists()) {
            return;
        }

        $altas = app(AltasPorVerificar::class);
        $d = $altas->definicion($padron);
        $titulo = $altas->titulo($padron, $registro);
        $a = $d['genero'] === 'f' ? 'a' : 'o';
        [$tipo, $encabezado, $texto, $url] = match ($resultado) {
            'rechazada' => ['alta_rechazada', "Tu alta «{$titulo}» fue rechazad{$a}",
                'Motivo: '.($registro->getAttribute('motivo_rechazo') ?: '—').'. Ya no se puede usar en la caseta; revisa los datos o pregunta a tu supervisor.', null],
            'unida' => ['alta_unida', "Tu alta «{$titulo}» ya existía",
                'Se unió con «'.($destino ? $altas->titulo($padron, $destino) : '—').'»: lo que registraste pasó a ese registro. La próxima vez búscalo con ese nombre.',
                $destino ? route($d['ruta']).'#'.$d['ancla'].$destino->getKey() : null],
            default => ['alta_verificada', "Tu alta «{$titulo}» quedó verificad{$a}", 'Ya es un registro normal del '.$d['padron'].'.',
                route($d['ruta']).'#'.$d['ancla'].$registro->getKey()],
        };

        $this->centro->avisar((int) $registro->getAttribute('empresa_id'), [$creador], $tipo, [
            'titulo' => $encabezado,
            'texto' => $texto,
            'url' => $url,
            'referencia_tipo' => 'alta_'.$padron.'_resultado',
            'referencia_id' => (int) $registro->getKey(),
        ]);
    }

    /**
     * El mismo circuito (y las mismas palabras) para las altas de
     * colaboradores que hace la caseta: «Alta por verificar» a quien las
     * valida en Recursos Humanos (colaboradores.aprobar en esa sede) y el
     * resultado a quien la registró.
     */
    public function colaboradorPorVerificar(Colaborador $colaborador, User $quien): void
    {
        $usuarios = $this->conPermiso((int) $colaborador->empresa_id, 'colaboradores.aprobar', $colaborador->sede_id === null ? null : (int) $colaborador->sede_id)
            ->reject(fn (int $id) => $id === (int) $quien->id);
        $this->centro->avisar((int) $colaborador->empresa_id, $usuarios, 'alta_por_verificar', [
            'titulo' => 'Alta por verificar: '.$colaborador->nombreCompleto(),
            'texto' => 'Colaborador registrado por '.$quien->name.' en la caseta'.($colaborador->sede?->nombre ? ' de '.$colaborador->sede->nombre : '')
                .'. Verifícalo (asígnale su número) o únelo con su registro correcto.',
            'url' => route('colaboradores.index', ['registro' => 'provisional']).'#colaborador-'.$colaborador->id,
            'referencia_tipo' => 'alta_colaboradores',
            'referencia_id' => (int) $colaborador->id,
            'acciones' => [['etiqueta' => 'Verificar', 'abrir' => true]],
        ]);
    }

    /** @param  string  $resultado  verificada | unida */
    public function colaboradorResultado(Colaborador $colaborador, string $resultado, User $quien, ?Colaborador $destino = null): void
    {
        $this->centro->resolver('alta_colaboradores', (int) $colaborador->id);
        $creador = (int) $colaborador->creado_por;
        if ($creador === 0 || $creador === (int) $quien->id
            || ! User::whereKey($creador)->where('activo', true)->where('empresa_id', $colaborador->empresa_id)->exists()) {
            return;
        }
        $unida = $resultado === 'unida';
        $this->centro->avisar((int) $colaborador->empresa_id, [$creador], $unida ? 'alta_unida' : 'alta_verificada', [
            'titulo' => $unida ? 'Tu alta «'.$colaborador->nombreCompleto().'» ya existía' : 'Tu alta «'.$colaborador->nombreCompleto().'» quedó verificada',
            'texto' => $unida
                ? 'Recursos Humanos la unió con '.($destino?->nombreCompleto() ?? 'su registro').($destino?->num_empleado ? ' (núm. '.$destino->num_empleado.')' : '').': lo que registraste pasó a ese colaborador.'
                : 'Recursos Humanos le asignó su número'.($colaborador->num_empleado ? ' ('.$colaborador->num_empleado.')' : '').'; ya es un colaborador normal.',
            'referencia_tipo' => 'alta_colaboradores_resultado',
            'referencia_id' => (int) $colaborador->id,
        ]);
    }

    /**
     * Usuarios activos de la empresa con un permiso que alcance esa sede
     * (sin sede conocida, todos los que tienen el permiso: así la verifican,
     * ver AltasPorVerificar::limitarVerificacion).
     *
     * @return Collection<int, int>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($sedeId === null || $efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || in_array($sedeId, $efectivo->sedes, true));
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
