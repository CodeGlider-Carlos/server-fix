<?php
/*
ez/pats/config/config.php

Configuración mínima SOLO para Stripe.
No poner conexión BD aquí.
No poner tablas aquí.
No poner pats_db() aquí.
*/

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Stripe · Llaves de prueba
define('STRIPE_PUBLIC_KEY',  'pk_test_51TRgpYC9lznTKS2PcOZmqHp9zzeFFaZ4XIKDgdAugG6qc6kz2xkShn84VJzrkGVHKYirQ87fpaULURfKtGdqmH7700HezFwzXx');   // pk_live_... ó pk_test_...
define('STRIPE_SECRET_KEY',  'sk_test_51TRgpYC9lznTKS2PqZDWwyExnR1RzWIQDROVj3wUOt5wGI3x24vJCSYiHYU3TBL3nUqjRztGn8H9yPWAPxJCXHy100v1GJvczN');   // sk_live_... ó sk_test_...
|--------------------------------------------------------------------------
| Estas son llaves TEST.
| Para producción cambiar ambas por pk_live_... y sk_live_...



define('STRIPE_PUBLIC_KEY', 'pk_live_51TVxiwFfHAtjwQWhJHhdYfO3AXg2ffFtmj8tOrV966EYYoX0hvQzeFbYai4TqMi0JKFObanOpMIMCVToNAcrDgnH001Hvt4RHO');

define('STRIPE_SECRET_KEY', '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');

|--------------------------------------------------------------------------
*/

define('STRIPE_PUBLIC_KEY', 'pk_live_51TVxiwFfHAtjwQWhJHhdYfO3AXg2ffFtmj8tOrV966EYYoX0hvQzeFbYai4TqMi0JKFObanOpMIMCVToNAcrDgnH001Hvt4RHO');

define('STRIPE_SECRET_KEY', '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');

// define('STRIPE_PUBLIC_KEY', 'pk_test_51TRgpYC9lznTKS2PcOZmqHp9zzeFFaZ4XIKDgdAugG6qc6kz2xkShn84VJzrkGVHKYirQ87fpaULURfKtGdqmH7700HezFwzXx');

// define('STRIPE_SECRET_KEY', 'sk_test_51TRgpYC9lznTKS2PqZDWwyExnR1RzWIQDROVj3wUOt5wGI3x24vJCSYiHYU3TBL3nUqjRztGn8H9yPWAPxJCXHy100v1GJvczN');


define('STRIPE_CURRENCY', 'mxn');

/*
|--------------------------------------------------------------------------
| Recuperación manual de altas PATS
|--------------------------------------------------------------------------
| Clave para acceder a admin_generar_token.php y crear tokens de un solo uso.
| Cámbiala por una contraseña segura antes de usar en producción.
|--------------------------------------------------------------------------
*/
define('PATS_RECOVERY_KEY', 'E8C1BCD14F6E8ECD66D9B727D73C9DF5708E23F9');

/*
|--------------------------------------------------------------------------
| Helper opcional
|--------------------------------------------------------------------------
*/

if (!function_exists('pats_stripe_is_configured')) {
    function pats_stripe_is_configured(): bool {
        return defined('STRIPE_PUBLIC_KEY')
            && defined('STRIPE_SECRET_KEY')
            && trim((string) STRIPE_PUBLIC_KEY) !== ''
            && trim((string) STRIPE_SECRET_KEY) !== ''
            && str_starts_with((string) STRIPE_PUBLIC_KEY, 'pk_')
            && str_starts_with((string) STRIPE_SECRET_KEY, 'sk_')
            && !str_contains((string) STRIPE_PUBLIC_KEY, 'AQUI')
            && !str_contains((string) STRIPE_SECRET_KEY, 'AQUI')
            && !str_contains((string) STRIPE_PUBLIC_KEY, 'REEMPLAZAR')
            && !str_contains((string) STRIPE_SECRET_KEY, 'REEMPLAZAR');
    }
}