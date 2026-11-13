
<?php
session_start();
include("conexion.php");

function mostrarErrorInicio($mensaje, $codigo = 400)
{
    http_response_code($codigo);
    $mensajeSeguro = htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8");
    echo "<!DOCTYPE html>
<html lang=\"es\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Iniciar sesión - Tienda de Anime</title>
    <link rel=\"stylesheet\" href=\"style.css\">
</head>
<body class=\"auth-page\">
    <main class=\"auth-card\">
        <h2>Iniciar sesión</h2>
        <p class=\"auth-error\">{$mensajeSeguro}</p>
        <p class=\"auth-footer\"><a href=\"login.html\">Volver al inicio de sesión</a></p>
    </main>
</body>
</html>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    mostrarErrorInicio("Método no permitido.");
}

$identificador = trim($_POST["usuario"] ?? "");
$password = $_POST["password"] ?? "";

if ($identificador === "" || $password === "") {
    mostrarErrorInicio("Completa el nombre de usuario o correo y la contraseña.");
}

$sql = "
    SELECT id, nombre, `contrase" . "\xC3\xB1" . "a` AS password_hash
    FROM usuarios
    WHERE nombre = ? OR correo = ?
";
$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    mostrarErrorInicio("No se pudo preparar el inicio de sesión.", 500);
}

mysqli_stmt_bind_param($stmt, "ss", $identificador, $identificador);
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);
$fila = mysqli_fetch_assoc($resultado);

if ($fila && password_verify($password, $fila["password_hash"])) {
    session_regenerate_id(true);
    $_SESSION["id_usuario"] = $fila["id"];
    $_SESSION["usuario"] = $fila["nombre"];
    mysqli_stmt_close($stmt);
    mysqli_close($conexion);
    header("Location: index.php");
    exit;
}

mysqli_stmt_close($stmt);
mysqli_close($conexion);
mostrarErrorInicio("El usuario, correo o contraseña son incorrectos.", 401);
?>
