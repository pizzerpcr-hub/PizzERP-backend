<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function selectableIngredients(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || (! $user->hasModulePermission('productos', 'crear')
            && ! $user->hasModulePermission('productos', 'editar'))) {
            abort(403, 'No tiene permiso para seleccionar ingredientes.');
        }

        return response()->json(['ingredientes' => Ingrediente::query()->where('estado', 'ACTIVO')
            ->orderBy('nombre')->get(['id_ingrediente', 'nombre', 'unidad_medida'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return $this->listResponse($request,
            Producto::query()->select(['id_producto', 'id_categoria', 'codigo_producto', 'nombre', 'descripcion', 'precio', 'estado'])
                ->with(['categoria:id_categoria,nombre', 'ingredientes:id_ingrediente,nombre,unidad_medida,estado'])
                ->orderBy('nombre')->orderBy('id_producto'),
            'productos',
            search: fn ($query, string $term) => $query->where(fn ($query) => $query
                ->whereLike('codigo_producto', "%{$term}%")
                ->orWhereLike('nombre', "%{$term}%")
                ->orWhereLike('descripcion', "%{$term}%")
                ->orWhereHas('categoria', fn ($query) => $query->whereLike('nombre', "%{$term}%")))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules());
        $producto = DB::transaction(function () use ($data, $manager): Producto {
            $producto = Producto::create([
                ...collect($data)->except('ingredientes')->all(),
                'estado' => $data['estado'] ?? 'ACTIVO',
            ]);
            if (isset($data['ingredientes'])) {
                $producto->ingredientes()->sync($this->ingredientQuantities($data['ingredientes']));
            }
            $this->recordAudit($manager, "Producto {$producto->codigo_producto} (ID {$producto->id_producto}) creado.");

            return $producto;
        });
        $producto->load(['categoria:id_categoria,nombre', 'ingredientes:id_ingrediente,nombre,unidad_medida,estado']);

        return response()->json(['producto' => $producto], 201);
    }

    public function update(Request $request, Producto $producto): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules(true, $producto));
        DB::transaction(function () use ($producto, $data, $manager): void {
            $producto->fill(collect($data)->except('ingredientes')->all());

            $ingredientsChanged = false;
            if (isset($data['ingredientes'])) {
                $quantities = $this->ingredientQuantities($data['ingredientes']);
                $ingredientsChanged = $this->ingredientsChanged($producto, $quantities);

                if ($ingredientsChanged) {
                    $producto->ingredientes()->sync($quantities);
                }
            }

            if ($producto->isDirty() || $ingredientsChanged) {
                $producto->save();
                $this->recordAudit($manager, "Producto {$producto->codigo_producto} (ID {$producto->id_producto}) actualizado.");
            }
        });
        $producto->load(['categoria:id_categoria,nombre', 'ingredientes:id_ingrediente,nombre,unidad_medida,estado']);

        return response()->json(['producto' => $producto]);
    }

    public function updateStatus(Request $request, Producto $producto): JsonResponse
    {
        $this->normalizeInput($request);
        $manager = $this->authorizeAdministrator($request);
        $data = $request->validate([
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ]);

        DB::transaction(function () use ($producto, $data, $manager): void {
            $lockedProduct = Producto::query()->whereKey($producto->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedProduct->estado !== $data['estado']) {
                $lockedProduct->estado = $data['estado'];
                $lockedProduct->save();
                $this->recordAudit($manager, "Producto {$lockedProduct->codigo_producto} (ID {$lockedProduct->id_producto}) cambió de estado a {$lockedProduct->estado}.");
            }
        });
        $producto->refresh()->load(['categoria:id_categoria,nombre', 'ingredientes:id_ingrediente,nombre,unidad_medida,estado']);

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
            'ingredientes' => ['sometimes', 'array', 'max:100'],
            'ingredientes.*' => ['required', 'array:id_ingrediente,cantidad_requerida,unidad_medida'],
            'ingredientes.*.id_ingrediente' => ['required', 'integer', 'distinct', Rule::exists('ingredientes', 'id_ingrediente')],
            'ingredientes.*.cantidad_requerida' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'ingredientes.*.unidad_medida' => ['sometimes', 'required', 'string', 'max:30'],
        ];
    }

    /**
     * @param  array<int, array{id_ingrediente: int, cantidad_requerida: numeric-string|int|float, unidad_medida?: string}>  $ingredientes
     * @return array<int, array{cantidad_requerida: numeric-string|int|float, unidad_medida: string}>
     */
    private function ingredientQuantities(array $ingredientes): array
    {
        $quantities = [];
        $baseUnits = Ingrediente::query()->whereKey(array_column($ingredientes, 'id_ingrediente'))
            ->pluck('unidad_medida', 'id_ingrediente');

        foreach ($ingredientes as $index => $ingrediente) {
            $baseUnit = $baseUnits[$ingrediente['id_ingrediente']];
            $unit = $ingrediente['unidad_medida'] ?? $baseUnit;
            $compatibleUnits = match ($baseUnit) {
                'g', 'kg' => ['g', 'kg'],
                'ml', 'l' => ['ml', 'l'],
                default => [$baseUnit],
            };

            if (! in_array($unit, $compatibleUnits, true)) {
                throw ValidationException::withMessages([
                    "ingredientes.{$index}.unidad_medida" => 'La unidad debe ser compatible con el ingrediente.',
                ]);
            }

            $quantities[$ingrediente['id_ingrediente']] = [
                'cantidad_requerida' => $ingrediente['cantidad_requerida'],
                'unidad_medida' => $unit,
            ];
        }

        return $quantities;
    }

    /** @param array<int, array{cantidad_requerida: numeric-string|int|float, unidad_medida: string}> $quantities */
    private function ingredientsChanged(Producto $producto, array $quantities): bool
    {
        $current = $producto->ingredientes()->get()->mapWithKeys(fn (Ingrediente $ingrediente): array => [
            $ingrediente->id_ingrediente => [
                'cantidad_requerida' => number_format((float) $ingrediente->pivot->cantidad_requerida, 2, '.', ''),
                'unidad_medida' => $ingrediente->pivot->unidad_medida ?? $ingrediente->unidad_medida,
            ],
        ])->all();
        $requested = array_map(
            fn (array $quantity): array => [
                'cantidad_requerida' => number_format((float) $quantity['cantidad_requerida'], 2, '.', ''),
                'unidad_medida' => $quantity['unidad_medida'],
            ],
            $quantities
        );

        ksort($current);
        ksort($requested);

        return $current !== $requested;
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
