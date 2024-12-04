
<?php 
    function connect(): mysqli{
        $db = mysqli_connect("localhost","root","","Outsourcing");
        if($db){
            return $db;
        }else{
            die;
        }
    }
?>

