<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Support\Terminos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registro de la Bitácora de accesos (SEGCAT: bitacora_accesos).
 *
 * Estados: proveedor y contratista nacen "pendiente" (el host debe
 * autorizar); los demás nacen "en_sitio". La salida los deja "finalizado".
 *
 * Movimientos: "entrada" es el ingreso normal. Un huésped, proveedor o
 * contratista que sale un rato (tour, por material) genera una fila
 * "salida_temporal" ligada a su entrada (acceso_origen_id), que sigue en
 * sitio hasta su regreso; al volver se cierra y se deja una fila "regreso"
 * ya finalizada, con el vehículo y conductor del regreso.
 */
class Acceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** clave => etiqueta en tarjetas y filtros (SEGCAT: textoTipo()) */
    public const TIPOS = [
        'colaborador' => 'Colaborador',
        'huesped' => 'Huésped',
        'visitante' => 'Personal Externo',
        'proveedor' => 'Proveedor',
        'contratista' => 'Contratista',
        'emergencia' => 'Servicio de Emergencia',
    ];

    /** Opciones del formulario (textos de SEGCAT). */
    public const OPCIONES_TIPO = [
        'colaborador' => 'Colaborador',
        'huesped' => 'Huésped',
        'visitante' => 'Personal Externo / Visita',
        'proveedor' => 'Proveedor',
        'contratista' => 'Contratista (Obra)',
        'emergencia' => 'Servicio de Emergencia',
    ];

    /** Ícono de cada tipo en el formulario. */
    public const ICONOS_TIPO = [
        'colaborador' => 'bi-person-badge', 'huesped' => 'bi-suitcase2', 'visitante' => 'bi-person-vcard',
        'proveedor' => 'bi-truck', 'contratista' => 'bi-cone-striped', 'emergencia' => 'bi-heart-pulse',
    ];

    /** Colaboradores, huéspedes y emergencias nunca llevan gafete de control. */
    public const SIN_GAFETE = ['colaborador', 'huesped', 'emergencia'];

    /** Nacen pendientes: el acceso físico se autoriza cuando el host confirma. */
    public const CON_AUTORIZACION = ['proveedor', 'contratista'];

    /**
     * Proveedor o contratista que espera al departamento pero el departamento
     * no tiene responsable en la sede (accesos.autorizacion): la caseta lo
     * pasa con «Autorizar por excepción».
     */
    public const SIN_RESPONSABLE = 'sin_resp';

    /** Pasó con «Autorizar por excepción» (el motivo queda en motivo_excepcion). */
    public const POR_EXCEPCION = 'excepcion';

    /** Pueden salir un rato y volver sin cerrar su visita (tour / salida temporal). */
    public const CON_SALIDA_TEMPORAL = ['huesped', 'proveedor', 'contratista'];

    /** Quedan en el Padrón de personas. */
    public const AL_PADRON = ['visitante', 'proveedor', 'contratista'];

    public const ESTADOS = ['pendiente' => 'PENDIENTE', 'en_sitio' => 'EN SITIO', 'finalizado' => 'FINALIZADO'];

    /** Estados en los que la persona tiene (o espera) su gafete y su lugar. */
    public const ESTADOS_ABIERTOS = ['pendiente', 'en_sitio'];

    public const MOVIMIENTOS = ['entrada' => 'Ingreso', 'salida_temporal' => 'Salida temporal', 'regreso' => 'Regreso'];

    public const MODOS = ['a_pie' => 'A pie', 'moto' => 'Motocicleta', 'auto' => 'Automóvil'];

    /** Identificación que se queda en caseta (SEGCAT: ID Custodiada). */
    public const IDENTIFICACIONES = ['ine' => 'INE / IFE', 'licencia' => 'Licencia Conducir', 'pasaporte' => 'Pasaporte'];

    // «departamento»: Recepción (ADR-0007). «general» y «huesped»: C-04 / DD-23 (cuáles ofrece cada empresa: App\Services\Accesos\MotivosVisita)
    public const MOTIVOS = ['rh' => 'Recursos Humanos', 'colaborador' => 'Visita a Colaborador', 'departamento' => 'Visita a Departamento',
        'general' => 'Visita general', 'huesped' => 'Visita a huésped'];

    /**
     * Recursos Humanos: ¿a qué viene? La caseta ve TRES opciones
     * (OPCIONES_CASETA). Con «Ya tiene cita» la plataforma busca su ficha y
     * deduce sola si viene a su entrevista con el departamento, a entregar
     * documentos o a firmar su contrato; si no encuentra ficha, lo registra
     * como «Busca empleo». Aquí están todos los valores que puede traer
     * accesos.viene_a (los deducidos y los de antes, para poder leerlos).
     */
    public const VIENE_A = [
        'busca_empleo' => 'Busca empleo', 'cita' => 'Cita con RR. HH.', 'entrevista' => 'Entrevista', 'documentos' => 'Entrega de documentos',
        'firma' => 'Firma de contrato', 'tramite' => 'Otro trámite de RR. HH.',
        'informes' => 'Informes / ver vacantes', // valor de antes: ya no se ofrece (el enlace a las vacantes se muestra siempre)
    ];

    /** Las tres opciones que ve la caseta. */
    public const OPCIONES_CASETA = ['busca_empleo' => 'Busca empleo', 'cita' => 'Ya tiene cita', 'tramite' => 'Otro trámite de RR. HH.'];

    /** Texto corto de «Viene a» (panel de Recepción y tarjeta de caseta). */
    public const VIENE_A_CORTO = [
        'busca_empleo' => 'Busca empleo', 'cita' => 'Cita', 'entrevista' => 'Entrevista', 'documentos' => 'Documentos', 'firma' => 'Firma',
        'informes' => 'Informes', 'tramite' => 'Trámite',
    ];

    /** «¿A qué viene?» que requieren una ficha de candidato existente («cita» se deduce; los demás son valores de antes). */
    public const VIENE_CON_FICHA = ['cita', 'entrevista', 'documentos', 'firma'];

    /** Los que abren o usan la ficha del candidato (lo demás es un trámite sin ficha). */
    public const VIENE_COMO_CANDIDATO = ['busca_empleo', 'cita', 'entrevista', 'documentos', 'firma'];

    public const PASES = ['estancia' => 'Estancia', 'daypass' => 'Daypass', 'nightpass' => 'Nightpass'];

    public const TIPOS_VISITA = ['cortesia' => 'Cortesía', 'levantamiento' => 'Levantamiento / Recorrido', 'ejecucion' => 'Ejecución de Trabajo'];

    public const TIPOS_EMERGENCIA = [
        'ambulancia' => 'Ambulancia', 'bomberos' => 'Bomberos', 'proteccion_civil' => 'Protección Civil', 'policia' => 'Policía', 'otro' => 'Otro',
    ];

    /** Nombre cuando una emergencia llega sin que dé tiempo de capturarlo. */
    public const NOMBRE_EMERGENCIA = 'UNIDAD DE EMERGENCIA';

    protected $table = 'accesos';

    protected $attributes = ['movimiento' => 'entrada', 'estado' => 'en_sitio', 'num_acompanantes' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'tipo', 'movimiento', 'acceso_origen_id', 'estado', 'nombre',
        'colaborador_id', 'persona_id', 'proveedor_id', 'empresa_procedencia', 'motivo_visita',
        'visita_colaborador_id', 'persona_visita', 'host_colaborador_id', 'identificacion',
        'gafete_id', 'gafete_texto', 'modo_arribo', 'vehiculo_id', 'placas', 'zona_estacionamiento_id',
        'conductor', 'num_acompanantes', 'tiene_reserva', 'numero_reserva', 'tipo_pase', 'habitacion',
        'tipo_visita', 'departamento_id', 'viene_a', 'area_trabajo', 'actividad', 'tipo_emergencia', 'observaciones',
        'entrada_at', 'aviso_padron',
    ];

    protected function casts(): array
    {
        return [
            'entrada_at' => 'datetime', 'autorizado_at' => 'datetime', 'salida_at' => 'datetime',
            'tiene_reserva' => 'boolean', 'num_acompanantes' => 'integer',
            'gafete_no_regreso' => 'boolean', 'identificacion_devuelta' => 'boolean',
            // C-25: «Pedir al supervisor» y la excepción del agente
            'supervisor_pedido_en' => 'datetime', 'excepcion_por_revisar' => 'boolean', 'excepcion_revisada_en' => 'datetime',
        ];
    }

    // -------------------------------------------------------------- Relaciones

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'host_colaborador_id');
    }

    public function visitaColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'visita_colaborador_id');
    }

    public function gafete(): BelongsTo
    {
        return $this->belongsTo(Gafete::class);
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(ZonaEstacionamiento::class, 'zona_estacionamiento_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'acceso_origen_id');
    }

    public function acompanantes(): HasMany
    {
        return $this->hasMany(AcompananteAcceso::class)->orderBy('id');
    }

    /** Salida temporal (tour) que sigue abierta: la persona está fuera ahora (solo puede haber una). */
    public function salidaTemporalAbierta(): HasOne
    {
        return $this->hasOne(self::class, 'acceso_origen_id')
            ->where('movimiento', 'salida_temporal')->where('estado', 'en_sitio');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    public function salidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salida_por');
    }

    // Recepción de candidatos y autorizaciones (ADR-0007)

    /** Ficha del candidato que se registró con este acceso. */
    public function candidatoRecepcion(): HasOne
    {
        return $this->hasOne(Candidato::class, 'acceso_id');
    }

    /** Postulación del candidato a la que se ligó esta visita (fase 1 de candidatos). */
    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    /** Solicitud de autorización al departamento (la más reciente). */
    public function autorizacionDepartamento(): HasOne
    {
        return $this->hasOne(Autorizacion::class, 'acceso_id')->latestOfMany();
    }
    // Fin Recepción de candidatos

    // ------------------------------------------------------------------ Ayudas

    /** Nombre del tipo según el giro de su empresa (Giros: «Visitante», «Residente»…; en hotel, el de siempre). */
    public function etiquetaTipo(): string
    {
        return $this->terminos()->etiquetaTipo($this->tipo);
    }

    /** Palabras del giro de la empresa del registro (docs/tecnico/giros.md). */
    public function terminos(): Terminos
    {
        // Consultas que no traen empresa_id (listas con columnas elegidas): la empresa activa
        $empresaId = $this->getAttributes()['empresa_id'] ?? null;

        return $empresaId === null ? Terminos::actual() : Terminos::deEmpresaId((int) $empresaId);
    }

    /** Motivo de la visita con el texto del giro («Visita a Residente» en condominios). */
    public function etiquetaMotivo(): string
    {
        return $this->terminos()->etiquetaMotivo($this->motivo_visita);
    }

    public function llevaGafete(): bool
    {
        return ! in_array($this->tipo, self::SIN_GAFETE, true);
    }

    /**
     * Proveedor o contratista pendiente que espera al departamento (con o sin
     * responsable): la caseta no lo confirma con el host; si no puede
     * esperar, lo pasa con «Autorizar por excepción».
     */
    public function esperaAlDepartamento(): bool
    {
        return $this->estado === 'pendiente' && in_array($this->tipo, self::CON_AUTORIZACION, true)
            && in_array($this->getAttributes()['autorizacion'] ?? null, ['esperando', 'espera', self::SIN_RESPONSABLE], true);
    }

    public function admiteSalidaTemporal(): bool
    {
        return in_array($this->tipo, self::CON_SALIDA_TEMPORAL, true);
    }

    /** "Salida a Tour" para huéspedes; "Salida Temporal" para proveedor y contratista (textos de SEGCAT). */
    public function textoSalidaTemporal(): string
    {
        return $this->tipo === 'huesped' ? 'Salida a Tour' : 'Salida Temporal';
    }

    /** Gafete que se muestra: la nomenclatura del momento o "S/G" (sin gafete). */
    public function gafeteVisible(): string
    {
        return $this->gafete_texto ?: 'S/G';
    }
}
