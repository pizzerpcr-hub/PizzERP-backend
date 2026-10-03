<?php

namespace App\Models;

use Database\Factories\ComboFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Combo extends Model
{
    /** @use HasFactory<ComboFactory> */
    use HasFactory;

    protected $table = 'combos';

    protected $primaryKey = 'id_combo';

    public $timestamps = false;

    protected $fillable = ['codigo_combo', 'nombre', 'descripcion', 'precio', 'estado', 'fecha_inicio', 'fecha_fin'];

    protected function casts(): array
    {
        return ['precio' => 'decimal:2', 'fecha_inicio' => 'date:Y-m-d', 'fecha_fin' => 'date:Y-m-d'];
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'combo_producto', 'id_combo', 'id_producto')
            ->withPivot('cantidad')
            ->orderBy('productos.id_producto');
    }

    public function productosPromocionados(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'promocion_productos', 'id_promocion', 'id_producto')
            ->withPivot('id_promocion_producto', 'cantidad')
            ->orderBy('productos.id_producto');
    }
}
