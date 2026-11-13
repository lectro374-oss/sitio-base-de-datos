<?php
session_start();
include("conexion.php");

if (!isset($_SESSION["id_usuario"])) {
    $_SESSION["store_message"] = "Inicia sesión para agregar productos al carrito.";
    header("Location: login.html");
    exit;
}

$idProducto = filter_input(INPUT_POST, "id_producto", FILTER_VALIDATE_INT);
if (!$idProducto || $idProducto < 1) {
    $_SESSION["store_message"] = "El producto seleccionado no es válido.";
    header("Location: index.php");
    exit;
}

mysqli_begin_transaction($conexion);

$productoSql = "SELECT id, nombre, precio, stock FROM figuras WHERE id = ? FOR UPDATE";
$productoStmt = mysqli_prepare($conexion, $productoSql);
if (!$productoStmt) {
    mysqli_rollback($conexion);
    die("No se pudo preparar el producto.");
}

mysqli_stmt_bind_param($productoStmt, "i", $idProducto);
mysqli_stmt_execute($productoStmt);
$producto = mysqli_fetch_assoc(mysqli_stmt_get_result($productoStmt));

if (!$producto || (int) $producto["stock"] < 1) {
    mysqli_stmt_close($productoStmt);
    mysqli_rollback($conexion);
    mysqli_close($conexion);
    $_SESSION["store_message"] = "El producto no tiene unidades disponibles.";
    header("Location: index.php");
    exit;
}

$cartSql = "
    SELECT id, cantidad
    FROM carrito
    WHERE id_usuario = ? AND id_producto = ? AND tipo_producto = 'figura'
    FOR UPDATE
";
$cartStmt = mysqli_prepare($conexion, $cartSql);
if (!$cartStmt) {
    mysqli_stmt_close($productoStmt);
    mysqli_rollback($conexion);
    die("No se pudo preparar el carrito.");
}

$usuarioId = (int) $_SESSION["id_usuario"];
$tipoProducto = "figura";
mysqli_stmt_bind_param($cartStmt, "ii", $usuarioId, $idProducto);
mysqli_stmt_execute($cartStmt);
$cartItem = mysqli_fetch_assoc(mysqli_stmt_get_result($cartStmt));

if ($cartItem) {
    if ((int) $cartItem["cantidad"] >= (int) $producto["stock"]) {
        mysqli_stmt_close($cartStmt);
        mysqli_stmt_close($productoStmt);
        mysqli_rollback($conexion);
        mysqli_close($conexion);
        $_SESSION["store_message"] = "No puedes agregar más unidades de este producto. Disponibles: " .
            (int) $producto["stock"] . ".";
        header("Location: index.php");
        exit;
    }
    $updateCart = mysqli_prepare($conexion, "UPDATE carrito SET cantidad = cantidad + 1 WHERE id = ?");
    mysqli_stmt_bind_param($updateCart, "i", $cartItem["id"]);
    mysqli_stmt_execute($updateCart);
    mysqli_stmt_close($updateCart);
} else {
    $insertCart = mysqli_prepare($conexion, "
        INSERT INTO carrito (id_usuario, id_producto, tipo_producto, nombre, precio, cantidad)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    mysqli_stmt_bind_param(
        $insertCart,
        "iissd",
        $usuarioId,
        $idProducto,
        $tipoProducto,
        $producto["nombre"],
        $producto["precio"]
    );
    mysqli_stmt_execute($insertCart);
    mysqli_stmt_close($insertCart);
}

mysqli_stmt_close($cartStmt);
mysqli_stmt_close($productoStmt);
mysqli_commit($conexion);
mysqli_close($conexion);

header("Location: carrito.php");
exit;
?>
