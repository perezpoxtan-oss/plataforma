<?php

namespace App\Services\Novedades;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundUmbral;
use App\Models\Novedad;
use App\Models\Persona;
use App\Models\RoboDetalle;
use App\Models\User;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Archivo de Lost & Found (SEGCAT: lf_archivo.php, lf_articulo_cerrar_modal.php,
 * lf_articulo_proceso.php, lf_config_umbrales.php, lf_auditoria_pdf.php).
 *
 * Permisos del submódulo "lost_found":
 *  - ver: el archivo, la ficha del artículo y su firma de entrega;
 *  - imprimir: etiqueta de la bolsa y Auditoría de Inventario;
 *  - firmar: "Cerrar / Entregar" (el cierre siempre lleva firma);
 *  - configurar: "Días de Resguardo" (con alcance de toda la empresa: los
 *    días son de la empresa, no de una sede).
 * Con alcance de sede solo se ve y se toca lo de sus sedes; con "propios",
 * solo lo que él registró. Fuera de su alcance u otra empresa: 404.
 *
 * Estatus de un artículo: EN_RESGUARDO → DEVUELTO (en persona o por
 * paquetería) | DONADO | DESTRUIDO | ENTREGADO_BENEFICENCIA. Un artículo
 * cerrado ya no se vuelve a cerrar (SEGCAT permitía cierres repetidos).
 */
class ArchivoLostFound
{
    /** Pestañas del archivo => estatus que muestran (null = todos). */
    public const FILTROS = [
        'todos' => null,
        'resguardo' => ['EN_RESGUARDO'],
        'urgentes' => ['EN_RESGUARDO'],
        'devueltos' => ['DEVUELTO'],
        'otros' => ['DONADO', 'DESTRUIDO', 'ENTREGADO_BENEFICENCIA'],
    ];

    public const POR_PAGINA = 60;

    /** Máximo de días de resguardo que se puede configurar (10 años). */
    public const MAX_DIAS = 3650;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorNovedades $novedades,
        private readonly AdministradorRoles $auditoria,
        private readonly Firmas $firmas,
        private readonly Tenant $tenant,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Limita los artículos al alcance del actor en un permiso de Lost & Found.
     *
     * @param  Builder<LostFoundArticulo>  $consulta
     * @return Builder<LostFoundArticulo>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        if ($efectivo->alcance === Alcance::Empresa) {
            return $consulta;
        }

        return $consulta
            ->when($efectivo->sedes !== null, fn ($q) => $q->whereIn('lost_found_articulos.sede_id', $efectivo->sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('lost_found_articulos.creado_por', $actor->id));
    }

    /** ¿Puede hacer esto sobre el artículo? (misma regla que limitar). */
    public function permite(User $actor, string $permiso, LostFoundArticulo $articulo): bool
    {
        return $this->limitar(LostFoundArticulo::query()->whereKey($articulo->id), $actor, $permiso)->exists();
    }

    /** Artículo dentro del alcance del permiso; si no, 404. */
    public function buscar(User $actor, int $id, string $permiso): LostFoundArticulo
    {
        $articulo = $this->limitar(LostFoundArticulo::query(), $actor, $permiso)->find($id);
        abort_if($articulo === null, 404);

        return $articulo;
    }

    /** Los días de resguardo se configuran para toda la empresa: hace falta el permiso con alcance de empresa. */
    public function puedeConfigurar(User $actor): bool
    {
        if ($actor->es_superadmin) {
            return true;
        }

        return ($this->autorizador->permisosEfectivos($actor)['lost_found.configurar'] ?? null)?->alcance === Alcance::Empresa;
    }

    // ------------------------------------------------------------------ Archivo

    /**
     * Filtros de la pantalla: pestaña, texto, sede y tipo de valor.
     *
     * @param  Builder<LostFoundArticulo>  $consulta
     * @param  array{filtro?: ?string, q?: ?string, sede?: mixed, tipo?: ?string}  $f
     * @param  array<string, int>  $umbrales
     * @return Builder<LostFoundArticulo>
     */
    public function filtrar(Builder $consulta, array $f, array $umbrales): Builder
    {
        $filtro = array_key_exists($f['filtro'] ?? '', self::FILTROS) ? $f['filtro'] : 'todos';
        $estatus = self::FILTROS[$filtro];
        $consulta->when($estatus !== null, fn ($q) => $q->whereIn('lost_found_articulos.estatus', $estatus));
        if ($filtro === 'urgentes') {
            $this->soloUrgentes($consulta, $umbrales);
        }
        if (! empty($f['sede'])) {
            $consulta->where('lost_found_articulos.sede_id', (int) $f['sede']);
        }
        if (isset(LostFoundArticulo::TIPOS_VALOR[$f['tipo'] ?? ''])) {
            $consulta->where('lost_found_articulos.tipo_valor', $f['tipo']);
        }
        $texto = trim((string) ($f['q'] ?? ''));
        if ($texto !== '') {
            // Se guardan en mayúsculas: se compara en mayúsculas (SQLite no pasa a minúsculas las letras acentuadas)
            $como = '%'.addcslashes(mb_strtoupper($texto), '%_\\').'%';
            $consulta->where(function (Builder $q) use ($como) {
                foreach (['folio', 'objeto', 'marca', 'color', 'ubicacion_bodega', 'lugar_detalle'] as $columna) {
                    $q->orWhere('lost_found_articulos.'.$columna, 'like', $como);
                }
                $q->orWhereHas('areaEspecifica', fn ($x) => $x->where('nombre', 'like', $como))
                    ->orWhereHas('novedad', fn ($x) => $x->where('ubicacion', 'like', $como));
            });
        }

        return $consulta;
    }

    /**
     * En resguardo con el semáforo en amarillo o rojo: lleva al menos el 70 %
     * de los días de su tipo de valor.
     *
     * @param  Builder<LostFoundArticulo>  $consulta
     * @param  array<string, int>  $umbrales
     * @return Builder<LostFoundArticulo>
     */
    public function soloUrgentes(Builder $consulta, array $umbrales): Builder
    {
        return $consulta->where('lost_found_articulos.estatus', LostFoundArticulo::EN_RESGUARDO)
            ->where(function (Builder $q) use ($umbrales) {
                foreach (LostFoundArticulo::TIPOS_VALOR as $tipo => $texto) {
                    $dias = (int) ceil(($umbrales[$tipo] ?? LostFoundUmbral::POR_OMISION['OTRO']) * 0.7);
                    $q->orWhere(fn (Builder $x) => $x->where('lost_found_articulos.tipo_valor', $tipo)
                        ->where('lost_found_articulos.created_at', '<=', now()->subDays($dias)));
                }
            });
    }

    /**
     * Tickets de Lost & Found sin ningún artículo capturado (SEGCAT: "Sin
     * artículos capturados aún"), para completarlos.
     *
     * @return Builder<Novedad>
     */
    public function ticketsSinArticulos(User $actor, ?string $texto, ?int $sede): Builder
    {
        $texto = trim((string) $texto);
        $como = '%'.addcslashes(mb_strtoupper($texto), '%_\\').'%';

        return $this->novedades->limitar(Novedad::query(), $actor, 'ver')
            ->where('categoria', 'lost_found')
            ->where('estatus', '!=', Novedad::RESUELTO)
            ->whereDoesntHave('articulos')
            ->when($sede !== null, fn ($q) => $q->where('sede_id', $sede))
            ->when($texto !== '', fn ($q) => $q->where(fn ($x) => $x->where('ubicacion', 'like', $como)->orWhere('descripcion', 'like', '%'.addcslashes($texto, '%_\\').'%')))
            ->with('sede:id,nombre')
            ->orderByDesc('created_at');
    }

    // ------------------------------------------------------- Cerrar / Entregar

    /**
     * Registrar Cierre (SEGCAT: lf_articulo_proceso.php?accion=cerrar).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function cerrar(User $actor, LostFoundArticulo $articulo, array $entrada): LostFoundEntrega
    {
        if (! $articulo->enResguardo()) {
            throw ValidationException::withMessages(['tipo_cierre' => "El artículo {$articulo->folio} ya se cerró ({$articulo->etiquetaEstatus()}). Un artículo cerrado no se vuelve a cerrar."]);
        }
        $entrada = array_map(fn ($v) => is_string($v) ? trim((string) preg_replace('/[ \t]+/u', ' ', $v)) : $v, $entrada);
        $tipo = $entrada['tipo_cierre'] ?? null;
        $persona = $tipo === 'PERSONA';
        $paqueteria = $tipo === 'PAQUETERIA';

        $v = Validator::make($entrada, [
            'tipo_cierre' => ['required', 'in:'.implode(',', array_keys(LostFoundEntrega::TIPOS_CIERRE))],
            'recibe_es' => ['nullable', 'in:externo,colaborador'],
            'nombre_recibe' => [$persona || $paqueteria || $tipo === 'BENEFICENCIA' ? 'required_unless:recibe_es,colaborador' : 'nullable', 'nullable', 'string', 'max:150'],
            'tipo_identificacion' => ['nullable', 'string', 'max:50'],
            'correo_recibe' => ['nullable', 'email', 'max:150'],
            'paqueteria' => [$paqueteria ? 'required' : 'nullable', 'nullable', 'in:'.implode(',', LostFoundEntrega::PAQUETERIAS)],
            'numero_guia' => [$paqueteria ? 'required' : 'nullable', 'nullable', 'string', 'max:100'],
            'colaborador_id' => ['nullable', 'integer'],
            'persona_id' => ['nullable', 'integer'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'firma' => ['nullable', 'string'],
        ], [
            'tipo_cierre.required' => 'Elige cómo se cierra el artículo.',
            'tipo_cierre.in' => 'Elige cómo se cierra el artículo.',
            'nombre_recibe.required_unless' => $tipo === 'BENEFICENCIA' ? 'Escribe la institución o persona que recibe la donación.' : 'Escribe el nombre de quien recibe.',
            'paqueteria.required' => 'Elige la paquetería.',
            'paqueteria.in' => 'Elige la paquetería de la lista.',
            'numero_guia.required' => 'Escribe el número de guía del envío.',
            'correo_recibe.email' => 'Revisa el correo electrónico (ejemplo: nombre@correo.com).',
            'max' => 'El campo «:attribute» es muy largo (máximo :max caracteres).',
        ], [
            'nombre_recibe' => 'Nombre de quien recibe', 'tipo_identificacion' => 'Tipo de identificación', 'correo_recibe' => 'Correo electrónico',
            'numero_guia' => 'Número de guía', 'observaciones' => 'Comentarios adicionales',
        ])->validate();

        // Quién recibe: colaborador (lector universal) o persona del padrón
        $colaborador = null;
        if ($tipo === 'DONADO' || ($persona && ($v['recibe_es'] ?? null) === 'colaborador')) {
            $colaborador = $this->colaboradorValido($v['colaborador_id'] ?? null, $tipo === 'DONADO'
                ? 'Escanea el gafete o busca al colaborador que recibe la donación.'
                : 'Escanea el gafete o busca al colaborador que recibe el artículo.');
        }
        $personaPadron = $persona && $colaborador === null ? $this->personaValida($v['persona_id'] ?? null) : null;

        $nombre = $colaborador !== null ? $colaborador->nombreCompleto() : ($v['nombre_recibe'] ?? null);
        $identificacion = $v['tipo_identificacion'] ?? null;
        if ($personaPadron !== null && ($identificacion === null || $identificacion === '')) {
            $identificacion = Persona::IDENTIFICACIONES[$personaPadron->tipo_identificacion] ?? null;
        }

        // Firma obligatoria (dedo, lápiz o mouse) en el disco privado
        $ruta = $this->firmas->guardar($v['firma'] ?? null, 'lost-found', 'firma', mb_strtolower(LostFoundEntrega::ETIQUETAS_FIRMA[$tipo]));

        try {
            $entrega = DB::transaction(function () use ($actor, $articulo, $tipo, $v, $persona, $paqueteria, $colaborador, $personaPadron, $nombre, $identificacion, $ruta) {
                // Otra pestaña o persona pudo cerrarlo al mismo tiempo
                $fresco = LostFoundArticulo::query()->lockForUpdate()->find($articulo->id);
                if ($fresco === null || ! $fresco->enResguardo()) {
                    throw ValidationException::withMessages(['tipo_cierre' => "El artículo {$articulo->folio} ya se cerró. Recarga la pantalla."]);
                }

                $entrega = LostFoundEntrega::create([
                    'articulo_id' => $articulo->id,
                    'tipo_cierre' => $tipo,
                    'colaborador_id' => $colaborador?->id,
                    'persona_id' => $personaPadron?->id,
                    'nombre_recibe' => $this->mayus($nombre),
                    'tipo_identificacion' => $persona ? $this->mayus($identificacion) : null,
                    'correo_recibe' => $persona || $paqueteria ? (($v['correo_recibe'] ?? null) ?: null) : null,
                    'paqueteria' => $paqueteria ? $v['paqueteria'] : null,
                    'numero_guia' => $paqueteria ? $this->mayus($v['numero_guia'] ?? null) : null,
                    'firma_ruta' => $ruta,
                    'observaciones' => ($v['observaciones'] ?? null) ?: null,
                ]);
                $articulo->forceFill([
                    'estatus' => LostFoundArticulo::ESTATUS_POR_CIERRE[$tipo],
                    'cerrado_en' => now(),
                    'cerrado_por' => $actor->id,
                ])->save();

                // Queda en el Minuto a Minuto del ticket (y del Robo, si el hallazgo era lo "robado")
                $texto = "Cerró el artículo {$articulo->folio} ({$articulo->objeto}): ".LostFoundEntrega::OPCIONES_CIERRE[$tipo]
                    .($entrega->nombre_recibe ? ' — recibe '.$entrega->nombre_recibe : '')
                    .($entrega->numero_guia ? ' — guía '.$entrega->paqueteria.' '.$entrega->numero_guia : '').'.';
                $this->novedades->anotar($articulo->novedad, $actor, $texto, 'sistema');
                RoboDetalle::with('novedad')->where('articulo_vinculado_id', $articulo->id)->get()
                    ->each(fn (RoboDetalle $r) => $r->novedad && $this->novedades->anotar($r->novedad, $actor, 'El hallazgo vinculado '.$texto, 'sistema'));

                return $entrega;
            });
        } catch (\Throwable $e) {
            $this->firmas->borrar($ruta);
            throw $e;
        }

        $this->auditoria->auditar($actor, 'lost_found.cerrado', $articulo, ['estatus' => LostFoundArticulo::EN_RESGUARDO], [
            'estatus' => $articulo->estatus, 'folio' => $articulo->folio, 'tipo_cierre' => $tipo, 'recibe' => $entrega->nombre_recibe,
            'colaborador_id' => $entrega->colaborador_id, 'persona_id' => $entrega->persona_id, 'paqueteria' => $entrega->paqueteria, 'numero_guia' => $entrega->numero_guia,
        ]);

        return $entrega;
    }

    private function colaboradorValido(mixed $id, string $mensaje): Colaborador
    {
        $colaborador = $id === null || $id === '' ? null
            : Colaborador::where('activo', true)->whereNull('fusionado_en_id')->find((int) $id);
        if ($colaborador === null) {
            throw ValidationException::withMessages(['colaborador_id' => $mensaje]);
        }

        return $colaborador;
    }

    private function personaValida(mixed $id): ?Persona
    {
        if ($id === null || $id === '') {
            return null;
        }
        $persona = Persona::where('activo', true)->find((int) $id);
        if ($persona === null) {
            throw ValidationException::withMessages(['persona_id' => 'La persona elegida no está en el Padrón de personas de esta empresa o está desactivada.']);
        }

        return $persona;
    }

    private function mayus(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_strtoupper($texto);
    }

    // ------------------------------------------------------- Días de Resguardo

    /**
     * Guardar los Días de Resguardo de la empresa (SEGCAT: lf_config_umbrales.php).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, int> los días que quedaron
     */
    public function guardarUmbrales(User $actor, array $entrada): array
    {
        $reglas = $atributos = [];
        foreach (LostFoundArticulo::TIPOS_VALOR as $tipo => $texto) {
            $reglas['dias.'.$tipo] = ['required', 'integer', 'min:1', 'max:'.self::MAX_DIAS];
            $atributos['dias.'.$tipo] = $texto;
        }
        $v = Validator::make($entrada, $reglas, [
            'required' => 'Escribe los días de «:attribute».',
            'integer' => 'Los días de «:attribute» deben ser un número entero.',
            'min' => 'Los días de «:attribute» deben ser al menos 1: un artículo no puede vencer el mismo día que se encuentra.',
            'max' => 'Los días de «:attribute» no pueden pasar de :max (10 años).',
        ], $atributos)->validate();

        $antes = LostFoundUmbral::vigentes();
        DB::transaction(function () use ($v) {
            foreach ($v['dias'] as $tipo => $dias) {
                LostFoundUmbral::updateOrCreate(['tipo_valor' => $tipo], ['dias' => (int) $dias]);
            }
        });
        $despues = LostFoundUmbral::vigentes();

        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'lost_found.configurado', Empresa::findOrFail($this->tenant->empresaId()), $antes, $despues);
        }

        return $despues;
    }
}
