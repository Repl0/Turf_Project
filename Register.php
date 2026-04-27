<?php
require("config/db.php");

$message = "";

if (isset($_POST["register"])) {
    $name = $_POST["name"];
    $email = $_POST["email"];
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if ($password != $confirm_password) {
        $message = "Password and confirm password do not match.";
    } else {
        $check_email = "SELECT * FROM users WHERE email = '$email'";
        $result = mysqli_query($conn, $check_email);

        if (mysqli_num_rows($result) > 0) {
            $message = "Email already registered. Please use another email.";
        } else {
            $query = "INSERT INTO users (name, email, password, role)
                      VALUES ('$name', '$email', '$password', 'user')";

            if (mysqli_query($conn, $query)) {
                $message = "Registration successful! You can now login.";
            } else {
                $message = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - Turf Booking System</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">

            <div class="card shadow">
                <div class="card-header bg-success text-white text-center">
                    <h4>Create Account</h4>
                </div>

                <div class="card-body">

                    <?php if ($message != "") { ?>
                        <div class="alert alert-info">
                            <?php echo $message; ?>
                        </div>
                    <?php } ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>

                        <button type="submit" name="register" class="btn btn-success w-100">
                            Register
                        </button>
                    </form>

                    <p class="text-center mt-3">
                        Already have an account?
                        <a href="login.php">Login here</a>
                    </p>

                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>