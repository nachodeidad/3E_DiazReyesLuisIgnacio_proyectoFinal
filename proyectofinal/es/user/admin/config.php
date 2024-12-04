<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "Outsourcing";

// Intentar establecer la conexión
$conexion = mysqli_connect('localhost', 'root', '', 'Outsourcing', null, '/var/run/mysqld/mysqld.sock');
// Verificar la conexión

if ($conexion) {
} else {
    "No conectado";
}

// Establecer el conjunto de caracteres a utf8
mysqli_set_charset($conexion, "utf8");
?>