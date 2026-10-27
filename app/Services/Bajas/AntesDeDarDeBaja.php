<?php

namespace App\Services\Bajas;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Espacio;
use App\Models\Llave;
use App\Models\PaseSalida;
use App\Models\Persona;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Reserva;
use App\Models\Responsiva;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacante;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Activos\CampanaActivos;
use App\Services\Activos\CustodiaActivos;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Borrado\RegistroBorrado;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Support\EstadoActivo;
use App\Support\Receptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan de correcciones, C-20 (DD-06): pantalla común «Antes de dar de baja».
 *
 * Antes de dar de baja un registro se revisa SOLO lo vigente que depende de
 * él (lo histórico no importa: para eso está «Eliminar definitivamente» y su
 * lista de «qué lo usa», RegistroBorrado, de donde se toman el nombre y el
 * tipo de cada registro).
 *
 * Regla (DD-06):
 *  - Se puede dar de baja igual: lo pendiente (llaves, equipos de una
 *    responsiva, equipos que salieron a su resguardo) pasa a NO REGRESÓ y se
 *    avisa en la campana a quien da de baja esos activos en la sede.
 *  - Se bloquea solo una sede con gente en sitio y una zona de
 *    estacionamiento con autos dentro.
 *  - Si es el único responsable de un departamento, se pide un reemplazo.
 *  - Colaborador: «Dar de baja sus vehículos» viene marcado y su etiqueta NFC se apaga.
 *
 * Sin nada vigente, la baja es como siempre (un clic y la confirmación).
 * Corre con la empresa de trabajo ya fijada en el Tenant.
 */
class AntesDeDarDeBaja
{
    /** Máximo de ejemplos por renglón. */
    private const EJEMPLOS = 5;

    /**
     * clave => [modelo, permiso de la baja, ruta de la baja (PATCH activo=0) o null si es con voucher, ruta de la lista]
     */
    public const TIPOS = [
        'colaboradores' => [Colaborador::class, 'colaboradores.eliminar', 'colaboradores.estado', 'colaboradores.index'],
        'personas' => [Persona::class, 'visitantes.eliminar', 'personas.estado', 'personas.index'],
        'proveedores' => [Proveedor::class, 'proveedores.eliminar', 'proveedores.estado', 'proveedores.index'],
        'vehiculos' => [Vehiculo::class, 'vehiculos.eliminar', 'vehiculos.estado', 'vehiculos.index'],
        'llaves' => [Llave::class, 'llaves.eliminar', null, 'llaves.index'],
        'equipos' => [Equipo::class, 'equipos.eliminar', null, 'equipos.index'],
        'sedes' => [Sede::class, 'sedes.eliminar', 'sedes.estado', 'sedes.index'],
        'estacionamientos' => [ZonaEstacionamiento::class, 'estacionamientos.eliminar', 'estacionamientos.estado', 'estacionamientos.index'],
        'departamentos' => [Departamento::class, 'departamentos.eliminar', 'departamentos.estado', 'departamentos.index'],
        'puestos' => [Puesto::class, 'puestos.eliminar', 'puestos.estado', 'puestos.index'],
        'turnos' => [Turno::class, 'turnos.eliminar', 'turnos.estado', 'turnos.index'],
        'espacios' => [Espacio::class, 'espacios.eliminar', 'espacios.estado', null],
    ];

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $centro,
    ) {}

    /**
     * Lo vigente que depende del registro.
     *
     * @return array{items: list<array{clave: string, nivel: string, texto: string, efecto: ?string, ejemplos: list<array{texto: string, url: ?string}>}>, bloquea: bool, reemplazo: list<array{id: int, nombre: string}>, vehiculos: int}
     */
    public function revisar(string $tipo, Model $registro): array
    {
        $items = match ($tipo) {
            'colaboradores' => $this->deColaborador($registro),
            'personas' => $this->dePersona($registro),
            'proveedores' => $this->deProveedor($registro),
            'vehiculos' => $this->deVehiculo($registro),
            'llaves' => $this->deLlave($registro),
            'equipos' => $this->deEquipo($registro),
            'sedes' => $this->deSede($registro),
            'estacionamientos' => $this->deZona($registro),
            'departamentos' => $this->deDepartamento($registro),
            'puestos' => $this->dePuesto($registro),
            'turnos' => $this->deTurno($registro),
            'espacios' => $this->deEspacio($registro),
            default => [],
        };
        $items = array_values(array_filter($items));

        return [
            'items' => $items,
            'bloquea' => collect($items)->contains('nivel', 'bloquea'),
            'reemplazo' => $tipo === 'colaboradores' ? $this->responsableUnicoDe($registro) : [],
            'vehiculos' => $tipo === 'colaboradores' ? Vehiculo::where('colaborador_id', $registro->getKey())->where('activo', true)->count() : 0,
        ];
    }

    /** ¿Hay algo que revisar (o que bloquea)? */
    public function hayPendientes(array $revision): bool
    {
        return $revision['items'] !== [] || $revision['reemplazo'] !== [] || $revision['vehiculos'] > 0;
    }

    /**
     * Para los botones «Dar de baja» (PATCH activo=0) de cada padrón: sin
     * nada vigente, la baja sigue igual; con algo vigente y sin pasar por la
     * pantalla (revisado=1), manda a «Antes de dar de baja». Con la pantalla
     * revisada, revisa otra vez los bloqueos y el reemplazo.
     *
     * @throws HttpResponseException|ValidationException
     */
    public function exigirRevisado(string $tipo, Model $registro, Request $request): array
    {
        $revision = $this->revisar($tipo, $registro);
        if (! $request->boolean('revisado')) {
            if ($this->hayPendientes($revision)) {
                throw new HttpResponseException(redirect()->route('bajas.revisar', ['tipo' => $tipo, 'id' => $registro->getKey()]));
            }

            return $revision;
        }
        if ($revision['bloquea']) {
            $motivo = collect($revision['items'])->firstWhere('nivel', 'bloquea');
            throw ValidationException::withMessages(['baja' => 'No se puede dar de baja todavía: '.$motivo['texto'].'. '.$motivo['efecto']]);
        }
        if ($revision['reemplazo'] !== []) {
            $reemplazo = $request->input('reemplazo_id');
            // Revisión Etapa 2 (R2-01): users no tiene el scope de tenant; el reemplazo es de la misma empresa
            $candidato = is_numeric($reemplazo) ? User::where('empresa_id', $registro->getAttribute('empresa_id'))->where('activo', true)->whereKey((int) $reemplazo)
                ->where(fn ($q) => $q->whereNull('colaborador_id')->orWhere('colaborador_id', '!=', $registro->getKey()))->first() : null;
            // C-08: el reemplazo debe poder responder (permiso «Responder»); si no, el departamento seguiría sin quien autorice
            if ($candidato !== null && ! app(Cobertura::class)->puedeResponderEn($candidato, null)) {
                throw ValidationException::withMessages(['reemplazo_id' => $candidato->name.' no tiene permiso para responder autorizaciones: elige a alguien que sí pueda.']);
            }
            if ($candidato === null) {
                throw ValidationException::withMessages(['reemplazo_id' => 'Es el único responsable de '.collect($revision['reemplazo'])->pluck('nombre')->join(', ', ' y ')
                    .': elige quién lo reemplaza para que las autorizaciones no se queden sin respuesta.']);
            }
        }

        return $revision;
    }

    /**
     * Ya dado de baja: lo pendiente pasa a NO REGRESÓ, el reemplazo toma su
     * lugar, sus vehículos (si se dejó marcado) y su etiqueta NFC se apagan,
     * y se avisa en la campana. Devuelve el resumen para el mensaje.
     *
     * @param  array<string, mixed>  $entrada  reemplazo_id, bajar_vehiculos
     * @return list<string>
     */
    public function aplicar(string $tipo, Model $registro, array $revision, array $entrada, User $actor): array
    {
        $hecho = [];
        $noRegresaron = [];
        $receptor = match ($tipo) {
            'colaboradores' => Receptor::COLABORADOR,
            'personas' => Receptor::PERSONA,
            'proveedores' => Receptor::PROVEEDOR,
            default => null,
        };

        DB::transaction(function () use ($tipo, $registro, $revision, $entrada, $actor, $receptor, &$hecho, &$noRegresaron) {
            if ($receptor !== null) {
                $noRegresaron = $this->marcarNoRegreso($receptor, (int) $registro->getKey(), $actor);
            }
            if ($tipo === 'colaboradores') {
                // Equipos que salieron a su resguardo en un pase
                foreach ($this->pasesDeColaborador($registro)->where('destino_tipo', 'colaborador')->where('colaborador_destino_id', $registro->getKey()) as $pase) {
                    foreach ($pase->articulos()->whereNotNull('equipo_id')->get() as $a) {
                        $equipo = Equipo::whereKey($a->equipo_id)->where('estado', EstadoActivo::FUERA)->first();
                        if ($equipo !== null) {
                            $equipo->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
                            $noRegresaron[] = [$equipo, 'equipo', 'equipo '.$equipo->numero_serie];
                        }
                    }
                }
                // Llaves de las que era responsable permanente: quedan sin responsable
                $llaves = Llave::where('colaborador_id', $registro->getKey())->where('activo', true)->get();
                foreach ($llaves as $llave) {
                    $llave->forceFill(['colaborador_id' => null])->save();
                    $this->auditoria->auditar($actor, 'llaves.actualizado', $llave, ['colaborador_id' => $registro->getKey()], ['colaborador_id' => null, 'motivo' => 'Baja de su responsable']);
                }
                if ($llaves->isNotEmpty()) {
                    $hecho[] = $llaves->count() === 1 ? "la llave {$llaves->first()->nomenclatura} quedó sin responsable permanente" : $llaves->count().' llaves quedaron sin responsable permanente';
                }
                // Reemplazo del único responsable de un departamento
                if ($revision['reemplazo'] !== [] && is_numeric($entrada['reemplazo_id'] ?? null)) {
                    $cuentas = User::where('colaborador_id', $registro->getKey())->pluck('id');
                    $n = 0;
                    $reemplazo = User::find((int) $entrada['reemplazo_id']);
                    $alcance = $reemplazo === null ? [] : app(Cobertura::class)->sedesDe($reemplazo);
                    foreach (DepartamentoResponsable::whereIn('user_id', $cuentas)->get() as $fila) {
                        // A-04: «todas sus sedes» solo para quien entra a todas; si no, una fila por cada sede suya
                        $sedes = $fila->sede_id === null && $alcance !== null ? array_map('intval', $alcance) : [$fila->sede_id];
                        foreach ($sedes as $sede) {
                            DepartamentoResponsable::firstOrCreate(['departamento_id' => $fila->departamento_id, 'user_id' => (int) $entrada['reemplazo_id'], 'sede_id' => $sede],
                                ['es_suplente' => $fila->es_suplente]);
                        }
                        $fila->delete();
                        $n++;
                    }
                    app(Cobertura::class)->olvidar();
                    if ($n > 0) {
                        $nombre = User::whereKey((int) $entrada['reemplazo_id'])->value('name');
                        $this->auditoria->auditar($actor, 'autorizaciones.responsables', $registro, null, ['reemplazo' => $nombre, 'responsabilidades' => $n]);
                        $hecho[] = "{$nombre} quedó como responsable en su lugar";
                    }
                }
                // Sus vehículos (viene marcado)
                if (filter_var($entrada['bajar_vehiculos'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $vehiculos = Vehiculo::where('colaborador_id', $registro->getKey())->where('activo', true)->get();
                    foreach ($vehiculos as $v) {
                        $v->forceFill(['activo' => false])->save();
                        $this->auditoria->auditar($actor, 'vehiculos.desactivado', $v, ['activo' => true], ['activo' => false, 'motivo' => 'Baja de su dueño']);
                    }
                    if ($vehiculos->isNotEmpty()) {
                        $hecho[] = $vehiculos->count() === 1 ? "su vehículo {$vehiculos->first()->placas} se dio de baja" : $vehiculos->count().' vehículos se dieron de baja';
                    }
                }
                // Su credencial con NFC deja de identificarlo
                if ($registro->getAttribute('etiqueta_nfc') !== null) {
                    $antes = $registro->getAttribute('etiqueta_nfc');
                    $registro->forceFill(['etiqueta_nfc' => null])->save();
                    $this->auditoria->auditar($actor, 'colaboradores.etiqueta_quitada', $registro, ['etiqueta_nfc' => $antes], ['etiqueta_nfc' => null, 'motivo' => 'Baja']);
                    $hecho[] = 'su etiqueta NFC se apagó';
                }
            }
            if ($tipo === 'llaves' || $tipo === 'equipos') {
                $this->marcarActivoNoRegreso($registro, $actor, $noRegresaron);
            }
        });

        foreach ($noRegresaron as [$activo, $origen, $descripcion]) {
            app(CustodiaActivos::class)->avisarNoRegreso($activo, $origen, $descripcion,
                'Se dio de baja a '.$this->nombre($tipo, $registro).' y no lo había regresado.', $actor);
        }
        if ($noRegresaron !== []) {
            $hecho[] = count($noRegresaron) === 1 ? $noRegresaron[0][2].' quedó «No regresó»' : count($noRegresaron).' artículos quedaron «No regresó»';
        }
        if ($revision['items'] !== [] && ! in_array($tipo, ['llaves', 'equipos'], true)) {
            $this->avisarJefe($tipo, $registro, $revision, $actor);
        }

        return $hecho;
    }

    /** «Juan Pérez», «la zona Sótano 1». */
    public function nombre(string $tipo, Model $registro): string
    {
        $definicion = RegistroBorrado::de($tipo === 'personas' ? 'personas' : $tipo);

        return $definicion !== null ? RegistroBorrado::conArticulo($definicion).' «'.RegistroBorrado::nombre($definicion, $registro).'»' : (string) $registro->getKey();
    }

    // -------------------------------------------------------------- Revisiones

    /** @return list<array<string, mixed>|null> */
    private function deColaborador(Colaborador $c): array
    {
        return [
            ...$this->entregadoA(Receptor::COLABORADOR, (int) $c->id),
            $this->item('llaves_permanentes', 'pendiente', Llave::where('colaborador_id', $c->id)->where('activo', true)->get(),
                fn ($n) => $n === 1 ? 'Es responsable permanente de 1 llave' : "Es responsable permanente de {$n} llaves",
                'Quedarán sin responsable permanente.', fn (Llave $l) => [$l->nomenclatura, route('llaves.index').'#llave-'.$l->id]),
            $this->item('pases', 'aviso', $this->pasesDeColaborador($c),
                fn ($n) => $n === 1 ? 'Tiene 1 pase de salida abierto a su nombre' : "Tiene {$n} pases de salida abiertos a su nombre",
                'Los equipos que salieron a su resguardo pasan a NO REGRESÓ; los demás pases siguen su curso.', fn (PaseSalida $p) => [$p->folio.' · '.$p->insignia(false)[0], route('pases-salida.show', $p->id)]),
            $this->item('en_sitio', 'aviso', Acceso::where('colaborador_id', $c->id)->where('estado', 'en_sitio')->where('movimiento', 'entrada')->get(),
                fn () => 'Está en sitio ahora', 'Dale salida en la Bitácora de accesos cuando se retire.', fn (Acceso $a) => ['Entró '.$a->entrada_at?->format('d/m H:i'), route('accesos.index')]),
            $this->item('host', 'aviso', Acceso::whereIn('estado', Acceso::ESTADOS_ABIERTOS)->where('movimiento', 'entrada')
                ->where(fn ($q) => $q->where('host_colaborador_id', $c->id)->orWhere('visita_colaborador_id', $c->id))->get(),
                fn ($n) => $n === 1 ? 'Tiene 1 visita en espera o en sitio' : "Tiene {$n} visitas en espera o en sitio",
                'Avísale a la caseta quién las atenderá.', fn (Acceso $a) => [$a->nombre, route('accesos.index')]),
            $c->etiqueta_nfc !== null ? ['clave' => 'nfc', 'nivel' => 'aviso', 'texto' => 'Tiene una etiqueta NFC en su credencial', 'efecto' => 'Se apaga: ya no lo identificará en el lector.', 'ejemplos' => []] : null,
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function dePersona(Persona $p): array
    {
        return [
            ...$this->entregadoA(Receptor::PERSONA, (int) $p->id),
            $this->item('en_sitio', 'aviso', Acceso::where('persona_id', $p->id)->whereIn('estado', Acceso::ESTADOS_ABIERTOS)->where('movimiento', 'entrada')->get(),
                fn () => 'Está en sitio o esperando autorización', 'Dale salida en la Bitácora de accesos cuando se retire.', fn (Acceso $a) => [$a->sede?->nombre ?? 'Bitácora de accesos', route('accesos.index')]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deProveedor(Proveedor $e): array
    {
        return [
            ...$this->entregadoA(Receptor::PROVEEDOR, (int) $e->id),
            $this->item('en_sitio', 'aviso', Acceso::where('proveedor_id', $e->id)->whereIn('estado', Acceso::ESTADOS_ABIERTOS)->where('movimiento', 'entrada')->get(),
                fn ($n) => $n === 1 ? '1 persona de esta empresa está en sitio' : "{$n} personas de esta empresa están en sitio",
                'Dales salida en la Bitácora de accesos cuando se retiren.', fn (Acceso $a) => [$a->nombre, route('accesos.index')]),
            $this->item('personal', 'aviso', Persona::where('proveedor_id', $e->id)->where('activo', true)->get(),
                fn ($n) => $n === 1 ? 'Tiene 1 persona activa en el padrón' : "Tiene {$n} personas activas en el padrón",
                'Siguen activas; si ya no vienen, dalas de baja en el Padrón de personas.', fn (Persona $p) => [$p->nombre_completo, route('personas.index').'#persona-'.$p->id]),
            $this->item('pases', 'aviso', PaseSalida::where('proveedor_id', $e->id)->whereIn('estado', PaseSalida::FUERA)->get(),
                fn ($n) => $n === 1 ? 'Tiene 1 pase de salida con equipo fuera' : "Tiene {$n} pases de salida con equipo fuera",
                'Los equipos siguen FUERA hasta que regresen.', fn (PaseSalida $p) => [$p->folio, route('pases-salida.show', $p->id)]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deVehiculo(Vehiculo $v): array
    {
        return [
            $this->item('en_sitio', 'aviso', Acceso::where('vehiculo_id', $v->id)->where('estado', 'en_sitio')->get(),
                fn () => 'Está dentro ahora', 'Libera su lugar o dale salida en la Bitácora de accesos.', fn (Acceso $a) => [$a->nombre.($a->zona?->nombre ? ' · '.$a->zona->nombre : ''), route('accesos.index')]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deLlave(Llave $l): array
    {
        $prestamo = PrestamoLlave::vigentes()->with('colaborador')->where('llave_id', $l->id)->get();
        $l->loadMissing('colaborador');

        return [
            $this->item('prestamo', 'pendiente', $prestamo, fn () => 'Está prestada',
                'Si no regresó, al continuar el préstamo queda «No regresó» y se abre su voucher a nombre de quien la tiene.',
                fn (PrestamoLlave $p) => [$p->nombreReceptor().' · desde '.$p->prestado_en?->format('d/m H:i'), route('prestamo_llaves.index').'#prestamo-'.$p->id]),
            $l->colaborador_id !== null ? ['clave' => 'responsable', 'nivel' => 'aviso', 'texto' => 'Tiene responsable permanente: '.($l->colaborador?->nombreCompleto() ?? '—'), 'efecto' => 'El voucher lo propone como responsable.', 'ejemplos' => []] : null,
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deEquipo(Equipo $e): array
    {
        $estado = app(CustodiaActivos::class)->estado($e);
        if (! in_array($estado['estado'], EstadoActivo::EN_CUSTODIA, true)) {
            return [];
        }

        return [[
            'clave' => 'custodia', 'nivel' => 'pendiente',
            'texto' => 'Está '.$estado['texto'].($estado['quien'] ? ' a cargo de '.$estado['quien'] : '').($estado['donde'] ? ' ('.$estado['donde'].')' : ''),
            'efecto' => 'Si no regresó, al continuar queda «No regresó» y se abre su voucher a nombre de quien lo tenía.',
            'ejemplos' => $estado['url'] ? [['texto' => 'Ver dónde está', 'url' => $estado['url']]] : [],
        ]];
    }

    /** @return list<array<string, mixed>|null> */
    private function deSede(Sede $s): array
    {
        // Cuentas cuya única sede es esta (usuario_roles.sede_id; null = todas las sedes)
        $cuentas = User::where('activo', true)->where('es_superadmin', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('usuario_roles')->whereColumn('usuario_roles.user_id', 'users.id')->where('usuario_roles.sede_id', $s->id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('usuario_roles')->whereColumn('usuario_roles.user_id', 'users.id')
                ->where(fn ($o) => $o->whereNull('usuario_roles.sede_id')->orWhere('usuario_roles.sede_id', '!=', $s->id)))
            ->get(['id', 'name']);

        return [
            $this->item('en_sitio', 'bloquea', Acceso::where('sede_id', $s->id)->whereIn('estado', Acceso::ESTADOS_ABIERTOS)->get(),
                fn ($n) => $n === 1 ? 'Hay 1 persona en sitio o esperando autorización' : "Hay {$n} personas en sitio o esperando autorización",
                'Dales salida en la Bitácora de accesos antes de dar de baja la sede.', fn (Acceso $a) => [$a->nombre, route('accesos.index')]),
            $this->item('prestamos', 'aviso', PrestamoLlave::vigentes()->with(['llave:id,nomenclatura', 'colaborador'])->where('sede_id', $s->id)->get(),
                fn ($n) => "Hay {$n} ".($n === 1 ? 'llave prestada' : 'llaves prestadas'), 'Recíbelas o márcalas «No regresó» en Préstamo de llaves.',
                fn (PrestamoLlave $p) => [($p->llave?->nomenclatura ?? 'Llave').' · '.$p->nombreReceptor(), route('prestamo_llaves.index').'#prestamo-'.$p->id]),
            $this->item('colaboradores', 'aviso', Colaborador::where('sede_id', $s->id)->where('activo', true)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'colaborador activo' : 'colaboradores activos'), 'Cámbialos de sede o dalos de baja.',
                fn (Colaborador $c) => [$c->nombreCompleto(), route('colaboradores.index')]),
            $this->item('cuentas', 'aviso', $cuentas->values(), fn ($n) => "{$n} ".($n === 1 ? 'cuenta solo tiene' : 'cuentas solo tienen').' esta sede',
                'Asígnales otra sede en Usuarios o no podrán trabajar.', fn (User $u) => [$u->name, route('usuarios.index')]),
            $this->item('rutas', 'aviso', Ruta::where('sede_id', $s->id)->where('activo', true)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'ruta de transporte activa' : 'rutas de transporte activas'), 'Siguen activas hasta que las des de baja.', fn (Ruta $r) => [$r->nombre, null]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deZona(ZonaEstacionamiento $z): array
    {
        return [
            $this->item('autos', 'bloquea', Acceso::where('zona_estacionamiento_id', $z->id)->where('estado', 'en_sitio')->get(),
                fn ($n) => $n === 1 ? 'Hay 1 auto dentro' : "Hay {$n} autos dentro",
                'Cámbialos de zona o libera su lugar en Estacionamientos antes de darla de baja.', fn (Acceso $a) => [($a->placas ?? '—').' · '.$a->nombre, route('estacionamientos.index').'#zona-'.$z->id]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deDepartamento(Departamento $d): array
    {
        return [
            $this->item('colaboradores', 'aviso', Colaborador::where('departamento_id', $d->id)->where('activo', true)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'colaborador activo' : 'colaboradores activos'), 'Cámbialos de departamento después.', fn (Colaborador $c) => [$c->nombreCompleto(), null]),
            $this->item('responsables', 'aviso', DepartamentoResponsable::with('usuario:id,name')->where('departamento_id', $d->id)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'responsable que autoriza' : 'responsables que autorizan'), 'Las visitas a este departamento dejarán de pedir su autorización.',
                fn (DepartamentoResponsable $r) => [$r->usuario?->name ?? '—', null]),
            $this->item('llaves', 'aviso', Llave::where('departamento_id', $d->id)->where('activo', true)->get(),
                fn ($n) => "{$n} ".($n === 1 ? 'llave es' : 'llaves son').' para este departamento', 'Cámbiales el departamento objetivo.', fn (Llave $l) => [$l->nomenclatura, route('llaves.index').'#llave-'.$l->id]),
            $this->item('vacantes', 'aviso', Vacante::where('departamento_id', $d->id)->whereIn('estado', ['borrador', 'publicada', 'pausada'])->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'vacante abierta' : 'vacantes abiertas'), 'Ciérralas o cámbialas de departamento.', fn (Vacante $v) => [(string) ($v->titulo ?? $v->id), null]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function dePuesto(Puesto $p): array
    {
        return [
            $this->item('colaboradores', 'aviso', Colaborador::where('puesto_id', $p->id)->where('activo', true)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'colaborador activo' : 'colaboradores activos'), 'Cámbialos de puesto después.', fn (Colaborador $c) => [$c->nombreCompleto(), null]),
            $this->item('llaves', 'aviso', Llave::where('puesto_id', $p->id)->where('activo', true)->get(),
                fn ($n) => "{$n} ".($n === 1 ? 'llave es' : 'llaves son').' para este puesto', 'Cámbiales el puesto objetivo.', fn (Llave $l) => [$l->nomenclatura, route('llaves.index').'#llave-'.$l->id]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deTurno(Turno $t): array
    {
        return [
            $this->item('colaboradores', 'aviso', Colaborador::where('turno_id', $t->id)->where('activo', true)->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'colaborador activo' : 'colaboradores activos'), 'Cámbialos de turno después.', fn (Colaborador $c) => [$c->nombreCompleto(), null]),
            $this->item('rutas', 'aviso', Ruta::where('turno_id', $t->id)->where('activo', true)->get(),
                fn ($n) => "{$n} ".($n === 1 ? 'ruta activa usa' : 'rutas activas usan').' este turno', 'El turno de la ruta es opcional: se puede quitar.', fn (Ruta $r) => [$r->nombre, null]),
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private function deEspacio(Espacio $e): array
    {
        return [
            $this->item('reservas', 'aviso', Reserva::where('espacio_id', $e->id)->whereIn('estado', Reserva::OCUPAN)->where('inicio', '>=', now())->get(),
                fn ($n) => "Tiene {$n} ".($n === 1 ? 'reserva futura' : 'reservas futuras'), 'Cancélalas o cámbialas de área en Reservas.', fn (Reserva $r) => [($r->folio ? $r->folio.' · ' : '').$r->titulo, null]),
            $this->item('llaves', 'aviso', $e->belongsToMany(Llave::class, 'espacio_llave')->where('llaves.activo', true)->get(),
                fn ($n) => "{$n} ".($n === 1 ? 'llave la abre' : 'llaves la abren'), 'Revisa a qué lugares abren.', fn (Llave $l) => [$l->nomenclatura, route('llaves.index').'#llave-'.$l->id]),
        ];
    }

    // -------------------------------------------------------------------- Apoyo

    /**
     * Lo que se le prestó o se le dejó en resguardo (C-16: colaborador, persona o empresa).
     *
     * @return list<array<string, mixed>|null>
     */
    private function entregadoA(string $tipo, int $id): array
    {
        $prestamos = PrestamoLlave::vigentes()->with('llave:id,nomenclatura')
            ->where(fn ($q) => $this->deReceptor($q, $tipo, $id, 'prestamos_llaves'))->get();
        $responsivas = Responsiva::with('equipos.equipo:id,numero_serie')->where('estado', Responsiva::EN_CAMPO)
            ->where(fn ($q) => $this->deReceptor($q, $tipo, $id, 'responsivas'))->get();

        return [
            $this->item('prestamos', 'pendiente', $prestamos, fn ($n) => $n === 1 ? 'Tiene 1 llave prestada' : "Tiene {$n} llaves prestadas",
                'Si no las regresa, al dar de baja quedan «No regresó» y se avisa para su voucher.',
                fn (PrestamoLlave $p) => [($p->llave?->nomenclatura ?? 'Llave').' · desde '.$p->prestado_en?->format('d/m H:i'), route('prestamo_llaves.index').'#prestamo-'.$p->id]),
            $this->item('responsivas', 'pendiente', $responsivas, fn ($n) => $n === 1 ? 'Tiene 1 responsiva con equipo a su cargo' : "Tiene {$n} responsivas con equipo a su cargo",
                'Si no los regresa, al dar de baja sus equipos quedan «No regresó» y se avisa para su voucher.',
                fn (Responsiva $r) => [$r->folio.' · '.$r->equipos->whereNull('devuelto_en')->map(fn ($f) => $f->equipo?->numero_serie)->filter()->join(', '), route('responsivas.index').'#responsiva-'.$r->id]),
        ];
    }

    private function deReceptor(Builder $q, string $tipo, int $id, string $tabla): void
    {
        $q->where(fn ($r) => $r->where($tabla.'.receptor_tipo', $tipo)->where($tabla.'.receptor_id', $id));
        if ($tipo === Receptor::COLABORADOR) {
            $q->orWhere(fn ($r) => $r->whereNull($tabla.'.receptor_tipo')->where($tabla.'.colaborador_id', $id));
        }
    }

    /** @return Collection<int, PaseSalida> */
    private function pasesDeColaborador(Colaborador $c): Collection
    {
        return PaseSalida::whereIn('estado', [PaseSalida::PENDIENTE, PaseSalida::APROBADO, ...PaseSalida::FUERA])
            ->where(fn ($q) => $q->where('colaborador_id', $c->id)->orWhere('colaborador_destino_id', $c->id))->get();
    }

    /**
     * Préstamos y responsivas vigentes a su nombre → NO REGRESÓ.
     *
     * @return list<array{0: Model, 1: string, 2: string}>
     */
    private function marcarNoRegreso(string $tipo, int $id, User $actor): array
    {
        $avisos = [];
        foreach (PrestamoLlave::vigentes()->where(fn ($q) => $this->deReceptor($q, $tipo, $id, 'prestamos_llaves'))->get() as $p) {
            $antes = $p->only(['estado']);
            $p->forceFill(['estado' => PrestamoLlave::NO_REGRESO])->save();
            $this->auditoria->auditar($actor, 'prestamo_llaves.no_regreso', $p, $antes, ['estado' => PrestamoLlave::NO_REGRESO, 'motivo' => 'Baja de quien la tenía']);
            app(CampanaActivos::class)->resolverLlave($p);
            $llave = Llave::find($p->llave_id);
            if ($llave !== null && $llave->activo) {
                $llave->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
                $avisos[] = [$llave, 'llave', 'llave '.$llave->nomenclatura];
            }
        }
        foreach (Responsiva::where('estado', Responsiva::EN_CAMPO)->where(fn ($q) => $this->deReceptor($q, $tipo, $id, 'responsivas'))->get() as $r) {
            foreach (EquipoResponsiva::where('responsiva_id', $r->id)->whereNull('devuelto_en')->get() as $fila) {
                $equipo = Equipo::find($fila->equipo_id);
                if ($equipo !== null && in_array($equipo->estado, EstadoActivo::EN_RESGUARDO, true)) {
                    $equipo->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
                    $this->auditoria->auditar($actor, 'equipos.no_regreso', $equipo, ['estado' => $equipo->getOriginal('estado')], ['estado' => EstadoActivo::NO_REGRESO, 'responsiva' => $r->folio]);
                    $avisos[] = [$equipo, 'equipo', 'equipo '.$equipo->numero_serie];
                }
            }
        }

        return $avisos;
    }

    /**
     * «Dar de baja igual» de una llave prestada o un equipo a cargo de alguien
     * (C-14 + DD-06): queda NO REGRESÓ y la baja con voucher ya se permite.
     *
     * @param  list<array{0: Model, 1: string, 2: string}>  $avisos
     */
    private function marcarActivoNoRegreso(Model $activo, User $actor, array &$avisos): void
    {
        if ($activo instanceof Llave) {
            foreach (PrestamoLlave::vigentes()->where('llave_id', $activo->id)->get() as $p) {
                $p->forceFill(['estado' => PrestamoLlave::NO_REGRESO])->save();
                $this->auditoria->auditar($actor, 'prestamo_llaves.no_regreso', $p, ['estado' => PrestamoLlave::EN_USO], ['estado' => PrestamoLlave::NO_REGRESO]);
                app(CampanaActivos::class)->resolverLlave($p);
            }
            if ($activo->activo && ! $activo->noRegreso()) {
                $activo->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
            }
        }
        if ($activo instanceof Equipo && in_array($activo->estado, EstadoActivo::EN_CUSTODIA, true)) {
            $antes = $activo->estado;
            $activo->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
            $this->auditoria->auditar($actor, 'equipos.no_regreso', $activo, ['estado' => $antes], ['estado' => EstadoActivo::NO_REGRESO]);
        }
    }

    /**
     * Único responsable activo de un departamento (en alguna sede): sin él
     * nadie respondería las autorizaciones.
     *
     * @return list<array{id: int, nombre: string}>
     */
    private function responsableUnicoDe(Colaborador $c): array
    {
        $cuentas = User::where('colaborador_id', $c->id)->pluck('id')->map(fn ($id) => (int) $id);
        if ($cuentas->isEmpty()) {
            return [];
        }
        // C-08: «único» con la misma regla de cobertura que la caseta (los demás deben poder responder en esa sede)
        $cobertura = app(Cobertura::class);
        $mapa = $cobertura->mapa();
        $filas = $cobertura->filas();
        $resultado = [];
        foreach ($filas->filter(fn ($f) => $cuentas->contains((int) $f->user_id)) as $fila) {
            $depto = (int) $fila->departamento_id;
            if (! isset($mapa[$depto])) {
                continue; // departamento dado de baja
            }
            $sedes = $fila->sede_id !== null ? [(int) $fila->sede_id] : array_keys($mapa[$depto]['sedes']);
            foreach ($sedes as $sede) {
                $otros = $filas->contains(fn ($o) => (int) $o->departamento_id === $depto && ! $cuentas->contains((int) $o->user_id) && $cobertura->cubre($o, (int) $sede));
                if (! $otros) {
                    $resultado[$depto] = ['id' => $depto, 'nombre' => $mapa[$depto]['nombre']];
                }
            }
        }

        return array_values($resultado);
    }

    /**
     * Lo pendiente llega a la campana de quien cuida los activos en la sede
     * (quien da de baja llaves o equipos), regla 1.
     */
    private function avisarJefe(string $tipo, Model $registro, array $revision, User $actor): void
    {
        $sede = $registro instanceof Sede ? $registro->id : ($registro->getAttributes()['sede_id'] ?? null);
        $campana = app(CampanaActivos::class);
        $usuarios = $campana->conPermiso((int) $actor->getAttribute('empresa_id') ?: (int) $registro->getAttribute('empresa_id'), 'llaves.eliminar', $sede)
            ->merge($campana->conPermiso((int) $registro->getAttribute('empresa_id'), 'equipos.eliminar', $sede))
            ->unique()->reject(fn (int $id) => $id === (int) $actor->id)->values();
        $lista = collect($revision['items'])->pluck('texto')->join('; ');
        $this->centro->avisar((int) $registro->getAttribute('empresa_id'), $usuarios, 'baja_con_pendientes', [
            'titulo' => 'Baja con pendientes: '.$this->nombre($tipo, $registro),
            'texto' => mb_substr($actor->name.' dio de baja '.$this->nombre($tipo, $registro).'. Quedó pendiente: '.$lista.'.', 0, 500),
            'url' => self::TIPOS[$tipo][3] !== null ? route(self::TIPOS[$tipo][3]) : null,
            'referencia_tipo' => 'baja_'.$tipo,
            'referencia_id' => (int) $registro->getKey(),
        ]);
    }

    /**
     * @param  Collection<int, Model>  $filas
     * @return array{clave: string, nivel: string, texto: string, efecto: ?string, ejemplos: list<array{texto: string, url: ?string}>}|null
     */
    private function item(string $clave, string $nivel, Collection $filas, callable $texto, ?string $efecto, callable $ejemplo): ?array
    {
        if ($filas->isEmpty()) {
            return null;
        }

        return [
            'clave' => $clave, 'nivel' => $nivel, 'texto' => $texto($filas->count()), 'efecto' => $efecto,
            'ejemplos' => $filas->take(self::EJEMPLOS)->map(function (Model $m) use ($ejemplo) {
                [$t, $u] = $ejemplo($m);

                return ['texto' => (string) $t, 'url' => $u];
            })->values()->all(),
        ];
    }
}
