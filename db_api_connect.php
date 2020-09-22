<?php
//$db = mysqli_connect("localhost", "root", "tax1976"); // or die("Could not connect.");
$db = mysqli_connect("localhost", "user", "password", "database_name");

if(!$db)
  die("no db");

// Check connection
if (mysqli_connect_errno())
    {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
    }
