<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionPlataforma;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Identidad;
use App\Support\ImagenSegura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Identidad de la plataforma: nombre, eslogan, colores y símbolo.
 * Solo el Super Administrador (módulo de tipo plataforma).
 */
class IdentidadController extends Controller
{
    /** Imágenes permitidas: sin SVG (puede llevar código) y de tamaño acotado. */
    private const IMAGENES = [
        'simbolo' => ['max' => 512, 'etiqueta' => 'símbolo'],
        'favicon' => ['max' => 256, 'etiqueta' => 'ícono de pestaña'],
    ];

    private const CARPETA = 'identidad';

    public function __construct(private readonly Identidad $identidad) {}

    public function edit(): View
    {
        Gate::authorize('identidad.ver');

        return view('administracion.identidad.edit', [
            'valores' => $this->identidad->todos(),
            'puedeEditar' => auth()->user()->can('identidad.editar'),
        ]);
    }

    public function update(Request $request, AdministradorRoles $auditoria): RedirectResponse
    {
        Gate::authorize('identidad.editar');

        $reglasImagen = fn (int $max) => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', "max:{$max}", 'dimensions:max_width=2048,max_height=2048'];

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
            'nombre_corto' => ['required', 'string', 'max:20'],
            'eslogan' => ['nullable', 'string', 'max:120'],
            'titular' => ['nullable', 'string', 'max:60'],
            'color_primario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_acento' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'correo_soporte' => ['nullable', 'email:rfc', 'max:150'],
            'telefono_soporte' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]+$/'],
            'simbolo' => $reglasImagen(self::IMAGENES['simbolo']['max']),
            'favicon' => $reglasImagen(self::IMAGENES['favicon']['max']),
        ], [
            'color_primario.regex' => 'El color debe tener el formato #RRGGBB.',
            'color_acento.regex' => 'El color debe tener el formato #RRGGBB.',
            'telefono_soporte.regex' => 'El teléfono solo puede tener números, espacios, +, guiones y paréntesis.',
            'simbolo.mimes' => 'El símbolo debe ser PNG, JPG o WEBP (SVG no está permitido por seguridad).',
            'favicon.mimes' => 'El ícono debe ser PNG, JPG o WEBP (SVG no está permitido por seguridad).',
        ], [
            'nombre_corto' => 'nombre corto',
            'color_primario' => 'color principal',
            'color_acento' => 'color de acento',
            'correo_soporte' => 'correo de soporte',
            'telefono_soporte' => 'teléfono de soporte',
        ]);

        $antes = $this->identidad->todos();
        $nuevos = collect($datos)->except(array_keys(self::IMAGENES))->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();
        $nuevos['color_primario'] = strtolower($nuevos['color_primario']);
        $nuevos['color_acento'] = strtolower($nuevos['color_acento']);

        foreach (array_keys(self::IMAGENES) as $campo) {
            $archivo = $request->file($campo);

            if ($archivo instanceof UploadedFile) {
                // Seguridad: se guarda una copia re-dibujada (sin código ni metadatos escondidos)
                $nueva = 'storage/'.ImagenSegura::guardar($archivo, self::CARPETA, $campo);
                $this->borrar($antes[$campo] ?? null);
                $nuevos[$campo] = $nueva;
            } elseif ($request->boolean("quitar_{$campo}")) {
                $this->borrar($antes[$campo] ?? null);
                $nuevos[$campo] = null;
            }
        }

        // Un valor nulo regresa al valor por defecto
        $this->identidad->guardar($nuevos + array_fill_keys(['eslogan', 'titular', 'correo_soporte', 'telefono_soporte'], null));

        $registro = ConfiguracionPlataforma::where('clave', 'identidad')->first();
        if ($registro !== null) {
            $auditoria->auditar($request->user(), 'identidad.actualizada', $registro, $antes, $this->identidad->todos());
        }

        return redirect()->route('identidad.edit')->with('ok', 'Identidad actualizada. Los cambios ya se ven en todas las pantallas.');
    }

    private function borrar(?string $ruta): void
    {
        if ($ruta !== null && str_starts_with($ruta, 'storage/'.self::CARPETA.'/')) {
            Storage::disk('public')->delete(substr($ruta, strlen('storage/')));
        }
    }
}
