<?php

namespace App\Support\Money;

/**
 * Regla de redondeo EXPLÍCITA de las operaciones de `Money` que pueden producir fracciones.
 * No hay valor por defecto implícito: quien llama elige (lo habitual es HalfUp).
 */
enum Rounding
{
    /** Mitad hacia fuera de cero: 0,5 -> 1; -0,5 -> -1 (la habitual fiscal/comercial). */
    case HalfUp;
    /** Mitad hacia el par ("del banquero"): 0,5 -> 0; 1,5 -> 2; 2,5 -> 2. */
    case HalfEven;
    /** Hacia cero (truncar): 1,9 -> 1; -1,9 -> -1. */
    case Down;
    /** Hacia fuera de cero: 1,1 -> 2; -1,1 -> -2. */
    case Up;
}
