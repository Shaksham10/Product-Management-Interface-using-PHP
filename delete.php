<?php 
include ("connect.php");
if (isset($_GET['deleteid'])){
    $name=$_GET['deleteid'];
    $sql="DELETE from `office` WHERE Name = '$name'";
    $result = mysqli_query($conn,$sql);
    if($result){
        header('Location: display.php');
    } else{
        die(mysqli_error($conn));
    }
    }
?>

