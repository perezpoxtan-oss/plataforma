<?php

namespace App\Services\Importacion;

use App\Models\Proveedor;
use App\Models\User;
use App\Services\Padrones\AdministradorProveedores;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Empresas externas (proveedores, contratistas, agencias): mismo servicio
 * que «Nueva empresa externa» (AdministradorProveedores). Se identifican por
 * su razón social o nombre comercial. Al actualizar, las sedes donde opera
 * no cambian (tienen su propio diálogo en la ficha).
 */
class ImportadorProveedores extends Importador
{
    public function __construct(private readonly AdministradorProveedores $proveedores) {}

    public function tipo(): string
    {
        return 'proveedores';
    }

    public function modulo(): string
    {
        return 'proveedores';
    }

    public function titulo(): string
    {
        return 'Empresas externas';
    }

    public function registro(): string
    {
        return 'empresa externa';
    }

    public function rutaLista(): string
    {
        return route('proveedores.index');
    }

    public function nombreClave(): string
    {
        return 'Razón social o nombre comercial';
    }

    public function columnas(): array
    {
        return [
            new Columna('nombre', 'Razón social o nombre comercial', true, 'Distribuidora del Sureste', 'Única en la empresa; con ella se reconoce a la que ya existe.', alias: ['nombre', 'razon social', 'empresa']),
            new Columna('categoria', 'Categoría', true, 'Proveedor de insumos / alimentos', implode(', ', Proveedor::CATEGORIAS).'.'),
            new Columna('rfc', 'RFC', false, 'DSU010101AB1', '12 caracteres (moral) o 13 (física).'),
            new Columna('telefono', 'Teléfono', false, '9981112233', 'De 7 a 15 dígitos; puede llevar «+» y código de país.'),
            new Columna('direccion', 'Dirección', false, 'Calle 10 número 25, Centro'),
            new Columna('sedes', 'Sedes donde opera', false, 'Todas', '«Todas» o los nombres de las sedes separados por coma. Vacío = todas (o tus sedes si solo administras algunas).', campos: ['todas_las_sedes', 'sedes.0', 'sedes.*']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $nombre = $this->valor($fila, 'nombre');

        return $nombre === null ? null : mb_strtolower(AdministradorProveedores::normalizarNombre($nombre));
    }

    public function existente(array $fila): ?Model
    {
        $nombre = $this->valor($fila, 'nombre');

        return $nombre === null ? null : $this->proveedores->buscarPorNombre($nombre);
    }

    public function describir(Model $registro): string
    {
        /** @var Proveedor $registro */
        return $registro->nombre.($registro->activo ? '' : ' (dada de baja)');
    }

    public function editable(User $actor, Model $existente): bool
    {
        /** @var Proveedor $existente */
        $visible = $this->proveedores->consulta($actor, 'proveedores.editar')->whereKey($existente->id)->exists();

        return $visible && $this->proveedores->esPropioSiAplica($actor, $existente, 'proveedores.editar')
            && $this->proveedores->esExclusivoDe($existente, $this->proveedores->sedes($actor, 'proveedores.editar'));
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        $errores = [];
        $datos = $this->datos($fila, $errores);
        $sedes = $this->intentar($errores, fn () => $this->sedes($fila)) ?? ['todas_las_sedes' => true, 'sedes' => []];

        return $this->guardarOReportar($errores, fn () => $this->proveedores->crear($actor, $this->peticion($datos + $sedes))[0]);
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        /** @var Proveedor $existente */
        $actual = $existente->only(['nombre', 'categoria', 'rfc', 'telefono', 'direccion']);

        $errores = [];
        $datos = $this->datos($fila, $errores);

        return $this->guardarOReportar($errores, fn () => $this->proveedores->actualizar($actor, $existente, $this->peticion($this->encima($actual, $datos))));
    }

    /**
     * @param  array<string, list<string>>  $errores
     * @return array<string, mixed>
     */
    private function datos(array $fila, array &$errores): array
    {
        return [
            'nombre' => $this->valor($fila, 'nombre'),
            'categoria' => $this->intentar($errores, fn () => $this->opcion($this->valor($fila, 'categoria'), Proveedor::CATEGORIAS, 'categoria', 'una categoría válida', [
                'proveedor' => 'proveedor', 'insumos' => 'proveedor', 'alimentos' => 'proveedor', 'agencia de renta de autos' => 'agencia_autos',
            ])),
            'rfc' => $this->valor($fila, 'rfc'),
            'telefono' => $this->valor($fila, 'telefono'),
            'direccion' => $this->valor($fila, 'direccion'),
        ];
    }

    /**
     * «Todas» (o vacío) = todas las sedes; si no, los nombres separados por coma.
     *
     * @return array{todas_las_sedes: bool, sedes: list<int>}
     */
    private function sedes(array $fila): array
    {
        $texto = $this->valor($fila, 'sedes');
        if ($texto === null || in_array(Columna::normalizar($texto), ['todas', 'todaslassedes'], true)) {
            return ['todas_las_sedes' => true, 'sedes' => []];
        }
        $ids = [];
        foreach (preg_split('/[,;|]/', $texto) ?: [] as $nombre) {
            $nombre = trim($nombre);
            if ($nombre !== '') {
                $ids[] = (int) $this->sede($nombre, 'sedes')?->id;
            }
        }
        if ($ids === []) {
            throw ValidationException::withMessages(['sedes' => 'Escribe «Todas» o el nombre de al menos una sede.']);
        }

        return ['todas_las_sedes' => false, 'sedes' => array_values(array_unique($ids))];
    }
}
