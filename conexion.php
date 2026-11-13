<?php
$conexion = mysqli_connect(
"localhost",
"root",
"",
"tienda_anime_v3"
);
if (!$conexion) {
die("No se pudo conectar con la base de datos.");
}
mysqli_set_charset($conexion, "utf8mb4");
?>