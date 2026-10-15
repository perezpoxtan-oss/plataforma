<?php

namespace App\Services\Importacion;

use App\Models\Persona;
use App\Models\User;
use App\Services\Personas\AdministradorPersonas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Padrón de personas: mismo servicio que «Registrar Persona»
 * (AdministradorPersonas). Se identifican por su folio de identificación
 * o, si no lo traen, por su nombre completo.
 */
class ImportadorPersonas extends Importador
{
    public function __construct(private readonly AdministradorPersonas $personas) {}

    public function tipo(): string
    {
        return 'personas';
    }

    public function modulo(): string
    {
        return 'visitantes';
    }

    public function titulo(): string
    {
        return 'Padrón de personas';
    }

    public function registro(): string
    {
        return 'persona';
    }

    public function rutaLista(): string
    {
        return route('personas.index');
    }

    public function nombreClave(): string
    {
        return 'Folio de identificación (o el nombre completo si no lo trae)';
    }

    public function columnas(): array
    {
        return [
            new Columna('tipo', 'Tipo', true, 'Visitante', 'Visitante, Proveedor o Contratista.'),
            new Columna('nombre_completo', 'Nombre completo', true, 'María González Ruiz', alias: ['nombre']),
            new Columna('empresa', 'Empresa que representa', false, '', 'Solo proveedores y contratistas. Si la empresa ya está en Empresas externas, se liga sola.', alias: ['empresa', 'empresa de procedencia'], campos: ['empresa_procedencia', 'proveedor_id']),
            new Columna('tipo_identificacion', 'Tipo de identificación', false, 'INE', 'INE, Pasaporte, Licencia de conducir, CURP, Cédula profesional, Credencial IMSS u Otra. Obligatorio si hay folio.', alias: ['identificacion']),
            new Columna('folio_identificacion', 'Folio de identificación', false, 'IDMEX1234567890', 'Único en la empresa; con él se reconoce a quien ya existe.', alias: ['folio']),
            new Columna('telefono', 'Teléfono', false, '9987654321', 'De 10 a 15 dígitos.'),
            new Columna('categoria', 'Categoría', false, 'General', 'Solo visitantes: General, Prospecto de RR. HH. o Familiar.'),
            new Columna('motivo_visita', 'Motivo de la visita', false, 'Entrega de documentos', alias: ['motivo']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $folio = Persona::normalizarFolio($this->valor($fila, 'folio_identificacion'));
        if ($folio !== null) {
            return 'folio:'.$folio;
        }
        $nombre = $this->valor($fila, 'nombre_completo');

        return $nombre === null ? null : 'nombre:'.mb_strtolower((string) preg_replace('/\s+/u', ' ', $nombre));
    }

    public function existente(array $fila): ?Model
    {
        $folio = Persona::normalizarFolio($this->valor($fila, 'folio_identificacion'));
        if ($folio !== null) {
            return Persona::where('folio_identificacion', $folio)->first();
        }
        $nombre = $this->valor($fila, 'nombre_completo');
        if ($nombre === null) {
            return null;
        }
        $iguales = Persona::whereRaw('LOWER(nombre_completo) = ?', [mb_strtolower((string) preg_replace('/\s+/u', ' ', $nombre))])->limit(2)->get();
        if ($iguales->count() > 1) {
            throw ValidationException::withMessages(['nombre_completo' => 'Hay varias personas con este nombre en el padrón: escribe su folio de identificación para saber cuál es.']);
        }

        return $iguales->first();
    }

    public function describir(Model $registro): string
    {
        /** @var Persona $registro */
        return $registro->nombre_completo.' ('.(Persona::TIPOS[$registro->tipo] ?? $registro->tipo).($registro->activo ? '' : ', dada de baja').')';
    }

    public function editable(User $actor, Model $existente): bool
    {
        $ids = $this->personas->idsEnAlcance($actor, 'visitantes.editar');

        return $ids === null || in_array((int) $existente->getKey(), $ids, true);
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        [$datos, $errores] = $this->datos($fila);

        return $this->guardarOReportar($errores, fn () => $this->personas->crear($actor, $this->peticion($datos)));
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        /** @var Persona $existente */
        $actual = $existente->only(['tipo', 'categoria', 'nombre_completo', 'proveedor_id', 'empresa_procedencia', 'tipo_identificacion', 'folio_identificacion', 'telefono', 'motivo_visita']);
        [$nuevo, $errores] = $this->datos($fila);
        if (($nuevo['empresa_procedencia'] ?? null) !== null) {
            // Otra empresa escrita: el servicio la vuelve a ligar si está en el directorio
            $actual['proveedor_id'] = null;
        }

        return $this->guardarOReportar($errores, fn () => $this->personas->actualizar($actor, $existente, $this->peticion($this->encima($actual, $nuevo))));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function datos(array $fila): array
    {
        $errores = [];
        $datos = ['nombre_completo' => $this->valor($fila, 'nombre_completo'), 'empresa_procedencia' => $this->valor($fila, 'empresa'),
            'folio_identificacion' => $this->valor($fila, 'folio_identificacion'), 'telefono' => $this->valor($fila, 'telefono'),
            'motivo_visita' => $this->valor($fila, 'motivo_visita')];
        foreach ([
            'tipo' => [Persona::TIPOS, 'un tipo de persona válido', []],
            'tipo_identificacion' => [Persona::IDENTIFICACIONES, 'un tipo de identificación válido', ['licencia' => 'licencia', 'credencial para votar' => 'ine', 'ife' => 'ine']],
            'categoria' => [Persona::CATEGORIAS, 'una categoría válida', ['prospecto' => 'prospecto_rrhh']],
        ] as $campo => [$opciones, $nombre, $extra]) {
            $datos[$campo] = $this->intentar($errores, fn () => $this->opcion($this->valor($fila, $campo), $opciones, $campo, $nombre, $extra));
        }

        return [$datos, $errores];
    }
}
