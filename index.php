<?php
session_start();
$usuarioActual = $_SESSION["usuario"] ?? null;
$storeMessage = $_SESSION["store_message"] ?? "";
unset($_SESSION["store_message"]);
include("conexion.php");
$productResult = mysqli_query($conexion, "SELECT id, nombre, precio, stock, imagen FROM figuras ORDER BY id DESC");
$products = [];
while ($product = mysqli_fetch_assoc($productResult)) {
    $products[] = $product;
}
$cartCount = 0;
$esAdmin = false;
if (isset($_SESSION["id_usuario"])) {
    $roleStmt = mysqli_prepare($conexion, "SELECT es_admin FROM usuarios WHERE id = ?");
    $userId = (int) $_SESSION["id_usuario"];
    mysqli_stmt_bind_param($roleStmt, "i", $userId);
    mysqli_stmt_execute($roleStmt);
    $role = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
    $esAdmin = $role && (int) $role["es_admin"] === 1;
    mysqli_stmt_close($roleStmt);

    $cartStmt = mysqli_prepare($conexion, "SELECT COALESCE(SUM(cantidad), 0) AS total FROM carrito WHERE id_usuario = ?");
    mysqli_stmt_bind_param($cartStmt, "i", $userId);
    mysqli_stmt_execute($cartStmt);
    $cartCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($cartStmt))["total"];
    mysqli_stmt_close($cartStmt);
}
mysqli_close($conexion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda de Anime</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="home-page">

<header class="Header-anime">
    <!-- Buscador a la izquierda -->
    <div class="buscador">
        <input type="text" placeholder="Buscar" class="input-buscador">
        <button class="boton-buscador" type="submit"></button>
    </div>

    <!-- Título al medio -->
    <h1 class="titulo">Tienda de anime</h1>

<div class="usuario-carrito">
    <?php if ($usuarioActual !== null): ?>
        <span class="saludo-usuario">Hola, <?php echo htmlspecialchars($usuarioActual, ENT_QUOTES, "UTF-8"); ?></span>
        <?php if ($esAdmin): ?>
            <a class="enlace-admin" href="admin.php">ADMINISTRACIÓN</a>
        <?php endif; ?>
        <a class="boton-logout" href="logout.php">CERRAR SESIÓN</a>
    <?php else: ?>
        <a href="registro.html">CREAR CUENTA</a>
        <span>|</span>
        <a href="login.html">INICIAR SESIÓN</a>
    <?php endif; ?>
    <a class="carrito" href="<?php echo $usuarioActual !== null ? 'carrito.php' : 'login.html'; ?>" aria-label="Carrito de compras">
        <span class="contador-carrito"><?php echo $cartCount; ?></span>
    </a>
</div>
    <!-- Menú de navegación abajo -->
    <nav class="nav-contenedor">
        <ul class="menu-navegacion">
            <li><a href="#">Inicio</a></li>
            <li><a href="#">Productos</a></li>
            <li><a href="#">Ofertas</a></li>
            <li><a href="#">Contacto</a></li>
        </ul>
    </nav>
</header>

<main>
    <?php if ($storeMessage !== ""): ?>
        <p class="cart-message"><?php echo htmlspecialchars($storeMessage, ENT_QUOTES, "UTF-8"); ?></p>
    <?php endif; ?>
    <p class="promocion">Envíos a todo el país · Comprá online de forma segura</p>
    <section class="productos" aria-labelledby="productos-titulo">
        <h2 class="productos-titulo" id="productos-titulo">Productos destacados</h2>
        <div class="productos-grid">
            <?php foreach ($products as $product): ?>
                <?php $stock = (int) $product["stock"]; ?>
                <article class="producto">
                    <div class="producto-imagen">
                        <img src="<?php echo htmlspecialchars($product["imagen"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($product["nombre"], ENT_QUOTES, "UTF-8"); ?>">
                    </div>
                    <h3 class="producto-nombre"><?php echo htmlspecialchars($product["nombre"], ENT_QUOTES, "UTF-8"); ?></h3>
                    <p class="producto-precio">$ <?php echo number_format((float) $product["precio"], 2, ",", "."); ?> ARS</p>
                    <p class="producto-stock"><?php echo $stock > 0 ? $stock . " disponibles" : "AGOTADO"; ?></p>
                    <form action="agregar_carrito.php" method="POST">
                        <input type="hidden" name="id_producto" value="<?php echo (int) $product["id"]; ?>">
                        <button class="boton-carrito" type="submit" <?php echo $stock < 1 ? "disabled" : ""; ?>>AGREGAR AL CARRITO</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>

</body>
</html>