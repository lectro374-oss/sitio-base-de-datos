<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
header("Location: login.html");
exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inicio</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<h1>
Bienvenido
<?php
echo htmlspecialchars(
$_SESSION["usuario"]
);
?>
</h1>
<p>Esta página requiere una sesión iniciada.</p>
<a href="logout.php">Cerrar sesión</a>
</body>
</html>
