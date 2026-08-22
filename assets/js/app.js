document.addEventListener('DOMContentLoaded', function () {
    const mapElements = document.querySelectorAll('[data-map]');
    mapElements.forEach(function (mapEl) {
        if (typeof mapboxgl === 'undefined') return;
        mapboxgl.accessToken = mapEl.dataset.mapboxToken || '';
        const lat = parseFloat(mapEl.dataset.lat) || 30.18;
        const lng = parseFloat(mapEl.dataset.lng) || 67.00;
        const map = new mapboxgl.Map({
            container: mapEl,
            style: 'mapbox://styles/mapbox/streets-v11',
            center: [lng, lat],
            zoom: 12,
        });
        new mapboxgl.Marker().setLngLat([lng, lat]).addTo(map);
    });

    if (window.jQuery) {
        window.jQuery(document).ready(function () {
            if (typeof window.jQuery('[data-bs-toggle="tooltip"]').tooltip === 'function') {
                window.jQuery('[data-bs-toggle="tooltip"]').tooltip();
            }

            setTimeout(function () {
                window.jQuery('.alert').fadeOut('slow');
            }, 5000);

            window.showToast = function (message, type = 'success') {
                const icons = {
                    success: 'fa-check-circle',
                    error: 'fa-exclamation-circle',
                    warning: 'fa-exclamation-triangle',
                    info: 'fa-info-circle'
                };

                const colors = {
                    success: '#48bb78',
                    error: '#fc8181',
                    warning: '#f6ad55',
                    info: '#63b3ed'
                };

                const toast = `
                    <div class="alert alert-nekirot-${type} d-flex align-items-center shadow-lg fade-in" style="border-left: 4px solid ${colors[type] || colors.success};">
                        <i class="fas ${icons[type] || icons.info} me-2" style="color: ${colors[type] || colors.success};"></i>
                        <div>${message}</div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                    </div>
                `;

                window.jQuery('.toast-container').append(toast);
                setTimeout(function () {
                    window.jQuery('.toast-container .alert').last().fadeOut('slow', function () {
                        window.jQuery(this).remove();
                    });
                }, 5000);
            };

            window.shareWhatsApp = function (phone, message) {
                const url = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
                window.open(url, '_blank');
            };

            window.copyToClipboard = function (text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        window.showToast('Copied to clipboard!', 'success');
                    }).catch(function () {
                        fallbackCopy(text);
                    });
                } else {
                    fallbackCopy(text);
                }
            };

            function fallbackCopy(text) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                window.showToast('Copied to clipboard!', 'success');
            }

            window.timeAgo = function (timestamp) {
                const diff = Math.floor((Date.now() - new Date(timestamp).getTime()) / 1000);
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
                return new Date(timestamp).toLocaleDateString();
            };

            if (window.location.pathname.includes('dashboard')) {
                setInterval(function () {
                    window.location.reload();
                }, 30000);
            }
        });
    }
});

window.getCurrentLocation = function () {
    return new Promise(function (resolve, reject) {
        if (!navigator.geolocation) {
            reject('Geolocation not supported');
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            resolve({
                lat: position.coords.latitude,
                lng: position.coords.longitude
            });
        }, function (error) {
            reject(error.message);
        });
    });
};
