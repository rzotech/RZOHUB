/**
 * RZO Key Management - Client Interaction Utilities
 */

document.addEventListener('DOMContentLoaded', () => {
    // Copy key to clipboard
    const copyBtn = document.getElementById('btn-copy-key');
    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            const keyElement = document.getElementById('license-key-text');
            if (!keyElement) return;
            const text = keyElement.innerText.trim();
            if (!text || text === 'NO_LICENSE_ASSIGNED' || text.includes('NO KEY ACTIVE') || text.includes('NO ACTIVE KEY')) return;

            navigator.clipboard.writeText(text).then(() => {
                const originalText = copyBtn.innerText;
                copyBtn.innerText = 'Copied!';
                copyBtn.classList.add('btn-primary');
                copyBtn.classList.remove('btn-outline');
                setTimeout(() => {
                    copyBtn.innerText = originalText;
                    copyBtn.classList.remove('btn-primary');
                    copyBtn.classList.add('btn-outline');
                }, 2000);
            }).catch(() => {
                // Fallback copy
                const temp = document.createElement('textarea');
                temp.value = text;
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);
                alert('License key copied to clipboard!');
            });
        });
    }

    // Live countdown timer for expiration & cooldown unlock
    const countdownEl = document.getElementById('live-countdown');
    const cooldownEl = document.getElementById('cooldown-timer');
    const getKeyBtn = document.getElementById('btn-get-key');
    const cooldownBox = document.getElementById('cooldown-box');

    const sourceEl = cooldownEl || countdownEl;
    if (sourceEl) {
        let remainingSeconds = parseInt(sourceEl.getAttribute('data-seconds') || '0', 10);

        function formatTime(sec) {
            if (sec <= 0) return '00:00:00';
            const days = Math.floor(sec / 86400);
            const hours = Math.floor((sec % 86400) / 3600);
            const minutes = Math.floor((sec % 3600) / 60);
            const seconds = sec % 60;

            let str = '';
            if (days > 0) str += `${days}d `;
            str += `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            return str;
        }

        function updateTimers() {
            if (remainingSeconds <= 0) {
                if (countdownEl) countdownEl.innerText = 'Expired';
                if (cooldownEl) cooldownEl.innerText = '00:00:00 (Ready to Renew)';

                // Dynamically unlock button if currently locked
                if (getKeyBtn && getKeyBtn.tagName === 'BUTTON') {
                    const newLink = document.createElement('a');
                    newLink.href = 'get_key.php';
                    newLink.id = 'btn-get-key';
                    newLink.className = 'btn btn-primary';
                    newLink.innerHTML = `
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                        <span>Get 24h License Key</span>
                    `;
                    getKeyBtn.parentNode.replaceChild(newLink, getKeyBtn);
                    if (cooldownBox) {
                        cooldownBox.style.display = 'none';
                    }
                }
                return;
            }

            const formatted = formatTime(remainingSeconds);
            if (countdownEl) countdownEl.innerText = formatted;
            if (cooldownEl) cooldownEl.innerText = formatted;

            remainingSeconds--;
        }

        updateTimers();
        setInterval(updateTimers, 1000);
    }
});
