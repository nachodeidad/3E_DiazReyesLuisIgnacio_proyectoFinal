<?php
session_start();
include '../../../config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Usuario no autenticado']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['newPhoto'])) {
    $file = $_FILES['newPhoto'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

    if (!in_array($file['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido']);
        exit;
    }

    $uploadDir = 'uploads/profile_photos/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = uniqid() . '_' . $file['name'];
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        $stmt = $conexion->prepare("UPDATE usuario SET foto = ? WHERE numero = ?");
        $stmt->bind_param('si', $filePath, $user_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'newPhotoUrl' => $filePath]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al actualizar la base de datos']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al subir el archivo']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}

