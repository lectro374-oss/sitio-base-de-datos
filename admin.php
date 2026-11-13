<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.html");
    exit;
}

include("conexion.php");
$usuarioId = (int) $_SESSION["id_usuario"];
$roleStmt = mysqli_prepare($conexion, "SELECT es_admin FROM usuarios WHERE id = ?");
if (!$roleStmt) {
    die("No se pudo verificar el permiso de administrador.");
}
mysqli_stmt_bind_param($roleStmt, "i", $usuarioId);
mysqli_stmt_execute($roleStmt);
$role = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
mysqli_stmt_close($roleStmt);

if (!$role || (int) $role["es_admin"] !== 1) {
    http_response_code(403);
    mysqli_close($conexion);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso denegado - Tienda de Anime</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body class="auth-page">
        <main class="auth-card">
            <h2>Acceso denegado</h2>
            <p class="auth-error">No tienes permisos para acceder al panel de administración.</p>
            <p class="auth-footer"><a href="index.php">Volver a la tienda</a></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}

function redirigirAdmin($mensaje)
{
    $_SESSION["admin_notice"] = $mensaje;
    header("Location: admin.php");
    exit;
}

function guardarImagenAdmin($archivo)
{
    if (!isset($archivo) || $archivo["error"] !== UPLOAD_ERR_OK || $archivo["size"] > 5 * 1024 * 1024) {
        return false;
    }

    $mimeTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($archivo["tmp_name"]);
    if (!isset($mimeTypes[$mimeType]) || !getimagesize($archivo["tmp_name"])) {
        return false;
    }

    $imageDirectory = __DIR__ . DIRECTORY_SEPARATOR . "images" . DIRECTORY_SEPARATOR . "productos";
    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true)) {
        return false;
    }

    $filename = bin2hex(random_bytes(16)) . "." . $mimeTypes[$mimeType];
    if (!move_uploaded_file($archivo["tmp_name"], $imageDirectory . DIRECTORY_SEPARATOR . $filename)) {
        return false;
    }

    return "images/productos/" . $filename;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf"] ?? "";
    if (!is_string($csrf) || !hash_equals($_SESSION["admin_csrf"], $csrf)) {
        redirigirAdmin("La solicitud venció. Inténtalo nuevamente.");
    }

    $accion = $_POST["accion"] ?? "";
    if ($accion === "eliminar_producto") {
        $idProducto = filter_input(INPUT_POST, "id_producto", FILTER_VALIDATE_INT);
        if (!$idProducto || $idProducto < 1) {
            redirigirAdmin("El producto solicitado no es válido.");
        }

        if (!mysqli_begin_transaction($conexion)) {
            die("No se pudo iniciar la eliminación del producto.");
        }

        $productStmt = mysqli_prepare($conexion, "SELECT id FROM figuras WHERE id = ? FOR UPDATE");
        if (!$productStmt) {
            mysqli_rollback($conexion);
            die("No se pudo verificar el producto solicitado.");
        }
        mysqli_stmt_bind_param($productStmt, "i", $idProducto);
        if (!mysqli_stmt_execute($productStmt)) {
            mysqli_stmt_close($productStmt);
            mysqli_rollback($conexion);
            die("No se pudo verificar el producto solicitado.");
        }
        $productExists = mysqli_fetch_assoc(mysqli_stmt_get_result($productStmt));
        mysqli_stmt_close($productStmt);
        if (!$productExists) {
            mysqli_rollback($conexion);
            redirigirAdmin("No se encontró ese producto.");
        }

        $cartStmt = mysqli_prepare(
            $conexion,
            "DELETE FROM carrito WHERE id_producto = ? AND tipo_producto = 'figura'"
        );
        if (!$cartStmt) {
            mysqli_rollback($conexion);
            die("No se pudieron quitar del carrito las referencias a este producto.");
        }
        mysqli_stmt_bind_param($cartStmt, "i", $idProducto);
        if (!mysqli_stmt_execute($cartStmt)) {
            mysqli_stmt_close($cartStmt);
            mysqli_rollback($conexion);
            die("No se pudieron quitar del carrito las referencias a este producto.");
        }
        mysqli_stmt_close($cartStmt);

        $deleteStmt = mysqli_prepare($conexion, "DELETE FROM figuras WHERE id = ?");
        if (!$deleteStmt) {
            mysqli_rollback($conexion);
            die("No se pudo preparar la eliminación del producto.");
        }
        mysqli_stmt_bind_param($deleteStmt, "i", $idProducto);
        if (!mysqli_stmt_execute($deleteStmt) || mysqli_stmt_affected_rows($deleteStmt) !== 1) {
            mysqli_stmt_close($deleteStmt);
            mysqli_rollback($conexion);
            die("No se pudo eliminar el producto.");
        }
        mysqli_stmt_close($deleteStmt);

        if (!mysqli_commit($conexion)) {
            mysqli_rollback($conexion);
            die("No se pudo confirmar la eliminación del producto.");
        }
        redirigirAdmin("El producto se eliminó del catálogo y de los carritos. El historial de ventas se conservó.");
    }

    if ($accion === "actualizar_producto") {
        $idProducto = filter_input(INPUT_POST, "id_producto", FILTER_VALIDATE_INT);
        $nombre = trim($_POST["nombre"] ?? "");
        $franquicia = trim($_POST["franquicia"] ?? "");
        $descripcion = trim($_POST["descripcion"] ?? "");
        $precio = $_POST["precio"] ?? "";
        $stock = filter_input(INPUT_POST, "stock", FILTER_VALIDATE_INT);
        if (!$idProducto || $nombre === "" || strlen($nombre) > 255) {
            redirigirAdmin("El nombre del producto es obligatorio.");
        }
        if ($franquicia === "" || strlen($franquicia) > 100) {
            redirigirAdmin("La categoría es obligatoria y no debe superar los 100 caracteres.");
        }
        if (!is_numeric($precio) || (float) $precio <= 0) {
            redirigirAdmin("Ingresa un precio mayor que cero.");
        }
        if ($stock === false || $stock === null || $stock < 0) {
            redirigirAdmin("Ingresa un stock igual o mayor que cero.");
        }

        $currentStmt = mysqli_prepare($conexion, "SELECT imagen FROM figuras WHERE id = ?");
        mysqli_stmt_bind_param($currentStmt, "i", $idProducto);
        mysqli_stmt_execute($currentStmt);
        $currentProduct = mysqli_fetch_assoc(mysqli_stmt_get_result($currentStmt));
        mysqli_stmt_close($currentStmt);
        if (!$currentProduct) {
            redirigirAdmin("No se encontró ese producto.");
        }

        $imageUrl = $currentProduct["imagen"];
        if (isset($_FILES["imagen"]) && $_FILES["imagen"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $uploadedImage = guardarImagenAdmin($_FILES["imagen"]);
            if ($uploadedImage === false) {
                redirigirAdmin("La imagen debe ser JPG, PNG o WEBP y no superar los 5 MB.");
            }
            $imageUrl = $uploadedImage;
        }

        $stmt = mysqli_prepare(
            $conexion,
            "UPDATE figuras SET nombre = ?, precio = ?, stock = ?, descripcion = ?, franquicia = ?, imagen = ? WHERE id = ?"
        );
        if (!$stmt) {
            die("No se pudo preparar la actualización del producto.");
        }
        $precioNumerico = (float) $precio;
        mysqli_stmt_bind_param(
            $stmt,
            "sdisssi",
            $nombre,
            $precioNumerico,
            $stock,
            $descripcion,
            $franquicia,
            $imageUrl,
            $idProducto
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirigirAdmin("No se pudo actualizar el producto.");
        }
        mysqli_stmt_close($stmt);
        redirigirAdmin("Se actualizaron los datos del producto.");
    }

    if ($accion === "agregar_producto") {
        $nombre = trim($_POST["nombre"] ?? "");
        $franquicia = trim($_POST["franquicia"] ?? "Anime");
        $descripcion = trim($_POST["descripcion"] ?? "");
        $precio = $_POST["precio"] ?? "";
        $stock = filter_input(INPUT_POST, "stock", FILTER_VALIDATE_INT);

        if ($nombre === "" || strlen($nombre) > 255) {
            redirigirAdmin("El nombre es obligatorio y no debe superar los 255 caracteres.");
        }
        if ($franquicia === "" || strlen($franquicia) > 100) {
            redirigirAdmin("La franquicia es obligatoria y no debe superar los 100 caracteres.");
        }
        if (!is_numeric($precio) || (float) $precio <= 0) {
            redirigirAdmin("Ingresa un precio mayor que cero.");
        }
        if ($stock === false || $stock === null || $stock < 0) {
            redirigirAdmin("Ingresa un stock igual o mayor que cero.");
        }
        if (!isset($_FILES["imagen"]) || $_FILES["imagen"]["error"] !== UPLOAD_ERR_OK) {
            redirigirAdmin("Selecciona una imagen válida para el producto.");
        }
        $imageUrl = guardarImagenAdmin($_FILES["imagen"]);
        if ($imageUrl === false) {
            redirigirAdmin("La imagen debe ser JPG, PNG o WEBP y no superar los 5 MB.");
        }

        $stmt = mysqli_prepare(
            $conexion,
            "INSERT INTO figuras (nombre, precio, stock, descripcion, franquicia, imagen) VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            die("No se pudo preparar el alta del producto.");
        }
        $precioNumerico = (float) $precio;
        mysqli_stmt_bind_param(
            $stmt,
            "sdisss",
            $nombre,
            $precioNumerico,
            $stock,
            $descripcion,
            $franquicia,
            $imageUrl
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            redirigirAdmin("No se pudo guardar el producto.");
        }
        mysqli_stmt_close($stmt);
        redirigirAdmin("El producto se agregó correctamente.");
    }

    if ($accion === "designar_admin") {
        $nombreUsuario = trim($_POST["nombre_usuario"] ?? "");
        if ($nombreUsuario === "") {
            redirigirAdmin("Escribe el nombre de usuario al que deseas dar acceso.");
        }

        $stmt = mysqli_prepare($conexion, "UPDATE usuarios SET es_admin = 1 WHERE nombre = ?");
        if (!$stmt) {
            die("No se pudo preparar la asignación del administrador.");
        }
        mysqli_stmt_bind_param($stmt, "s", $nombreUsuario);
        mysqli_stmt_execute($stmt);
        $actualizado = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($actualizado === 0) {
            $checkStmt = mysqli_prepare($conexion, "SELECT id, es_admin FROM usuarios WHERE nombre = ?");
            mysqli_stmt_bind_param($checkStmt, "s", $nombreUsuario);
            mysqli_stmt_execute($checkStmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
            mysqli_stmt_close($checkStmt);
            redirigirAdmin($existing
                ? "Ese usuario ya tiene permisos de administrador."
                : "No se encontró ese nombre de usuario.");
        }
        redirigirAdmin("Se otorgó acceso de administrador a " . $nombreUsuario . ".");
    }

    redirigirAdmin("La acción solicitada no es válida.");
}

$notice = $_SESSION["admin_notice"] ?? "";
unset($_SESSION["admin_notice"]);
$products = mysqli_query($conexion, "SELECT id, nombre, precio, stock, descripcion, franquicia, imagen FROM figuras ORDER BY id");
$admins = mysqli_query($conexion, "SELECT nombre FROM usuarios WHERE es_admin = 1 ORDER BY nombre");
$csrfToken = htmlspecialchars($_SESSION["admin_csrf"], ENT_QUOTES, "UTF-8");
$isAddingProduct = isset($_GET["nuevo"]) && $_GET["nuevo"] === "1";
$editingProduct = null;
if (isset($_GET["editar"])) {
    $editingId = filter_input(INPUT_GET, "editar", FILTER_VALIDATE_INT);
    if (!$editingId || $editingId < 1) {
        redirigirAdmin("El producto solicitado no es válido.");
    }
    $editStmt = mysqli_prepare(
        $conexion,
        "SELECT id, nombre, precio, stock, descripcion, franquicia, imagen FROM figuras WHERE id = ?"
    );
    if (!$editStmt) {
        die("No se pudo cargar el producto solicitado.");
    }
    mysqli_stmt_bind_param($editStmt, "i", $editingId);
    mysqli_stmt_execute($editStmt);
    $editingProduct = mysqli_fetch_assoc(mysqli_stmt_get_result($editStmt));
    mysqli_stmt_close($editStmt);
    if (!$editingProduct) {
        redirigirAdmin("No se encontró el producto solicitado.");
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración - Tienda de Anime</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page admin-page">
    <main class="admin-card">
        <header class="admin-header">
            <div>
                <h1>Panel de administración</h1>
                <p>Administra el catálogo, las existencias y los accesos.</p>
            </div>
            <a class="admin-back-link" href="index.php">← Volver a la tienda</a>
        </header>

        <?php if ($notice !== ""): ?>
            <p class="admin-notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>

        <?php if ($editingProduct !== null): ?>
            <section class="admin-section admin-editor-section">
                <a class="admin-return-link" href="admin.php">← Volver a los productos</a>
                <div class="admin-section-heading">
                    <div>
                        <h2>Editar producto</h2>
                        <p>Actualiza los datos y guarda los cambios del artículo.</p>
                    </div>
                </div>
                <div class="admin-edit-layout">
                    <div class="admin-edit-preview">
                        <img src="<?php echo htmlspecialchars($editingProduct["imagen"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($editingProduct["nombre"], ENT_QUOTES, "UTF-8"); ?>">
                        <p>Imagen actual</p>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="admin-form admin-edit-form">
                        <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="accion" value="actualizar_producto">
                        <input type="hidden" name="id_producto" value="<?php echo (int) $editingProduct["id"]; ?>">
                        <div class="admin-form-grid">
                            <div class="admin-field admin-field-wide">
                                <label for="editar-nombre">Nombre del producto</label>
                                <input id="editar-nombre" type="text" name="nombre" maxlength="255" value="<?php echo htmlspecialchars($editingProduct["nombre"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="admin-field">
                                <label for="editar-franquicia">Categoría o franquicia</label>
                                <input id="editar-franquicia" type="text" name="franquicia" maxlength="100" value="<?php echo htmlspecialchars($editingProduct["franquicia"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="admin-field">
                                <label for="editar-precio">Precio (ARS)</label>
                                <input id="editar-precio" type="number" name="precio" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string) $editingProduct["precio"], ENT_QUOTES, "UTF-8"); ?>" required>
                            </div>
                            <div class="admin-field">
                                <label for="editar-stock">Unidades disponibles</label>
                                <input id="editar-stock" type="number" name="stock" min="0" value="<?php echo (int) $editingProduct["stock"]; ?>" required>
                            </div>
                            <div class="admin-field admin-field-wide">
                                <label for="editar-descripcion">Descripción</label>
                                <textarea id="editar-descripcion" name="descripcion" rows="4"><?php echo htmlspecialchars($editingProduct["descripcion"] ?? "", ENT_QUOTES, "UTF-8"); ?></textarea>
                            </div>
                            <div class="admin-field admin-field-wide">
                                <label for="editar-imagen">Reemplazar imagen (opcional; JPG, PNG o WEBP, máximo 5 MB)</label>
                                <input id="editar-imagen" type="file" name="imagen" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            </div>
                        </div>
                        <button class="admin-save-button" type="submit">Guardar cambios</button>
                    </form>
                </div>
            </section>
        <?php elseif ($isAddingProduct): ?>
            <section class="admin-section admin-editor-section">
                <a class="admin-return-link" href="admin.php">← Volver a los productos</a>
                <div class="admin-section-heading">
                    <div>
                        <h2>Agregar producto</h2>
                        <p>Completa los datos para incorporarlo al catálogo.</p>
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data" class="admin-form admin-new-product-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="accion" value="agregar_producto">
                    <div class="admin-form-grid">
                        <div class="admin-field">
                            <label for="nuevo-nombre">Nombre del producto</label>
                            <input id="nuevo-nombre" type="text" name="nombre" maxlength="255" required>
                        </div>
                        <div class="admin-field">
                            <label for="nueva-franquicia">Categoría o franquicia</label>
                            <input id="nueva-franquicia" type="text" name="franquicia" maxlength="100" value="Anime" required>
                        </div>
                        <div class="admin-field">
                            <label for="nuevo-precio">Precio (ARS)</label>
                            <input id="nuevo-precio" type="number" name="precio" min="0.01" step="0.01" required>
                        </div>
                        <div class="admin-field">
                            <label for="nuevo-stock">Existencias iniciales</label>
                            <input id="nuevo-stock" type="number" name="stock" min="0" required>
                        </div>
                        <div class="admin-field admin-field-wide">
                            <label for="nueva-descripcion">Descripción</label>
                            <textarea id="nueva-descripcion" name="descripcion" rows="3"></textarea>
                        </div>
                        <div class="admin-field admin-field-wide">
                            <label for="nueva-imagen">Imagen del producto (JPG, PNG o WEBP; máximo 5 MB)</label>
                            <input id="nueva-imagen" type="file" name="imagen" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                        </div>
                    </div>
                    <button class="admin-save-button" type="submit">Agregar al catálogo</button>
                </form>
            </section>
        <?php else: ?>
            <section class="admin-section" id="productos">
                <div class="admin-section-heading">
                    <div>
                        <h2>Productos de la tienda</h2>
                        <p>Selecciona un producto para editar sus datos.</p>
                    </div>
                    <span class="admin-count"><?php echo mysqli_num_rows($products); ?> productos</span>
                </div>
                <div class="admin-products">
                    <?php while ($product = mysqli_fetch_assoc($products)): ?>
                        <article class="admin-product-row">
                            <img src="<?php echo htmlspecialchars($product["imagen"], ENT_QUOTES, "UTF-8"); ?>" alt="">
                            <div class="admin-product-row-info">
                                <span class="admin-product-category"><?php echo htmlspecialchars($product["franquicia"], ENT_QUOTES, "UTF-8"); ?></span>
                                <h3><?php echo htmlspecialchars($product["nombre"], ENT_QUOTES, "UTF-8"); ?></h3>
                                <div class="admin-product-meta">
                                    <span>$ <?php echo number_format((float) $product["precio"], 2, ",", "."); ?> ARS</span>
                                    <span><?php echo (int) $product["stock"]; ?> disponibles</span>
                                </div>
                            </div>
                            <div class="admin-product-actions">
                                <a class="admin-edit-button" href="admin.php?editar=<?php echo (int) $product["id"]; ?>">Editar</a>
                                <form method="POST" class="admin-delete-form" onsubmit="return confirm('¿Eliminar este producto del catálogo y de los carritos? El historial de ventas se conservará.');">
                                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="accion" value="eliminar_producto">
                                    <input type="hidden" name="id_producto" value="<?php echo (int) $product["id"]; ?>">
                                    <button class="admin-delete-button" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endwhile; ?>
                    <a class="admin-add-tile" href="admin.php?nuevo=1" aria-label="Agregar un producto nuevo">
                        <span class="admin-add-icon" aria-hidden="true">+</span>
                        <strong>Agregar producto</strong>
                    </a>
                </div>
            </section>

            <section class="admin-section" id="administradores">
                <div class="admin-section-heading">
                    <div>
                        <h2>Administradores</h2>
                        <p>Concede acceso al panel a un usuario registrado.</p>
                    </div>
                </div>
                <form method="POST" class="admin-form admin-admin-form">
                    <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="accion" value="designar_admin">
                    <label for="nombre-usuario">Nombre de usuario existente</label>
                    <div class="admin-form-row">
                        <input id="nombre-usuario" type="text" name="nombre_usuario" required>
                        <button type="submit">Designar administrador</button>
                    </div>
                </form>
                <h3 class="admin-current-title">Administradores actuales</h3>
                <ul class="admin-list">
                    <?php while ($admin = mysqli_fetch_assoc($admins)): ?>
                        <li><?php echo htmlspecialchars($admin["nombre"], ENT_QUOTES, "UTF-8"); ?></li>
                    <?php endwhile; ?>
                </ul>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
<?php
mysqli_free_result($products);
mysqli_free_result($admins);
mysqli_close($conexion);
?>
