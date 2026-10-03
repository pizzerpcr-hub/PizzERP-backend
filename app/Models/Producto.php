<?php

namespace App\Models;

use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    protected $primaryKey = 'id_producto';

    public $timestamps = false;

    protected $fillable = [
        'id_categoria',
        'codigo_producto',
        'nombre',
        'descripcion',
        'precio',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'id_categoria', 'id_categoria');
    }

    public function ingredientes(): BelongsToMany
    {
        return $this->belongsToMany(Ingrediente::class, 'producto_ingredientes', 'id_producto', 'id_ingrediente')
            ->withPivot('id_producto_ingrediente', 'cantidad_requerida', 'unidad_medida')
            ->orderBy('ingredientes.id_ingrediente');
    }

    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Combo::class, 'promocion_productos', 'id_producto', 'id_promocion')
            ->withPivot('id_promocion_producto', 'cantidad');
    }
}
