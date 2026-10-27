<?php

namespace App\Services\Importacion;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Turno;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Colaboradores: mismo servicio que «Nuevo Colaborador» y «Editar»
 * (AdministradorColaboradores). Se identifican por su número de empleado.
 * Los datos personales solo se toman si quien importa tiene
 * «colaboradores.datos_personales».
 */
class ImportadorColaboradores extends Importador
{
    public function __construct(private readonly AdministradorColaboradores $colaboradores) {}

    public function tipo(): string
    {
        return 'colaboradores';
    }

    public function modulo(): string
    {
        return 'colaboradores';
    }

    public function titulo(): string
    {
        return 'Colaboradores';
    }

    public function registro(): string
    {
        return 'colaborador';
    }

    public function rutaLista(): string
    {
        return route('colaboradores.index');
    }

    public function nombreClave(): string
    {
        return 'Número de empleado';
    }

    public function columnas(): array
    {
        return [
            new Columna('num_empleado', 'Número de empleado', true, 'E-1001', 'Único en la empresa; con él se reconoce a quien ya existe.', alias: ['numero', 'nomina', 'numero de nomina', 'no empleado']),
            new Columna('nombre', 'Nombre(s)', true, 'Juan', alias: ['nombre', 'nombres']),
            new Columna('apellido_paterno', 'Apellido paterno', true, 'Pérez'),
            new Columna('apellido_materno', 'Apellido materno', false, 'López'),
            new Columna('telefono', 'Teléfono', false, '9981234567', 'De 10 a 15 dígitos; los espacios y guiones se quitan solos.'),
            new Columna('sede', 'Sede', false, 'Sede Centro', 'Nombre o código de la sede, como aparece en Estructura → Sedes.', campos: ['sede_id']),
            new Columna('departamento', 'Departamento', false, 'Recepción', 'Como aparece en Estructura → Departamentos y que aplique en esa sede.', campos: ['departamento_id']),
            new Columna('puesto', 'Puesto', false, 'Recepcionista', 'Como aparece en Estructura → Puestos.', campos: ['puesto_id']),
            new Columna('turno', 'Turno', false, '', 'Nombre del turno, como aparece en Estructura → Turnos.', campos: ['turno_id']),
            new Columna('curp', 'CURP', false, 'PELJ900101HQRRPN09', '18 caracteres.', true),
            new Columna('rfc', 'RFC', false, 'PELJ900101AB1', '12 o 13 caracteres.', true),
            new Columna('nss', 'NSS', false, '12345678901', '11 dígitos.', true, ['numero de seguro social', 'imss']),
            new Columna('fecha_nacimiento', 'Fecha de nacimiento', false, '01/01/1990', 'Día/mes/año.', true),
            new Columna('lugar_nacimiento', 'Estado de nacimiento', false, 'Quintana Roo', 'Uno de los 32 estados o «Extranjero».', true, ['lugar de nacimiento']),
            new Columna('nacionalidad', 'Nacionalidad', false, 'Mexicana', '', true),
            new Columna('correo_personal', 'Correo personal', false, 'juan.perez@correo.com', '', true, ['correo']),
            new Columna('direccion_completa', 'Dirección completa', false, 'Av. Principal 123, Centro', '', true, ['direccion']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $num = $this->valor($fila, 'num_empleado');

        return $num === null ? null : mb_strtoupper((string) preg_replace('/\s+/u', ' ', $num));
    }

    public function existente(array $fila): ?Model
    {
        $clave = $this->clave($fila);

        $porNumero = $clave === null ? null : Colaborador::where('num_empleado', $clave)->first();
        if ($porNumero !== null) {
            return $porNumero;
        }
        // C-27 (A-11): la caseta pudo darlo de alta sin número: se propone «Unir con el alta de la caseta» en vez de duplicarlo
        $nombre = $this->valor($fila, 'nombre');
        $paterno = $this->valor($fila, 'apellido_paterno');
        if ($nombre === null || $paterno === null) {
            return null;
        }
        // Solo si no hay duda (revisión Etapa 3a): un único homónimo, que es el alta de la caseta, y de la misma sede si la fila la trae.
        // Con otro homónimo (verificado o provisional) o de otra sede, puede ser otra persona: se da de alta y se une a mano en «Altas por verificar».
        $parecidos = collect($this->colaboradores->parecidos($nombre, $paterno, $this->valor($fila, 'apellido_materno')));
        $provisional = $parecidos->count() === 1 ? $parecidos->firstWhere('provisional', true) : null;
        if ($provisional === null) {
            return null;
        }
        $sede = $this->valor($fila, 'sede') === null ? null : $this->sede($this->valor($fila, 'sede'))?->id;
        if ($sede !== null && $provisional['sede_id'] !== null && (int) $provisional['sede_id'] !== (int) $sede) {
            return null;
        }

        return Colaborador::whereKey($provisional['id'])->where('provisional', true)->whereNull('fusionado_en_id')->first();
    }

    public function describir(Model $registro): string
    {
        /** @var Colaborador $registro */
        if ($registro->provisional && $registro->fusionado_en_id === null && $registro->activo) {
            return $registro->nombreCompleto().' (alta de la caseta por verificar: al actualizar se une con ella y queda verificada)';
        }

        return $registro->nombreCompleto().($registro->activo ? '' : ' (dado de baja)');
    }

    public function editable(User $actor, Model $existente): bool
    {
        return $this->colaboradores->limitar(Colaborador::query()->whereKey($existente->getKey()), $actor, 'colaboradores.editar')->exists();
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        [$datos, $errores] = $this->datos($actor, $fila, true);

        return $this->guardarOReportar($errores, fn () => $this->colaboradores->crear($actor, $empresaId, $this->peticion($datos)));
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        /** @var Colaborador $existente */
        $actual = $existente->only(['num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'telefono', 'sede_id', 'departamento_id', 'puesto_id']);

        [$datos, $errores] = $this->datos($actor, $fila, false);

        $guardado = $this->guardarOReportar($errores, fn () => $this->colaboradores->actualizar($actor, $empresaId, $existente, $this->peticion($this->encima($actual, $datos))));
        // C-27: unir con el alta de la caseta = la plantilla la completa y la verifica
        if ($existente->provisional && $existente->fusionado_en_id === null && $existente->activo && ! empty($existente->num_empleado)) {
            $this->colaboradores->verificarDesdeImportacion($actor, $existente);
        }

        return $guardado;
    }

    /**
     * Lo que recibe el servicio y los errores al traducir la fila.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function datos(User $actor, array $fila, bool $alta): array
    {
        $errores = [];
        // (no flecha: $errores va por referencia)
        $resolver = function (string $columna, \Closure $fn) use (&$errores) {
            return $this->intentar($errores, $fn);
        };

        $datos = [
            'num_empleado' => $this->valor($fila, 'num_empleado'),
            'nombre' => $this->valor($fila, 'nombre'),
            'apellido_paterno' => $this->valor($fila, 'apellido_paterno'),
            'apellido_materno' => $this->valor($fila, 'apellido_materno'),
            'telefono' => $this->valor($fila, 'telefono'),
            'sede_id' => $resolver('sede', fn () => $this->sede($this->valor($fila, 'sede'))?->id),
            'departamento_id' => $resolver('departamento', fn () => $this->porNombre(Departamento::class, $this->valor($fila, 'departamento'), 'departamento', 'el departamento', 'Estructura → Departamentos')),
            'puesto_id' => $resolver('puesto', fn () => $this->porNombre(Puesto::class, $this->valor($fila, 'puesto'), 'puesto', 'el puesto', 'Estructura → Puestos')),
        ];
        // El turno solo se toca si la fila lo trae (al actualizar, vacío = conserva el actual)
        $turno = $this->valor($fila, 'turno');
        if ($turno !== null || $alta) {
            $datos['turno_id'] = $resolver('turno', fn () => $this->porNombre(Turno::class, $turno, 'turno', 'el turno', 'Estructura → Turnos'));
        }

        // Datos personales: solo con permiso; si no lo tiene, esas columnas se ignoran
        if ($actor->can('colaboradores.datos_personales')) {
            foreach (Colaborador::DATOS_PERSONALES as $campo) {
                $valor = $this->valor($fila, $campo);
                if ($campo === 'fecha_nacimiento') {
                    $valor = $resolver($campo, fn () => $this->fecha($valor, 'fecha_nacimiento'));
                }
                if ($campo === 'lugar_nacimiento' && $valor !== null) {
                    $valor = $this->estado($valor);
                }
                if ($valor !== null || $alta) {
                    $datos[$campo] = $valor;
                }
            }
        }

        return [$datos, $errores];
    }

    /** «quintana roo» → «Quintana Roo» (el servicio solo acepta los de la lista). */
    private function estado(string $texto): string
    {
        foreach (Colaborador::ESTADOS_NACIMIENTO as $estado) {
            if (Columna::normalizar($estado) === Columna::normalizar($texto)) {
                return $estado;
            }
        }

        return $texto;
    }

    /**
     * @param  class-string<Model>  $modelo
     */
    private function porNombre(string $modelo, ?string $texto, string $columna, string $nombre, string $donde): ?int
    {
        if ($texto === null) {
            return null;
        }
        $buscado = Columna::normalizar($texto);
        $encontrado = $modelo::query()->orderByDesc('activo')->orderBy('id')->get(['id', 'nombre', 'activo'])
            ->first(fn ($m) => Columna::normalizar((string) $m->nombre) === $buscado);
        if ($encontrado === null) {
            throw ValidationException::withMessages([$columna => 'No existe '.$nombre." «{$texto}» en esta empresa: escríbelo como aparece en {$donde}."]);
        }

        return (int) $encontrado->id;
    }
}
