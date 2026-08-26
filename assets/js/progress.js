/**
 * Progress Bar and Loading Utilities
 * Provides functions for showing progress, loading states, and success animations
 */

class ProgressBar {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.percentage = 0;
        this.status = '';
    }

    show() {
        if (this.container) {
            this.container.classList.remove('hidden');
        }
    }

    hide() {
        if (this.container) {
            this.container.classList.add('hidden');
        }
    }

    update(percentage, status = '') {
        this.percentage = Math.min(100, Math.max(0, percentage));
        this.status = status;
        this.render();
    }

    render() {
        if (!this.container) return;

        const fill = this.container.querySelector('.progress-bar-fill');
        const percentageText = this.container.querySelector('.progress-percentage');
        const statusText = this.container.querySelector('.progress-status');

        if (fill) {
            fill.style.width = this.percentage + '%';
        }

        if (percentageText) {
            percentageText.textContent = Math.round(this.percentage) + '%';
        }

        if (statusText && this.status) {
            statusText.innerHTML = `<i class="fas fa-spinner"></i> ${this.status}`;
        }
    }

    complete(message = 'Complete!') {
        this.update(100, message);
        setTimeout(() => {
            if (this.container) {
                const statusText = this.container.querySelector('.progress-status');
                if (statusText) {
                    statusText.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
                }
            }
        }, 300);
    }

    error(message = 'Error occurred') {
        if (this.container) {
            this.container.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
            const statusText = this.container.querySelector('.progress-status');
            if (statusText) {
                statusText.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            }
        }
    }
}

// Loading Spinner
const LoadingSpinner = {
    show(message = 'Loading...') {
        let spinner = document.getElementById('loadingSpinner');
        
        if (!spinner) {
            spinner = document.createElement('div');
            spinner.id = 'loadingSpinner';
            spinner.className = 'spinner-overlay';
            spinner.innerHTML = `
                <div class="spinner"></div>
                <p>${message}</p>
            `;
            document.body.appendChild(spinner);
        } else {
            spinner.querySelector('p').textContent = message;
            spinner.classList.remove('hidden');
        }
    },

    hide() {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.classList.add('hidden');
        }
    },

    remove() {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.remove();
        }
    }
};

// Upload Progress Tracker
class UploadProgress {
    constructor(file) {
        this.file = file;
        this.startTime = Date.now();
        this.loaded = 0;
        this.total = file.size;
    }

    update(loaded) {
        this.loaded = loaded;
    }

    getPercentage() {
        return (this.loaded / this.total) * 100;
    }

    getSpeed() {
        const elapsed = (Date.now() - this.startTime) / 1000; // seconds
        const speed = this.loaded / elapsed; // bytes per second
        return this.formatBytes(speed) + '/s';
    }

    getETA() {
        const elapsed = (Date.now() - this.startTime) / 1000;
        const speed = this.loaded / elapsed;
        const remaining = this.total - this.loaded;
        const eta = remaining / speed;
        return this.formatTime(eta);
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    formatTime(seconds) {
        if (seconds < 60) return Math.round(seconds) + 's';
        const minutes = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);
        return minutes + 'm ' + secs + 's';
    }
}

// Toast Notification (better than alert)
const Toast = {
    show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        };

        toast.innerHTML = `
            <div class="flex items-center gap-3 ${colors[type]} text-white px-6 py-4 rounded-lg shadow-lg">
                <i class="fas ${icons[type]} text-xl"></i>
                <span>${message}</span>
            </div>
        `;

        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            animation: slideInRight 0.3s ease-out;
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    success(message, duration) {
        this.show(message, 'success', duration);
    },

    error(message, duration) {
        this.show(message, 'error', duration);
    },

    warning(message, duration) {
        this.show(message, 'warning', duration);
    },

    info(message, duration) {
        this.show(message, 'info', duration);
    }
};

// Add toast animations to document
if (!document.getElementById('toast-styles')) {
    const style = document.createElement('style');
    style.id = 'toast-styles';
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ProgressBar, LoadingSpinner, UploadProgress, Toast };
}
