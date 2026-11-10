/* Pantalla «Sin conexión» de la app: vuelve a cargar la plataforma (AppNativaPlugin.reintentar) */
document.getElementById('reintentar').addEventListener('click', function () {
    var nativa = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.AppNativa;
    if (nativa) {
        nativa.reintentar();
    } else {
        window.history.back();
    }
});
