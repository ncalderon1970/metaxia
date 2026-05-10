<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/DB.php';

$nuevoHash = password_hash('admin1234', PASSWORD_DEFAULT);

$pdo = DB::conn();
$stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE email = 'admin@metis.cl'");
$stmt->execute([$nuevoHash]);

echo 'Hash actualizado: ' . $nuevoHash;
echo '<br>Filas afectadas: ' . $stmt->rowCount();