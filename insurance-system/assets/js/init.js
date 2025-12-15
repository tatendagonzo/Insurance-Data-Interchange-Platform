// Simple initialization script to get the dashboard working
// Initialize only what's necessary for the dashboard to show
console.log('Initializing dashboard UI...');

// Make currentUser available globally
window.currentUser = null;

// Show loading overlay
const loadingOverlay = document.getElementById('loadingOverlay');
if (loadingOverlay) {
    loadingOverlay.style.display = 'flex';
}

// Basic user data check
const userData = localStorage.getItem('user');
if (!userData) {
    window.location.href = 'index.html';
    throw new Error('No user data found, redirecting to login');
}

try {
    // Parse and store user data
    window.currentUser = JSON.parse(userData);
    console.log('User data loaded:', window.currentUser);
    
    // Update UI with user info
    const updateUI = () => {
        const userNameElement = document.getElementById('userName');
        const userRoleElement = document.getElementById('userRole');
        const appContainer = document.querySelector('.app-container');
        
        if (userNameElement) userNameElement.textContent = window.currentUser?.name || 'User';
        if (userRoleElement) userRoleElement.textContent = (window.currentUser?.role || 'user').toUpperCase();
        if (appContainer) appContainer.style.display = 'block';
    };
    
    // Wait for DOM to be fully loaded before updating UI
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            updateUI();
            if (loadingOverlay) loadingOverlay.style.display = 'none';
        });
    } else {
        updateUI();
        if (loadingOverlay) loadingOverlay.style.display = 'none';
    }
    
    console.log('Dashboard UI initialized successfully');
    
} catch (error) {
    console.error('Error initializing dashboard UI:', error);
    if (loadingOverlay) {
        loadingOverlay.innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <h3 style="color: #ef4444;">Initialization Error</h3>
                <p>${error.message || 'Failed to load dashboard'}</p>
                <button onclick="window.location.reload()" 
                        style="margin-top: 15px; padding: 8px 16px; background: #3b82f6; color: white; 
                               border: none; border-radius: 4px; cursor: pointer;">
                    Refresh Page
                </button>
            </div>`;
    }
}
