<?php
$servername = "localhost";
$username = "root";
$password = "0136613Aa";
$dbname = "outsourcing6";

// Intentar establecer la conexión
$conexion = mysqli_connect('localhost', 'root', '0136613Aa', 'outsourcing6', null, '/var/run/mysqld/mysqld.sock');

// Verificar la conexión
if (!$conexion) {
    die("Error de conexión: " . mysqli_connect_error());
}

// Establecer el conjunto de caracteres a utf8
mysqli_set_charset($conexion, "utf8");
?>