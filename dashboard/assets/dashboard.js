window.addEventListener('pageshow', function (event) {
    // 1) event.persisted === true -> veio do bfcache (histórico)
    // 2) type === "back_forward" cobre outros casos
    var navEntries = performance.getEntriesByType("navigation");
    var navType = navEntries && navEntries.length ? navEntries[0].type : null;

    if (event.persisted || navType === "back_forward") {
        window.location.reload();
    }
});

function reloadIfNeeded() {
    // Recarrega sempre que a aba volta a ficar visível
    if (!document.hidden) {
        window.location.reload();
    }
}

// Quando a aba volta a ficar visível
document.addEventListener('visibilitychange', reloadIfNeeded);

// Quando a janela/aba ganha foco
window.addEventListener('focus', reloadIfNeeded);

function toggleTopNavMenu() {
    var el = document.getElementById('topNavMobileMenu');
    if (!el) return;
    el.classList.toggle('open');
}