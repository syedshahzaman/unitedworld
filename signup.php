<?php
// Create data directory if it doesn't exist
if (!file_exists('data')) {
    mkdir('data', 0777, true);
}

// Initialize variables
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['fullName'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $referralCode = trim($_POST['referralCode'] ?? '');

    // Validation
    if (empty($fullName)) {
        $errors[] = 'Full name is required';
    }

    if (empty($mobile)) {
        $errors[] = 'Mobile number is required';
    } else {
        // Remove all non-numeric characters for validation
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
        // Check if it's a valid Indian mobile number (10 digits starting with 6-9)
        if (!preg_match('/^[6-9][0-9]{9}$/', $cleanMobile)) {
            $errors[] = 'Please enter a valid Indian mobile number (10 digits starting with 6-9)';
        }
    }

    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter, one lowercase letter, and one number';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    // Check if mobile already exists
    if (empty($errors)) {
        $mobileExists = false;
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
        
        if (file_exists('data/users.tsv')) {
            $file = fopen('data/users.tsv', 'r');
            while (($data = fgetcsv($file, 0, "\t")) !== FALSE) {
                if (isset($data[1]) && $data[1] === $cleanMobile) { // Mobile is in second column
                    $mobileExists = true;
                    break;
                }
            }
            fclose($file);
        }

        if ($mobileExists) {
            $errors[] = 'This mobile number is already registered';
        }
    }

    // If no errors, save data
    if (empty($errors)) {
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);

        // Prepare data for TSV
        $timestamp = date('Y-m-d H:i:s');
        $userData = [
            $timestamp,                    // Registration time
            $cleanMobile,                   // Mobile number (clean)
            $fullName,                      // Full name
            $mobile,                        // Original formatted mobile
            $hashedPassword,                 // Hashed password
            $referralCode ?: 'NULL',         // Referral code or NULL
            $_SERVER['REMOTE_ADDR'],         // IP address
            date('Y-m-d')                    // Registration date
        ];

        // Save to TSV file
        $file = fopen('data/users.tsv', 'a');
        if ($file) {
            fputcsv($file, $userData, "\t");
            fclose($file);
            $success = true;
        } else {
            $errors[] = 'Unable to save data. Please try again.';
        }
    }
}

// Function to display form with previous values
function old($field) {
    return isset($_POST[$field]) ? htmlspecialchars($_POST[$field]) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UNITED WORLD - Create Trading Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --secondary: #0f172a;
            --dark: #020617;
            --light: #f8fafc;
            --gray: #94a3b8;
            --gray-dark: #475569;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--dark);
            color: var(--light);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 20%, rgba(99, 102, 241, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(139, 92, 246, 0.15) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .container {
            max-width: 480px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        /* Header Styles */
        .header {
            text-align: center;
            margin-bottom: 2.5rem;
            animation: fadeInDown 0.8s ease;
        }

        .logo-wrapper {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 100px;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(10px);
        }

        .logo {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: 2px;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-subtitle {
            color: var(--gray);
            font-size: 0.75rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-top: 0.25rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 1rem 0 0.5rem;
            background: linear-gradient(to right, #fff, var(--primary-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Form Styles */
        .form-section {
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 2.5rem;
            border: 1px solid rgba(99, 102, 241, 0.2);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 0.8s ease;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--danger);
        }

        .alert i {
            font-size: 1.25rem;
        }

        .alert ul {
            margin: 0;
            padding-left: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.75rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--light);
            letter-spacing: 0.5px;
        }

        .form-label i {
            color: var(--primary);
            margin-right: 0.5rem;
            width: 16px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.03);
            border: 2px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            color: var(--light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.07);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .form-input::placeholder {
            color: var(--gray-dark);
            font-size: 0.95rem;
        }

        /* Phone input specific */
        .phone-input-wrapper {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.03);
            border: 2px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .phone-input-wrapper:focus-within {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.07);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .country-code {
            padding: 1rem 0.5rem 1rem 1.25rem;
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
            background: transparent;
            border-right: 2px solid rgba(148, 163, 184, 0.1);
        }

        .phone-input {
            flex: 1;
            padding: 1rem 1.25rem 1rem 0.75rem;
            background: transparent;
            border: none;
            color: var(--light);
            font-size: 1rem;
        }

        .phone-input:focus {
            outline: none;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s ease;
            padding: 0.5rem;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        /* Password strength indicator */
        .password-strength {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.5rem;
        }

        .strength-bar {
            height: 4px;
            flex: 1;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .strength-bar.active {
            background: var(--primary);
        }

        .strength-text {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Button Styles */
        .btn-primary {
            width: 100%;
            padding: 1.1rem;
            background: var(--gradient);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 30px -10px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-secondary {
            display: block;
            text-align: center;
            color: var(--gray);
            background: none;
            border: none;
            font-size: 0.95rem;
            cursor: pointer;
            margin-top: 1.5rem;
            padding: 0.75rem;
            text-decoration: none;
            transition: color 0.3s ease;
            position: relative;
        }

        .btn-secondary:hover {
            color: var(--primary);
        }

        .btn-secondary::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: var(--gradient);
            transition: width 0.3s ease;
        }

        .btn-secondary:hover::after {
            width: 50px;
        }

        /* Notice Section */
        .notice-section {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            padding: 1.5rem;
            margin-top: 2rem;
            border: 1px solid rgba(245, 158, 11, 0.2);
            animation: fadeIn 1s ease;
        }

        .notice-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--warning);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .notice-title i {
            font-size: 1.1rem;
        }

        .notice-list {
            list-style: none;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .notice-list li {
            margin-bottom: 0.75rem;
            position: relative;
            padding-left: 1.5rem;
            display: flex;
            align-items: center;
        }

        .notice-list li i {
            color: var(--warning);
            font-size: 0.75rem;
            position: absolute;
            left: 0;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 2rem 1.5rem;
            margin-top: 2rem;
            color: var(--gray-dark);
            font-size: 0.875rem;
            position: relative;
            z-index: 1;
        }

        /* Animations */
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Loading State */
        .btn-primary.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }

        .btn-primary.loading:after {
            content: "";
            position: absolute;
            width: 1.2rem;
            height: 1.2rem;
            top: 50%;
            left: 50%;
            margin: -0.6rem 0 0 -0.6rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container {
                margin: 1rem auto;
            }

            .form-section {
                padding: 1.75rem;
            }

            .page-title {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="logo-wrapper">
                <div class="logo">UNITED WORLD</div>
            </div>
            <div class="logo-subtitle">TRADING & ALLOCATION</div>
            <h1 class="page-title">Create Account</h1>
            <p class="page-subtitle">Join the United World trading community</p>
        </header>

        <main>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Account created successfully!</strong><br>
                        You can now <a href="/login" style="color: var(--success); font-weight: 600; text-decoration: underline;">login here</a>.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin-top: 0.5rem;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form class="form-section" method="POST" action="" id="signupForm" novalidate>
                <div class="form-group">
                    <label for="fullName" class="form-label">
                        <i class="fas fa-user"></i>Full Name
                    </label>
                    <div class="input-wrapper">
                        <input type="text" id="fullName" name="fullName" class="form-input" 
                               placeholder="John Smith" value="<?php echo old('fullName'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="mobile" class="form-label">
                        <i class="fas fa-phone-alt"></i>Mobile Number
                    </label>
                    <div class="phone-input-wrapper">
                        <span class="country-code">+91</span>
                        <input type="tel" id="mobile" name="mobile" class="phone-input" 
                               placeholder="98765 43210" value="<?php echo old('mobile'); ?>" 
                               maxlength="10" pattern="[6-9][0-9]{9}" required>
                    </div>
                    <small style="color: var(--gray); font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                        <i class="fas fa-info-circle"></i> Enter 10-digit mobile number starting with 6-9
                    </small>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i>Create Password
                    </label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" class="form-input" 
                               placeholder="Enter password" minlength="8" required>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength">
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                    </div>
                    <div class="strength-text" id="strengthText">
                        Use at least 8 characters, one uppercase, one lowercase and one number
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirmPassword" class="form-label">
                        <i class="fas fa-check-circle"></i>Confirm Password
                    </label>
                    <div class="password-wrapper">
                        <input type="password" id="confirmPassword" name="confirmPassword" class="form-input" 
                               placeholder="Re-enter password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="referralCode" class="form-label">
                        <i class="fas fa-gift"></i>Referral Code 
                        <span style="color: var(--gray); font-weight: normal;">(optional)</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="text" id="referralCode" name="referralCode" class="form-input" 
                               placeholder="Enter referral code" value="<?php echo old('referralCode'); ?>">
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="submitBtn">
                    <i class="fas fa-rocket" style="margin-right: 0.5rem;"></i>
                    Create Account
                </button>
                <a href="/login" class="btn-secondary">
                    <i class="fas fa-sign-in-alt" style="margin-right: 0.5rem;"></i>
                    Already have an account? Login
                </a>
            </form>

            <div class="notice-section">
                <div class="notice-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Important Information
                </div>
                <ul class="notice-list">
                    <li><i class="fas fa-chart-line"></i> Trading involves market risk</li>
                    <li><i class="fas fa-calculator"></i> Prices are system-calculated and may fluctuate</li>
                    <li><i class="fas fa-ban"></i> No guaranteed, fixed, or assured returns</li>
                    <li><i class="fas fa-code-branch"></i> This platform is under active development</li>
                    <li><i class="fas fa-clock"></i> Manual processing during early phase</li>
                </ul>
            </div>
        </main>
    </div>

    <footer class="footer">
        <i class="fas fa-copyright"></i> 2024 UNITED WORLD. All rights reserved.
    </footer>

    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBars = document.querySelectorAll('.strength-bar');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/\d/)) strength++;
            
            // Reset bars
            strengthBars.forEach(bar => {
                bar.classList.remove('active');
                bar.style.background = 'rgba(148, 163, 184, 0.2)';
            });
            
            // Activate bars based on strength
            for (let i = 0; i < strength; i++) {
                strengthBars[i].classList.add('active');
                if (strength === 1) {
                    strengthBars[i].style.background = '#ef4444';
                } else if (strength === 2) {
                    strengthBars[i].style.background = '#f59e0b';
                } else if (strength === 3) {
                    strengthBars[i].style.background = '#10b981';
                }
            }
            
            // Update text
            if (strength === 0) {
                strengthText.innerHTML = 'Enter a password';
            } else if (strength === 1) {
                strengthText.innerHTML = 'Weak password';
            } else if (strength === 2) {
                strengthText.innerHTML = 'Medium password';
            } else if (strength === 3) {
                strengthText.innerHTML = 'Strong password';
            }
        });

        // Form validation and submission
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const mobile = document.getElementById('mobile').value.replace(/\s/g, '');
            const submitBtn = document.getElementById('submitBtn');
            
            let errors = [];

            // Validate mobile number (Indian format)
            if (!/^[6-9][0-9]{9}$/.test(mobile)) {
                errors.push('Please enter a valid Indian mobile number (10 digits starting with 6-9)');
                e.preventDefault();
            }

            // Validate password length
            if (password.length < 8) {
                errors.push('Password must be at least 8 characters long');
                e.preventDefault();
            }

            // Validate password strength
            if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(password)) {
                errors.push('Password must contain uppercase, lowercase and number');
                e.preventDefault();
            }

            // Validate password match
            if (password !== confirmPassword) {
                errors.push('Passwords do not match');
                e.preventDefault();
            }

            if (errors.length > 0) {
                alert('Please fix the following:\n• ' + errors.join('\n• '));
                return;
            }

            // Show loading state
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });

        // Real-time password confirmation validation
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;

            if (confirmPassword && password !== confirmPassword) {
                this.style.borderColor = '#ef4444';
            } else if (confirmPassword) {
                this.style.borderColor = '#10b981';
            } else {
                this.style.borderColor = '';
            }
        });

        // Phone number formatting (only allow numbers)
        document.getElementById('mobile').addEventListener('input', function(e) {
            let value = this.value.replace(/[^0-9]/g, '');
            if (value.length > 10) {
                value = value.slice(0, 10);
            }
            this.value = value;
        });

        // Remove email validation and keep only mobile
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>