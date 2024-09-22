<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php/ssh_test_error.log');

session_start();
require __DIR__ . '/vendor/autoload.php';
use phpseclib3\Net\SSH2;

function attempt_ssh_connection($server_ip, $ssh_username, $password, $new_password = null) {
    try {
        $ssh = new SSH2($server_ip, 22, 15);
        $ssh->enableQuietMode();
        
        if (!@$ssh->login($ssh_username, $password)) {
            $errors = $ssh->getErrors();
            return ['status' => 'error', 'message' => "Login failed. Errors: " . implode(", ", $errors)];
        }
        
        $ssh->setTimeout(5);
        
        $output = $ssh->read('/(Current|New) password:/s', SSH2::READ_REGEX);
        if ($output !== false && (strpos($output, 'Current password:') !== false || strpos($output, 'New password:') !== false)) {
            if ($new_password === null) {
                return ['status' => 'password_change_required', 'message' => "Password change is required."];
            }
            
            $ssh->write($password . "\n");
            $ssh->read('New password:');
            $ssh->write($new_password . "\n");
            $ssh->read('Retype new password:');
            $ssh->write($new_password . "\n");  // Use the same new password for confirmation
            
            $result = $ssh->read('#');
            if (strpos($result, 'successfully') !== false) {
                return ['status' => 'success', 'message' => "Password changed successfully. Please reconnect with the new password."];
            } else {
                return ['status' => 'password_change_failed', 'message' => "Failed to change password. Server response: " . $result];
            }
        }
        
        $result = $ssh->exec('echo "Connection successful"');
        if (trim($result) !== "Connection successful") {
            return ['status' => 'error', 'message' => "Connection test failed. Output: " . $result];
        }
        
        return ['status' => 'success', 'message' => "Authentication successful for $ssh_username@$server_ip"];
    } catch (Exception $e) {
        error_log("SSH Connection Error: " . $e->getMessage());
        return ['status' => 'error', 'message' => "Connection error: " . $e->getMessage()];
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $server_ip = $_POST['server_ip'];
    $ssh_username = $_POST['ssh_username'];
    $password = $_POST['password'];
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : null;
    
    $result = attempt_ssh_connection($server_ip, $ssh_username, $password, $new_password);
    echo json_encode($result);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH Authentication Test</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">SSH Authentication Test</h1>
        <form id="auth-form">
            <div class="form-group">
                <label for="server_ip">Server IP:</label>
                <input type="text" class="form-control" id="server_ip" name="server_ip" required>
            </div>
            <div class="form-group">
                <label for="ssh_username">SSH Username:</label>
                <input type="text" class="form-control" id="ssh_username" name="ssh_username" value="root">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="form-group" id="new_password_group" style="display:none;">
                <label for="new_password">New Password:</label>
                <input type="password" class="form-control" id="new_password" name="new_password">
            </div>
            <button type="submit" class="btn btn-primary">Test Connection</button>
        </form>
        <div id="result" class="mt-3"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <script>
    $(document).ready(function() {
        $('#auth-form').submit(function(e) {
            e.preventDefault();
            attemptConnection();
        });

        function attemptConnection() {
            $('#result').html('<div class="alert alert-info">Testing connection...</div>');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $('#auth-form').serialize(),
                dataType: 'json',
                timeout: 20000,
                success: function(response) {
                    console.log("Server response:", response);  // Debug log
                    if (response.status === 'success') {
                        $('#result').html('<div class="alert alert-success">' + response.message + '</div>');
                        $('#new_password_group').hide();
                    } else if (response.status === 'password_change_required' || response.status === 'password_change_failed') {
                        promptForNewPassword(response.message);
                    } else {
                        Swal.fire({
                            title: 'Connection Failed',
                            text: response.message + " Would you like to try again?",
                            icon: 'error',
                            showCancelButton: true,
                            confirmButtonText: 'Try Again',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                attemptConnection();
                            } else {
                                $('#result').html('<div class="alert alert-danger">' + response.message + '</div>');
                            }
                        });
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.log("AJAX Error:", textStatus, errorThrown);
                    console.log("Response Text:", jqXHR.responseText);
                    $('#result').html('<div class="alert alert-danger">An error occurred while testing the connection. Details: ' + textStatus + ' - ' + errorThrown + '</div>');
                }
            });
        }

        function promptForNewPassword(message) {
            Swal.fire({
                title: 'Password Change Required',
                text: message,
                input: 'password',
                inputAttributes: {
                    autocapitalize: 'off'
                },
                showCancelButton: true,
                confirmButtonText: 'Set New Password',
                showLoaderOnConfirm: true,
                preConfirm: (password) => {
                    $('#new_password').val(password);
                    return attemptConnection();
                },
                allowOutsideClick: () => !Swal.isLoading()
            });
        }
    });
    </script>
</body>
</html>
