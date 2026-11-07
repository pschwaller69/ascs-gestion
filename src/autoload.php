<?php
declare(strict_types=1);

// L'association est en France : dates et heures en heure de Paris, en ligne de commande comme sur le web.
date_default_timezone_set('Europe/Paris');

// Autoload minimal : Ascs\Foo\Bar -> src/Foo/Bar.php (aucune dépendance Composer).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ascs\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
