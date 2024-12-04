<?php
session_start();
include '../../../config.php';
// Verificar si el usuario está logueado y es una empresa
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'EMP') {
    header("Location: login.php");
    exit();
}

$prospecto_id = $_GET['prospecto_id'];
$sql = "SELECT * FROM prospecto WHERE numero = $prospecto_id";
$result = $conexion->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil del Prospecto</title>
    <link rel="stylesheet" href="css/perfilProspecto.css">
</head>
<body>

<div class="profile-container">
    <?php if ($result->num_rows > 0): ?>
        <?php $prospecto = $result->fetch_assoc(); ?>
        
        <div class="profile-header">
            <h2><?= $prospecto['nombre'] . ' ' . $prospecto['primerApellido'] . ' ' . $prospecto['segundoApellido']; ?></h2>
            <p class="dob">📅 Fecha de Nacimiento: <?= $prospecto['fechaNacimiento']; ?></p>
            <p class="phone">📞 Teléfono: <?= $prospecto['numTel']; ?></p>
            <p class="summary">💼 <?= $prospecto['resumen']; ?></p>
            <?php if (!is_null($prospecto['aniosExperiencia'])): ?>
                <p class="experience">⏏️ Experiencia: 
                    <?= ($prospecto['aniosExperiencia'] == floor($prospecto['aniosExperiencia'])) 
                        ? number_format($prospecto['aniosExperiencia'], 0) . ' años' 
                        : number_format($prospecto['aniosExperiencia'], 1) . ' años'; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Carreras Estudiadas -->
        <?php
        $sql_carreras = "SELECT c.nombre as nombre_carrera, ce.anioConcluido 
                         FROM carreras_estudiadas ce
                         JOIN carrera c ON ce.carrera = c.codigo
                         WHERE ce.prospecto = $prospecto_id";
        $result_carreras = $conexion->query($sql_carreras);
        ?>
        <?php if ($result_carreras->num_rows > 0): ?>
            <div class="profile-section">
                <h3>🎓 Carreras Estudiadas</h3>
                <ul class="career-list">
                    <?php while($carrera = $result_carreras->fetch_assoc()): ?>
                        <li><?= $carrera['nombre_carrera'] . " (Año de conclusión: " . $carrera['anioConcluido'] . ")"; ?></li>
                    <?php endwhile; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Experiencia Laboral -->
        <?php
        $sql_experiencia = "SELECT * FROM experiencia WHERE prospecto = $prospecto_id ORDER BY fechaInicio DESC";
        $result_experiencia = $conexion->query($sql_experiencia);
        ?>
        <?php if ($result_experiencia->num_rows > 0): ?>
            <div class="profile-section">
                <h3>👔 Experiencia Laboral</h3>
                <p></p>
                <?php while($exp = $result_experiencia->fetch_assoc()): ?>
                    <div class="experience-item">
                        <h4><?= $exp['puesto'] . " en " . $exp['nombreEmpresa']; ?></h4>
                        <p>📅 <?= $exp['fechaInicio'] . " - " . $exp['fechaFin']; ?></p>
                        <p class="exp-description"><?= $exp['descripcion']; ?></p>

                        <!-- Responsabilidades -->
                        <?php
                        $sql_responsabilidades = "SELECT * FROM responsabilidades WHERE experiencia = " . $exp['numero'];
                        $result_responsabilidades = $conexion->query($sql_responsabilidades);
                        ?>
                        <?php if ($result_responsabilidades->num_rows > 0): ?>
                            <h5>Responsabilidades:</h5>
                            <ul class="responsibility-list">
                                <?php while($resp = $result_responsabilidades->fetch_assoc()): ?>
                                    <li><?= $resp['descripcion']; ?></li>
                                <?php endwhile; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p>No se encontró información del prospecto.</p>
    <?php endif; ?>

</div>

</body>
</html>

<?php $conexion->close(); ?>
