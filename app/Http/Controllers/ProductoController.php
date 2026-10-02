<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function selectableCategories(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || (! $user->hasModulePermission('productos', 'crear')
            && ! $user->hasModulePermission('productos', 'editar'))) {
            abort(403, 'No tiene permiso para seleccionar categorías.');
        }

        return response()->json(['categorias' => Categoria::query()->where('estado', 'ACTIVO')
            ->orderBy('nombre')->get(['id_categoria', 'nombre'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return response()->json([
            'productos' => Producto::query()->with('categoria:id_categoria,nombre')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules());
        $producto = DB::transaction(function () use ($data, $manager): Producto {
            $producto = Producto::create([
                ...$data,
                'estado' => $data['estado'] ?? 'ACTIVO',
            ]);
            $this->recordAudit($manager, "Producto {$producto->codigo_producto} (ID {$producto->id_producto}) creado.");

            return $producto;
        });
        $producto->load('categoria:id_categoria,nombre');

        return response()->json(['producto' => $producto], 201);
    }

    public function update(Request $request, Producto $producto): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules(true, $producto));
        DB::transaction(function () use ($producto, $data, $manager): void {
            $producto->fill($data);

            if ($producto->isDirty()) {
                $producto->save();
                $this->recordAudit($manager, "Producto {$producto->codigo_producto} (ID {$producto->id_producto}) actualizado.");
            }
        });
        $producto->load('categoria:id_categoria,nombre');

        return response()->json(['producto' => $producto]);
    }

    private function authorizeAdministrator(Request $request): User
    {
        return $this->authorizeModule($request, 'productos');
    }

    private function normalizeInput(Request $request): void
    {
        foreach (['codigo_producto', 'nombre', 'descripcion', 'estado'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([
                    $field => in_array($field, ['codigo_producto', 'estado'], true)
                        ? mb_strtoupper(trim($value))
                        : trim($value),
                ]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $partial = false, ?Producto $producto = null): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'id_categoria' => [...$presence, 'integer', Rule::exists('categorias', 'id_categoria')],
            'codigo_producto' => [
                ...$presence,
                'string',
                'max:30',
                Rule::unique('productos', 'codigo_producto')->ignore($producto?->id_producto, 'id_producto'),
            ],
            'nombre' => [...$presence, 'string', 'max:100'],
            'descripcion' => [...$presence, 'string', 'max:150'],
            'precio' => [...$presence, 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'estado' => ['sometimes', 'required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }

    private function recordAudit(User $manager, string $description): void
    {
        Bitacora::create([
            'id_usuario' => $manager->id_usuario,
            'descripcion_movimiento' => $description,
            'tipo_movimiento' => 'GESTION_PRODUCTOS',
            'motivo' => 'Gestión del catálogo de productos.',
            'fecha' => now(),
        ]);
    }
}
