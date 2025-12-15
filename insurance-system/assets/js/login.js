// Login functionality
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const errorMessage = document.getElementById('errorMessage');
    const loginBtn = document.getElementById('loginBtn');
    const loginText = document.getElementById('loginText');
    const loginLoader = document.getElementById('loginLoader');

    loginForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;

        // Show loading state
        loginBtn.disabled = true;
        loginText.style.display = 'none';
        loginLoader.style.display = 'inline';
        hideError();

        try {
            const response = await fetch('backend/api/auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'login',
                    email: email,
                    password: password
                })
            });

            const data = await response.json();
            console.log('Login response:', data);

            if (data.success) {
                // Verify the user data structure
                if (!data.user || !data.user.id || !data.user.name || !data.user.role) {
                    console.error('Invalid user data structure:', data.user);
                    throw new Error('Invalid user data received from server');
                }
                
                // Prepare user data with required fields
                const userData = {
                    id: data.user.id,
                    name: data.user.name || 'User',
                    role: data.user.role || 'user',
                    email: data.user.email || ''
                };
                
                // Store user data
                console.log('Storing user data:', userData);
                localStorage.setItem('user', JSON.stringify(userData));
                
                // Redirect to dashboard
                window.location.href = 'dashboard.html';
            } else {
                showError(data.error || 'Login failed. Please try again.');
            }
        } catch (error) {
            console.error('Login error:', error);
            showError('Connection error. Please check if XAMPP is running and try again.');
        } finally {
            // Reset button state
            loginBtn.disabled = false;
            loginText.style.display = 'inline';
            loginLoader.style.display = 'none';
        }
    });
});

function fillDemo(email, password) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = password;
    hideDemoAccounts();
}

function hideDemoAccounts() {
    document.getElementById('demoAccounts').style.display = 'none';
}

function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.querySelector('.password-toggle');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleBtn.textContent = '🙈';
    } else {
        passwordInput.type = 'password';
        toggleBtn.textContent = '👁️';
    }
}

function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
}

function hideError() {
    document.getElementById('errorMessage').style.display = 'none';
}