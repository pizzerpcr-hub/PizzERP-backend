<?php

namespace App\Http\Controllers;

use App\Events\ModuleDataChanged;
use App\Models\Bitacora;
use App\Models\Combo;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComboController extends Controller
{
    public function selectableProducts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || (! $user->hasModulePermission('combos', 'crear')
            && ! $user->hasModulePermission('combos', 'editar'))) {
            abort(403, 'No tiene permiso para seleccionar productos de combos.');
        }

        return response()->json(['productos' => Producto::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id_producto', 'codigo_producto', 'nombre'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeModule($request, 'combos');

        return $this->listResponse($request,
            Combo::query()->with('productos')->orderBy('nombre')->orderBy('id_combo'),
            'combos',
            fn (Combo $combo): array => $this->payload($combo),
            fn ($query, string $term) => $query->where(fn ($query) => $query
                ->whereLike('codigo_combo', "%{$term}%")
                ->orWhereLike('nombre', "%{$term}%")
                ->orWhereLike('estado', "%{$term}%")
                ->orWhereHas('productos', fn ($query) => $query->whereLike('nombre', "%{$term}%")))
        );
    }

    public function show(Request $request, string $combo): JsonResponse
    {
        $this->authorizeModule($request, 'combos');

        return response()->json(['combo' => $this->payload(Combo::query()->with('productos')->findOrFail($combo))]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeModule($request, 'combos');
        $this->normalize($request);
        $data = $request->validate($this->rules($request));
        $combo = DB::transaction(function () use ($actor, $data): Combo {
            $this->lockActiveProducts($data['productos']);
            $combo = Combo::create(collect($data)->except('productos')->all() + ['estado' => 'ACTIVO']);
            $this->syncProducts($combo, $data['productos']);
            $this->audit($actor, $combo, 'creado');
            ModuleDataChanged::dispatch('combos', 'created');

            return $combo;
        });

        return response()->json(['message' => 'Combo creado exitosamente.', 'combo' => $this->payload($combo->load('productos'))], 201);
    }

    public function update(Request $request, string $combo): JsonResponse
    {
        $actor = $this->authorizeModule($request, 'combos');
        $this->normalize($request);
        $updated = DB::transaction(function () use ($actor, $request, $combo): Combo {
            $locked = Combo::query()->lockForUpdate()->findOrFail($combo);
            $data = $request->validate($this->rules($request, true, $locked));
            $start = $data['fecha_inicio'] ?? $locked->fecha_inicio->format('Y-m-d');
            $end = $data['fecha_fin'] ?? $locked->fecha_fin->format('Y-m-d');
            $this->validateDates($start, $end);
            $products = $data['productos'] ?? $this->productQuantities($locked);
            $this->lockActiveProducts($products);
            $locked->fill(collect($data)->except('productos')->all());
            $changed = $locked->isDirty();
            $locked->save();
            if (isset($data['productos'])) {
                $changes = $this->syncProducts($locked, $products);
                $changed = $changed || collect($changes)->contains(fn (array $ids): bool => $ids !== []);
            }
            if ($changed) {
                $this->audit($actor, $locked, 'actualizado');
                ModuleDataChanged::dispatch('combos', 'updated');
            }

            return $locked;
        });

        return response()->json(['message' => 'Combo actualizado exitosamente.', 'combo' => $this->payload($updated->load('productos'))]);
    }

    public function updateStatus(Request $request, string $combo): JsonResponse
    {
        $actor = $this->authorizeModule($request, 'combos');
        $this->normalize($request);
        $data = $request->validate(['estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])]]);
        $updated = DB::transaction(function () use ($actor, $combo, $data): Combo {
            $locked = Combo::query()->lockForUpdate()->findOrFail($combo);
            if ($data['estado'] === 'ACTIVO') {
                $this->lockActiveProducts($this->productQuantities($locked));
            }
            if ($locked->estado !== $data['estado']) {
                $locked->estado = $data['estado'];
                $locked->save();
                $this->audit($actor, $locked, 'estado actualizado a '.$locked->estado);
                ModuleDataChanged::dispatch('combos', 'status');
            }

            return $locked;
        });

        return response()->json(['message' => 'Estado del combo actualizado exitosamente.', 'combo' => $this->payload($updated->load('productos'))]);
    }

    private function normalize(Request $request): void
    {
        foreach (['codigo_combo', 'nombre', 'descripcion', 'estado'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim($request->input($field));
                $request->merge([$field => in_array($field, ['codigo_combo', 'estado'], true) ? mb_strtoupper($value) : $value]);
            }
        }
    }

    private function rules(Request $request, bool $partial = false, ?Combo $combo = null): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $codeRules = [...$required, 'string', 'max:30'];
        if (! $combo || $request->input('codigo_combo') !== $combo->codigo_combo) {
            $codeRules[] = Rule::unique('combos', 'codigo_combo')->ignore($combo?->id_combo, 'id_combo');
        }

        return [
            'codigo_combo' => $codeRules,
            'nombre' => [...$required, 'string', 'max:100'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:150'],
            'precio' => [...$required, 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'estado' => $partial ? ['prohibited'] : ['sometimes', 'required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'fecha_inicio' => [...$required, 'date_format:Y-m-d'],
            'fecha_fin' => [...$required, 'date_format:Y-m-d', ...($partial ? [] : ['after_or_equal:fecha_inicio'])],
            'productos' => [...$required, 'array', 'min:2', 'max:100'],
            'productos.*' => ['required', 'array:id_producto,cantidad'],
            'productos.*.id_producto' => ['required', 'integer', 'distinct', 'min:1'],
            'productos.*.cantidad' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    private function validateDates(string $start, string $end): void
    {
        if ($end < $start) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
    }

    private function lockActiveProducts(array $products): void
    {
        $ids = array_column($products, 'id_producto');
        $found = Producto::query()->whereIn('id_producto', $ids)->orderBy('id_producto')->lockForUpdate()->get(['id_producto', 'estado']);
        if (count($ids) < 2 || $found->count() !== count($ids) || $found->contains(fn (Producto $product): bool => $product->estado !== 'ACTIVO')) {
            throw ValidationException::withMessages(['productos' => 'El combo requiere al menos dos productos distintos, existentes y activos.']);
        }
    }

    private function productQuantities(Combo $combo): array
    {
        return $combo->productos()->get()->map(fn (Producto $product): array => ['id_producto' => $product->id_producto, 'cantidad' => $product->pivot->cantidad])->all();
    }

    private function syncProducts(Combo $combo, array $products): array
    {
        $quantities = [];
        foreach ($products as $product) {
            $quantities[$product['id_producto']] = ['cantidad' => $product['cantidad']];
        }

        return $combo->productos()->sync($quantities);
    }

    private function payload(Combo $combo): array
    {
        return [
            'id_combo' => $combo->id_combo, 'codigo_combo' => $combo->codigo_combo,
            'nombre' => $combo->nombre, 'descripcion' => $combo->descripcion,
            'precio' => $combo->precio, 'estado' => $combo->estado,
            'fecha_inicio' => $combo->fecha_inicio->format('Y-m-d'), 'fecha_fin' => $combo->fecha_fin->format('Y-m-d'),
            'productos' => $combo->productos->map(fn (Producto $product): array => [
                'id_producto' => $product->id_producto, 'codigo_producto' => $product->codigo_producto,
                'nombre' => $product->nombre, 'estado' => $product->estado, 'cantidad' => (int) $product->pivot->cantidad,
            ])->all(),
        ];
    }

    private function audit(User $actor, Combo $combo, string $action): void
    {
        Bitacora::create([
            'id_usuario' => $actor->id_usuario,
            'descripcion_movimiento' => "Combo {$combo->codigo_combo} (ID {$combo->id_combo}) {$action}.",
            'tipo_movimiento' => 'GESTION_COMBOS', 'motivo' => 'Gestión de combos y promociones.', 'fecha' => now(),
        ]);
    }
}
