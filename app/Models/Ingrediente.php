<?php

namespace App\Models;

use Database\Factories\IngredienteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingrediente extends Model
{
    /** @use HasFactory<IngredienteFactory> */
    use HasFactory;

    protected $table = 'ingredientes';

    protected $primaryKey = 'id_ingrediente';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'unidad_medida',
        'cantidad_disponible',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_disponible' => 'decimal:2',
        ];
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_ingredientes', 'id_ingrediente', 'id_producto')
            ->withPivot('id_producto_ingrediente', 'cantidad_requerida', 'unidad_medida');
    }
}
