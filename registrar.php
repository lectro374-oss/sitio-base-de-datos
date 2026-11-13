<?php
session_start();
include("conexion.php");

function mostrarMensajeRegistro($mensaje, $codigo = 400)
{
    http_response_code($codigo);
    $mensajeSeguro = htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8");
    echo "<!DOCTYPE html>
<html lang=\"es\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Registro - Tienda de Anime</title>
    <link rel=\"stylesheet\" href=\"style.css\">
</head>
<body class=\"auth-page\">
    <main class=\"auth-card\">
        <h2>Crear cuenta</h2>
        <p class=\"auth-error\">{$mensajeSeguro}</p>
        <p class=\"auth-footer\"><a href=\"registro.html\">Volver al registro</a></p>
    </main>
</body>
</html>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    mostrarMensajeRegistro("Método no permitido.");
}

$usuario = trim($_POST["usuario"] ?? "");
$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if (strlen($usuario) < 3 || strlen($usuario) > 30) {
    mostrarMensajeRegistro("El nombre de usuario debe tener entre 3 y 30 caracteres.");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mostrarMensajeRegistro("El correo electrónico ingresado no es válido.");
}
if (strlen($password) < 8) {
    mostrarMensajeRegistro("La contraseña debe tener al menos 8 caracteres.");
}

$checkStmt = mysqli_prepare(
    $conexion,
    "SELECT nombre, correo FROM usuarios WHERE nombre = ? OR correo = ? LIMIT 1"
);
if (!$checkStmt) {
    mostrarMensajeRegistro("No se pudo verificar el nombre y el correo.", 500);
}
mysqli_stmt_bind_param($checkStmt, "ss", $usuario, $email);
mysqli_stmt_execute($checkStmt);
$existingUser = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
mysqli_stmt_close($checkStmt);

if ($existingUser) {
    if (strcasecmp($existingUser["nombre"], $usuario) === 0) {
        mostrarMensajeRegistro("Ese nombre de usuario ya está en uso.");
    }
    mostrarMensajeRegistro("Ese correo electrónico ya está en uso.");
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$passwordColumn = "contrase" . "\xC3\xB1" . "a";
$sql = "
    INSERT INTO usuarios (nombre, correo, `$passwordColumn`, direccion)
    VALUES (?, ?, ?, ?)
";
$direccion = "";
$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    mostrarMensajeRegistro("No se pudo preparar el registro.", 500);
}

mysqli_stmt_bind_param($stmt, "ssss", $usuario, $email, $passwordHash, $direccion);

try {
    $insertado = mysqli_stmt_execute($stmt);
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        mysqli_stmt_close($stmt);
        mysqli_close($conexion);
        mostrarMensajeRegistro("Ese nombre de usuario o correo electrónico ya está en uso.");
    }
    throw $error;
}

if (!$insertado) {
    mysqli_stmt_close($stmt);
    mysqli_close($conexion);
    mostrarMensajeRegistro("No se pudo completar el registro. Inténtalo nuevamente.", 500);
}

$_SESSION["id_usuario"] = mysqli_insert_id($conexion);
$_SESSION["usuario"] = $usuario;
mysqli_stmt_close($stmt);
mysqli_close($conexion);
header("Location: index.php");
exit;
?>
