<?php
session_start();
include '../../../config.php';
// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Función para manejar la subida de archivos
function uploadFile($file, $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'])
{
    $targetDir = "documentacion/";
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $fileName = uniqid() . '_' . basename($file["name"]);
    $targetFilePath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

    // Verificar si la extensión del archivo es permitida
    if (in_array($fileType, $allowedExtensions)) {
        // Subir archivo
        if (move_uploaded_file($file["tmp_name"], $targetFilePath)) {
            return $targetFilePath;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

// Procesar la subida de documentos
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_documents') {
    $uploadedFiles = [];
    $documentTypes = ['rfc', 'acta_nacimiento', 'grados_academicos'];

    foreach ($documentTypes as $docType) {
        if (isset($_FILES[$docType]) && $_FILES[$docType]['error'] == 0) {
            $uploadedFile = uploadFile($_FILES[$docType]);
            if ($uploadedFile) {
                $uploadedFiles[$docType] = $uploadedFile;
            }
        }
    }

    if (!empty($uploadedFiles)) {
        $updateQuery = "UPDATE prospecto SET ";
        $updateParams = [];
        foreach ($uploadedFiles as $docType => $filePath) {
            $updateQuery .= "{$docType} = ?, ";
            $updateParams[] = $filePath;
        }
        $updateQuery = rtrim($updateQuery, ", ");
        $updateQuery .= " WHERE numero = ?";
        $updateParams[] = $user_id;

        $stmt = $conexion->prepare($updateQuery);
        $stmt->bind_param(str_repeat('s', count($updateParams)), ...$updateParams);
        
        if ($stmt->execute()) {
            $success = 'Documentos actualizados correctamente.';
        } else {
            $error = 'Error al actualizar los documentos.';
        }
    }
}

// Procesar la actualización de la foto de perfil
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile_picture') {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $filename = $_FILES["profile_picture"]["name"];
        $filetype = $_FILES["profile_picture"]["type"];
        $filesize = $_FILES["profile_picture"]["size"];

        // Verify file extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!array_key_exists($ext, $allowed)) {
            $error = "Error: Por favor selecciona un formato de archivo válido.";
        }

        // Verify file size - 5MB maximum
        $maxsize = 5 * 1024 * 1024;
        if ($filesize > $maxsize) {
            $error = "Error: El tamaño del archivo es mayor que el límite permitido (5MB).";
        }

        // Verify MYME type of the file
        if (in_array($filetype, $allowed)) {
            // Check whether file exists before uploading it
            $new_filename = "profile_" . date("YmdHis") . "." . $ext;
            $target = "../../../../Outsourcing/img/" . $new_filename;
            
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target)) {
                // Update database with new file path
                $update_query = "UPDATE usuario SET foto = ? WHERE numero = ?";
                $update_stmt = $conexion->prepare($update_query);
                $update_stmt->bind_param("si", $new_filename, $user_id);
                
                if ($update_stmt->execute()) {
                    $success = "La foto de perfil se ha actualizado correctamente.";
                    $prospecto['foto'] = $new_filename; // Update the current session data
                } else {
                    $error = "Hubo un problema al actualizar la base de datos.";
                }
            } else {
                $error = "Lo sentimos, hubo un error subiendo tu archivo.";
            }
        } else {
            $error = "Error: Ha ocurrido un problema con la subida del archivo. Por favor intenta de nuevo.";
        }
    } else {
        $error = "Error: " . $_FILES["profile_picture"]["error"];
    }
}

// Obtener los datos del prospecto
$query = "SELECT p.*, u.correo, 
          p.rfc, p.acta_nacimiento, p.grados_academicos 
          FROM prospecto p 
          INNER JOIN usuario u ON p.usuario = u.numero 
          WHERE u.numero = ?";
$stmt = $conexion->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$prospecto = $result->fetch_assoc();

if (!$prospecto) {
    die("No se encontró el perfil del prospecto.");
}

// Calcular la edad usando el procedimiento almacenado
$stmt = $conexion->prepare("CALL SP_calcularEdad(?, @edad);");
$stmt->bind_param("i", $prospecto['numero']);
$stmt->execute();
$stmt->close();

$result = $conexion->query("SELECT @edad AS edad;");
$edad = $result->fetch_assoc()['edad'];

// Obtener experiencia laboral
$query_exp = "SELECT e.*, r.descripcion AS responsabilidad, r.numero AS responsabilidad_id
              FROM experiencia e 
              LEFT JOIN responsabilidades r ON e.numero = r.experiencia 
              WHERE e.prospecto = ?
              ORDER BY e.fechaInicio DESC, r.numero ASC";
$stmt_exp = $conexion->prepare($query_exp);
$stmt_exp->bind_param("i", $prospecto['numero']);
$stmt_exp->execute();
$result_exp = $stmt_exp->get_result();

$experiencias = [];
while ($row = $result_exp->fetch_assoc()) {
    $exp_id = $row['numero'];
    if (!isset($experiencias[$exp_id])) {
        $experiencias[$exp_id] = $row;
        $experiencias[$exp_id]['responsabilidades'] = [];
    }
    if ($row['responsabilidad']) {
        $experiencias[$exp_id]['responsabilidades'][] = [
            'id' => $row['responsabilidad_id'],
            'descripcion' => $row['responsabilidad']
        ];
    }
}

// Obtener carreras estudiadas
$query_edu = "SELECT c.codigo, c.nombre, ce.anioConcluido 
              FROM carreras_estudiadas ce 
              JOIN carrera c ON ce.carrera = c.codigo 
              WHERE ce.prospecto = ?";
$stmt_edu = $conexion->prepare($query_edu);
$stmt_edu->bind_param("i", $prospecto['numero']);
$stmt_edu->execute();
$result_edu = $stmt_edu->get_result();
$educacion = $result_edu->fetch_all(MYSQLI_ASSOC);

// Procesar la actualización del perfil
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_about_me':
                $telefono = $_POST['phone'];
                $fechaNacimiento = $_POST['birthdate'];
                $resumen = $_POST['summary'];

                // Validar número de teléfono
                if (!preg_match('/^\d{10}$/', $telefono)) {
                    $error = 'El número de teléfono debe tener 10 dígitos.';
                    break;
                }

                // Validar edad (mayor de 18 años)
                $fechaNacimiento = new DateTime($fechaNacimiento);
                $hoy = new DateTime();
                $edad = $hoy->diff($fechaNacimiento)->y;
                if ($edad < 18) {
                    $error = 'Debes ser mayor de 18 años.';
                    break;
                }

                // Actualizar en la base de datos
                $stmt = $conexion->prepare("UPDATE prospecto SET numTel = ?, fechaNacimiento = ?, resumen = ? WHERE numero = ?");

                // Convert the formatted date to a variable
                $formattedFechaNacimiento = $fechaNacimiento->format('Y-m-d');

                // Pass the variable to bind_param
                $stmt->bind_param("sssi", $telefono, $formattedFechaNacimiento, $resumen, $prospecto['numero']);


                if ($stmt->execute()) {
                    $success = 'Perfil actualizado correctamente.';
                    // Redirige a la misma página para actualizar la membresía actual
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $error = 'Error al actualizar el perfil.';
                }
                break;

            case 'update_education':
                // Eliminar educación existente
                $stmt = $conexion->prepare("DELETE FROM carreras_estudiadas WHERE prospecto = ?");
                $stmt->bind_param("i", $prospecto['numero']);
                $stmt->execute();

                // Insertar nueva educación
                $stmt = $conexion->prepare("INSERT INTO carreras_estudiadas (prospecto, carrera, anioConcluido) VALUES (?, ?, ?)");
                foreach ($_POST['carrera'] as $index => $carrera) {
                    $anioConcluido = $_POST['anioConcluido'][$index];

                    // Validar año de conclusión
                    if ($anioConcluido > date('Y') || $anioConcluido < 1950) {
                        $error = 'Año de conclusión inválido.';
                        break 2; // Salir del switch y del foreach
                    }

                    $stmt->bind_param("isi", $prospecto['numero'], $carrera, $anioConcluido);
                    if (!$stmt->execute()) {
                        $error = 'Error al actualizar el historial académico.';
                        break 2; // Salir del switch y del foreach
                    }
                }
                if (!isset($error)) {
                    $success = 'Historial académico actualizado correctamente.';
                    // Redirige a la misma página para actualizar la membresía actual
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                break;

            case 'update_experience':
                // Iniciar transacción
                $conexion->begin_transaction();

                try {
                    // Obtener las experiencias existentes del prospecto
                    $stmt = $conexion->prepare("SELECT numero FROM experiencia WHERE prospecto = ?");
                    $stmt->bind_param("i", $prospecto['numero']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $existing_experiences = $result->fetch_all(MYSQLI_ASSOC);
                    $existing_exp_ids = array_column($existing_experiences, 'numero');

                    // Procesar las experiencias enviadas en el formulario
                    $submitted_exp_ids = [];
                    if (isset($_POST['puesto']) && is_array($_POST['puesto'])) {
                        foreach ($_POST['puesto'] as $index => $puesto) {
                            $empresa = $_POST['empresa'][$index];
                            $fechaInicio = $_POST['fechaInicio'][$index];
                            $fechaFin = $_POST['fechaFin'][$index];
                            $exp_id = isset($_POST['exp_id'][$index]) ? intval($_POST['exp_id'][$index]) : null;

                            // Validar fechas
                            if ($fechaInicio > $fechaFin) {
                                throw new Exception('La fecha de inicio no puede ser posterior a la fecha de fin.');
                            }

                            if ($exp_id && in_array($exp_id, $existing_exp_ids)) {
                                // Actualizar experiencia existente
                                $stmt_update_exp = $conexion->prepare("UPDATE experiencia SET puesto = ?, nombreEmpresa = ?, fechaInicio = ?, fechaFin = ? WHERE numero = ? AND prospecto = ?");
                                $stmt_update_exp->bind_param("ssssii", $puesto, $empresa, $fechaInicio, $fechaFin, $exp_id, $prospecto['numero']);
                                if (!$stmt_update_exp->execute()) {
                                    throw new Exception('Error al actualizar experiencia.');
                                }
                            } else {
                                // Insertar nueva experiencia
                                $stmt_insert_exp = $conexion->prepare("INSERT INTO experiencia (prospecto, puesto, nombreEmpresa, fechaInicio, fechaFin) VALUES (?, ?, ?, ?, ?)");
                                $stmt_insert_exp->bind_param("issss", $prospecto['numero'], $puesto, $empresa, $fechaInicio, $fechaFin);
                                if (!$stmt_insert_exp->execute()) {
                                    throw new Exception('Error al insertar experiencia.');
                                }
                                $exp_id = $conexion->insert_id;
                            }

                            $submitted_exp_ids[] = $exp_id;

                            // Procesar responsabilidades
                            if (isset($_POST['responsabilidades'][$index]) && is_array($_POST['responsabilidades'][$index])) {
                                // Eliminar responsabilidades existentes para esta experiencia
                                $stmt_delete_resp = $conexion->prepare("DELETE FROM responsabilidades WHERE experiencia = ?");
                                $stmt_delete_resp->bind_param("i", $exp_id);
                                $stmt_delete_resp->execute();

                                // Insertar nuevas responsabilidades
                                $stmt_insert_resp = $conexion->prepare("INSERT INTO responsabilidades (experiencia, descripcion) VALUES (?, ?)");
                                foreach ($_POST['responsabilidades'][$index] as $resp) {
                                    $stmt_insert_resp->bind_param("is", $exp_id, $resp);
                                    if (!$stmt_insert_resp->execute()) {
                                        throw new Exception('Error al insertar responsabilidad.');
                                    }
                                }
                            }
                        }
                    }

                    // Eliminar experiencias que ya no existen en el formulario
                    $exp_to_delete = array_diff($existing_exp_ids, $submitted_exp_ids);
                    if (!empty($exp_to_delete)) {
                        $exp_ids_str = implode(',', $exp_to_delete);
                        $conexion->query("DELETE FROM responsabilidades WHERE experiencia IN ($exp_ids_str)");
                        $conexion->query("DELETE FROM experiencia WHERE numero IN ($exp_ids_str) AND prospecto = {$prospecto['numero']}");
                    }

                    $conexion->commit();
                    $success = 'Experiencia laboral actualizada correctamente.';
                    // Redirige a la misma página para actualizar la membresía actual
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } catch (Exception $e) {
                    $conexion->rollback();
                    $error = $e->getMessage();
                }
                break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil del Prospecto</title>
    <link rel="stylesheet" href="css/prospectProfile.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/react@17/umd/react.development.js"></script>
    <script src="https://unpkg.com/react-dom@17/umd/react-dom.development.js"></script>
    <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        /* Estilos adicionales para la sección de documentación */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input[type="file"] {
            display: block;
            margin-bottom: 5px;
        }
        .document-status {
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'incluides/sidebar.php'; ?>

    <div class="card">
        <div class="card__img"></div>
        <div class="card__avatar">
            <img src="<?php echo htmlspecialchars($prospecto['foto'] ?? 'img/default-user.jpg'); ?>" alt="Foto de perfil" class="profile-image" id="profileImage">
            <button class="change-photo-btn" id="changePhotoBtn">
                <i class="fas fa-camera"></i>
            </button>
        </div>
        <div class="card__title">
            <h2><?php echo htmlspecialchars($prospecto['nombre'] . ' ' . $prospecto['primerApellido'] . ' ' . $prospecto['segundoApellido']); ?></h2>
        </div>
        <div class="card__subtitle"><?php echo nl2br(htmlspecialchars($prospecto['resumen'])); ?></div>
        <div class="card__subtitle">
            <?php
            $anios = $prospecto['aniosExperiencia'];
            if ($anios > 0) {
                $aniosFormat = (fmod($anios, 1) === 0.0) ? intval($anios) : number_format($anios, 1);
                echo htmlspecialchars($aniosFormat) . ' años de experiencia laboral';
            } 
            ?>
        </div>

        <div class="card__copy">
            <h2>Información</h2>

            <?php if ($error): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <?php if ($success): ?>
                <p class="success"><?php echo $success; ?></p>
            <?php endif; ?>

            <div class="tabs">
                <div class="tab active" data-tab="habilidades">Acerca de mí</div>
                <div class="tab" data-tab="carreras">Historial académico</div>
                <div class="tab" data-tab="experiencia">Experiencia laboral</div>
                <div class="tab" data-tab="documentacion">Documentación</div>
            </div>
            <div class="scrollable-content">
                <div id="habilidades" class="content-section active">
                    <div class="profile-details">
                        <div class="detail-item">
                            <span class="detail-label">Teléfono:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($prospecto['numTel']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Fecha de Nacimiento:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($prospecto['fechaNacimiento']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Edad: <?php echo $edad; ?> años</span>
                        </div>
                    </div>
                    <button id="editAboutMeBtn" class="editarboton">
                        Editar información básica
                        <svg viewBox="0 0 512 512" class="salvaje">
                            <path d="M410.3 231l11.3-11.3-33.9-33.9-62.1-62.1L291.7 89.8l-11.3 11.3-22.6 22.6L58.6 322.9c-10.4 10.4-18 23.3-22.2 37.4L1 480.7c-2.5 8.4-.2 17.5 6.1 23.7s15.3 8.5 23.7 6.1l120.3-35.4c14.1-4.2 27-11.8 37.4-22.2L387.7 253.7 410.3 231zM160 399.4l-9.1 22.7c-4 3.1-8.5 5.4-13.3 6.9L59.4 452l23-78.1c1.4-4.9 3.8-9.4 6.9-13.3l22.7-9.1v32c0 8.8 7.2 16 16 16h32zM362.7 18.7L348.3 33.2 325.7 55.8 314.3 67.1l33.9 33.9 62.1 62.1 33.9 33.9 11.3-11.3 22.6-22.6 14.5-14.5c25-25 25-65.5 0-90.5L453.3 18.7c-25-25-65.5-25-90.5 0zm-47.4 168l-144 144c-6.2 6.2-16.4 6.2-22.6 0s-6.2-16.4 0-22.6l144-144c6.2-6.2 16.4-6.2 22.6 0s6.2 16.4 0 22.6z"></path>
                        </svg>
                    </button>
                </div>

                <div id="carreras" class="content-section">
                    <div class="teams-list" id="teams-list">
                        <?php if (!empty($educacion)): ?>
                            <?php foreach ($educacion as $edu): ?>
                                <div class="team-item">
                                    <div class="team-icon"></div>
                                    <span class="editable"><?php echo htmlspecialchars($edu['nombre']) . ' (' . htmlspecialchars($edu['anioConcluido']) . ')'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No hay información académica disponible.</p>
                        <?php endif; ?>
                    </div>
                    <button id="editEducationBtn" class="editarboton">
                        Editar Historial Académico
                        <svg viewBox="0 0 512 512" class="salvaje">
                            <path d="M410.3 231l11.3-11.3-33.9-33.9-62.1-62.1L291.7 89.8l-11.3 11.3-22.6 22.6L58.6 322.9c-10.4 10.4-18 23.3-22.2 37.4L1 480.7c-2.5 8.4-.2 17.5 6.1 23.7s15.3 8.5 23.7 6.1l120.3-35.4c14.1-4.2 27-11.8 37.4-22.2L387.7 253.7 410.3 231zM160 399.4l-9.1 22.7c-4 3.1-8.5 5.4-13.3 6.9L59.4 452l23-78.1c1.4-4.9 3.8-9.4 6.9-13.3l22.7-9.1v32c0 8.8 7.2 16 16 16h32zM362.7 18.7L348.3 33.2 325.7 55.8 314.3 67.1l33.9 33.9 62.1 62.1 33.9 33.9 11.3-11.3 22.6-22.6 14.5-14.5c25-25 25-65.5 0-90.5L453.3 18.7c-25-25-65.5-25-90.5 0zm-47.4 168l-144 144c-6.2 6.2-16.4 6.2-22.6 0s-6.2-16.4 0-22.6l144-144c6.2-6.2 16.4-6.2 22.6 0s6.2 16.4 0 22.6z"></path>
                        </svg>
                    </button>
                </div>

                
<div id="experiencia" class="content-section">
                    <div class="experience-list">
                        <?php if (!empty($experiencias)): ?>
                            <?php foreach ($experiencias as $exp): ?>
                                <div class="experience-item">
                                    <h3><?php echo htmlspecialchars($exp['puesto']); ?></h3>
                                    <p><?php echo htmlspecialchars($exp['nombreEmpresa']); ?></p>
                                    <p><?php echo htmlspecialchars($exp['fechaInicio']) . ' - ' . htmlspecialchars($exp['fechaFin']); ?></p>
                                    <?php if (!empty($exp['responsabilidades'])): ?>
                                        <ul>
                                            <?php foreach ($exp['responsabilidades'] as $resp): ?>
                                                <li><?php echo htmlspecialchars($resp['descripcion']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No hay experiencia laboral registrada.</p>
                        <?php endif; ?>
                    </div>
                    <button id="editExperienceBtn" class="editarboton">
                        Editar Experiencia
                        <svg viewBox="0 0 512 512" class="salvaje">
                            <path d="M410.3 231l11.3-11.3-33.9-33.9-62.1-62.1L291.7 89.8l-11.3 11.3-22.6 22.6L58.6 322.9c-10.4 10.4-18 23.3-22.2 37.4L1 480.7c-2.5 8.4-.2 17.5 6.1 23.7s15.3 8.5 23.7 6.1l120.3-35.4c14.1-4.2 27-11.8 37.4-22.2L387.7 253.7 410.3 231zM160 399.4l-9.1 22.7c-4 3.1-8.5 5.4-13.3 6.9L59.4 452l23-78.1c1.4-4.9 3.8-9.4 6.9-13.3l22.7-9.1v32c0 8.8 7.2 16 16 16h32zM362.7 18.7L348.3 33.2 325.7 55.8 314.3 67.1l33.9 33.9 62.1 62.1 33.9 33.9 11.3-11.3 22.6-22.6 14.5-14.5c25-25 25-65.5 0-90.5L453.3 18.7c-25-25-65.5-25-90.5 0zm-47.4 168l-144 144c-6.2 6.2-16.4 6.2-22.6 0s-6.2-16.4 0-22.6l144-144c6.2-6.2 16.4-6.2 22.6 0s6.2 16.4 0 22.6z"></path>
                        </svg>
                    </button>
                </div>

                <div id="documentacion" class="content-section">
                    <div id="react-documentacion-root"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Acerca de mí -->
    <div id="aboutMeModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Editar información básica</h2>
            <form id="editAboutMeForm" method="post" action="">
                <div class="aboutme-item">
                    <input type="hidden" name="action" value="update_about_me">
                    <div class="form-group">
                        <label for="edit-phone">Teléfono:</label>
                        <input type="tel" id="edit-phone" name="phone" value="<?php echo htmlspecialchars($prospecto['numTel']); ?>" required pattern="\d{10}">
                    </div>
                    <div class="form-group">
                        <label for="edit-birthdate">Fecha de Nacimiento:</label>
                        <input type="date" id="edit-birthdate" name="birthdate" value="<?php echo htmlspecialchars($prospecto['fechaNacimiento']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-summary">Resumen:</label>
                        <input type="text" id="edit-summary" name="summary" value="<?php echo htmlspecialchars($prospecto['resumen']); ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <!-- Modal para Historial Académico -->
    <div id="educationModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Editar Historial Académico</h2>
            <form id="editEducationForm" method="post" action="">
                <input type="hidden" name="action" value="update_education">
                <div id="educationList">
                    <?php if (!empty($educacion)): ?>
                        <?php foreach ($educacion as $index => $edu): ?>
                            <div class="education-item">
                                <label>Grado académico:</label>
                                <select name="carrera[]" required>
                                    <?php foreach ($todas_carreras as $carrera): ?>
                                        <option value="<?php echo htmlspecialchars($carrera['codigo']); ?>" <?php echo ($carrera['codigo'] == $edu['codigo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($carrera['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Año de conclusión:</label>
                                <input type="number" name="anioConcluido[]" value="<?php echo htmlspecialchars($edu['anioConcluido']); ?>" required min="1900" max="<?php echo date('Y'); ?>">
                                <button type="button" class="remove-education">Eliminar</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" id="addEducation">Agregar Educación</button>
                <button type="submit" class="btn-submit">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <!-- Modal para Experiencia -->
    <div id="experienceModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Editar Experiencia</h2>
            <form id="editExperienceForm" method="post" action="">
                <input type="hidden" name="action" value="update_experience">
                <div id="experienceList">
                    <?php foreach ($experiencias as $index => $exp): ?>
                        <div class="experience-item">
                            <input type="hidden" name="exp_id[]" value="<?php echo htmlspecialchars($exp['numero']); ?>">
                            <input type="text" name="puesto[]" value="<?php echo htmlspecialchars($exp['puesto']); ?>" required>
                            <input type="text" name="empresa[]" value="<?php echo htmlspecialchars($exp['nombreEmpresa']); ?>" required>
                            <input type="date" name="fechaInicio[]" value="<?php echo htmlspecialchars($exp['fechaInicio']); ?>" required>
                            <input type="date" name="fechaFin[]" value="<?php echo htmlspecialchars($exp['fechaFin']); ?>" required>
                            <div class="responsabilidades-list">
                                <?php foreach ($exp['responsabilidades'] as $resp): ?>
                                    <div class="responsabilidad-item">
                                        <input type="text" name="responsabilidades[<?php echo $index; ?>][]" value="<?php echo htmlspecialchars($resp['descripcion']); ?>" required>
                                        <button type="button" class="remove-responsabilidad">Eliminar</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="add-responsabilidad">Agregar Responsabilidad</button>
                            <button type="button" class="remove-experience">Eliminar Experiencia</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="addExperience">Agregar Experiencia</button>
                <button type="submit" class="btn-submit">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <!-- New Photo Modal -->
    <div id="photoModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Cambiar foto de perfil</h2>
            <form id="photoForm" enctype="multipart/form-data">
                <input type="file" id="newPhoto" name="newPhoto" accept="image/*" required>
                <button type="submit" class="btn-submit">Subir foto</button>
            </form>
        </div>
    </div>

    <script type="text/babel">
        function DocumentacionUpload({ rfc, actaNacimiento, gradosAcademicos }) {
            const [documents, setDocuments] = React.useState({
                rfc: rfc,
                actaNacimiento: actaNacimiento,
                gradosAcademicos: gradosAcademicos
            });

            const handleFileUpload = async (event, docType) => {
                const file = event.target.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('file', file);
                formData.append('docType', docType);

                try {
                    const response = await fetch('upload_document.php', {
                        method: 'POST',
                        body: formData
                    });

                    if (response.ok) {
                        const result = await response.json();
                        setDocuments(prev => ({ ...prev, [docType]: result.fileName }));
                        alert('Documento subido con éxito');
                    } else {
                        alert('Error al subir el documento');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error al subir el documento');
                }
            };

            return (
                <div>
                    <h3>Documentación</h3>
                    <div className="form-group">
                        <label htmlFor="rfc">RFC:</label>
                        <input type="file" id="rfc" onChange={(e) => handleFileUpload(e, 'rfc')} accept=".pdf,.jpg,.jpeg,.png" />
                        {documents.rfc ? <span className="document-status">Documento actual: {documents.rfc}</span> : <span className="document-status">No hay documento cargado</span>}
                    </div>
                    <div className="form-group">
                        <label htmlFor="actaNacimiento">Acta de Nacimiento:</label>
                        <input type="file" id="actaNacimiento" onChange={(e) => handleFileUpload(e, 'actaNacimiento')} accept=".pdf,.jpg,.jpeg,.png" />
                        {documents.actaNacimiento ? <span className="document-status">Documento actual: {documents.actaNacimiento}</span> : <span className="document-status">No hay documento cargado</span>}
                    </div>
                    <div className="form-group">
                        <label htmlFor="gradosAcademicos">Grados Académicos:</label>
                        <input type="file" id="gradosAcademicos" onChange={(e) => handleFileUpload(e, 'gradosAcademicos')} accept=".pdf,.jpg,.jpeg,.png" />
                        {documents.gradosAcademicos ? <span className="document-status">Documento actual: {documents.gradosAcademicos}</span> : <span className="document-status">No hay documento cargado</span>}
                    </div>
                </div>
            );
        }

        ReactDOM.render(
            <DocumentacionUpload 
                rfc="<?php echo $prospecto['rfc'] ? htmlspecialchars($prospecto['rfc']) : ''; ?>"
                actaNacimiento="<?php echo $prospecto['acta_nacimiento'] ? htmlspecialchars($prospecto['acta_nacimiento']) : ''; ?>"
                gradosAcademicos="<?php echo $prospecto['grados_academicos'] ? htmlspecialchars($prospecto['grados_academicos']) : ''; ?>"
            />,
            document.getElementById('react-documentacion-root')
        );
    </script>

    <script>
        $(document).ready(function () {
            const tabs = document.querySelectorAll('.tab');
            const contentSections = document.querySelectorAll('.content-section');

            // Tab switching functionality
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    const tabId = tab.getAttribute('data-tab');
                    contentSections.forEach(section => {
                        section.classList.remove('active');
                        if (section.id === tabId) {
                            section.classList.add('active');
                        }
                    });
                });
            });

            // Modal functionality
            const modals = {
                aboutMe: document.getElementById('aboutMeModal'),
                education: document.getElementById('educationModal'),
                experience: document.getElementById('experienceModal'),
                photo: document.getElementById('photoModal')
            };

            const openModal = (modalId) => {
                modals[modalId].style.display = 'block';
            };

            const closeModal = (modalId) => {
                modals[modalId].style.display = 'none';
            };

            // Open modals
            $('#editAboutMeBtn').click(() => openModal('aboutMe'));
            $('#editEducationBtn').click(() => openModal('education'));
            $('#editExperienceBtn').click(() => openModal('experience'));
            $('#changePhotoBtn').click(() => openModal('photo'));

            // Close modals
            $('.close').click(function () {
                $(this).closest('.modal').hide();
            });

            $(window).click(function (event) {
                if ($(event.target).hasClass('modal')) {
                    $('.modal').hide();
                }
            });

            // Add new education
            $('#addEducation').click(function () {
                $('#educationList').append(`
                    <div class="education-item">
                        <select name="carrera[]" required>
                            <?php foreach ($todas_carreras as $carrera): ?>
                                <option value="<?php echo htmlspecialchars($carrera['codigo']); ?>">
                                    <?php echo htmlspecialchars($carrera['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="anioConcluido[]" required min="1900" max="<?php echo date('Y'); ?>">
                        <button type="button" class="remove-education">Eliminar</button>
                    </div>
                `);
            });

            // Remove education
            $(document).on('click', '.remove-education', function () {
                $(this).closest('.education-item').remove();
            });

            // Add new experience
            $('#addExperience').click(function () {
                const index = $('#experienceList .experience-item').length;
                $('#experienceList').append(`
                    <div class="experience-item">
                        <input type="text" name="puesto[]" required placeholder="Puesto">
                        <input type="text" name="empresa[]" required placeholder="Empresa">
                        <input type="date" name="fechaInicio[]" required>
                        <input type="date" name="fechaFin[]" required>
                        <div class="responsabilidades-list">
                            <div class="responsabilidad-item">
                                <input type="text" name="responsabilidades[${index}][]" required placeholder="Responsabilidad">
                                <button type="button" class="remove-responsabilidad">Eliminar</button>
                            </div>
                        </div>
                        <button type="button" class="add-responsabilidad">Agregar Responsabilidad</button>
                        <button type="button" class="remove-experience">Eliminar Experiencia</button>
                    </div>
                `);
            });

            // Remove experience
            $(document).on('click', '.remove-experience', function () {
                $(this).closest('.experience-item').remove();
            });

            // Add new responsabilidad
            $(document).on('click', '.add-responsabilidad', function () {
                const experienceItem = $(this).closest('.experience-item');
                const index = $('#experienceList .experience-item').index(experienceItem);
                experienceItem.find('.responsabilidades-list').append(`
                    <div class="responsabilidad-item">
                        <input type="text" name="responsabilidades[${index}][]" required placeholder="Responsabilidad">
                        <button type="button" class="remove-responsabilidad">Eliminar</button>
                    </div>
                `);
            });

            // Remove responsabilidad
            $(document).on('click', '.remove-responsabilidad', function () {
                $(this).closest('.responsabilidad-item').remove();
            });

            // Photo change functionality
            const photoForm = document.getElementById('photoForm');
            photoForm.onsubmit = function(e) {
                e.preventDefault();
                const formData = new FormData(photoForm);
                
                fetch('upload_photo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('profileImage').src = data.newPhotoUrl;
                        closeModal('photo');
                        alert('Foto de perfil actualizada con éxito');
                    } else {
                        alert('Error al actualizar la foto de perfil: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al subir la foto');
                });
            }
        });
    </script>
</body>
</html>

