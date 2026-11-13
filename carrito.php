<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.html");
    exit;
}

include("conexion.php");
$usuarioId = (int) $_SESSION["id_usuario"];
$cartMessage = $_SESSION["cart_message"] ?? "";
$purchaseMessage = $_SESSION["purchase_message"] ?? "";
unset($_SESSION["cart_message"], $_SESSION["purchase_message"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "buy") {
        mysqli_begin_transaction($conexion);
        $itemsStmt = mysqli_prepare($conexion, "
            SELECT id, id_producto, tipo_producto, nombre, precio, cantidad
            FROM carrito
            WHERE id_usuario = ?
            FOR UPDATE
        ");
        if (!$itemsStmt) {
            mysqli_rollback($conexion);
            die("No se pudo preparar la compra.");
        }

        mysqli_stmt_bind_param($itemsStmt, "i", $usuarioId);
        mysqli_stmt_execute($itemsStmt);
        $itemsResult = mysqli_stmt_get_result($itemsStmt);
        $cartItems = [];
        while ($cartItem = mysqli_fetch_assoc($itemsResult)) {
            $cartItems[] = $cartItem;
        }
        mysqli_stmt_close($itemsStmt);

        if (!$cartItems) {
            mysqli_rollback($conexion);
            $_SESSION["cart_message"] = "El carrito está vacío.";
            header("Location: carrito.php");
            exit;
        }

        foreach ($cartItems as $cartItem) {
            if ($cartItem["tipo_producto"] === "figura") {
                $stockStmt = mysqli_prepare($conexion, "SELECT stock FROM figuras WHERE id = ? FOR UPDATE");
            } elseif ($cartItem["tipo_producto"] === "libro") {
                $stockStmt = mysqli_prepare($conexion, "SELECT stock FROM libros WHERE id = ? FOR UPDATE");
            } else {
                mysqli_rollback($conexion);
                $_SESSION["cart_message"] = "No se reconoce el tipo de uno de los productos.";
                header("Location: carrito.php");
                exit;
            }

            mysqli_stmt_bind_param($stockStmt, "i", $cartItem["id_producto"]);
            mysqli_stmt_execute($stockStmt);
            $stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stockStmt));
            mysqli_stmt_close($stockStmt);

            if (!$stockRow || (int) $stockRow["stock"] < (int) $cartItem["cantidad"]) {
                $disponibles = $stockRow ? (int) $stockRow["stock"] : 0;
                mysqli_rollback($conexion);
                $_SESSION["cart_message"] = "No hay stock suficiente de " . $cartItem["nombre"] .
                    ". Disponibles: " . $disponibles . "; en el carrito: " . (int) $cartItem["cantidad"] . ".";
                header("Location: carrito.php");
                exit;
            }
        }

        $saleStmt = mysqli_prepare($conexion, "INSERT INTO ventas (id_usuario) VALUES (?)");
        mysqli_stmt_bind_param($saleStmt, "i", $usuarioId);
        mysqli_stmt_execute($saleStmt);
        $saleId = mysqli_insert_id($conexion);
        mysqli_stmt_close($saleStmt);

        $detailStmt = mysqli_prepare($conexion, "
            INSERT INTO detalle (id_venta, id_usuario, id_producto, tipo_producto, cantidad, precio_unitario)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($cartItems as $cartItem) {
            $productId = (int) $cartItem["id_producto"];
            $quantity = (int) $cartItem["cantidad"];
            $productType = $cartItem["tipo_producto"];
            $unitPrice = (float) $cartItem["precio"];

            if ($productType === "figura") {
                $stockUpdate = mysqli_prepare(
                    $conexion,
                    "UPDATE figuras SET stock = stock - ? WHERE id = ? AND stock >= ?"
                );
            } else {
                $stockUpdate = mysqli_prepare(
                    $conexion,
                    "UPDATE libros SET stock = stock - ? WHERE id = ? AND stock >= ?"
                );
            }
            mysqli_stmt_bind_param($stockUpdate, "iii", $quantity, $productId, $quantity);
            mysqli_stmt_execute($stockUpdate);

            if (mysqli_stmt_affected_rows($stockUpdate) !== 1) {
                mysqli_stmt_close($stockUpdate);
                mysqli_stmt_close($detailStmt);
                mysqli_rollback($conexion);
                $_SESSION["cart_message"] = "El stock cambió mientras se procesaba la compra. Revisa las cantidades e inténtalo nuevamente.";
                header("Location: carrito.php");
                exit;
            }
            mysqli_stmt_close($stockUpdate);

            mysqli_stmt_bind_param(
                $detailStmt,
                "iiisid",
                $saleId,
                $usuarioId,
                $productId,
                $productType,
                $quantity,
                $unitPrice
            );
            mysqli_stmt_execute($detailStmt);
        }
        mysqli_stmt_close($detailStmt);

        $clearCartStmt = mysqli_prepare($conexion, "DELETE FROM carrito WHERE id_usuario = ?");
        mysqli_stmt_bind_param($clearCartStmt, "i", $usuarioId);
        mysqli_stmt_execute($clearCartStmt);
        mysqli_stmt_close($clearCartStmt);
        mysqli_commit($conexion);
        $_SESSION["purchase_message"] = "¡Gracias por tu compra! La compra simbólica se registró correctamente.";
        header("Location: carrito.php");
        exit;
    }

    $cartId = filter_input(INPUT_POST, "cart_id", FILTER_VALIDATE_INT);
    if (!$cartId || !in_array($action, ["increase", "decrease", "remove"], true)) {
        $_SESSION["cart_message"] = "La acción solicitada no es válida.";
        header("Location: carrito.php");
        exit;
    }

    mysqli_begin_transaction($conexion);
    $itemStmt = mysqli_prepare($conexion, "
        SELECT id, id_producto, tipo_producto, cantidad
        FROM carrito
        WHERE id = ? AND id_usuario = ?
        FOR UPDATE
    ");
    mysqli_stmt_bind_param($itemStmt, "ii", $cartId, $usuarioId);
    mysqli_stmt_execute($itemStmt);
    $cartItem = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt));

    if (!$cartItem) {
        mysqli_stmt_close($itemStmt);
        mysqli_rollback($conexion);
        $_SESSION["cart_message"] = "No se encontró ese producto en el carrito.";
        header("Location: carrito.php");
        exit;
    }

    if ($action === "increase") {
        if ($cartItem["tipo_producto"] === "figura") {
            $stockTable = "figuras";
        } elseif ($cartItem["tipo_producto"] === "libro") {
            $stockTable = "libros";
        } else {
            $stockTable = "";
        }

        if ($stockTable === "") {
            $stockRow = null;
        } else {
            $stockStmt = mysqli_prepare($conexion, "SELECT stock FROM " . $stockTable . " WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stockStmt, "i", $cartItem["id_producto"]);
            mysqli_stmt_execute($stockStmt);
            $stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stockStmt));
            mysqli_stmt_close($stockStmt);
        }
        $availableStock = $stockRow ? (int) $stockRow["stock"] : 0;

        if ((int) $cartItem["cantidad"] >= $availableStock) {
            mysqli_stmt_close($itemStmt);
            mysqli_rollback($conexion);
            $_SESSION["cart_message"] = "No puedes agregar más unidades. Disponibles: " . $availableStock . ".";
            header("Location: carrito.php");
            exit;
        }
        $updateCart = mysqli_prepare($conexion, "UPDATE carrito SET cantidad = cantidad + 1 WHERE id = ?");
    } elseif ($action === "decrease" && (int) $cartItem["cantidad"] > 1) {
        $updateCart = mysqli_prepare($conexion, "UPDATE carrito SET cantidad = cantidad - 1 WHERE id = ?");
    } else {
        $updateCart = mysqli_prepare($conexion, "DELETE FROM carrito WHERE id = ?");
    }

    mysqli_stmt_bind_param($updateCart, "i", $cartId);
    mysqli_stmt_execute($updateCart);
    mysqli_stmt_close($updateCart);
    mysqli_stmt_close($itemStmt);
    mysqli_commit($conexion);
    header("Location: carrito.php");
    exit;
}

$stmt = mysqli_prepare($conexion, "
    SELECT c.id, c.id_producto, c.nombre, c.precio, c.cantidad,
        c.precio * c.cantidad AS subtotal, COALESCE(f.imagen, l.imagen, '') AS imagen
    FROM carrito c
    LEFT JOIN figuras f ON c.tipo_producto = 'figura' AND f.id = c.id_producto
    LEFT JOIN libros l ON c.tipo_producto = 'libro' AND l.id = c.id_producto
    WHERE c.id_usuario = ?
    ORDER BY c.id DESC
");
mysqli_stmt_bind_param($stmt, "i", $usuarioId);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);
$total = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrito - Tienda de Anime</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page cart-page">
    <main class="cart-card">
        <h1>Tu carrito</h1>
        <?php if ($purchaseMessage !== ""): ?>
            <p class="cart-success"><?php echo htmlspecialchars($purchaseMessage, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>
        <?php if ($cartMessage !== ""): ?>
            <p class="cart-message"><?php echo htmlspecialchars($cartMessage, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>
        <?php if (mysqli_num_rows($items) === 0): ?>
            <p class="cart-empty">Tu carrito está vacío.</p>
        <?php else: ?>
            <div class="cart-items">
                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                    <?php $total += (float) $item["subtotal"]; ?>
                    <div class="cart-item">
                        <img class="cart-item-image" src="<?php echo htmlspecialchars($item["imagen"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($item["nombre"], ENT_QUOTES, "UTF-8"); ?>">
                        <div>
                            <h2><?php echo htmlspecialchars($item["nombre"], ENT_QUOTES, "UTF-8"); ?></h2>
                            <p><?php echo (int) $item["cantidad"]; ?> unidad(es) × $ <?php echo number_format((float) $item["precio"], 2, ",", "."); ?> ARS</p>
                        </div>
                        <div class="cart-controls">
                            <form method="POST">
                                <input type="hidden" name="cart_id" value="<?php echo (int) $item["id"]; ?>">
                                <input type="hidden" name="action" value="decrease">
                                <button type="submit" aria-label="Quitar una unidad">−</button>
                            </form>
                            <span><?php echo (int) $item["cantidad"]; ?></span>
                            <form method="POST">
                                <input type="hidden" name="cart_id" value="<?php echo (int) $item["id"]; ?>">
                                <input type="hidden" name="action" value="increase">
                                <button type="submit" aria-label="Agregar una unidad">+</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="cart_id" value="<?php echo (int) $item["id"]; ?>">
                                <input type="hidden" name="action" value="remove">
                                <button class="cart-remove" type="submit">QUITAR</button>
                            </form>
                        </div>
                        <strong>$ <?php echo number_format((float) $item["subtotal"], 2, ",", "."); ?> ARS</strong>
                    </div>
                <?php endwhile; ?>
            </div>
            <div class="cart-total">
                <span>Total</span>
                <strong>$ <?php echo number_format($total, 2, ",", "."); ?> ARS</strong>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="buy">
                <button class="cart-buy" type="submit">CONFIRMAR COMPRA</button>
            </form>
        <?php endif; ?>
        <div class="cart-actions">
            <a class="cart-link" href="index.php">← Seguir comprando</a>
            <a class="boton-logout" href="logout.php">CERRAR SESIÓN</a>
        </div>
    </main>
</body>
</html>
<?php
mysqli_stmt_close($stmt);
mysqli_close($conexion);
?>
